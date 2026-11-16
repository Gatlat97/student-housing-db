<?php
session_start();

// DATABASE CONNECTION
$servername = "localhost";
$username = "root";
$password = "Hunter52105";
$dbname = "final_project";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

function e($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function nullableString($value): ?string {
    $value = trim((string)$value);
    return $value === "" ? null : $value;
}

/* Get the list of students for the temporary Viewing As selector. */
$students = [];
$result = $conn->query(
    "SELECT s.user_id, u.first_name, u.last_name
     FROM students s
     JOIN users u ON u.user_id = s.user_id
     ORDER BY u.last_name, u.first_name"
);

while ($row = $result->fetch_assoc()) {
    $students[(int)$row["user_id"]] = $row["first_name"] . " " . $row["last_name"];
}

if (!$students) {
    die("No student profiles were found. Import your student data first.");
}

/*
 * Use the logged-in user's ID when login is connected.
 * Until then, allow the Viewing As selector to choose a profile.
 */
$studentId = (int)($_SESSION["user_id"] ?? $_GET["student"] ?? $_POST["student_id"] ?? 0);

if (!isset($students[$studentId])) {
    $studentId = (int)array_key_first($students);
}

$message = "";
$messageType = "success";

/* Save profile changes. */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save") {
    // Keep the selected profile ID valid.
    $postedId = (int)($_POST["student_id"] ?? 0);

    if (!isset($students[$postedId])) {
        $message = "The selected student could not be found.";
        $messageType = "error";
    } else {
        $studentId = $postedId;

        $firstName = trim($_POST["first_name"] ?? "");
        $lastName = trim($_POST["last_name"] ?? "");
        $universityId = nullableString($_POST["university_id"] ?? "");
        $year = nullableString($_POST["year"] ?? "");
        $major = nullableString($_POST["major"] ?? "");
        $gender = nullableString($_POST["gender"] ?? "");
        $bio = nullableString($_POST["bio"] ?? "");

        $budgetInput = trim($_POST["budget"] ?? "");
        $budget = ($budgetInput === "") ? null : filter_var($budgetInput, FILTER_VALIDATE_FLOAT);

        $bedtime = nullableString($_POST["bedtime"] ?? "");
        $wakeTime = nullableString($_POST["wake_time"] ?? "");
        $cleanliness = nullableString($_POST["cleanliness"] ?? "");
        $noise = nullableString($_POST["noise"] ?? "");
        $smoking = nullableString($_POST["smoking"] ?? "");
        $guests = nullableString($_POST["guests"] ?? "");
        $housingType = nullableString($_POST["housing_type"] ?? "");
        $studyLocation = nullableString($_POST["study_location"] ?? "");

        if ($firstName === "" || $lastName === "") {
            $message = "First name and last name are required.";
            $messageType = "error";
        } elseif ($budgetInput !== "" && $budget === false) {
            $message = "Please enter a valid number for your monthly budget.";
            $messageType = "error";
        } else {
            // Confirm that the selected university exists, if one was selected.
            if ($universityId !== null) {
                $checkUniversity = $conn->prepare(
                    "SELECT university_id FROM universities WHERE university_id = ?"
                );
                $checkUniversity->bind_param("i", $universityId);
                $checkUniversity->execute();
                $validUniversity = $checkUniversity->get_result()->num_rows > 0;
                $checkUniversity->close();

                if (!$validUniversity) {
                    $message = "Please choose a valid university.";
                    $messageType = "error";
                }
            }

            if ($message === "") {
                $conn->begin_transaction();

                try {
                    $userUpdate = $conn->prepare(
                        "UPDATE users
                         SET first_name = ?, last_name = ?
                         WHERE user_id = ?"
                    );
                    $userUpdate->bind_param("ssi", $firstName, $lastName, $studentId);
                    $userUpdate->execute();
                    $userUpdate->close();

                    $studentUpdate = $conn->prepare(
                        "UPDATE students
                         SET university_id = ?, year = ?, major = ?, gender = ?, bio = ?
                         WHERE user_id = ?"
                    );

                    // Bind nullable values as strings; MySQL stores NULL when PHP values are null.
                    $studentUpdate->bind_param(
                        "issssi",
                        $universityId,
                        $year,
                        $major,
                        $gender,
                        $bio,
                        $studentId
                    );
                    $studentUpdate->execute();
                    $studentUpdate->close();

                    /*
                     * This assumes preferences.student_id is a PRIMARY KEY
                     * or has a UNIQUE index, as expected by the roommate profile join.
                     */
                    $preferenceUpdate = $conn->prepare(
                        "INSERT INTO preferences
                            (student_id, budget, bedtime, wake_time, cleanliness, noise,
                             smoking, guests, housing_type, study_location)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            budget = VALUES(budget),
                            bedtime = VALUES(bedtime),
                            wake_time = VALUES(wake_time),
                            cleanliness = VALUES(cleanliness),
                            noise = VALUES(noise),
                            smoking = VALUES(smoking),
                            guests = VALUES(guests),
                            housing_type = VALUES(housing_type),
                            study_location = VALUES(study_location)"
                    );

                    $preferenceUpdate->bind_param(
                        "idssssssss",
                        $studentId,
                        $budget,
                        $bedtime,
                        $wakeTime,
                        $cleanliness,
                        $noise,
                        $smoking,
                        $guests,
                        $housingType,
                        $studyLocation
                    );
                    $preferenceUpdate->execute();
                    $preferenceUpdate->close();

                    $conn->commit();

                    // Refresh the displayed name in the Viewing As selector.
                    $students[$studentId] = $firstName . " " . $lastName;
                    $message = "Your profile has been saved.";
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $message = "Your profile could not be saved. Check that your database columns match this form.";
                    $messageType = "error";
                }
            }
        }
    }
}

