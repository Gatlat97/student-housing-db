<?php
    $servername = "localhost"; $username = "root"; $password = "Hunter52105"; $dbname = "final_project";
    $conn = new mysqli( $servername, $username, $password, $dbname );
    if ($conn->connect_error) { 
        die("Connection failed: " . $conn->error); 
    }

    // Get address from the form 
    $address = $_POST["address"];

    // Find the listing before deleting it 
    $find = $conn->prepare( "SELECT * FROM housing WHERE address = ?" );
    $find->bind_param("s", $address); $find->execute();
    $result = $find->get_result();
    $success = false; $message = "";
    if ($result->num_rows > 0) {

        // Get all information about the listing 
        $listing = $result->fetch_assoc();

        $listingAddress = $listing["address"]; $listingRent = $listing["rent"]; $listingBedrooms = $listing["bedrooms"];

        // Delete the listing 
        $stmt = $conn->prepare( "DELETE FROM housing WHERE address = ?" );
        $stmt->bind_param("s", $address);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success = true;
                $message = "<h2>Listing Removed Successfully!</h2>";
                $message .= "<p>";
                $message .= "<strong>Address:</strong> " . htmlspecialchars($listingAddress) . "<br>";
                $message .= "<strong>Monthly Rent:</strong> $" . htmlspecialchars($listingRent) . "<br>";
                $message .= "<strong>Bedrooms:</strong> " . htmlspecialchars($listingBedrooms);
                $message .= "</p>";
            }
            $stmt->close();
        } else {
            $message = "<h2>Error Removing Listing</h2>";
            $message .= "<p>" . htmlspecialchars($stmt->error) . "</p>";
            $stmt->close(); }
    } else {
        $message = "<h2>Listing Not Found</h2>";
        $message .= "<p>No housing listing was found at:</p>";
        $message .= "<p><strong>Address:  " . htmlspecialchars($address) . "</strong></p>"; }
        $find->close(); $conn->close();
?>

<!DOCTYPE html> <html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remove Housing</title>
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