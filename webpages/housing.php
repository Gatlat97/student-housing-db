<?php
    $servername = "localhost";
    $username = "root";
    $password = "NewPassword123!";
    $dbname = "student_housing";

    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $conn->set_charset("utf8mb4");

    // Available attributes users can search
    $attributes = [
        "title" => "Listing Title",
        "address" => "Address",
        "city" => "City",
        "state" => "State",
        "price" => "Monthly Rent",
        "bedrooms" => "Bedrooms",
        "bathrooms" => "Bathrooms",
        "sqft" => "Square Feet",
        "cats_allowed" => "Cats Allowed",
        "dogs_allowed" => "Dogs Allowed"
    ];

    // Get search information
    $attribute = $_GET['attribute'] ?? 'address';
    $keyword = trim($_GET['keyword'] ?? '');

    // Make sure the selected attribute is valid
    if (!array_key_exists($attribute, $attributes)) {
        $attribute = 'address';
    }

    // Search the selected attribute
    $sql = "SELECT * FROM listing
            WHERE CAST(`$attribute` AS CHAR) LIKE ?
            ORDER BY listing_id DESC";

    $stmt = $conn->prepare($sql);

    $search = "%" . $keyword . "%";
    $stmt->bind_param("s", $search);

    $stmt->execute();
    $result = $stmt->get_result();
?>


<!DOCTYPE html> <html lang="en">
<head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Housing</title>
    <link rel="stylesheet" href="style.css"> </head>

<body>
    <div class="page">
        <nav class="tabs">
            <a href="index.php" class="tab"> Home </a>
            <a href="housing.php" class="tab active">Housing </a>
            <a href="roommates.php" class="tab"> Roommates </a>
            <a href="profile.php" class="tab"> Profile </a>
        </nav>

        <h1>Housing</h1>
        
        <form method="GET" action="">

    <label for="attribute">Search By:</label>

    <select name="attribute" id="attribute">
        <?php foreach ($attributes as $column => $label): ?>
            <option value="<?php echo htmlspecialchars($column); ?>"
                <?php echo ($attribute === $column) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($label); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <input type="text" name="keyword" placeholder="Enter search value" value="<?php echo htmlspecialchars($keyword); ?>">
    <button type="submit">Search</button>
    <button type="button" onclick="window.location.href='housing.php'">Clear Search</button>

    </form>
        <!-- Housing Listings -->
        <div class="housing-list">
            <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {

                        echo "<div class='housing-option'>";

                        echo "<strong>Address:</strong> " .
                            htmlspecialchars($row["address"] ?? "Address unavailable") . " &nbsp;&nbsp; ";

                        echo "<strong>Location:</strong> " .
                            htmlspecialchars($row["city"] ?? "City unavailable") . ", " .
                            htmlspecialchars($row["state"] ?? "State unavailable") . " &nbsp;&nbsp; ";

                        echo "<strong>Monthly Rent:</strong> $" .
                            htmlspecialchars((string)($row["price"] ?? "N/A")) . " &nbsp;&nbsp; ";

                        echo "<strong>Bedrooms:</strong> " .
                            htmlspecialchars((string)($row["bedrooms"] ?? "N/A")) . " &nbsp;&nbsp; ";

                        echo "<strong>Bathrooms:</strong> " .
                            htmlspecialchars((string)($row["bathrooms"] ?? "N/A")) . " &nbsp;&nbsp; ";

                        echo "<strong>Square Feet:</strong> " .
                            htmlspecialchars((string)($row["sqft"] ?? "N/A"));

                        echo "<strong>Cats Allowed:</strong> " .
                            (($row["cats_allowed"] == 1) ? "Yes" : "No") . " &nbsp;&nbsp; ";
                            
                        echo "<strong>Dogs Allowed:</strong> " .
                            (($row["dogs_allowed"] == 1) ? "Yes" : "No") . " &nbsp;&nbsp; ";
    

                        echo "</div>";

                        // Breakpoint between listings
                        echo "<hr>";
                    }
                } else {
                    echo "<p>No housing options are currently available.</p>";
                }
            ?>
        </div>
        <!-- Buttons -->
        <!-- <div class="button-container">
            <button type="button" onclick="showAddForm()">Add Housing</button>
            <button type="button" onclick="showRemoveForm()">Remove a Listing</button>
        </div> -->

        <!-- Add Housing Form -->
        <!-- <div id="addHousing" style="display: none;">
            <h2 class="form-title">Add Housing</h2>
            <p class=form-title>Please fill out the form below to add a new housing listing.</p>
            <form class="form" action="add_housing.php" method="POST" enctype="multipart/form-data" >
                <input type="text" name="address" placeholder="Address" required >
                <input type="number" name="rent" placeholder="Monthly Rent" required >
                <input type="number" name="bedrooms" placeholder="Number of Bedrooms" required >
                <button type="submit"> Add Housing </button>
            </form>

        </div> -->

        <!-- Remove Housing Form -->
        <!-- <div id="removeHousing" style="display: none;">
            <h2 class="form-title">Remove a Listing</h2>
            <p class=form-title>Please enter the address of the housing listing you wish to remove.</p>
            <form class="form" action="remove.php" method="POST">
                <input type="text" name="address" placeholder="Address" required>
                <button type="submit">Remove Listing</button>
            </form>
        </div> -->
    </div>
    <!-- <script>

    function showAddForm() {
        document.getElementById("addHousing").style.display = "block";
        document.getElementById("removeHousing").style.display = "none";
    }

    function showRemoveForm() {
        document.getElementById("removeHousing").style.display = "block";
        document.getElementById("addHousing").style.display = "none";
    }
    </script> -->
</body>
</html>
<?php
    $conn->close();
?>