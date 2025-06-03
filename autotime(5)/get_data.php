<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "autotime2";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set content type to JSON
header('Content-Type: application/json');

// Get request type
$type = $_GET['type'] ?? '';

switch ($type) {
    case 'sections':
        $dept = $_GET['dept'] ?? '';
        
        if (empty($dept)) {
            echo json_encode([]);
            exit;
        }
        
        $query = "SELECT DISTINCT id FROM classes WHERE dept = '$dept'";
        $result = $conn->query($query);
        
        $sections = [];
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                // Assuming id is formatted as section-year-semester-batch
                $parts = explode('-', $row['id']);
                if (isset($parts[0])) {
                    $sections[] = $parts[0];
                }
            }
        }
        
        echo json_encode(array_unique($sections));
        break;
        
    case 'years':
        $dept = $_GET['dept'] ?? '';
        $section = $_GET['section'] ?? '';
        
        if (empty($dept) || empty($section)) {
            echo json_encode([]);
            exit;
        }
        
        $query = "SELECT year FROM classes WHERE dept = '$dept' AND id LIKE '$section-%'";
        $result = $conn->query($query);
        
        $years = [];
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $years[] = $row['year'];
            }
        }
        
        echo json_encode(array_unique($years));
        break;
        
    case 'semesters':
        $dept = $_GET['dept'] ?? '';
        $section = $_GET['section'] ?? '';
        $year = $_GET['year'] ?? '';
        
        if (empty($dept) || empty($section) || empty($year)) {
            echo json_encode([]);
            exit;
        }
        
        $query = "SELECT semester FROM classes WHERE dept = '$dept' AND id LIKE '$section-%' AND year = '$year'";
        $result = $conn->query($query);
        
        $semesters = [];
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $semesters[] = $row['semester'];
            }
        }
        
        echo json_encode(array_unique($semesters));
        break;
        
    case 'batches':
        $dept = $_GET['dept'] ?? '';
        $section = $_GET['section'] ?? '';
        $year = $_GET['year'] ?? '';
        $semester = $_GET['semester'] ?? '';
        
        if (empty($dept) || empty($section) || empty($year) || empty($semester)) {
            echo json_encode([]);
            exit;
        }
        
        $query = "SELECT batch_start, batch_end FROM classes WHERE dept = '$dept' AND id LIKE '$section-%' AND year = '$year' AND semester = '$semester'";
        $result = $conn->query($query);
        
        $batches = [];
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $batches[] = [
                    'start' => $row['batch_start'],
                    'end' => $row['batch_end']
                ];
            }
        }
        
        echo json_encode($batches);
        break;
        
    default:
        echo json_encode([]);
}

$conn->close();
?>