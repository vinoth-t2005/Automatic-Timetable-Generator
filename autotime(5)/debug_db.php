<?php
// Include database configuration
include 'db_config.php';

// Get database connection
$db = DatabaseConfig::getInstance();
$conn = $db->getConnection();

echo "<h2>Database Connection Test</h2>";
if ($conn) {
    echo "<p>✅ Database connection successful!</p>";
} else {
    echo "<p>❌ Database connection failed!</p>";
    exit;
}

// Check MySQL version
$version_result = $conn->query("SELECT VERSION() as version");
$version_data = $version_result->fetch_assoc();
echo "<p>MySQL Version: " . $version_data['version'] . "</p>";

// Create test table if not exists
echo "<h2>Table Structure Test</h2>";
try {
    // Create test table
    $conn->query("
        CREATE TABLE IF NOT EXISTS timetable_slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            timetable_id VARCHAR(255) NOT NULL,
            template_id INT NOT NULL,
            course_id INT NOT NULL,
            staff_id INT NOT NULL,
            day VARCHAR(20) NOT NULL,
            period VARCHAR(10) NOT NULL,
            is_lab BOOLEAN DEFAULT FALSE,
            confirmed BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (timetable_id),
            INDEX (template_id),
            INDEX (staff_id)
        )
    ");
    
    echo "<p>✅ Table structure created or already exists</p>";
    
    // Check for constraints
    $constraints = $conn->query("SHOW CREATE TABLE timetable_slots");
    $row = $constraints->fetch_assoc();
    $create_statement = $row['Create Table'];
    
    echo "<pre>" . htmlspecialchars($create_statement) . "</pre>";
    
    // Check if unique constraint exists
    if (strpos($create_statement, 'UNIQUE KEY') !== false) {
        echo "<p>✅ Unique constraint exists</p>";
    } else {
        echo "<p>❌ No unique constraint found</p>";
        
        // Try to add constraint
        try {
            $conn->query("
                ALTER TABLE timetable_slots 
                ADD UNIQUE KEY unique_slot (template_id, timetable_id, day, period)
            ");
            echo "<p>✅ Unique constraint added</p>";
        } catch (Exception $e) {
            echo "<p>❌ Error adding constraint: " . $e->getMessage() . "</p>";
        }
    }
    
    // Check for existing data
    $data_result = $conn->query("SELECT COUNT(*) as count FROM timetable_slots");
    $data_count = $data_result->fetch_assoc();
    echo "<p>Records in timetable_slots: " . $data_count['count'] . "</p>";
    
    if ($data_count['count'] > 0) {
        // Show sample data
        $sample = $conn->query("SELECT * FROM timetable_slots LIMIT 5");
        echo "<h3>Sample Data:</h3>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Timetable ID</th><th>Template ID</th><th>Course ID</th><th>Staff ID</th><th>Day</th><th>Period</th><th>Is Lab</th><th>Confirmed</th></tr>";
        
        while ($row = $sample->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['timetable_id'] . "</td>";
            echo "<td>" . $row['template_id'] . "</td>";
            echo "<td>" . $row['course_id'] . "</td>";
            echo "<td>" . $row['staff_id'] . "</td>";
            echo "<td>" . $row['day'] . "</td>";
            echo "<td>" . $row['period'] . "</td>";
            echo "<td>" . ($row['is_lab'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . ($row['confirmed'] ? 'Yes' : 'No') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}

// Test a transaction
echo "<h2>Transaction Test</h2>";
try {
    $conn->begin_transaction();
    $conn->query("INSERT INTO timetable_slots 
                 (timetable_id, template_id, course_id, staff_id, day, period, is_lab, confirmed)
                 VALUES ('test_timetable', 999, 999, 999, 'Monday', '1', 0, 1)");
    $conn->commit();
    echo "<p>✅ Transaction test successful</p>";
    
    // Clean up test data
    $conn->query("DELETE FROM timetable_slots WHERE timetable_id='test_timetable' AND template_id=999");
    echo "<p>✅ Test data cleaned up</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p>❌ Transaction test failed: " . $e->getMessage() . "</p>";
}

// Check PHP error log
echo "<h2>PHP Error Log</h2>";
$log_file = ini_get('error_log');
if (file_exists($log_file)) {
    $log_content = file_get_contents($log_file);
    $log_lines = explode("\n", $log_content);
    $recent_lines = array_slice($log_lines, -20); // Get last 20 lines
    
    echo "<pre>";
    foreach ($recent_lines as $line) {
        echo htmlspecialchars($line) . "\n";
    }
    echo "</pre>";
} else {
    echo "<p>Error log not found or not accessible</p>";
}
?>

<p><a href="next_44.php">Return to Timetable Generator</a></p>