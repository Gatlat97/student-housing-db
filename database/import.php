<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Database connection
// The password lives in db_config.php, which is listed in .gitignore
// so it never gets pushed to GitHub. Each teammate keeps their own copy.
require __DIR__ . "/db_config.php";   // defines $servername, $username, $password, $dbname

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

// CSV file name (without .csv) => table name in our database.
// The CSVs use plural names; our tables use singular names.
// sql.csv and summary.csv are not table data, so they are left out on purpose.
$tableMap = [
    "amenities"         => "amenity",
    "landlords"         => "landlord",
    "listing_amenities" => "listing_amenity",
    "listings"          => "listing",
    "match_requests"    => "match_request",
    "messages"          => "message",
    "preferences"       => "preferences",
    "reviews"           => "review",
    "saved_listings"    => "saved_listing",
    "students"          => "student",
    "universities"      => "university",
    "users"             => "user",
];

// CSV column name => our column name, for columns that are simply named differently.
$columnRename = [
    "match_request" => ["date" => "request_date"],
    "message"       => ["timestamp" => "sent_at"],
];

// In the CSVs, landlords and students have their own ID (landlord_id, student_id)
// plus a user_id. In our design they are subtypes of user, so user_id IS their
// primary key and the separate IDs don't exist. These columns are left out.
$dropColumns = [
    "landlord" => ["landlord_id"],
    "student"  => ["student_id"],
];

// Because of that, any CSV column holding a landlord_id or student_id must be
// translated to the matching user_id before it goes into our tables.
// table => [column => which ID map to use]
$translateColumns = [
    "listing"       => ["landlord_id" => "landlord"],
    "preferences"   => ["student_id"  => "student"],
    "review"        => ["student_id"  => "student"],
    "saved_listing" => ["student_id"  => "student"],
    // ASSUMPTION: roommate requests are between students, so these hold student_ids.
    "match_request" => ["sender_id" => "student", "receiver_id" => "student"],
    // ASSUMPTION: messages can be student <-> landlord, so these already hold user_ids
    // and are not translated.
];

// Reads one CSV and returns [fromColumn value => toColumn value],
// e.g. landlord_id => user_id from landlords.csv.
function readIdMap(string $path, string $fromColumn, string $toColumn): array
{
    $file = fopen($path, "r");
    if (!$file) {
        throw new Exception("Unable to open " . basename($path));
    }

    $headers = fgetcsv($file, 0, ",", '"', "");
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

    $from = array_search($fromColumn, $headers, true);
    $to   = array_search($toColumn, $headers, true);
    if ($from === false || $to === false) {
        fclose($file);
        throw new Exception(basename($path) . " needs columns $fromColumn and $toColumn");
    }

    $map = [];
    while (($row = fgetcsv($file, 0, ",", '"', "")) !== false) {
        if (count($row) === 1 && trim($row[0]) === "") {
            continue;
        }
        $map[trim($row[$from])] = trim($row[$to]);
    }
    fclose($file);
    return $map;
}

echo "<h2>Database CSV Import</h2>";

