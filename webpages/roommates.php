<?php
session_start();

/* 
   DATABASE CONNECTION */
// Login details live in database/db_config.php (ignored by Git, so the password stays private)
require __DIR__ . "/../database/db_config.php";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

/*
   MATCHING SETTINGS
*/
// How much each preference counts toward the match score (adds up to 100)
$weights = [
    "budget"         => 20,
    "cleanliness"    => 15,
    "smoking"        => 15,
    "noise"          => 10,
    "bedtime"        => 10,
    "guests"         => 10,
    "housing_type"   => 10,
    "wake_time"      => 5,
    "study_location" => 5
];

// Names shown to the user for each preference
$labels = [
    "budget"         => "Budget",
    "cleanliness"    => "Cleanliness",
    "smoking"        => "Smoking",
    "noise"          => "Noise Level",
    "bedtime"        => "Bedtime",
    "guests"         => "Guests",
    "housing_type"   => "Housing Type",
    "wake_time"      => "Wake-up Time",
    "study_location" => "Study Location"
];

// Available attributes users can search (same idea as housing.php)
$attributes = [
    "name"           => "Name",
    "major"          => "Major",
    "year"           => "Year",
    "gender"         => "Gender",
    "bio"            => "Bio",
    "housing_type"   => "Housing Type",
    "study_location" => "Study Location",
    "smoking"        => "Smoking",
    "guests"         => "Guests"
];

// The SQL column behind each search attribute
$searchColumns = [
    "name"           => "CONCAT(u.first_name, ' ', u.last_name)",
    "major"          => "s.major",
    "year"           => "s.year",
    "gender"         => "s.gender",
    "bio"            => "s.bio",
    "housing_type"   => "p.housing_type",
    "study_location" => "p.study_location",
    "smoking"        => "p.smoking",
    "guests"         => "p.guests"
];

/*
   MATCHING FUNCTIONS
*/

// Turns a time like "23:00" or "11:30 PM" into minutes after midnight.
// Returns null if the value isn't a clock time.
function toMinutes($value) {
    $time = strtotime("today " . trim((string)$value));
    if ($time === false) {
        return null;
    }
    return (int)date("G", $time) * 60 + (int)date("i", $time);
}

// How alike two answers are, from 0 (opposite) to 1 (identical).
// Returns null if either student left it blank, so it isn't counted.
//   - numbers (budget, 1-5 scales): closer numbers score higher
//   - clock times: closer times score higher (4+ hours apart = 0)
//   - text answers ("Sometimes", "Apartment"): same = 1, different = 0
function similarity($field, $a, $b, $ranges) {
    if ($a === null || $b === null || trim((string)$a) === "" || trim((string)$b) === "") {
        return null;
    }

    if (is_numeric($a) && is_numeric($b)) {
        $range = $ranges[$field];
        if ($range <= 0) {
            return ($a == $b) ? 1 : 0;
        }
        return max(0, 1 - abs($a - $b) / $range);
    }

    if ($field === "bedtime" || $field === "wake_time") {
        $minutesA = toMinutes($a);
        $minutesB = toMinutes($b);
        if ($minutesA !== null && $minutesB !== null) {
            $diff = abs($minutesA - $minutesB);
            $diff = min($diff, 1440 - $diff);   // 11 PM vs 1 AM is 2 hours apart, not 22
            return max(0, 1 - $diff / 240);
        }
    }

    return (strcasecmp(trim($a), trim($b)) === 0) ? 1 : 0;
}

// Scores one student against me. Returns the score (0-100) plus
// the preferences we share and the ones we differ on.
function compatibility($me, $other, $weights, $labels, $ranges) {
    $earned = 0;
    $possible = 0;
    $similar = [];
    $different = [];

    foreach ($weights as $field => $weight) {
        $sim = similarity($field, $me[$field], $other[$field], $ranges);
        if ($sim === null) {
            continue;
        }
        $earned += $weight * $sim;
        $possible += $weight;

        if ($sim >= 0.8) {
            $similar[] = $labels[$field];
        } elseif ($sim < 0.3 && $weight >= 10) {
            $different[] = $labels[$field];
        }
    }

    $score = ($possible > 0) ? (int)round($earned / $possible * 100) : 0;
    return [$score, $similar, $different];
}

