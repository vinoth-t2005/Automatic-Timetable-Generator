<?php
// Database connection settings
$host = "localhost";
$user = "root";
$password = "";
$database = "autotime2";

// Create connection to MySQL server
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle template preview request
if (isset($_GET['preview_id'])) {
    $preview_id = $_GET['preview_id'];
    $preview_stmt = $conn->prepare("SELECT * FROM templates WHERE id = ?");
    $preview_stmt->bind_param("i", $preview_id);
    $preview_stmt->execute();
    $template = $preview_stmt->get_result()->fetch_assoc();
    $preview_stmt->close();
    
    // Return template data as JSON
    header('Content-Type: application/json');
    echo json_encode($template);
}

$conn->close();
?>