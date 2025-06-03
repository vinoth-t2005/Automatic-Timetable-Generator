<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Connection Test</h1>";

// Try multiple connection parameters to find what works
$connectionAttempts = [
    [
        'host' => 'localhost',
        'user' => 'root',
        'password' => '',
        'database' => 'autotime2'
    ],
    [
        'host' => '127.0.0.1',
        'user' => 'root',
        'password' => '',
        'database' => 'autotime2'
    ],
    [
        'host' => 'localhost',
        'user' => 'autotime',
        'password' => 'autotime2',
        'database' => 'autotime2'
    ],
    [
        'host' => 'db', // Docker container name
        'user' => 'root',
        'password' => '',
        'database' => 'autotime2'
    ]
];

$successful = false;
$successfulConfig = null;

foreach ($connectionAttempts as $index => $config) {
    echo "<h2>Attempt " . ($index + 1) . ":</h2>";
    echo "<pre>";
    print_r($config);
    echo "</pre>";
    
    try {
        $conn = new mysqli(
            $config['host'],
            $config['user'],
            $config['password'],
            $config['database']
        );
        
        if ($conn->connect_error) {
            echo "<p style='color:red'>Connection failed: " . $conn->connect_error . "</p>";
        } else {
            echo "<p style='color:green'>Connection successful!</p>";
            $successful = true;
            $successfulConfig = $config;
            
            // Try to get some tables
            $result = $conn->query("SHOW TABLES");
            if ($result) {
                echo "<p>Tables in database:</p>";
                echo "<ul>";
                while ($row = $result->fetch_array()) {
                    echo "<li>" . $row[0] . "</li>";
                }
                echo "</ul>";
                
                // Try to check for timetable_slots in particular
                $tablesToCheck = ['timetable_slots', 'templates', 'courses', 'staff', 'staff_availability'];
                foreach ($tablesToCheck as $table) {
                    $checkResult = $conn->query("SHOW TABLES LIKE '$table'");
                    if ($checkResult->num_rows > 0) {
                        echo "<p style='color:green'>Table '$table' exists.</p>";
                        
                        // Check row count
                        $countResult = $conn->query("SELECT COUNT(*) as count FROM $table");
                        $countRow = $countResult->fetch_assoc();
                        echo "<p>Rows in $table: " . $countRow['count'] . "</p>";
                        
                        // Show some sample data
                        if ($countRow['count'] > 0 && $countRow['count'] < 100) {
                            $sampleResult = $conn->query("SELECT * FROM $table LIMIT 5");
                            echo "<table border='1'>";
                            // Headers
                            echo "<tr>";
                            while ($fieldInfo = $sampleResult->fetch_field()) {
                                echo "<th>" . $fieldInfo->name . "</th>";
                            }
                            echo "</tr>";
                            
                            // Data
                            while($row = $sampleResult->fetch_assoc()) {
                                echo "<tr>";
                                foreach($row as $value) {
                                    echo "<td>" . ($value === null ? "NULL" : htmlspecialchars($value)) . "</td>";
                                }
                                echo "</tr>";
                            }
                            echo "</table>";
                        }
                    } else {
                        echo "<p style='color:red'>Table '$table' does not exist!</p>";
                    }
                }
            } else {
                echo "<p style='color:red'>Failed to query tables: " . $conn->error . "</p>";
            }
            
            $conn->close();
            break;
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>Exception: " . $e->getMessage() . "</p>";
    }
}

if ($successful) {
    echo "<h2>Suggested Database Configuration:</h2>";
    echo "<pre>";
    echo "host: '" . $successfulConfig['host'] . "',\n";
    echo "user: '" . $successfulConfig['user'] . "',\n";
    echo "password: '" . $successfulConfig['password'] . "',\n";
    echo "database: '" . $successfulConfig['database'] . "',\n";
    echo "</pre>";
} else {
    echo "<h2>No successful connection found</h2>";
    echo "<p>Please check your database setup and credentials.</p>";
}
?>

<p><a href="next_44.php">Return to Timetable Generator</a></p>