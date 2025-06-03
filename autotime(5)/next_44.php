<?php
// Timetable Generation and Display System
// This page takes template_id and timetable_id from the session
// and automatically generates a timetable based on staff availability

// Error reporting and session start
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Redirect if template_id or timetable_id is not set
if (!isset($_SESSION['template_id']) || !isset($_SESSION['timetable_id'])) {
    header("Location: next_33.php");
    exit();
}

// Get template_id and timetable_id from session
$template_id = $_SESSION['template_id'];
$timetable_id = $_SESSION['timetable_id'];

// Database configuration
class DatabaseConfig {
    private static $instance = null;
    private $conn;

    private function __construct() {
        // Let's add extensive error logging for debugging the connection
        error_log("Attempting database connection");
        
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

// Create timetable_slots table if it doesn't exist and ensure proper structure
function createTimetableSlotsTable($conn) {
    // First check if table exists
    error_log("Step 1: Checking if timetable_slots table exists");
    $tableExistsQuery = "SHOW TABLES LIKE 'timetable_slots'";
    $tableExists = $conn->query($tableExistsQuery)->num_rows > 0;
    
    if ($tableExists) {
        error_log("Table exists, checking structure");
        
        // Check if 'confirmed' column exists
        $columnQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
        $columnExists = $conn->query($columnQuery)->num_rows > 0;
        
        if (!$columnExists) {
            // Add the confirmed column if it doesn't exist
            error_log("Adding 'confirmed' column that was missing");
            try {
                $conn->query("ALTER TABLE timetable_slots ADD COLUMN confirmed TINYINT(1) DEFAULT 0");
                error_log("Added confirmed column successfully");
            } catch (Exception $e) {
                error_log("Error adding confirmed column: " . $e->getMessage());
            }
        } else {
            error_log("Confirmed column already exists");
        }
        
        // Check and update unique constraint
        try {
            // Attempt to drop the constraint first (if it exists)
            $conn->query("ALTER TABLE timetable_slots DROP INDEX unique_slot");
            error_log("Dropped existing constraint");
        } catch (Exception $e) {
            // If there's an error (constraint doesn't exist), that's fine
            error_log("Note: Couldn't drop constraint: " . $e->getMessage());
        }
        
        // Add the unique constraint
        try {
            $conn->query("ALTER TABLE timetable_slots ADD UNIQUE KEY unique_slot (template_id, timetable_id, day, period)");
            error_log("Added unique constraint");
        } catch (Exception $e) {
            error_log("Error adding unique constraint: " . $e->getMessage());
        }
    } else {
        // Create table with proper constraint if it doesn't exist
        error_log("Table doesn't exist, creating new table with all required columns");
        $query = "CREATE TABLE timetable_slots (
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
        
        if (!$conn->query($query)) {
            throw new Exception("Error creating timetable_slots table: " . $conn->error);
        } else {
            error_log("Created new table with all columns including 'confirmed'");
        }
    }
    
    // Verify table structure after all operations
    error_log("Final table structure check:");
    $result = $conn->query("DESCRIBE timetable_slots");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    error_log("Columns in timetable_slots: " . implode(", ", $columns));
}

class TimetableGenerator {
    private $conn;
    private $template_id;
    private $timetable_id;
    private $template;
    private $workingDays;
    private $periodsData;
    private $breaksData;
    private $dayPeriodMap; // To track which periods are allocated on which days
    private $staffBusyPeriods = []; // Store busy periods for each staff member
    private $generatedTimetable = []; // Final timetable structure
    private $assignedCoursesToStaff = []; // Track assigned course periods to staff
    private $collisions = []; // Track any detected collisions
    private $isConfirmed = false; // Is the timetable confirmed

    public function __construct($conn, $template_id, $timetable_id) {
        $this->conn = $conn;
        $this->template_id = $template_id;
        $this->timetable_id = $timetable_id;
        $this->dayPeriodMap = [];
        
        // Check if this timetable has confirmed assignments
        $this->checkConfirmationStatus();
        
        // Get template details
        $this->template = $this->getTemplateDetails();
        
        if (!$this->template) {
            throw new Exception("Template not found for ID: $template_id");
        }
        
        // Set working days based on template's week_start and week_end
        $this->workingDays = $this->getDaysBetween($this->template['week_start'], $this->template['week_end']);
        
        // Parse periods and breaks data from template
        $this->periodsData = json_decode($this->template['periods_data'], true);
        $this->breaksData = json_decode($this->template['breaks_data'] ?? '{}', true);
        
        // Initialize dayPeriodMap with all days and periods
        foreach ($this->workingDays as $day) {
            $this->dayPeriodMap[$day] = [];
            $this->generatedTimetable[$day] = [];
            
            foreach ($this->periodsData as $periodId => $periodInfo) {
                $this->dayPeriodMap[$day][$periodId] = null; // null means period is available
                $this->generatedTimetable[$day][$periodId] = null;
            }
            
            // Add breaks to generatedTimetable
            if (!empty($this->breaksData)) {
                foreach ($this->breaksData as $breakId => $breakInfo) {
                    // Find appropriate position for break based on time
                    $this->generatedTimetable[$day]['break_' . $breakId] = [
                        'type' => 'break',
                        'name' => isset($breakInfo['is_lunch']) && $breakInfo['is_lunch'] ? 'Lunch Break' : 'Break',
                        'start_time' => $breakInfo['start_time'],
                        'end_time' => $breakInfo['end_time']
                    ];
                }
            }
        }
    }

    // Check if this timetable has confirmed assignments
    private function checkConfirmationStatus() {
        // Check if the `confirmed` column exists in the table
        $columnCheckQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
        $columnExists = $this->conn->query($columnCheckQuery)->num_rows > 0;

        if ($columnExists) {
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as confirmed_count 
                FROM timetable_slots 
                WHERE timetable_id = ? AND confirmed = 1
            ");
            $stmt->bind_param("s", $this->timetable_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            $this->isConfirmed = ($row['confirmed_count'] > 0);
        } else {
            // If the column doesn't exist yet, just set isConfirmed to false
            $this->isConfirmed = false;
        }
    }

    // Helper method to get template details
    private function getTemplateDetails() {
        $stmt = $this->conn->prepare("SELECT * FROM templates WHERE id = ?");
        $stmt->bind_param("i", $this->template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    // Helper method to get days between week_start and week_end
    private function getDaysBetween($start, $end) {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $start_idx = array_search($start, $days);
        $end_idx = array_search($end, $days);
        
        if ($start_idx === false || $end_idx === false) {
            throw new Exception("Invalid day names");
        }
        
        if ($start_idx <= $end_idx) {
            return array_slice($days, $start_idx, $end_idx - $start_idx + 1);
        } else {
            return array_merge(
                array_slice($days, $start_idx),
                array_slice($days, 0, $end_idx + 1)
            );
        }
    }
    
    // Get all course assignments for this timetable
    public function getCourseAssignments() {
        $stmt = $this->conn->prepare("
            SELECT ca.*, c.name as course_name, c.credits, 
                   s.name as staff_name, s.id as staff_id 
            FROM course_assignments ca
            JOIN courses c ON ca.course_id = c.id
            JOIN staff s ON ca.staff_id = s.id
            WHERE ca.timetable_id = ?
        ");
        $stmt->bind_param("s", $this->timetable_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get staff availability data
    public function getStaffAvailability() {
        $stmt = $this->conn->prepare("
            SELECT sa.staff_id, s.name as staff_name, sa.busy_periods 
            FROM staff_availability sa
            JOIN staff s ON sa.staff_id = s.id
            WHERE sa.template_id = ?
        ");
        $stmt->bind_param("i", $this->template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Process staff busy periods to identify conflicts
    private function processStaffBusyPeriods() {
        $staffAvailability = $this->getStaffAvailability();
        
        foreach ($staffAvailability as $availability) {
            $staffId = $availability['staff_id'];
            $busyPeriods = json_decode($availability['busy_periods'], true) ?: [];
            
            // Initialize entry for this staff if not exists
            if (!isset($this->staffBusyPeriods[$staffId])) {
                $this->staffBusyPeriods[$staffId] = [
                    'name' => $availability['staff_name'],
                    'busy' => []
                ];
            }
            
            // Process each busy period entry
            foreach ($busyPeriods as $busyPeriod) {
                // Skip entries that belong to this timetable (we're generating for this timetable)
                if (isset($busyPeriod['timetable_id']) && $busyPeriod['timetable_id'] === $this->timetable_id) {
                    continue;
                }
                
                // If type is lab and day is set, it's already assigned
                if (isset($busyPeriod['type']) && $busyPeriod['type'] === 'lab' && isset($busyPeriod['day'])) {
                    $day = $busyPeriod['day'];
                    $periods = $busyPeriod['periods'] ?? [];
                    
                    foreach ($periods as $periodId => $periodInfo) {
                        if (!isset($this->staffBusyPeriods[$staffId]['busy'][$day])) {
                            $this->staffBusyPeriods[$staffId]['busy'][$day] = [];
                        }
                        $this->staffBusyPeriods[$staffId]['busy'][$day][] = $periodId;
                    }
                }
                
                // If days_periods is set, it's already assigned regular course slots
                if (isset($busyPeriod['days_periods']) && !empty($busyPeriod['days_periods'])) {
                    foreach ($busyPeriod['days_periods'] as $dayPeriod) {
                        $day = $dayPeriod['day'];
                        $period = $dayPeriod['period'];
                        
                        if (!isset($this->staffBusyPeriods[$staffId]['busy'][$day])) {
                            $this->staffBusyPeriods[$staffId]['busy'][$day] = [];
                        }
                        $this->staffBusyPeriods[$staffId]['busy'][$day][] = $period;
                    }
                }
            }
        }
    }
    
    // Check if a staff is busy during a specific day and period
    private function isStaffBusy($staffId, $day, $periodId) {
        // First check the in-memory data
        if (isset($this->staffBusyPeriods[$staffId]) && 
            isset($this->staffBusyPeriods[$staffId]['busy'][$day]) && 
            in_array($periodId, $this->staffBusyPeriods[$staffId]['busy'][$day])) {
            return true;
        }
        
        // Check if the table and column exist
        $tableCheckQuery = "SHOW TABLES LIKE 'timetable_slots'";
        $tableExists = $this->conn->query($tableCheckQuery)->num_rows > 0;
        
        if ($tableExists) {
            $columnCheckQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
            $columnExists = $this->conn->query($columnCheckQuery)->num_rows > 0;
            
            if ($columnExists) {
                // Then check directly in the database for same template, same day/period, different timetable
                $stmt = $this->conn->prepare("
                    SELECT 1 FROM timetable_slots 
                    WHERE template_id = ? 
                    AND staff_id = ? 
                    AND day = ? 
                    AND period = ? 
                    AND timetable_id != ?
                    AND confirmed = 1
                    LIMIT 1
                ");
                $stmt->bind_param("iisss", $this->template_id, $staffId, $day, $periodId, $this->timetable_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                return $result->num_rows > 0;
            }
        }
        
        return false; // If table/column doesn't exist, assume not busy
    }
    
    // Handle lab assignments (which already have fixed periods)
    private function processLabAssignments($assignments) {
        foreach ($assignments as $assignment) {
            if ($assignment['is_lab']) {
                $labDay = $assignment['lab_day'];
                $labPeriods = json_decode($assignment['lab_periods'], true) ?: [];
                $staffId = $assignment['staff_id'];
                
                foreach ($labPeriods as $periodId => $periodInfo) {
                    // Check for collision with staff busy periods
                    if ($this->isStaffBusy($staffId, $labDay, $periodId)) {
                        $this->collisions[] = [
                            'type' => 'lab',
                            'day' => $labDay,
                            'period' => $periodId,
                            'course_name' => $assignment['course_name'],
                            'staff_name' => $assignment['staff_name'],
                            'message' => "Cannot assign. Staff {$assignment['staff_name']} is already assigned to a different timetable at this period in this template."
                        ];
                        
                        // We'll still proceed with the assignment for display purposes
                    }
                    
                    // Mark period as occupied in dayPeriodMap
                    $this->dayPeriodMap[$labDay][$periodId] = [
                        'course_id' => $assignment['course_id'],
                        'course_name' => $assignment['course_name'],
                        'staff_id' => $staffId,
                        'staff_name' => $assignment['staff_name'],
                        'is_lab' => true
                    ];
                    
                    // Add to generatedTimetable
                    $this->generatedTimetable[$labDay][$periodId] = [
                        'type' => 'lab',
                        'course_id' => $assignment['course_id'],
                        'course_name' => $assignment['course_name'],
                        'staff_id' => $staffId,
                        'staff_name' => $assignment['staff_name'],
                        'start_time' => $periodInfo['start_time'],
                        'end_time' => $periodInfo['end_time']
                    ];
                    
                    // Track this assignment for the staff
                    if (!isset($this->assignedCoursesToStaff[$staffId])) {
                        $this->assignedCoursesToStaff[$staffId] = [];
                    }
                    
                    $key = $assignment['course_id'] . '-lab';
                    if (!isset($this->assignedCoursesToStaff[$staffId][$key])) {
                        $this->assignedCoursesToStaff[$staffId][$key] = [
                            'course_id' => $assignment['course_id'],
                            'course_name' => $assignment['course_name'],
                            'is_lab' => true,
                            'assignments' => []
                        ];
                    }
                    
                    $this->assignedCoursesToStaff[$staffId][$key]['assignments'][] = [
                        'day' => $labDay,
                        'period' => $periodId,
                        'start_time' => $periodInfo['start_time'],
                        'end_time' => $periodInfo['end_time']
                    ];
                }
            }
        }
    }
    
    // Count how many assignments for a course are already on a specific day
    private function countDayAssignments($staffId, $courseKey, $day) {
        if (!isset($this->assignedCoursesToStaff[$staffId]) || 
            !isset($this->assignedCoursesToStaff[$staffId][$courseKey]) || 
            !isset($this->assignedCoursesToStaff[$staffId][$courseKey]['assignments'])) {
            return 0;
        }
        
        $count = 0;
        foreach ($this->assignedCoursesToStaff[$staffId][$courseKey]['assignments'] as $assignment) {
            if ($assignment['day'] === $day) {
                $count++;
            }
        }
        
        return $count;
    }
    
    // Find an available period on a specific day that doesn't conflict with staff's schedule
    private function findAvailablePeriod($day, $staffId) {
        // Get all period IDs
        $periodIds = array_keys($this->periodsData);
        
        // Shuffle periods to get different assignments on reload
        shuffle($periodIds);
        
        foreach ($periodIds as $periodId) {
            // Check if period is available in this timetable
            if ($this->dayPeriodMap[$day][$periodId] === null) {
                // Check if staff is busy during this period
                if (!$this->isStaffBusy($staffId, $day, $periodId)) {
                    return $periodId;
                }
            }
        }
        
        // No available period found
        return null;
    }
    
    // Assign a course to a specific day and period
    private function assignPeriod($day, $periodId, $courseId, $courseName, $staffId, $staffName, $isLab) {
        // Mark period as occupied in dayPeriodMap
        $this->dayPeriodMap[$day][$periodId] = [
            'course_id' => $courseId,
            'course_name' => $courseName,
            'staff_id' => $staffId,
            'staff_name' => $staffName,
            'is_lab' => $isLab
        ];
        
        // Add to generatedTimetable
        $this->generatedTimetable[$day][$periodId] = [
            'type' => $isLab ? 'lab' : 'regular',
            'course_id' => $courseId,
            'course_name' => $courseName,
            'staff_id' => $staffId,
            'staff_name' => $staffName,
            'start_time' => $this->periodsData[$periodId]['start_time'],
            'end_time' => $this->periodsData[$periodId]['end_time']
        ];
        
        // Add to staffBusyPeriods to prevent double bookings
        if (!isset($this->staffBusyPeriods[$staffId])) {
            $this->staffBusyPeriods[$staffId] = [
                'name' => $staffName,
                'busy' => []
            ];
        }
        
        if (!isset($this->staffBusyPeriods[$staffId]['busy'][$day])) {
            $this->staffBusyPeriods[$staffId]['busy'][$day] = [];
        }
        
        $this->staffBusyPeriods[$staffId]['busy'][$day][] = $periodId;
    }
    
    // Process regular course assignments that need period allocation
    private function processRegularAssignments($assignments) {
        $regularAssignments = [];
        
        // First, collect all regular assignments
        foreach ($assignments as $assignment) {
            if (!$assignment['is_lab']) {
                $regularAssignments[] = $assignment;
            }
        }
        
        // Sort assignments by number of periods (descending) to place larger courses first
        usort($regularAssignments, function($a, $b) {
            return $b['periods'] - $a['periods'];
        });
        
        // Now assign periods for each regular course
        foreach ($regularAssignments as $assignment) {
            $courseId = $assignment['course_id'];
            $staffId = $assignment['staff_id'];
            $periodsNeeded = intval($assignment['periods']);
            $courseName = $assignment['course_name'];
            $staffName = $assignment['staff_name'];
            
            // Skip if no periods needed
            if ($periodsNeeded <= 0) continue;
            
            // Initialize tracking for this staff-course combination
            if (!isset($this->assignedCoursesToStaff[$staffId])) {
                $this->assignedCoursesToStaff[$staffId] = [];
            }
            
            $key = $courseId . '-regular';
            if (!isset($this->assignedCoursesToStaff[$staffId][$key])) {
                $this->assignedCoursesToStaff[$staffId][$key] = [
                    'course_id' => $courseId,
                    'course_name' => $courseName,
                    'is_lab' => false,
                    'assignments' => [],
                    'periods_allocated' => 0,
                    'periods_needed' => $periodsNeeded
                ];
            }
            
            // Try to distribute periods evenly throughout the week
            $periodsAssigned = 0;
            $attemptsPerDay = 2; // Try to assign at most 2 periods per day for a course
            
            // First pass - assign one period per day if possible
            foreach ($this->workingDays as $day) {
                if ($periodsAssigned >= $periodsNeeded) break;
                
                // Skip if we've already assigned enough periods for this day
                $dayAssignmentsCount = $this->countDayAssignments($staffId, $key, $day);
                if ($dayAssignmentsCount >= $attemptsPerDay) continue;
                
                // Find available period
                $periodId = $this->findAvailablePeriod($day, $staffId);
                if ($periodId) {
                    $this->assignPeriod($day, $periodId, $courseId, $courseName, $staffId, $staffName, false);
                    $periodsAssigned++;
                    $this->assignedCoursesToStaff[$staffId][$key]['periods_allocated']++;
                    
                    // Add to assignments tracking
                    $this->assignedCoursesToStaff[$staffId][$key]['assignments'][] = [
                        'day' => $day,
                        'period' => $periodId,
                        'start_time' => $this->periodsData[$periodId]['start_time'],
                        'end_time' => $this->periodsData[$periodId]['end_time']
                    ];
                }
            }
            
            // Second pass - fill remaining periods where possible
            if ($periodsAssigned < $periodsNeeded) {
                foreach ($this->workingDays as $day) {
                    if ($periodsAssigned >= $periodsNeeded) break;
                    
                    // Allow more periods per day if needed
                    $maxPeriodsPerDay = min(3, $periodsNeeded - $periodsAssigned);
                    $dayAssignmentsCount = $this->countDayAssignments($staffId, $key, $day);
                    
                    // Continue assigning until we reach max for this day
                    while ($dayAssignmentsCount < $maxPeriodsPerDay && $periodsAssigned < $periodsNeeded) {
                        $periodId = $this->findAvailablePeriod($day, $staffId);
                        if ($periodId) {
                            $this->assignPeriod($day, $periodId, $courseId, $courseName, $staffId, $staffName, false);
                            $periodsAssigned++;
                            $this->assignedCoursesToStaff[$staffId][$key]['periods_allocated']++;
                            $dayAssignmentsCount++;
                            
                            // Add to assignments tracking
                            $this->assignedCoursesToStaff[$staffId][$key]['assignments'][] = [
                                'day' => $day,
                                'period' => $periodId,
                                'start_time' => $this->periodsData[$periodId]['start_time'],
                                'end_time' => $this->periodsData[$periodId]['end_time']
                            ];
                        } else {
                            // No available periods on this day, move to next day
                            break;
                        }
                    }
                }
            }
            
            // If we couldn't assign all needed periods, log this as a warning
            if ($periodsAssigned < $periodsNeeded) {
                $this->collisions[] = [
                    'type' => 'regular',
                    'course_name' => $courseName,
                    'staff_name' => $staffName,
                    'message' => "Could only assign {$periodsAssigned} of {$periodsNeeded} periods for {$courseName} (Staff: {$staffName})"
                ];
            }
        }
    }
    
    // Load confirmed assignments from the database
    private function loadConfirmedAssignments() {
        error_log("Loading confirmed assignments for timetable_id: " . $this->timetable_id);
        
        // Check if the table and column exist
        $tableCheckQuery = "SHOW TABLES LIKE 'timetable_slots'";
        $tableResult = $this->conn->query($tableCheckQuery);
        
        if (!$tableResult) {
            error_log("Error checking table: " . $this->conn->error);
            return;
        }
        
        $tableExists = $tableResult->num_rows > 0;
        
        if (!$tableExists) {
            error_log("Table 'timetable_slots' does not exist yet, nothing to load");
            return; // Table doesn't exist yet, nothing to load
        }
        
        $columnCheckQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
        $columnResult = $this->conn->query($columnCheckQuery);
        
        if (!$columnResult) {
            error_log("Error checking column: " . $this->conn->error);
            return;
        }
        
        $columnExists = $columnResult->num_rows > 0;
        
        if (!$columnExists) {
            error_log("Column 'confirmed' does not exist yet, nothing to load");
            return; // Column doesn't exist yet, nothing to load
        }
        
        $query = "SELECT * FROM timetable_slots WHERE timetable_id = ? AND confirmed = 1";
        error_log("Query: " . $query . " with parameter: " . $this->timetable_id);
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            error_log("Error preparing statement: " . $this->conn->error);
            return;
        }
        
        $stmt->bind_param("s", $this->timetable_id);
        if (!$stmt->execute()) {
            error_log("Error executing statement: " . $stmt->error);
            $stmt->close();
            return;
        }
        
        $result = $stmt->get_result();
        $loadCount = $result->num_rows;
        error_log("Found " . $loadCount . " confirmed assignments");
        
        if ($loadCount === 0) {
            error_log("No confirmed assignments found for timetable_id: " . $this->timetable_id);
            $stmt->close();
            return;
        }
        
        $assignments = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        foreach ($assignments as $assignment) {
            $day = $assignment['day'];
            $periodId = $assignment['period'];
            $courseId = $assignment['course_id'];
            $staffId = $assignment['staff_id'];
            $isLab = $assignment['is_lab'];
            
            error_log("Loading assignment: day=$day, period=$periodId, course_id=$courseId, staff_id=$staffId, is_lab=" . ($isLab ? "true" : "false"));
            
            // Get course and staff names
            $stmt = $this->conn->prepare("
                SELECT c.name as course_name, s.name as staff_name
                FROM courses c, staff s
                WHERE c.id = ? AND s.id = ?
            ");
            $stmt->bind_param("ii", $courseId, $staffId);
            $stmt->execute();
            $result = $stmt->get_result();
            $names = $result->fetch_assoc();
            
            // Add to generatedTimetable
            $this->generatedTimetable[$day][$periodId] = [
                'type' => $isLab ? 'lab' : 'regular',
                'course_id' => $courseId,
                'course_name' => $names['course_name'],
                'staff_id' => $staffId,
                'staff_name' => $names['staff_name'],
                'start_time' => $this->periodsData[$periodId]['start_time'],
                'end_time' => $this->periodsData[$periodId]['end_time']
            ];
            
            // Mark period as occupied in dayPeriodMap
            $this->dayPeriodMap[$day][$periodId] = [
                'course_id' => $courseId,
                'course_name' => $names['course_name'],
                'staff_id' => $staffId,
                'staff_name' => $names['staff_name'],
                'is_lab' => $isLab
            ];
        }
    }
    
    // Save the generated timetable to the database
    public function confirmTimetable() {
        try {
            // Let's ensure we're in debug mode
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            
            // Clear any pending transaction that might be hanging
            try {
                $this->conn->query("COMMIT");
            } catch (Exception $e) {
                // Ignore any errors here
            }
            
            // First ensure the table exists with the correct structure
            error_log("Step 1: Ensuring table exists");
            
            // Drop the constraint if it exists to avoid conflicts (we'll recreate it)
            try {
                $this->conn->query("
                    ALTER TABLE timetable_slots 
                    DROP INDEX unique_slot
                ");
                error_log("Dropped existing constraint");
            } catch (Exception $e) {
                // Constraint might not exist, that's fine
                error_log("Note: Couldn't drop constraint: " . $e->getMessage());
            }
            
            try {
                $this->conn->query("
                    CREATE TABLE IF NOT EXISTS timetable_slots (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        timetable_id VARCHAR(255) NOT NULL,
                        template_id INT NOT NULL,
                        course_id INT NOT NULL,
                        staff_id INT NOT NULL,
                        day VARCHAR(20) NOT NULL,
                        period VARCHAR(10) NOT NULL,
                        is_lab BOOLEAN DEFAULT FALSE,
                        confirmed BOOLEAN DEFAULT FALSE,                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX (timetable_id),
                        INDEX (template_id),
                        INDEX (staff_id)
                    )
                ");
                error_log("Table created or verified");
                
                // Add the unique constraint in a separate step
                $this->conn->query("
                    ALTER TABLE timetable_slots 
                    ADD UNIQUE KEY unique_slot (template_id, timetable_id, day, period)
                ");
                error_log("Unique constraint added");
            } catch (Exception $e) {
                error_log("Error in table setup: " . $e->getMessage());
            }
            
            // Debug: Log timetable details
            error_log("Step 2: Starting timetable confirmation for timetable_id: " . $this->timetable_id . ", template_id: " . $this->template_id);
            
            // Manually handle the transaction
            $this->conn->autocommit(FALSE);
            
            // First, delete any existing assignments for this timetable and template combination
            error_log("Step 3: Deleting existing assignments for current template");
            $deleteQuery = "DELETE FROM timetable_slots WHERE timetable_id = ? AND template_id = ?";
            error_log("Delete query: " . $deleteQuery . " with parameters: " . $this->timetable_id . ", " . $this->template_id);
            
            $stmt = $this->conn->prepare($deleteQuery);
            if (!$stmt) {
                error_log("Prepare Error on DELETE: " . $this->conn->error);
                $this->conn->autocommit(TRUE);
                return false;
            }
            
            $stmt->bind_param("si", $this->timetable_id, $this->template_id);
            if (!$stmt->execute()) {
                error_log("Execute Error on DELETE: " . $stmt->error);
                $stmt->close();
                $this->conn->autocommit(TRUE);
                return false;
            }
            
            $stmt->close();
            error_log("Deleted existing timetable entries. Affected rows: " . $this->conn->affected_rows);
            
            // First check if the 'confirmed' column exists
            error_log("Step 4.1: Checking if confirmed column exists");
            $columnQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
            $columnExists = $this->conn->query($columnQuery)->num_rows > 0;
            
            if (!$columnExists) {
                // Add the confirmed column if it doesn't exist
                error_log("Step 4.2: Adding missing confirmed column");
                try {
                    $this->conn->query("ALTER TABLE timetable_slots ADD COLUMN confirmed TINYINT(1) DEFAULT 0");
                    error_log("Added confirmed column successfully");
                } catch (Exception $e) {
                    error_log("Error adding confirmed column: " . $e->getMessage());
                    $this->conn->autocommit(TRUE);
                    return false;
                }
            }
            
            // Then insert all current assignments
            error_log("Step 4.3: Inserting new assignments");
            
            if ($columnExists) {
                $insertQuery = "
                    INSERT INTO timetable_slots 
                    (timetable_id, template_id, course_id, staff_id, day, period, is_lab, confirmed)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ";
            } else {
                // If we just added the column but it's still causing issues, try without it
                $insertQuery = "
                    INSERT INTO timetable_slots 
                    (timetable_id, template_id, course_id, staff_id, day, period, is_lab)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ";
            }
            
            error_log("Insert query: " . $insertQuery);
            
            $stmt = $this->conn->prepare($insertQuery);
            if (!$stmt) {
                error_log("Prepare Error on INSERT: " . $this->conn->error);
                $this->conn->autocommit(TRUE);
                return false;
            }
            
            $insertCount = 0;

            foreach ($this->workingDays as $day) {
                foreach ($this->periodsData as $periodId => $periodInfo) {
                    if (isset($this->dayPeriodMap[$day][$periodId]) && $this->dayPeriodMap[$day][$periodId] !== null) {
                        $assignment = $this->dayPeriodMap[$day][$periodId];

                        error_log("Inserting assignment for day: $day, period: $periodId");

                        // Debug data being inserted
                        $debug_data = "timetable_id: " . $this->timetable_id . 
                                 ", template_id: " . $this->template_id . 
                                 ", course_id: " . $assignment['course_id'] . 
                                 ", staff_id: " . $assignment['staff_id'] . 
                                 ", day: " . $day . 
                                 ", period: " . $periodId . 
                                 ", is_lab: " . ($assignment['is_lab'] ? "1" : "0");
                        error_log("Data: " . $debug_data);

                        if ($columnExists) {
                            // If confirmed column exists, use 8 parameters
                            $isLabValue = $assignment['is_lab'] ? 1 : 0;
                            $confirmedValue = 1; // Always set to confirmed

                            if (!$stmt->bind_param(
                                "siissiii",
                                $this->timetable_id,
                                $this->template_id,
                                $assignment['course_id'],
                                $assignment['staff_id'],
                                $day,
                                $periodId,
                                $isLabValue,
                                $confirmedValue
                            )) {
                                error_log("Bind Param Error: " . $stmt->error);
                                $stmt->close();
                                $this->conn->rollback();
                                $this->conn->autocommit(TRUE);
                                return false;
                            }
                        } else {
                            // If we don't have confirmed column yet, use 7 parameters
                            $isLabValue = $assignment['is_lab'] ? 1 : 0;

                            if (!$stmt->bind_param(
                                "siissii",
                                $this->timetable_id,
                                $this->template_id,
                                $assignment['course_id'],
                                $assignment['staff_id'],
                                $day,
                                $periodId,
                                $isLabValue
                            )) {
                                error_log("Bind Param Error: " . $stmt->error);
                                $stmt->close();
                                $this->conn->rollback();
                                $this->conn->autocommit(TRUE);
                                return false;
                            }
                        }

                        if (!$stmt->execute()) {
                            error_log("Execute Error on insert: " . $stmt->error . " for day: $day, period: $periodId");
                            $stmt->close();
                            $this->conn->rollback();
                            $this->conn->autocommit(TRUE);
                            return false;
                        }

                        $insertCount++;
                    }
                }
            }

            $stmt->close();            // Check if any assignments were inserted
            if ($insertCount === 0) {
                error_log("No assignments were inserted - something is wrong with the data structure.");
                $this->conn->rollback();
                $this->conn->autocommit(TRUE);
                return false;
            }
            
            error_log("Successfully inserted $insertCount assignments");
            
            // Commit the transaction
            if (!$this->conn->commit()) {
                error_log("Error during commit: " . $this->conn->error);
                $this->conn->rollback();
                $this->conn->autocommit(TRUE);
                return false;
            }
            
            error_log("Transaction committed successfully");
            $this->conn->autocommit(TRUE);
            $this->isConfirmed = true;
            
            // First check if the 'confirmed' column exists
            $columnQuery = "SHOW COLUMNS FROM timetable_slots LIKE 'confirmed'";
            $columnExists = $this->conn->query($columnQuery)->num_rows > 0;
            
            if ($columnExists) {
                // Verify data was actually saved with confirmed = 1
                $verifyQuery = "SELECT COUNT(*) as count FROM timetable_slots WHERE timetable_id = ? AND confirmed = 1";
                $verifyStmt = $this->conn->prepare($verifyQuery);
                $verifyStmt->bind_param("s", $this->timetable_id);
                $verifyStmt->execute();
                $result = $verifyStmt->get_result();
                $row = $result->fetch_assoc();
                
                error_log("Verification: Found " . $row['count'] . " confirmed slots after insert");
                $verifyStmt->close();
            } else {
                // Just verify any data was saved for this timetable
                $verifyQuery = "SELECT COUNT(*) as count FROM timetable_slots WHERE timetable_id = ?";
                $verifyStmt = $this->conn->prepare($verifyQuery);
                $verifyStmt->bind_param("s", $this->timetable_id);
                $verifyStmt->execute();
                $result = $verifyStmt->get_result();
                $row = $result->fetch_assoc();
                
                error_log("Verification (without confirmed column): Found " . $row['count'] . " slots after insert");
                $verifyStmt->close();
            }
            
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log($e->getMessage());
            return false;
        }
    }
    
    // Update staff availability with the confirmed assignments
    public function updateStaffAvailability() {
        // For each staff member, find their existing busy_periods
        foreach ($this->assignedCoursesToStaff as $staffId => $courseAssignments) {
            // Get existing busy periods from the database
            $stmt = $this->conn->prepare("
                SELECT busy_periods FROM staff_availability 
                WHERE staff_id = ? AND template_id = ?
            ");
            $stmt->bind_param("ii", $staffId, $this->template_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $busyPeriods = json_decode($row['busy_periods'], true) ?: [];
                
                // Remove any existing entries for this timetable
                $busyPeriods = array_filter($busyPeriods, function($period) {
                    return !isset($period['timetable_id']) || $period['timetable_id'] !== $this->timetable_id;
                });
                
                // Add new assignments for this timetable
                foreach ($courseAssignments as $courseKey => $assignment) {
                    $parts = explode('-', $courseKey);
                    $courseId = $parts[0];
                    $type = $parts[1]; // 'lab' or 'regular'
                    
                    if ($type === 'lab') {
                        // Format lab assignments
                        foreach ($assignment['assignments'] as $labAssignment) {
                            $busyPeriod = [
                                'day' => $labAssignment['day'],
                                'type' => 'lab',
                                'periods' => [
                                    $labAssignment['period'] => [
                                        'start_time' => $labAssignment['start_time'],
                                        'end_time' => $labAssignment['end_time']
                                    ]
                                ],
                                'course_id' => $courseId,
                                'course_name' => $assignment['course_name'],
                                'timetable_id' => $this->timetable_id,
                                'timetable_name' => "Timetable #{$this->timetable_id}"
                            ];
                            $busyPeriods[] = $busyPeriod;
                        }
                    } else {
                        // Format regular assignments
                        $daysPeriods = [];
                        foreach ($assignment['assignments'] as $regularAssignment) {
                            $daysPeriods[] = [
                                'day' => $regularAssignment['day'],
                                'period' => $regularAssignment['period']
                            ];
                        }
                        
                        $busyPeriod = [
                            'type' => 'regular',
                            'periods' => count($daysPeriods),
                            'course_id' => $courseId,
                            'course_name' => $assignment['course_name'],
                            'days_periods' => $daysPeriods,
                            'timetable_id' => $this->timetable_id,
                            'timetable_name' => "Timetable #{$this->timetable_id}"
                        ];
                        $busyPeriods[] = $busyPeriod;
                    }
                }
                
                // Update the staff_availability record
                $busyPeriodsJson = json_encode($busyPeriods);
                $stmt = $this->conn->prepare("
                    UPDATE staff_availability 
                    SET busy_periods = ? 
                    WHERE staff_id = ? AND template_id = ?
                ");
                $stmt->bind_param("sii", $busyPeriodsJson, $staffId, $this->template_id);
                $stmt->execute();
            }
        }
    }
    
    // Main method to generate the timetable
    public function generateTimetable() {
        // If timetable is already confirmed, just load the assignments
        if ($this->isConfirmed) {
            $this->loadConfirmedAssignments();
            return $this->generatedTimetable;
        }
        
        // Process staff busy periods
        $this->processStaffBusyPeriods();
        
        // Get all course assignments for this timetable
        $assignments = $this->getCourseAssignments();
        
        // Handle lab assignments first (they have fixed periods)
        $this->processLabAssignments($assignments);
        
        // Then handle regular course assignments
        $this->processRegularAssignments($assignments);
        
        return $this->generatedTimetable;
    }
    
    // Get any collision warnings
    public function getCollisions() {
        return $this->collisions;
    }
    
    // Check if timetable is already confirmed
    public function isConfirmed() {
        return $this->isConfirmed;
    }
    
    // Helper method to sort time slots by start time
    private function sortTimeSlots($a, $b) {
        // Extract time from break_X or numeric period IDs
        $getTime = function($id) {
            if (strpos($id, 'break_') === 0) {
                // This is a break slot
                $breakId = substr($id, 6);
                return $this->breaksData[$breakId]['start_time'];
            } else {
                // This is a regular period
                return $this->periodsData[$id]['start_time'];
            }
        };
        
        $timeA = $getTime($a);
        $timeB = $getTime($b);
        
        return strtotime("2000-01-01 " . $timeA) - strtotime("2000-01-01 " . $timeB);
    }
    
    // Get timetable slots sorted by time
    public function getSortedTimetable() {
        $sortedTimetable = [];
        
        foreach ($this->workingDays as $day) {
            $sortedTimetable[$day] = [];
            
            // Combine periods and breaks
            $allSlots = array_merge(
                array_keys($this->periodsData),
                array_map(function($breakId) { return 'break_' . $breakId; }, array_keys($this->breaksData))
            );
            
            // Sort slots by start time
            usort($allSlots, [$this, 'sortTimeSlots']);
            
            // Add sorted slots to the timetable
            foreach ($allSlots as $slotId) {
                if (strpos($slotId, 'break_') === 0) {
                    // This is a break slot
                    $breakId = substr($slotId, 6);
                    $sortedTimetable[$day][$slotId] = [
                        'type' => 'break',
                        'name' => isset($this->breaksData[$breakId]['is_lunch']) && $this->breaksData[$breakId]['is_lunch'] ? 'Lunch Break' : 'Break',
                        'start_time' => $this->breaksData[$breakId]['start_time'],
                        'end_time' => $this->breaksData[$breakId]['end_time']
                    ];
                } else {
                    // This is a regular period
                    $periodId = $slotId;
                    $sortedTimetable[$day][$slotId] = $this->generatedTimetable[$day][$periodId];
                }
            }
        }
        
        return $sortedTimetable;
    }
}

// Database connection and initial setup
$db = DatabaseConfig::getInstance();
$conn = $db->getConnection();

// Ensure timetable_slots table exists
createTimetableSlotsTable($conn);

// Handle form actions
$message = '';
$timetableConfirmed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'confirm') {
            // Create timetable generator
            $generator = new TimetableGenerator($conn, $template_id, $timetable_id);
            
            // Generate timetable
            $generator->generateTimetable();
            
            // Confirm timetable and update staff availability
            if ($generator->confirmTimetable()) {
                // After confirming timetable, also update staff availability
                $generator->updateStaffAvailability();
                $message = "✅ Timetable confirmed successfully! Staff assignments have been saved.";
                $timetableConfirmed = true;
            } else {
                $message = "❌ Error confirming timetable. Please try again.";
                // Add error details to the message if available in the error log
                $errorLogFile = "error.log";
                if (file_exists($errorLogFile)) {
                    $logContent = file_get_contents($errorLogFile);
                    $recentErrors = substr($logContent, max(0, strlen($logContent) - 1000)); // Get last 1000 chars
                    error_log("Recent errors: " . $recentErrors);
                }
            }
        }
    }
}

// Create timetable generator
$generator = new TimetableGenerator($conn, $template_id, $timetable_id);

// Generate timetable
$timetable = $generator->generateTimetable();

// Get collisions
$collisions = $generator->getCollisions();

// Check if timetable is already confirmed
$isConfirmed = $generator->isConfirmed();

// Get sorted timetable for display
$sortedTimetable = $generator->getSortedTimetable();

// Get all periods and breaks (including their times) for the table header
$allPeriods = [];

// Suppress warnings for this section
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

foreach ($sortedTimetable as $day => $slots) {
    foreach ($slots as $slotId => $slotInfo) {
        if (!isset($allPeriods[$slotId])) {
            $allPeriods[$slotId] = [
                'id' => $slotId,
                'type' => $slotInfo['type'],
                'start_time' => $slotInfo['start_time'],
                'end_time' => $slotInfo['end_time'],
                'name' => $slotInfo['type'] === 'break' ? $slotInfo['name'] : 'Period ' . $slotId
            ];
        }
    }
    // We only need to process one day to get all slots
    break;
}

// Restore normal error reporting
error_reporting(E_ALL);?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Generator</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/timetable.css">
    <style>
        body {
            background-color: #ffffff;
            color: #333333;
        }
        .navbar {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            color: #333333;
        }
        .sidebar {
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }
        .sidebar a {
            color: #333333;
        }
        .sidebar a:hover {
            background-color: #e9ecef;
        }
        
        .collision-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
        
        .collision-list {
            margin-top: 10px;
            padding-left: 20px;
        }
        
        .timetable-actions {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        
        .confirmed-badge {
            display: inline-block;
            background-color: #d4edda;
            color: #155724;
            padding: 5px 10px;
            border-radius: 4px;
            margin-left: 10px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="title">Autotime - Timetable Generator</div>
    </div>
    <div class="sidebar">
        <ul>
            <li><a href="gg5.php">Dashboard</a></li>
            <li><a href="addstaff.php">Manage Staff</a></li>
            <li><a href="addcourse.php">Manage Courses</a></li>
            <li><a href="addclass.php">Manage Classes</a></li>
            <li><a href="template.php">Manage Templates</a></li>
            <li><a href="next_11.php">Course Assignments</a></li>
            <li><a href="next_22.php">Timetable Summary</a></li>
            <li><a href="next_33.php">Staff Availability</a></li>
            <li><a href="next_44.php">Generate Timetable</a></li>
        </ul>
    </div>
    <div class="content">
        <h1>
            Timetable Generator
            <?php if ($isConfirmed): ?>
                <span class="confirmed-badge">Confirmed ✓</span>
            <?php endif; ?>
        </h1>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo strpos($message, '✅') === 0 ? 'success' : 'danger'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($collisions)): ?>
            <div class="collision-warning">
                <strong>⚠️ Warning: The following conflicts were detected:</strong>
                <ul class="collision-list">
                    <?php foreach ($collisions as $collision): ?>
                        <li><?php echo htmlspecialchars($collision['message']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p>These conflicts might lead to scheduling issues. Please review and adjust course assignments if needed.</p>
            </div>
        <?php endif; ?>
        
        <?php if (!$isConfirmed): ?>
            <div class="timetable-actions">
                <form method="post" action="">
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-success">✅ Confirm Assignment</button>
                </form>
                <form method="get" action="">
                    <button type="submit" class="btn btn-primary">🔁 Change Assignment</button>
                </form>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($sortedTimetable)): ?>
            <div class="timetable-container">
                <table class="timetable">
                    <thead>
                        <tr>
                            <th>Day / Time</th>
                            <?php foreach ($allPeriods as $slotId => $slotInfo): ?>
                                <th>
                                    <?php echo htmlspecialchars($slotInfo['name']); ?>
                                    <span class="time">
                                        <?php echo htmlspecialchars($slotInfo['start_time'] . ' - ' . $slotInfo['end_time']); ?>
                                    </span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sortedTimetable as $day => $slots): ?>
                            <tr>
                                <td class="timetable-day"><?php echo $day; ?></td>
                                <?php foreach ($allPeriods as $slotId => $slotInfo): ?>
                                    <td>
                                        <?php if ($slotInfo['type'] === 'break'): ?>
                                            <div class="course-cell course-break">
                                                <div class="course-name"><?php echo htmlspecialchars($slotInfo['name']); ?></div>
                                                <div class="course-time">
                                                    <?php echo htmlspecialchars($slotInfo['start_time'] . ' - ' . $slotInfo['end_time']); ?>
                                                </div>
                                            </div>
                                        <?php elseif (isset($slots[$slotId]) && $slots[$slotId] !== null): ?>
                                            <?php $cellData = $slots[$slotId]; ?>
                                            <div class="course-cell <?php echo $cellData['type'] === 'lab' ? 'course-lab' : 'course-regular'; ?>">
                                                <div class="course-name">
                                                    <?php echo htmlspecialchars($cellData['course_name']); ?>
                                                    <?php if ($cellData['type'] === 'lab'): ?>
                                                        (Lab)
                                                    <?php endif; ?>
                                                </div>
                                                <div class="course-staff">
                                                    <?php echo htmlspecialchars($cellData['staff_name']); ?>
                                                </div>
                                                <div class="course-time">
                                                    <?php echo htmlspecialchars($cellData['start_time'] . ' - ' . $cellData['end_time']); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="export-section">
                <h3>Export Options</h3>
                <div class="export-options">
                    <button class="btn btn-primary" onclick="window.print()">Print Timetable</button>
                    <button class="btn btn-success" onclick="exportToExcel()">Export to Excel</button>
                    <button class="btn btn-primary" onclick="location.href='next_11.php'">Return to Course Assignments</button>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                No timetable data available. Please ensure you have selected a valid template and timetable.
            </div>
            <button class="btn btn-primary" onclick="location.href='next_33.php'">Return to Staff Availability</button>
        <?php endif; ?>
    </div>
    <script src="assets/timetable.js"></script>
</body>
</html>