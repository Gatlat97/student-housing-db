<?php
    $servername = "localhost"; $username = "root"; $password = "Hunter52105"; $dbname = "final_project";
    $conn = new mysqli( $servername, $username, $password, $dbname );
    if ($conn->error) { 
        die("Connection failed: " . $conn->error); 
    }
    $sql = "SELECT * FROM housing"; $result = $conn->query($sql);
?>

<!DOCTYPE html> <html lang="en">
<head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Housing</title>
    <link rel="stylesheet" href="style.css"> </head>

<body>
    <div class="page">
        <nav class="tabs">
            <a href="index.php" class="tab"> Home </a>
            <a href="housing.php" class="tab active"> Find Housing </a>
            <a href="roommates.php" class="tab"> Roommates </a>
            <a href="profile.php" class="tab"> Profile </a>
        </nav>

        <h1>Find Housing</h1>
        <p> This is the housing page for the student housing application.<br> Are you looking for a potential place to live? Or are you a landlord looking to post your property? Either way, you've come to the right place! </p>

        

        <h2> Browse all available housing options below. </h2>

        <!-- Housing Listings -->
        <div class="housing-list">

            <?php

                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<div class='housing-option'>";
                        echo "<p>";
                        echo "<strong>Address:</strong> " . htmlspecialchars($row["address"]) . " &nbsp;&nbsp; ";
                        echo "<strong>Monthly Rent:</strong> $" . htmlspecialchars($row["rent"]) . " &nbsp;&nbsp; ";
                        echo "<strong>Bedrooms:</strong> " . htmlspecialchars($row["bedrooms"]);
                        echo "</p>";
                        echo "</div>";
                    }
                } else {
                    echo "<p>No housing options are currently available.</p>";
                }
            ?>
        </div>
        <!-- Buttons -->
        <div class="button-container">
            <button type="button" onclick="showAddForm()">
                Add Housing
            </button>

            <button type="button" onclick="showRemoveForm()">
                Remove a Listing
            </button>
        </div>

        <!-- Add Housing Form -->
        <div id="addHousing" style="display: none;">
            <h2 class="form-title">Add Housing</h2>
            <p class=form-title>Please fill out the form below to add a new housing listing.</p>
            <form class="form" action="add_housing.php" method="POST" enctype="multipart/form-data" >
                <input type="text" name="address" placeholder="Address" required >
                <input type="number" name="rent" placeholder="Monthly Rent" required >
                <input type="number" name="bedrooms" placeholder="Number of Bedrooms" required >
                <button type="submit"> Add Housing </button>
            </form>

        </div>

        <!-- Remove Housing Form -->
        <div id="removeHousing" style="display: none;">
            <h2 class="form-title">Remove a Listing</h2>
            <p class=form-title>Please enter the address of the housing listing you wish to remove.</p>
            <form class="form" action="remove.php" method="POST">
                <input type="text" name="address" placeholder="Address" required>
                <button type="submit">Remove Listing</button>
            </form>
        </div>
    </div>
    <script>

    function showAddForm() {
        document.getElementById("addHousing").style.display = "block";
        document.getElementById("removeHousing").style.display = "none";
    }

    function showRemoveForm() {
        document.getElementById("removeHousing").style.display = "block";
        document.getElementById("addHousing").style.display = "none";
    }
    </script>
</body>
</html>
<?php
    $conn->close();
?>