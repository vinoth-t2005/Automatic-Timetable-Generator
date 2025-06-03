<?php
// Database connection
$conn = mysqli_connect("localhost", "root", "", "autotime2");

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Get department ID from query parameter
$dept_id = isset($_GET['dept']) ? intval($_GET['dept']) : 0;

if ($dept_id > 0) {
    // Query to get lab courses for the specified department
    // Assuming courses with lab components have "Lab" in their name or a specific flag
    // Adjust this query based on how you identify lab courses in your database
    $query = "SELECT id, name, course_code FROM courses 
              WHERE dept = ? AND (name LIKE '%Lab%' OR name LIKE '%Practical%') 
              ORDER BY name";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $dept_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    // Generate options for select element
    echo '<option value="">Select Lab Course</option>';
    while ($row = mysqli_fetch_assoc($result)) {
        echo '<option value="' . $row['id'] . '">' . $row['name'] . ' (' . $row['course_code'] . ')</option>';
    }
} else {
    echo '<option value="">Select Lab Course</option>';
}

mysqli_close($conn);
?>