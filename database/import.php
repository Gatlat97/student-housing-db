
<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Database connection
$servername = "localhost";
$username = "root";
$password = "Hunter52105";
$dbname = "final_project";
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

echo "<h2>Database CSV Import</h2>";

try {

    // --------------------------------------------------
    // 1. Find all CSV files in this directory
    // --------------------------------------------------

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

        $tableName = pathinfo($file, PATHINFO_FILENAME);
        $key = strtolower($tableName);

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

    // --------------------------------------------------
    // 2. Get database column names
    // --------------------------------------------------

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

    // --------------------------------------------------
    // 3. Find foreign key dependencies
    // --------------------------------------------------

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

    // --------------------------------------------------
    // 4. Sort tables by dependency order
    // --------------------------------------------------

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

    // --------------------------------------------------
    // 5. Display import order
    // --------------------------------------------------

    echo "<h3>Import Order</h3><ol>";

    foreach ($importOrder as $table) {
        echo "<li>" . htmlspecialchars($table) . "</li>";
    }

    echo "</ol>";

    // --------------------------------------------------
    // 6. Import each CSV file
    // --------------------------------------------------

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

        $columns = [];

        foreach ($headers as $header) {

            $header = trim($header);
            $key = strtolower($header);

            if (!isset($columnLookup[$key])) {
                fclose($file);
                throw new Exception(
                    "Unknown column '$header' in " .
                    basename($filePath)
                );
            }

            $columns[] = $columnLookup[$key];
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

                if (count($data) !== count($columns)) {
                    throw new Exception(
                        "Incorrect number of fields on CSV line " .
                        $lineNumber
                    );
                }

                // Copy CSV data into bound parameters
                foreach ($data as $index => $value) {

                    $value = trim($value);

                    // Convert empty numeric fields to NULL
                    if ($value === "") {
                        $values[$index] = null;
                    } else {
                        $values[$index] = $value;
                    }
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

    // --------------------------------------------------
    // 7. Finish
    // --------------------------------------------------

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
