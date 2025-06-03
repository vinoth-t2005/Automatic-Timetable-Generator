<?php
// Set content type to JSON
header('Content-Type: application/json');

// Prevent PHP from outputting errors/warnings that break JSON syntax
error_reporting(0);

// Database Connection
$conn = new mysqli("localhost", "root", "", "autotime2");
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

$staff = []; // Initialize staff array

// Scenario 1: Fetch staff based on the selected course
if (!empty($_POST['course_id'])) {
    $course_id = $_POST['course_id'];

    // Secure query with prepared statement
    $stmt = $conn->prepare("
        SELECT id, name 
        FROM staff 
        WHERE dept = (SELECT dept FROM courses WHERE id = ?)
    ");
    
    // Check if prepare was successful
    if ($stmt === false) {
        echo json_encode(['error' => 'Failed to prepare statement: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("i", $course_id);
    $execute_result = $stmt->execute();
    
    // Check if execution was successful
    if ($execute_result === false) {
        echo json_encode(['error' => 'Failed to execute statement: ' . $stmt->error]);
        exit;
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }

    echo json_encode($staff); // Return JSON response
    exit;
}

// Scenario 2: Fetch staff based on the selected department
if (!empty($_POST['department'])) {
    $dept = $_POST['department'];

    // Secure query with prepared statement
    $stmt = $conn->prepare("SELECT id, name FROM staff WHERE dept = ?");
    
    // Check if prepare was successful
    if ($stmt === false) {
        echo json_encode(['error' => 'Failed to prepare statement: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("s", $dept);
    $execute_result = $stmt->execute();
    
    // Check if execution was successful
    if ($execute_result === false) {
        echo json_encode(['error' => 'Failed to execute statement: ' . $stmt->error]);
        exit;
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }

    echo json_encode($staff); // Return JSON response
    exit;
}

// URL parameter for department (GET method)
if (isset($_GET['dept'])) {
    $dept = $_GET['dept'];
    
    // Secure query with prepared statement
    $stmt = $conn->prepare("SELECT id, name FROM staff WHERE dept = ?");
    
    // Check if prepare was successful
    if ($stmt === false) {
        echo json_encode(['error' => 'Failed to prepare statement: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("s", $dept);
    $execute_result = $stmt->execute();
    
    // Check if execution was successful
    if ($execute_result === false) {
        echo json_encode(['error' => 'Failed to execute statement: ' . $stmt->error]);
        exit;
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
    
    echo json_encode($staff); // Return JSON response
    exit;
}

// If we get here, no valid request parameter was provided
echo json_encode([]);

// Close statement and connection if it exists
if (isset($stmt)) {
    $stmt->close();
}
$conn->close();
?>