/* Load the selected student's current profile. */
$profileSql = "
    SELECT
        s.user_id,
        u.first_name,
        u.last_name,
        s.university_id,
        s.year,
        s.major,
        s.gender,
        s.bio,
        un.name AS university,
        p.budget,
        p.bedtime,
        p.wake_time,
        p.cleanliness,
        p.noise,
        p.smoking,
        p.guests,
        p.housing_type,
        p.study_location
    FROM students s
    JOIN users u ON u.user_id = s.user_id
    LEFT JOIN universities un ON un.university_id = s.university_id
    LEFT JOIN preferences p ON p.student_id = s.user_id
    WHERE s.user_id = ?
";

$stmt = $conn->prepare($profileSql);
$stmt->bind_param("i", $studentId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    die("The selected profile could not be loaded.");
}

/* University choices for the edit form. */
$universities = [];
$universityResult = $conn->query(
    "SELECT university_id, name FROM universities ORDER BY name"
);
while ($row = $universityResult->fetch_assoc()) {
    $universities[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .profile-page {
            max-width: 1000px;
            margin: 0 auto;
        }

        .profile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin: 25px 0;
        }

        .profile-header h1 {
            margin-bottom: 5px;
        }

        .profile-card {
            background: white;
            border: 1px solid #dce3e8;
            border-radius: 12px;
            padding: 22px;
            margin: 18px 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .profile-card h2 {
            margin-top: 0;
            border-bottom: 1px solid #e5e9ed;
            padding-bottom: 10px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 22px;
        }

        .profile-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .profile-field label {
            font-weight: bold;
        }

        .profile-field input,
        .profile-field select,
        .profile-field textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border: 1px solid #cbd3da;
            border-radius: 6px;
            font: inherit;
        }

        .profile-field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .profile-value {
            margin: 0;
            color: #34495e;
        }

        .profile-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .notice {
            padding: 12px 16px;
            border-radius: 6px;
            margin: 18px 0;
        }

        .notice.success {
            background: #eaf7ee;
            border-left: 5px solid #2e9d55;
        }

        .notice.error {
            background: #fdecec;
            border-left: 5px solid #c0392b;
        }

        .muted {
            color: #687782;
        }

        @media (max-width: 650px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>
<div class="page profile-page">
    <nav class="tabs">
        <a href="index.php" class="tab">Home</a>
        <a href="housing.php" class="tab">Housing</a>
        <a href="roommates.php" class="tab">Roommates</a>
        <a href="profile.php" class="tab active">Profile</a>
    </nav>

    <div class="profile-header">
        <div>
            <h1>My Profile</h1>
            <p class="muted">View and update your student information and roommate preferences.</p>
        </div>

        <!-- Temporary selector until the login system is connected. -->
        <?php if (empty($_SESSION["user_id"])): ?>
            <form method="GET" action="profile.php">
                <label for="student">Viewing As:</label>
                <select name="student" id="student" onchange="this.form.submit()">
                    <?php foreach ($students as $id => $name): ?>
                        <option value="<?php echo (int)$id; ?>"
                            <?php echo ($id === $studentId) ? "selected" : ""; ?>>
                            <?php echo e($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($message !== ""): ?>
        <div class="notice <?php echo e($messageType); ?>">
            <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <!-- PROFILE OVERVIEW -->
    <section class="profile-card">
        <h2>Profile Overview</h2>
        <h3><?php echo e($profile["first_name"] . " " . $profile["last_name"]); ?></h3>
        <p><strong>University:</strong> <?php echo e($profile["university"] ?: "Not set"); ?></p>
        <p><strong>Year:</strong> <?php echo e($profile["year"] ?: "Not set"); ?></p>
        <p><strong>Major:</strong> <?php echo e($profile["major"] ?: "Not set"); ?></p>
        <p><strong>Gender:</strong> <?php echo e($profile["gender"] ?: "Not set"); ?></p>
        <p><strong>About Me:</strong></p>
        <p><?php echo nl2br(e($profile["bio"] ?: "You have not added a bio yet.")); ?></p>
    </section>

    <!-- EDIT PROFILE -->
    <form method="POST" action="profile.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="student_id" value="<?php echo $studentId; ?>">

        <section class="profile-card">
            <h2>Edit Personal Information</h2>
            <div class="profile-grid">
                <div class="profile-field">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name"
                           value="<?php echo e($profile["first_name"]); ?>" required>
                </div>

                <div class="profile-field">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name"
                           value="<?php echo e($profile["last_name"]); ?>" required>
                </div>

                <div class="profile-field">
                    <label for="university_id">University</label>
                    <select id="university_id" name="university_id">
                        <option value="">Select a university</option>
                        <?php foreach ($universities as $university): ?>
                            <option value="<?php echo (int)$university["university_id"]; ?>"
                                <?php echo ((string)$profile["university_id"] === (string)$university["university_id"]) ? "selected" : ""; ?>>
                                <?php echo e($university["name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="profile-field">
                    <label for="year">Year in School</label>
                    <select id="year" name="year">
                        <option value="">Select year</option>
                        <?php foreach (["Freshman", "Sophomore", "Junior", "Senior", "Graduate", "Other"] as $option): ?>
                            <option value="<?php echo e($option); ?>"
                                <?php echo ($profile["year"] === $option) ? "selected" : ""; ?>>
                                <?php echo e($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="profile-field">
                    <label for="major">Major</label>
                    <input type="text" id="major" name="major"
                           value="<?php echo e($profile["major"]); ?>">
                </div>

                <div class="profile-field">
                    <label for="gender">Gender</label>
                    <input type="text" id="gender" name="gender"
                           value="<?php echo e($profile["gender"]); ?>">
                </div>

                <div class="profile-field full-width">
                    <label for="bio">About Me</label>
                    <textarea id="bio" name="bio"
                              placeholder="Write a little about yourself..."><?php echo e($profile["bio"]); ?></textarea>
                </div>
            </div>
        </section>

        <section class="profile-card">
            <h2>Roommate Preferences</h2>
            <p class="muted">These preferences are used to calculate roommate match scores.</p>

            <div class="profile-grid">
                <div class="profile-field">
                    <label for="budget">Monthly Budget ($)</label>
                    <input type="number" min="0" step="0.01" id="budget" name="budget"
                           value="<?php echo e($profile["budget"]); ?>">
                </div>

                <div class="profile-field">
                    <label for="housing_type">Preferred Housing Type</label>
                    <input type="text" id="housing_type" name="housing_type"
                           value="<?php echo e($profile["housing_type"]); ?>"
                           placeholder="Apartment, house, dorm, etc.">
                </div>

                <div class="profile-field">
                    <label for="bedtime">Bedtime</label>
                    <input type="time" id="bedtime" name="bedtime"
                           value="<?php echo e($profile["bedtime"]); ?>">
                </div>

                <div class="profile-field">
                    <label for="wake_time">Wake-up Time</label>
                    <input type="time" id="wake_time" name="wake_time"
                           value="<?php echo e($profile["wake_time"]); ?>">
                </div>

                <div class="profile-field">
                    <label for="cleanliness">Cleanliness (1-5)</label>
                    <select id="cleanliness" name="cleanliness">
                        <option value="">Select</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>"
                                <?php echo ((string)$profile["cleanliness"] === (string)$i) ? "selected" : ""; ?>>
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="profile-field">
                    <label for="noise">Noise Level (1-5)</label>
                    <select id="noise" name="noise">
                        <option value="">Select</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>"
                                <?php echo ((string)$profile["noise"] === (string)$i) ? "selected" : ""; ?>>
                                <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="profile-field">
                    <label for="smoking">Smoking</label>
                    <select id="smoking" name="smoking">
                        <option value="">Select</option>
                        <?php foreach (["No", "Yes", "Outside only", "Prefer not to say"] as $option): ?>
                            <option value="<?php echo e($option); ?>"
                                <?php echo ($profile["smoking"] === $option) ? "selected" : ""; ?>>
                                <?php echo e($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="profile-field">
                    <label for="guests">How Often Do You Have Guests?</label>
                    <select id="guests" name="guests">
                        <option value="">Select</option>
                        <?php foreach (["Rarely", "Sometimes", "Often", "Very often"] as $option): ?>
                            <option value="<?php echo e($option); ?>"
                                <?php echo ($profile["guests"] === $option) ? "selected" : ""; ?>>
                                <?php echo e($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="profile-field full-width">
                    <label for="study_location">Preferred Study Location</label>
                    <input type="text" id="study_location" name="study_location"
                           value="<?php echo e($profile["study_location"]); ?>"
                           placeholder="Library, home, coffee shop, etc.">
                </div>
            </div>

            <div class="profile-actions">
                <button type="submit">Save Changes</button>
                <button type="reset" class="secondary">Reset Form</button>
            </div>
        </section>
    </form>
</div>
</body>
</html>
<?php
$conn->close();
?>
