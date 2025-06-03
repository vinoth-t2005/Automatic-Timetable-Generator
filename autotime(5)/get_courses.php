<?php
// Database connection
$servername = "localhost";
$username = "root"; // Replace with your DB username
$password = ""; // Replace with your DB password
$dbname = "autotime2"; // Your DB name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get courses by department
if (isset($_GET['dept'])) {
    $dept = $_GET['dept'];
    
    $sql = "SELECT * FROM courses WHERE dept = ? ORDER BY credits DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dept);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $courses = array();
    
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    
    // Return courses as JSON
    header('Content-Type: application/json');
    echo json_encode($courses);
}

$conn->close();
?>