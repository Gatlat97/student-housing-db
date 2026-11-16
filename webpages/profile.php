<?php
session_start();

/* DATABASE CONNECTION */
$servername = "localhost";
$username = "root";
$password = "NewPassword123!";
$dbname = "student_housing";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* CREATE STUDENTS TABLE */


/* HANDLE LOGOUT */
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: profile.php");
    exit();
}
/* HANDLE LOGIN / ACCOUNT CREATION */
$loginError = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_id = trim($_POST["student_id"]);
    $password = trim($_POST["password"]);
    // Basic validation
    if ($student_id == "" || $password == "") {
        $loginError = "Please enter a student ID and password.";
    } else {
        /*
         * Check if the student already exists.
         */
        $stmt = $conn->prepare(
            "SELECT id, name, student_id, password
             FROM students
             WHERE student_id = ?"
        );
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();

        /* ACCOUNT EXISTS */
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            // Check password
            if (password_verify($password, $student["password"])) {
                // Login successful
                session_regenerate_id(true);
                $_SESSION["logged_in"] = true;
                $_SESSION["user_type"] = "student";
                $_SESSION["user_id"] = $student["id"];
                $_SESSION["name"] = $student["name"];
                $_SESSION["student_id"] = $student["student_id"];

                header("Location: profile.php");
                exit();
            } else {
                $loginError = "Incorrect password.";
            }
        /* ACCOUNT DOES NOT EXIST */
        } else {
            /*
             * Since the account doesn't exist,
             * create a new student account.
             *
             * The name is created from the student ID
             * for now. The student can change their
             * name later on the profile page.
             */
            $name = "Student " . $student_id;

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $insert = $conn->prepare(
                "INSERT INTO students (name, student_id, password)
                 VALUES (?, ?, ?)"
            );

            $insert->bind_param(
                "sss",
                $name,
                $student_id,
                $hashedPassword
            );

            if ($insert->execute()) {
                $newUserId = $insert->insert_id;

                // Log the new student in
                session_regenerate_id(true);
                $_SESSION["logged_in"] = true;
                $_SESSION["user_type"] = "student";
                $_SESSION["user_id"] = $newUserId;
                $_SESSION["name"] = $name;
                $_SESSION["student_id"] = $student_id;

                // Go to profile
                header("Location: profile.php");
                exit();

            } else {
                $loginError = "There was a problem creating your account.";
            }
            $insert->close();
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Profile</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="page">
            <nav class="tabs">
                <a href="index.php" class="tab">Home</a>
                <a href="housing.php" class="tab">Housing</a>
                <a href="roommates.php" class="tab">Roommates</a>
                <a href="profile.php" class="tab active">Profile</a>
            </nav>

            <?php if (isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true): ?>
                <!-- Logged-in student -->
                <h1>My Profile</h1>
                <h2>
                    Welcome,
                    <?php echo htmlspecialchars($_SESSION["name"]); ?>!
                </h2>

                <p>
                    <strong>Student ID:</strong>
                    <?php
                        echo htmlspecialchars($_SESSION["student_id"]);
                    ?>
                </p>
                <p>
                    <strong>Account Type:</strong>
                    Student
                </p>
                <hr>
                <h2>My Housing</h2>

                <p>Your housing information will appear here.</p>
                <h2>My Roommates</h2>
                <p>Your roommates will appear here.</p>
                <br>
                <a href="profile.php?logout=true" class="button">Logout</a>
            <?php else: ?>
                <!-- Login / Create Account -->
                <h1>Student Login</h1>
                <p>
                    Enter your email and password to log in.
                    If you don't have an account, one will
                    automatically be created for you.
                </p>

                <?php if ($loginError != ""): ?>
                    <p class="error">
                        <?php
                        echo htmlspecialchars($loginError);
                        ?>
                    </p>
                <?php endif; ?>

                <div class="button-container">
                    <button type="button" onclick="loginForm()">Login</button>
                    <button type="button" onclick="registerForm()">Create Account</button>
                </div>

                <div id="loginForm" style="display:none;">
                    <form method="POST"action="profile.php">
                        <label for="student_id">Student ID:</label>
                        <input type="text" id="student_id" name="student_id" required>
                        <br>
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required>
                        <br><br>
                        <button type="submit">Login</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <script>
            function loginForm() {
                var form = document.getElementById("loginForm");
                if (form.style.display === "none") {
                    form.style.display = "block";
                } else {
                    form.style.display = "none";
                }
            }
            function registerForm() {
                var form = document.getElementById("registerForm");
                if (form.style.display === "none") {
                    form.style.display = "block";
                } else {
                    form.style.display = "none";
                }
            }
        </script>
    </body>
</html>