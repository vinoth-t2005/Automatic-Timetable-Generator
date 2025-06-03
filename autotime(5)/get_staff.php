<?php
// Database Connection
$conn = new mysqli("localhost", "root", "", "autotime2");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if department is set
if (isset($_POST['department'])) {
    $dept = $conn->real_escape_string($_POST['department']);
    
    // Fetch staff members based on the selected department
    $query = "SELECT name FROM staff WHERE dept = '$dept'";
    $result = $conn->query($query);
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "<option value='" . $row['name'] . "'>" . $row['name'] . "</option>";
        }
    } else {
        echo "<option value=''>No staff available</option>";
    }
} else {
    echo "<option value=''>Department not specified</option>";
}

// Close the connection
$conn->close();
?>