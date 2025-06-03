<?php
// Repair the timetable_slots table - this is a one-time fix

// Database configuration
class DatabaseConfig {
    private static $instance = null;
    private $conn;

    private function __construct() {
        // Let's add extensive error logging for debugging the connection
        error_log("Attempting database connection in repair script");
        
        $config = [
            'host' => 'localhost',
            'user' => 'root', 
            'password' => '',
            'database' => 'autotime2',
            'charset' => 'utf8mb4'
        ];

        try {
            error_log("Connecting to database: " . json_encode($config));
            
            $this->conn = new mysqli(
                $config['host'], 
                $config['user'], 
                $config['password'], 
                $config['database']
            );
            
            error_log("Connection attempt result: " . ($this->conn->connect_error ? "Error: " . $this->conn->connect_error : "Success"));

            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }

            $this->conn->set_charset($config['charset']);
        } catch (Exception $e) {
            error_log($e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DatabaseConfig();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }
}

// Drop and recreate the timetable_slots table
function repairTimetableSlotsTable($conn) {
    echo "<h1>Timetable Slots Table Repair Utility</h1>";
    
    // First get information about the existing table
    echo "<h2>Current Table Status:</h2>";
    $tableCheckQuery = "SHOW TABLES LIKE 'timetable_slots'";
    $tableExists = $conn->query($tableCheckQuery)->num_rows > 0;
    
    if ($tableExists) {
        echo "<p>Table 'timetable_slots' exists. Checking structure...</p>";
        
        // Get column information
        $columnsQuery = "DESCRIBE timetable_slots";
        $result = $conn->query($columnsQuery);
        
        if ($result) {
            echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['Field'] . "</td>";
                echo "<td>" . $row['Type'] . "</td>";
                echo "<td>" . $row['Null'] . "</td>";
                echo "<td>" . $row['Key'] . "</td>";
                echo "<td>" . $row['Default'] . "</td>";
                echo "<td>" . $row['Extra'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Check for confirmed column
            $columnQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
            $columnExists = $conn->query($columnQuery)->num_rows > 0;
            
            if ($columnExists) {
                echo "<p>✅ The 'confirmed' column exists.</p>";
            } else {
                echo "<p>❌ The 'confirmed' column is missing.</p>";
            }
            
            // Check for unique constraint
            $indexQuery = "SHOW INDEX FROM timetable_slots WHERE Key_name = 'unique_slot'";
            $indexExists = $conn->query($indexQuery)->num_rows > 0;
            
            if ($indexExists) {
                echo "<p>✅ The unique constraint exists.</p>";
            } else {
                echo "<p>❌ The unique constraint is missing.</p>";
            }
        } else {
            echo "<p>Error getting column information: " . $conn->error . "</p>";
        }
    } else {
        echo "<p>Table 'timetable_slots' does not exist.</p>";
    }
    
    // Count existing records
    if ($tableExists) {
        $countQuery = "SELECT COUNT(*) as count FROM timetable_slots";
        $result = $conn->query($countQuery);
        if ($result) {
            $row = $result->fetch_assoc();
            echo "<p>Current record count: " . $row['count'] . "</p>";
        }
    }
    
    // Provide repair options
    echo "<h2>Repair Options:</h2>";
    echo "<form method='post'>";
    echo "<p><input type='submit' name='add_confirmed_column' value='Add missing confirmed column' /></p>";
    echo "<p><input type='submit' name='add_unique_constraint' value='Add missing unique constraint' /></p>";
    echo "<p><input type='submit' name='recreate_table' value='⚠️ Drop and recreate the entire table (will lose all data)' /></p>";
    echo "</form>";
    
    // Process repair options
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_confirmed_column'])) {
            // Add the confirmed column if it doesn't exist
            if ($tableExists) {
                $columnQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
                $columnExists = $conn->query($columnQuery)->num_rows > 0;
                
                if (!$columnExists) {
                    $alterQuery = "ALTER TABLE timetable_slots ADD COLUMN confirmed TINYINT(1) DEFAULT 0";
                    if ($conn->query($alterQuery)) {
                        echo "<p>✅ Added confirmed column successfully!</p>";
                    } else {
                        echo "<p>❌ Error adding confirmed column: " . $conn->error . "</p>";
                    }
                } else {
                    echo "<p>The confirmed column already exists.</p>";
                }
            } else {
                echo "<p>Cannot add column to non-existent table.</p>";
            }
        }
        
        if (isset($_POST['add_unique_constraint'])) {
            // Add the unique constraint if it doesn't exist
            if ($tableExists) {
                $indexQuery = "SHOW INDEX FROM timetable_slots WHERE Key_name = 'unique_slot'";
                $indexExists = $conn->query($indexQuery)->num_rows > 0;
                
                if (!$indexExists) {
                    // Drop it first just in case there's an alternate constraint
                    try {
                        $conn->query("ALTER TABLE timetable_slots DROP INDEX unique_slot");
                    } catch (Exception $e) {
                        // Ignore errors
                    }
                    
                    $alterQuery = "ALTER TABLE timetable_slots ADD UNIQUE KEY unique_slot (template_id, timetable_id, day, period)";
                    if ($conn->query($alterQuery)) {
                        echo "<p>✅ Added unique constraint successfully!</p>";
                    } else {
                        echo "<p>❌ Error adding unique constraint: " . $conn->error . "</p>";
                    }
                } else {
                    echo "<p>The unique constraint already exists.</p>";
                }
            } else {
                echo "<p>Cannot add constraint to non-existent table.</p>";
            }
        }
        
        if (isset($_POST['recreate_table'])) {
            // Drop and recreate the entire table
            $dropQuery = "DROP TABLE IF EXISTS timetable_slots";
            if ($conn->query($dropQuery)) {
                echo "<p>Dropped existing table.</p>";
                
                $createQuery = "CREATE TABLE timetable_slots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    timetable_id VARCHAR(255) NOT NULL,
                    template_id INT NOT NULL,
                    course_id INT NOT NULL,
                    staff_id INT NOT NULL,
                    day VARCHAR(20) NOT NULL,
                    period VARCHAR(10) NOT NULL,
                    is_lab TINYINT(1) DEFAULT 0,
                    confirmed TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (timetable_id),
                    INDEX (template_id),
                    INDEX (staff_id),
                    UNIQUE KEY unique_slot (template_id, timetable_id, day, period)
                )";
                
                if ($conn->query($createQuery)) {
                    echo "<p>✅ Table recreated successfully with all required columns and constraints!</p>";
                } else {
                    echo "<p>❌ Error creating new table: " . $conn->error . "</p>";
                }
            } else {
                echo "<p>❌ Error dropping table: " . $conn->error . "</p>";
            }
        }
        
        // Refresh the page to show updated status
        echo "<p><a href='repair_table.php'>Refresh page to see updated status</a></p>";
    }
}

// Get database connection
$db = DatabaseConfig::getInstance();
$conn = $db->getConnection();

// Run the repair function
repairTimetableSlotsTable($conn);
?>