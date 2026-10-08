<?php
    $servername = "localhost"; $username = "root"; $password = "Hunter52105"; $dbname = "final_project";
    $conn = new mysqli( $servername, $username, $password, $dbname );
    if ($conn->error) { 
        die("Connection failed: " . $conn->error); 
    }

    // Get values from the form 
    $address = $_POST["address"]; $rent = $_POST["rent"]; $bedrooms = $_POST["bedrooms"];
    $message = "";

    // Check if the listing already exists 
    $check = $conn->prepare( "SELECT id FROM housing WHERE address = ? AND rent = ? AND bedrooms = ?" );
    $check->bind_param( "sii", $address, $rent, $bedrooms );
    $check->execute();
    $result = $check->get_result();
    if ($result->num_rows > 0) {
        // Listing already exists 
        $message .= "<h2>Listing Already Exists</h2>";
        $message .= "<p>This housing listing has already been added.</p>";
        $message .= "<p>";
        $message .= "<strong>Address:</strong> " . htmlspecialchars($address) . " &nbsp;&nbsp; ";
        $message .= "<strong>Monthly Rent:</strong> $" . htmlspecialchars($rent) . " &nbsp;&nbsp; ";
        $message .= "<strong>Bedrooms:</strong> " . htmlspecialchars($bedrooms);
        $message .= "</p>";
    } else {
        // Add the new listing 
        $stmt = $conn->prepare( "INSERT INTO housing (address, rent, bedrooms) VALUES (?, ?, ?)" );
        $stmt->bind_param( "sii", $address, $rent, $bedrooms );
        if ($stmt->execute()) {
            $message .= "<h2>Housing Added Successfully!</h2>";
            $message .= "<p>";
            $message .= "<strong>Address:</strong> " . htmlspecialchars($address) . " &nbsp;&nbsp; ";
            $message .= "<strong>Monthly Rent:</strong> $" . htmlspecialchars($rent) . " &nbsp;&nbsp; ";
            $message .= "<strong>Bedrooms:</strong> " . htmlspecialchars($bedrooms);
            $message .= "</p>";
        } else {
            $message .= "<h2>Error Adding Housing</h2>";
            $message .= "<p>" . htmlspecialchars($stmt->error) . "</p>"; 
        }
        $stmt->close(); 
    }
    $check->close(); $conn->close();
?>

<!DOCTYPE html> <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Add Housing</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="page">
            <nav class="tabs">
                <a href="index.php" class="tab"> Home </a>
                <a href="housing.php" class="tab active"> Find Housing </a>
                <a href="roommates.php" class="tab"> Roommates </a>
                <a href="profile.php" class="tab"> Profile </a>
            </nav>
            <div class="housing-option">
                <?php echo $message; ?>
            </div>
            <div class="button-container">
            <button type="button" onclick="window.location.href='housing.php'">Return to Housing</button>
            </div>
        </div>
    </body>
</html>