/*
   STATUS WORDS
*/
// Reuse the spelling already in match_request (e.g. "Pending" vs "pending")
$statusWords = [];
$statusResult = $conn->query("SELECT DISTINCT status FROM match_request WHERE status IS NOT NULL");
while ($row = $statusResult->fetch_assoc()) {
    $statusWords[strtolower(trim($row["status"]))] = $row["status"];
}

function statusWord($word, $statusWords) {
    return $statusWords[$word] ?? $word;
}

/*
   CURRENT STUDENT
*/
// Uses the logged-in student if there is one. Until login is connected,
// the "Viewing As" menu picks which student's matches to show.
$students = [];
$studentResult = $conn->query(
    "SELECT s.user_id, u.first_name, u.last_name
     FROM student s
     JOIN user u ON u.user_id = s.user_id
     ORDER BY u.last_name, u.first_name"
);
while ($row = $studentResult->fetch_assoc()) {
    $students[(int)$row["user_id"]] = $row["first_name"] . " " . $row["last_name"];
}

if (count($students) === 0) {
    die("No students found. Run database/import.php first.");
}

$meId = (int)($_POST["student"] ?? $_GET["student"] ?? $_SESSION["user_id"] ?? 0);
if (!isset($students[$meId])) {
    $meId = array_key_first($students);
}

/* HANDLE BUTTONS (SEND / ACCEPT / DECLINE) */
// After a button is clicked, redirect back to this page so that
// refreshing the page can't send the same request twice.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST["action"] ?? "";
    $message = "";

    if ($action == "send") {
        $receiverId = (int)$_POST["receiver"];

        // Check for an existing request between these two students (either direction)
        $check = $conn->prepare(
            "SELECT request_id FROM match_request
             WHERE (sender_id = ? AND receiver_id = ?)
                OR (sender_id = ? AND receiver_id = ?)"
        );
        $check->bind_param("iiii", $meId, $receiverId, $receiverId, $meId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!isset($students[$receiverId]) || $receiverId == $meId) {
            $message = "That student could not be found.";
        } elseif ($exists) {
            $message = "You already have a request with " . $students[$receiverId] . ".";
        } else {
            // Next request_id is worked out in the same statement
            $insert = $conn->prepare(
                "INSERT INTO match_request (request_id, sender_id, receiver_id, status, request_date)
                 SELECT COALESCE(MAX(request_id), 0) + 1, ?, ?, ?, CURDATE()
                 FROM match_request"
            );
            $pending = statusWord("pending", $statusWords);
            $insert->bind_param("iis", $meId, $receiverId, $pending);

            if ($insert->execute()) {
                $message = "Request sent to " . $students[$receiverId] . ".";
            } else {
                $message = "There was a problem sending the request.";
            }
            $insert->close();
        }
    }

    if ($action == "accept" || $action == "decline") {
        $requestId = (int)$_POST["request_id"];
        $newStatus = statusWord(($action == "accept") ? "accepted" : "declined", $statusWords);

        // Only the student who received a pending request can answer it
        $update = $conn->prepare(
            "UPDATE match_request SET status = ?
             WHERE request_id = ? AND receiver_id = ? AND LOWER(status) = 'pending'"
        );
        $update->bind_param("sii", $newStatus, $requestId, $meId);
        $update->execute();

        $message = ($update->affected_rows > 0)
            ? "Request " . strtolower($newStatus) . "."
            : "That request was already answered.";
        $update->close();
    }

    // Go back to the same search after the redirect
    parse_str($_POST["search"] ?? "", $search);
    $search["student"] = $meId;
    $search["message"] = $message;
    header("Location: roommates.php?" . http_build_query($search));
    exit();
}

/*
   SEARCH INFORMATION
*/
$attribute = $_GET["attribute"] ?? "major";
$keyword = trim($_GET["keyword"] ?? "");
$minScore = (int)($_GET["min_score"] ?? 0);

// "Same university only" is on unless the search form turned it off
$sameUni = isset($_GET["searched"]) ? isset($_GET["same_uni"]) : true;

// Make sure the selected attribute is valid
if (!array_key_exists($attribute, $attributes)) {
    $attribute = "major";
}

