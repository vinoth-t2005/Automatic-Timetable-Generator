<?php
// Database Connection
$conn = new mysqli("localhost", "root", "", "autotime2");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_POST["department"])) {
    $department = $conn->real_escape_string($_POST["department"]);
    $query = "SELECT * FROM staff WHERE dept = '$department'";
    $result = $conn->query($query);
    
    $output = "";
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $output .= "<option value='".htmlspecialchars($row["name"])."'>".htmlspecialchars($row["name"])."</option>";
        }
    } else {
        $output = "<option value=''>No staff found in this department</option>";
    }
    echo $output;
}
?>