try {

    // 1. Find all CSV files in this directory

    $csvFiles = glob(__DIR__ . "/*.csv");

    if (!$csvFiles) {
        throw new Exception("No CSV files found in this directory.");
    }

    // Get all database tables
    $result = $conn->query("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '$dbname'
        AND TABLE_TYPE = 'BASE TABLE'
    ");

    $databaseTables = [];

    while ($row = $result->fetch_assoc()) {
        $databaseTables[strtolower($row['TABLE_NAME'])] =
            $row['TABLE_NAME'];
    }

    // Match CSV filenames to database tables
    $files = [];

    foreach ($csvFiles as $file) {

        $csvName = strtolower(pathinfo($file, PATHINFO_FILENAME));

        // Translate the CSV name to our table name; fall back to the CSV name itself
        $key = strtolower($tableMap[$csvName] ?? $csvName);

        if (isset($databaseTables[$key])) {
            $actualTable = $databaseTables[$key];
            $files[$actualTable] = $file;
        } else {
            echo "<p>Skipped: " .
                htmlspecialchars(basename($file)) .
                " (no matching database table)</p>";
        }
    }

    if (empty($files)) {
        throw new Exception("No CSV files matched database tables.");
    }

    // 2. Get database column names

    $columnResult = $conn->query("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '$dbname'
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");

    $tableColumns = [];

    while ($row = $columnResult->fetch_assoc()) {
        $tableColumns[$row['TABLE_NAME']][] =
            $row['COLUMN_NAME'];
    }

    // 3. Find foreign key dependencies

    $foreignKeys = $conn->query("
        SELECT TABLE_NAME, REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = '$dbname'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");

    $dependencies = [];

    foreach (array_keys($files) as $table) {
        $dependencies[$table] = [];
    }

    while ($row = $foreignKeys->fetch_assoc()) {

        $child = $row['TABLE_NAME'];
        $parent = $row['REFERENCED_TABLE_NAME'];

        // Only add dependencies when both tables have CSV files
        if (
            isset($files[$child]) &&
            isset($files[$parent]) &&
            $child !== $parent
        ) {
            $dependencies[$child][$parent] = true;
        }
    }

    // 4. Sort tables by dependency order

    $remaining = array_fill_keys(array_keys($files), true);
    $importOrder = [];

    while (!empty($remaining)) {

        $ready = [];

        foreach (array_keys($remaining) as $table) {

            $hasPendingParent = false;

            foreach (array_keys($dependencies[$table]) as $parent) {
                if (isset($remaining[$parent])) {
                    $hasPendingParent = true;
                    break;
                }
            }

            if (!$hasPendingParent) {
                $ready[] = $table;
            }
        }

        // Circular foreign key dependency
        if (empty($ready)) {
            throw new Exception(
                "Circular foreign key dependency detected. " .
                "Check your database relationships."
            );
        }

        sort($ready);

        foreach ($ready as $table) {
            $importOrder[] = $table;
            unset($remaining[$table]);
        }
    }

    // 5. Display import order

    echo "<h3>Import Order</h3><ol>";

    foreach ($importOrder as $table) {
        echo "<li>" . htmlspecialchars($table) . "</li>";
    }

    echo "</ol>";

    // 6. Empty the tables first, so the script can be rerun safely.
    // Without this, a second run would fail on duplicate primary keys.
    // Foreign key checks are switched off only while emptying, because
    // TRUNCATE refuses to empty a parent table that child tables point to.

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    foreach (array_reverse($importOrder) as $table) {
        $conn->query("TRUNCATE TABLE `" . str_replace("`", "``", $table) . "`");
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    echo "<p>Emptied " . count($importOrder) . " tables before importing.</p>";

    // Build the landlord_id => user_id and student_id => user_id lookups
    $idMaps = [
        "landlord" => readIdMap(__DIR__ . "/landlords.csv", "landlord_id", "user_id"),
        "student"  => readIdMap(__DIR__ . "/students.csv",  "student_id",  "user_id"),
    ];

    foreach ($idMaps as $name => $map) {
        $same = 0;
        foreach ($map as $old => $new) {
            if ((string)$old === (string)$new) {
                $same++;
            }
        }
        echo "<p>$name ID map: " . count($map) . " entries, " .
            "$same where the old ID already equals user_id.</p>";
    }

    // 7. Import each CSV file

    $totalImported = 0;

    foreach ($importOrder as $table) {

        $filePath = $files[$table];

        echo "<hr><h3>Importing: " .
            htmlspecialchars($table) . "</h3>";

        $file = fopen($filePath, "r");

        if (!$file) {
            throw new Exception(
                "Unable to open " . basename($filePath)
            );
        }

        // Read CSV headings
        $headers = fgetcsv($file, 0, ",", '"', "");

        if ($headers === false) {
            fclose($file);
            throw new Exception("CSV is empty: " . basename($filePath));
        }

        // Remove UTF-8 BOM if present
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

        // Match headings to actual database columns
        $columnLookup = [];

        foreach ($tableColumns[$table] as $column) {
            $columnLookup[strtolower($column)] = $column;
        }

        // $plan lists, for each column we insert, which CSV position it comes from
        // and which ID map (if any) translates its values.
        $columns = [];
        $plan = [];

        $drop      = $dropColumns[$table] ?? [];
        $rename    = $columnRename[$table] ?? [];
        $translate = $translateColumns[$table] ?? [];

        foreach ($headers as $csvIndex => $header) {

            $header = trim($header);
            $key = strtolower($header);

            if (in_array($key, $drop, true)) {
                continue;   // e.g. landlord_id in landlords.csv
            }

            $mapName = $translate[$key] ?? null;   // look up by the CSV name
            $key = strtolower($rename[$key] ?? $key);

            if (!isset($columnLookup[$key])) {
                fclose($file);
                throw new Exception(
                    "Unknown column '$header' in " .
                    basename($filePath)
                );
            }

            $columns[] = $columnLookup[$key];
            $plan[] = [$csvIndex, $mapName];
        }

        if (count($columns) !== count(array_unique($columns))) {
            fclose($file);
            throw new Exception(
                "Duplicate column headings in " . basename($filePath)
            );
        }

        // Build INSERT statement
        $quotedColumns = array_map(
            function ($column) {
                return "`" . str_replace("`", "``", $column) . "`";
            },
            $columns
        );

        $placeholders = implode(
            ", ",
            array_fill(0, count($columns), "?")
        );

        $sql = "INSERT INTO `" .
            str_replace("`", "``", $table) .
            "` (" . implode(", ", $quotedColumns) . ")
            VALUES (" . $placeholders . ")";

        $stmt = $conn->prepare($sql);

        // Bind CSV values as strings
        $values = array_fill(0, count($columns), null);
        $bindArguments = [str_repeat("s", count($columns))];

        foreach ($values as $index => &$value) {
            $bindArguments[] = &$value;
        }

        unset($value);

        call_user_func_array(
            [$stmt, "bind_param"],
            $bindArguments
        );

        $count = 0;
        $lineNumber = 1;

        $conn->begin_transaction();

        try {

            while (($data = fgetcsv($file, 0, ",", '"', "")) !== false) {

                $lineNumber++;

                // Skip completely blank rows
                if (
                    count($data) === 1 &&
                    trim($data[0]) === ""
                ) {
                    continue;
                }

                if (count($data) !== count($headers)) {
                    throw new Exception(
                        "Incorrect number of fields on CSV line " .
                        $lineNumber
                    );
                }

                // Copy CSV data into bound parameters
                foreach ($plan as $index => [$csvIndex, $mapName]) {

                    $value = trim($data[$csvIndex]);

                    // Convert empty fields to NULL
                    if ($value === "") {
                        $values[$index] = null;
                        continue;
                    }

                    // Translate landlord_id / student_id to user_id
                    if ($mapName !== null) {
                        if (!isset($idMaps[$mapName][$value])) {
                            throw new Exception(
                                "$value in column {$headers[$csvIndex]} has no matching $mapName"
                            );
                        }
                        $value = $idMaps[$mapName][$value];
                    }

                    $values[$index] = $value;
                }

                $stmt->execute();
                $count++;
            }

            $conn->commit();

        } catch (Throwable $e) {

            $conn->rollback();

            throw new Exception(
                "Failed importing " . basename($filePath) .
                " on line " . $lineNumber .
                ": " . $e->getMessage()
            );
        }

        fclose($file);
        $stmt->close();

        $totalImported += $count;

        echo "<p style='color:green;'>" .
            $count . " records imported into " .
            htmlspecialchars($table) . ".</p>";
    }

    // 8. Finish

    echo "<hr>";
    echo "<h2 style='color:green;'>Import Complete!</h2>";
    echo "<p>Total records imported: " .
        $totalImported . "</p>";

} catch (Throwable $e) {

    echo "<h2 style='color:red;'>Import Failed</h2>";
    echo "<pre>" .
        htmlspecialchars($e->getMessage()) .
        "</pre>";
}

$conn->close();

?>