/*
   MY PROFILE
*/
$profileSql =
    "SELECT s.user_id, u.first_name, u.last_name, s.university_id, s.year, s.major, s.gender, s.bio,
            un.name AS university,
            p.budget, p.bedtime, p.wake_time, p.cleanliness, p.noise, p.smoking,
            p.guests, p.housing_type, p.study_location
     FROM student s
     JOIN user u ON u.user_id = s.user_id
     LEFT JOIN university un ON un.university_id = s.university_id
     LEFT JOIN preferences p ON p.student_id = s.user_id";

$stmt = $conn->prepare($profileSql . " WHERE s.user_id = ?");
$stmt->bind_param("i", $meId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* 
   OTHER STUDENTS*/
$column = $searchColumns[$attribute];
$uniFilter = $sameUni ? (int)$me["university_id"] : 0;

// Gender is matched exactly, so searching "male" doesn't also find "female"
if ($attribute == "gender" && $keyword != "") {
    $searchCondition = "$column = ?";
    $searchValue = $keyword;
} else {
    $searchCondition = "CAST($column AS CHAR) LIKE ?";
    $searchValue = "%" . $keyword . "%";
}

$stmt = $conn->prepare(
    $profileSql . "
     WHERE s.user_id <> ?
       AND (? = 0 OR s.university_id = ?)
       AND " . $searchCondition
);
$stmt->bind_param("iiis", $meId, $uniFilter, $uniFilter, $searchValue);
$stmt->execute();
$candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Spread of each numeric answer (e.g. budgets from 500 to 1500 = 1000),
// so a $100 budget gap and a 1-point cleanliness gap are judged on their own scale
$ranges = [];
foreach ($weights as $field => $weight) {
    $numbers = [];
    foreach (array_merge([$me], $candidates) as $row) {
        if (is_numeric($row[$field])) {
            $numbers[] = (float)$row[$field];
        }
    }
    $ranges[$field] = (count($numbers) > 0) ? max($numbers) - min($numbers) : 0;
}

/* CORE AND SORT*/
$matches = [];
foreach ($candidates as $candidate) {
    list($score, $similar, $different) = compatibility($me, $candidate, $weights, $labels, $ranges);
    if ($score < $minScore) {
        continue;
    }
    $candidate["score"] = $score;
    $candidate["similar"] = $similar;
    $candidate["different"] = $different;
    $matches[] = $candidate;
}

// Highest match first
usort($matches, function ($a, $b) {
    return $b["score"] - $a["score"];
});

/* MY REQUESTS */
$stmt = $conn->prepare(
    "SELECT m.request_id, m.sender_id, m.receiver_id, m.status, m.request_date,
            u.first_name, u.last_name
     FROM match_request m
     JOIN user u ON u.user_id = IF(m.sender_id = ?, m.receiver_id, m.sender_id)
     WHERE m.sender_id = ? OR m.receiver_id = ?
     ORDER BY m.request_date DESC, m.request_id DESC"
);
$stmt->bind_param("iii", $meId, $meId, $meId);
$stmt->execute();
$myRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$requestWith = [];   // other student's id => latest request between us
$incoming = [];      // pending requests I need to answer

foreach ($myRequests as $request) {
    $otherId = ($request["sender_id"] == $meId) ? $request["receiver_id"] : $request["sender_id"];
    if (!isset($requestWith[$otherId])) {
        $requestWith[$otherId] = $request;
    }
    if ($request["receiver_id"] == $meId && strtolower($request["status"]) == "pending") {
        $incoming[] = $request;
    }
}

// Current search, passed through the buttons so it's kept after the redirect
$currentSearch = http_build_query([
    "attribute" => $attribute,
    "keyword"   => $keyword,
    "min_score" => $minScore,
    "same_uni"  => $sameUni ? 1 : null,
    "searched"  => 1
]);
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Roommates</title>
        <link rel="stylesheet" href="style.css">
        <style>
            /* Roommate page additions, using the same colors as style.css */
            .match-score {
                display: inline-block;
                padding: 2px 12px;
                border-radius: 12px;
                color: white;
                font-weight: bold;
            }
            .match-high { background-color: #27ae60; }
            .match-mid { background-color: #f39c12; }
            .match-low { background-color: #c0392b; }
            .similar { color: #27ae60; }
            .different { color: #c0392b; }
            .notice {
                background-color: #eaf4fb;
                border-left: 5px solid #3498db;
                padding: 10px 15px;
                margin: 20px;
            }
            .inline-form {
                display: inline-flex;
                flex-direction: row;
                gap: 10px;
                margin: 0 0 0 10px;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <nav class="tabs">
                <a href="index.php" class="tab">Home</a>
                <a href="housing.php" class="tab">Housing</a>
                <a href="roommates.php" class="tab active">Roommates</a>
                <a href="profile.php" class="tab">Profile</a>
            </nav>

            <h1>Find Roommates</h1>
            <p>Find roommates based on their description of their everyday life. Students with the most similar preferences are shown first.</p>

            <?php if (!empty($_GET["message"])): ?>
                <p class="notice"><?php echo htmlspecialchars($_GET["message"]); ?></p>
            <?php endif; ?>

            <!-- Viewing As (until login is connected) -->
            <form method="GET" action="">
                <label for="student">Viewing As:</label>
                <select name="student" id="student" onchange="this.form.submit()">
                    <?php foreach ($students as $id => $name): ?>
                        <option value="<?php echo $id; ?>" <?php echo ($id === $meId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="housing-option">
                <strong>University:</strong> <?php echo htmlspecialchars($me["university"] ?? "Not set"); ?> &nbsp;&nbsp;
                <strong>Major:</strong> <?php echo htmlspecialchars($me["major"] ?? "Not set"); ?> &nbsp;&nbsp;
                <strong>Year:</strong> <?php echo htmlspecialchars($me["year"] ?? "Not set"); ?> &nbsp;&nbsp;
                <strong>Budget:</strong> $<?php echo htmlspecialchars($me["budget"] ?? "Not set"); ?>
                <?php if ($me["budget"] === null && $me["smoking"] === null): ?>
                    <p class="different">This student has no saved preferences, so match scores can't be calculated.</p>
                <?php endif; ?>
            </div>

            <!-- Requests waiting for an answer -->
            <?php if (count($incoming) > 0): ?>
                <h2>Requests Waiting for You (<?php echo count($incoming); ?>)</h2>
                <?php foreach ($incoming as $request): ?>
                    <div class="housing-option">
                        <strong><?php echo htmlspecialchars($request["first_name"] . " " . $request["last_name"]); ?></strong>
                        sent you a request on <?php echo htmlspecialchars($request["request_date"]); ?>
                        <form method="POST" action="roommates.php" class="inline-form">
                            <input type="hidden" name="student" value="<?php echo $meId; ?>">
                            <input type="hidden" name="request_id" value="<?php echo (int)$request["request_id"]; ?>">
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($currentSearch); ?>">
                            <span class="button-container">
                                <button type="submit" name="action" value="accept">Accept</button>
                                <button type="submit" name="action" value="decline">Decline</button>
                            </span>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Search -->
            <form method="GET" action="">
                <input type="hidden" name="student" value="<?php echo $meId; ?>">
                <input type="hidden" name="searched" value="1">

                <label for="attribute">Search By:</label>
                <select name="attribute" id="attribute">
                    <?php foreach ($attributes as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"
                            <?php echo ($attribute === $key) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="keyword" placeholder="Enter search value" value="<?php echo htmlspecialchars($keyword); ?>">

                <label for="min_score">Minimum Match:</label>
                <select name="min_score" id="min_score">
                    <?php foreach ([0 => "Any", 50 => "50% or higher", 60 => "60% or higher", 70 => "70% or higher", 80 => "80% or higher"] as $value => $text): ?>
                        <option value="<?php echo $value; ?>" <?php echo ($minScore === $value) ? 'selected' : ''; ?>>
                            <?php echo $text; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>
                    <input type="checkbox" name="same_uni" value="1" <?php echo $sameUni ? 'checked' : ''; ?>>
                    Same university only
                </label>

                <div class="button-container">
                    <button type="submit">Search</button>
                    <button type="button" onclick="window.location.href='roommates.php?student=<?php echo $meId; ?>'">Clear Search</button>
                </div>
            </form>

            <!-- Roommate Matches -->
            <h2><?php echo count($matches); ?> Roommate Match<?php echo (count($matches) == 1) ? '' : 'es'; ?></h2>

            <div class="housing-list">
                <?php
                    if (count($matches) > 0) {
                        foreach ($matches as $match) {
                            $scoreClass = ($match["score"] >= 75) ? "match-high" : (($match["score"] >= 50) ? "match-mid" : "match-low");
                            $existing = $requestWith[$match["user_id"]] ?? null;

                            echo "<div class='housing-option'>";

                            echo "<h2>" . htmlspecialchars($match["first_name"] . " " . $match["last_name"]) .
                                " &nbsp;<span class='match-score $scoreClass'>" . $match["score"] . "% Match</span></h2>";

                            echo "<strong>University:</strong> " .
                                htmlspecialchars($match["university"] ?? "N/A") . " &nbsp;&nbsp; ";

                            echo "<strong>Major:</strong> " .
                                htmlspecialchars($match["major"] ?? "N/A") . " &nbsp;&nbsp; ";

                            echo "<strong>Year:</strong> " .
                                htmlspecialchars((string)($match["year"] ?? "N/A")) . " &nbsp;&nbsp; ";

                            echo "<strong>Gender:</strong> " .
                                htmlspecialchars($match["gender"] ?? "N/A");

                            if (!empty($match["bio"])) {
                                echo "<p>" . htmlspecialchars($match["bio"]) . "</p>";
                            } else {
                                echo "<br><br>";
                            }

                            echo "<strong>Budget:</strong> $" .
                                htmlspecialchars((string)($match["budget"] ?? "N/A")) . " &nbsp;&nbsp; ";

                            echo "<strong>Sleep:</strong> " .
                                htmlspecialchars($match["bedtime"] ?? "N/A") . " to " .
                                htmlspecialchars($match["wake_time"] ?? "N/A") . " &nbsp;&nbsp; ";

                            echo "<strong>Cleanliness:</strong> " .
                                htmlspecialchars((string)($match["cleanliness"] ?? "N/A")) . " &nbsp;&nbsp; ";

                            echo "<strong>Noise:</strong> " .
                                htmlspecialchars((string)($match["noise"] ?? "N/A")) . " &nbsp;&nbsp; ";

                            echo "<strong>Smoking:</strong> " .
                                htmlspecialchars($match["smoking"] ?? "N/A") . " &nbsp;&nbsp; ";

                            echo "<strong>Guests:</strong> " .
                                htmlspecialchars($match["guests"] ?? "N/A") . " &nbsp;&nbsp; ";

                            echo "<strong>Housing Type:</strong> " .
                                htmlspecialchars($match["housing_type"] ?? "N/A") . " &nbsp;&nbsp; ";

                            echo "<strong>Studies At:</strong> " .
                                htmlspecialchars($match["study_location"] ?? "N/A");

                            if (count($match["similar"]) > 0) {
                                echo "<p class='similar'><strong>Similar:</strong> " .
                                    htmlspecialchars(implode(", ", $match["similar"])) . "</p>";
                            }

                            if (count($match["different"]) > 0) {
                                echo "<p class='different'><strong>Different:</strong> " .
                                    htmlspecialchars(implode(", ", $match["different"])) . "</p>";
                            }

                            if ($existing) {
                                $who = ($existing["sender_id"] == $meId) ? "You sent a request" : "They sent you a request";
                                echo "<p><em>" . $who . " (" .
                                    htmlspecialchars(ucfirst(strtolower($existing["status"]))) . ")</em></p>";
                            } else {
                                echo "<form method='POST' action='roommates.php'>";
                                echo "<input type='hidden' name='action' value='send'>";
                                echo "<input type='hidden' name='student' value='" . $meId . "'>";
                                echo "<input type='hidden' name='receiver' value='" . (int)$match["user_id"] . "'>";
                                echo "<input type='hidden' name='search' value='" . htmlspecialchars($currentSearch, ENT_QUOTES) . "'>";
                                echo "<div class='button-container'><button type='submit'>Send Request</button></div>";
                                echo "</form>";
                            }

                            echo "</div>";

                            // Breakpoint between matches
                            echo "<hr>";
                        }
                    } else {
                        echo "<p>No roommates match your search. Try a different keyword or turn off \"Same university only\".</p>";
                    }
                ?>
            </div>
        </div>
    </body>
</html>
<?php
    $conn->close();
?>