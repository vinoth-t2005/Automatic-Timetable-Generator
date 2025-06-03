<?php
// Staff Availability Management Page

// Error reporting and session start
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Redirect if template_id is not set
if (!isset($_SESSION['template_id'])) {
    header("Location: next_22.php");
    exit();
}

// Get template_id from session
$template_id = $_SESSION['template_id'];

// Database configuration
class DatabaseConfig {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $config = [
            'host' => 'localhost',
            'user' => 'root',
            'password' => '',
            'database' => 'autotime2',
            'charset' => 'utf8mb4'
        ];

        try {
            $this->conn = new mysqli(
                $config['host'], 
                $config['user'], 
                $config['password'], 
                $config['database']
            );

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

class StaffAvailabilityManager {
    private $conn;
    private $template_id;

    public function __construct($conn, $template_id) {
        $this->conn = $conn;
        $this->template_id = $template_id;
    }

    // Create staff_availability table if it doesn't exist
    public function createStaffAvailabilityTable() {
        // First check if table exists
        $check = $this->conn->query("SHOW TABLES LIKE 'staff_availability'");
        if ($check->num_rows > 0) {
            return true; // Table already exists
        }

        $query = "CREATE TABLE staff_availability (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_template VARCHAR(191) NOT NULL UNIQUE,
            staff_id INT NOT NULL,
            template_id INT NOT NULL,
            busy_periods JSON COMMENT 'Stores days and periods when staff is busy',
            remaining_periods INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (staff_id) REFERENCES staff(id),
            FOREIGN KEY (template_id) REFERENCES templates(id),
            INDEX (staff_id, template_id)
        )";

        if (!$this->conn->query($query)) {
            throw new Exception("Error creating staff_availability table: " . $this->conn->error);
        }
        return true;
    }
    // Create timetable_slots table if it doesn't exist
    public function createTimetableSlotsTable() {
        // First check if table exists
        $check = $this->conn->query("SHOW TABLES LIKE 'timetable_slots'");
        if ($check->num_rows > 0) {
            return true; // Table already exists
        }
        
        $query = "CREATE TABLE timetable_slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            timetable_id VARCHAR(50) NOT NULL,
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
        
        if (!$this->conn->query($query)) {
            throw new Exception("Error creating timetable_slots table: " . $this->conn->error);
        }
        return true;
    }
    // Get template details
    public function getTemplateDetails() {
        $stmt = $this->conn->prepare("
            SELECT * FROM templates 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $this->template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    // Get all timetable IDs associated with this template from t1 table
    public function getAssociatedTimetableIds() {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT timetable_id
            FROM t1
            WHERE template_id = ?
        ");
        $stmt->bind_param("i", $this->template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    // Get timetable details by ID
    public function getTimetableDetails($timetable_id) {
        $stmt = $this->conn->prepare("
            SELECT c.id, 
                   CONCAT(c.dept, '-', c.year, '-', c.semester) as name, 
                   c.dept as department, 
                   c.year, 
                   c.batch_start as section
            FROM classes c
            WHERE c.id = ?
        ");
        $stmt->bind_param("i", $timetable_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return [
                'id' => $timetable_id,
                'name' => 'Timetable #' . $timetable_id,
                'department' => '',
                'year' => '',
                'section' => ''
            ];
        }
        
        return $result->fetch_assoc();
    }

    // Get all staff assigned to courses in any timetable using this template
    public function getAssignedStaff() {
        $stmt = $this->conn->prepare("
            SELECT DISTINCT s.id, s.name 
            FROM course_assignments ca
            JOIN staff s ON ca.staff_id = s.id
            WHERE ca.template_id = ?
        ");
        $stmt->bind_param("i", $this->template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    // Get lab assignments for a staff member across all timetables for this template
    public function getLabAssignments($staff_id) {
        $stmt = $this->conn->prepare("
            SELECT ca.timetable_id, ca.course_id, 
                   c.name as course_name, ca.lab_day, ca.lab_periods,
                   CONCAT(cl.dept, '-', cl.year, '-', cl.semester) as timetable_name, 
                   cl.dept as department, cl.year, cl.batch_start as section
            FROM course_assignments ca
            JOIN courses c ON ca.course_id = c.id
            LEFT JOIN classes cl ON ca.timetable_id = cl.id
            WHERE ca.template_id = ? 
            AND ca.staff_id = ?
            AND ca.is_lab = 1
        ");
        $stmt->bind_param("ii", $this->template_id, $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    // Get regular period assignments for a staff member across all timetables for this template
    public function getRegularAssignments($staff_id) {
        $stmt = $this->conn->prepare("
            SELECT scd.course_id, scd.course_name, 
                   scd.periods_allotted as periods,
                   st.timetable_id,
                   CONCAT(cl.dept, '-', cl.year, '-', cl.semester) as timetable_name, 
                   cl.dept as department, cl.year, cl.batch_start as section,
                   ts.day, ts.period
            FROM summary_course_details scd
            JOIN summary_table st ON scd.summary_id = st.id
            LEFT JOIN classes cl ON st.timetable_id = cl.id
            JOIN t1 t ON (st.template_id = t.template_id)
            LEFT JOIN timetable_slots ts ON (ts.timetable_id = st.timetable_id AND ts.course_id = scd.course_id AND ts.staff_id = scd.staff_id)
            WHERE st.template_id = ?
            AND scd.staff_id = ?
            AND scd.is_lab = 0
            GROUP BY scd.course_id, st.timetable_id, ts.day, ts.period
            ORDER BY st.created_at DESC
        ");
        $stmt->bind_param("ii", $this->template_id, $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    // Calculate total periods from template
    public function calculateTotalPeriods($template) {
        $days = $this->getDaysBetween($template['week_start'], $template['week_end']);
        $periods_data = json_decode($template['periods_data'], true);
        return count($periods_data) * count($days);
    }

    // Helper function to get days between week_start and week_end
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

    // Check if staff-template combination exists
    public function staffTemplateExists($staff_id, $template_id) {
        $stmt = $this->conn->prepare("
            SELECT id FROM staff_availability 
            WHERE staff_id = ? AND template_id = ?
        ");
        $stmt->bind_param("ii", $staff_id, $template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0 ? $result->fetch_assoc()['id'] : false;
    }

    // Update staff availability
    public function updateStaffAvailability($staff_id, $template_id, $template_name, $staff_name, $busy_periods, $remaining_periods) {
        $staff_template = "$staff_name - $template_name";
        $busy_periods_json = json_encode($busy_periods);

        $existing_id = $this->staffTemplateExists($staff_id, $template_id);

        if ($existing_id) {
            $stmt = $this->conn->prepare("
                UPDATE staff_availability 
                SET busy_periods = ?, 
                    remaining_periods = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->bind_param("sii", $busy_periods_json, $remaining_periods, $existing_id);
        } else {
            $stmt = $this->conn->prepare("
                INSERT INTO staff_availability 
                (staff_template, staff_id, template_id, busy_periods, remaining_periods) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("siisi", $staff_template, $staff_id, $template_id, $busy_periods_json, $remaining_periods);
        }

        return $stmt->execute();
    }

    // Remove staff members who are no longer assigned to any courses in this template
    public function cleanUnassignedStaff() {
        $stmt = $this->conn->prepare("
            DELETE sa FROM staff_availability sa
            LEFT JOIN (
                SELECT DISTINCT staff_id 
                FROM course_assignments 
                WHERE template_id = ?
            ) AS assigned_staff ON sa.staff_id = assigned_staff.staff_id
            WHERE sa.template_id = ? 
            AND assigned_staff.staff_id IS NULL
        ");
        $stmt->bind_param("ii", $this->template_id, $this->template_id);
        return $stmt->execute();
    }

    // Get all staff availability data for display
    public function getAllStaffAvailability() {
        $this->cleanUnassignedStaff();
        
        $stmt = $this->conn->prepare("
            SELECT sa.*, s.name as staff_name, t.name as template_name
            FROM staff_availability sa
            JOIN staff s ON sa.staff_id = s.id
            JOIN templates t ON sa.template_id = t.id
            WHERE sa.template_id = ?
        ");
        $stmt->bind_param("i", $this->template_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    // Format timetable name
    private function formatTimetableName($timetable) {
        if (!$timetable) {
            return 'Unknown Timetable';
        }
        
        $department = isset($timetable['department']) ? $timetable['department'] : '';
        $section = isset($timetable['section']) ? $timetable['section'] : '';
        $year = isset($timetable['year']) ? $timetable['year'] : '';
        $id = isset($timetable['timetable_id']) ? $timetable['timetable_id'] : 
              (isset($timetable['id']) ? $timetable['id'] : 'Unknown');
        
        if (isset($timetable['timetable_name']) && !empty($timetable['timetable_name'])) {
            return $timetable['timetable_name'] . ' (#' . $id . ')';
        }
        
        if (empty($department) && empty($section) && empty($year)) {
            return 'Timetable #' . $id;
        }
        
        return $department . '-' . $section . '-' . $year . ' (#' . $id . ')';
    }

    // Process staff availability
    public function processStaffAvailability() {
        try {
            $this->createStaffAvailabilityTable();
            $this->createTimetableSlotsTable();
            $template = $this->getTemplateDetails();
            $staff_list = $this->getAssignedStaff();
            $total_template_periods = $this->calculateTotalPeriods($template);

            foreach ($staff_list as $staff) {
                $staff_id = $staff['id'];
                $staff_name = $staff['name'];

                // Get lab assignments (fixed busy periods) across all timetables
                $lab_assignments = $this->getLabAssignments($staff_id);
                $lab_busy_periods = [];

                foreach ($lab_assignments as $lab) {
                    if (!empty($lab['lab_day']) && !empty($lab['lab_periods'])) {
                        $lab_periods = json_decode($lab['lab_periods'], true);
                        $timetable_name = $this->formatTimetableName($lab);
                        
                        $lab_busy_periods[] = [
                            'timetable_id' => $lab['timetable_id'],
                            'timetable_name' => $timetable_name,
                            'day' => $lab['lab_day'],
                            'periods' => $lab_periods,
                            'course_id' => $lab['course_id'],
                            'course_name' => $lab['course_name'],
                            'type' => 'lab'
                        ];
                    }
                }

                // Get regular assignments (potential busy periods) across all timetables
                $regular_assignments = $this->getRegularAssignments($staff_id);
                $regular_busy_periods = [];
                $total_assigned_periods = 0;

                // Group regular assignments by course and timetable
                $grouped_regular = [];
                foreach ($regular_assignments as $course) {
                    $key = $course['course_id'] . '-' . $course['timetable_id'];
                    if (!isset($grouped_regular[$key])) {
                        $timetable_name = $this->formatTimetableName($course);
                        
                        $grouped_regular[$key] = [
                            'timetable_id' => $course['timetable_id'],
                            'timetable_name' => $timetable_name,
                            'course_id' => $course['course_id'],
                            'course_name' => $course['course_name'],
                            'periods' => (int)$course['periods'],
                            'type' => 'regular',
                            'days_periods' => []
                        ];
                    }
                    
                    // Add day and period info if available
                    if (!empty($course['day']) && !empty($course['period'])) {
                        $grouped_regular[$key]['days_periods'][] = [
                            'day' => $course['day'],
                            'period' => $course['period']
                        ];
                    }
                }

                // Convert grouped regular assignments to final format
                foreach ($grouped_regular as $course) {
                    $total_assigned_periods += $course['periods'];
                    
                    // If we have day/period info, include it
                    if (!empty($course['days_periods'])) {
                        $course['periods_info'] = $course['days_periods'];
                    }
                    
                    $regular_busy_periods[] = $course;
                }

                // Combine all busy periods
                $busy_periods = array_merge($lab_busy_periods, $regular_busy_periods);
                $remaining_periods = $total_template_periods - ($this->countBusyPeriods($busy_periods));

                // Update database
                $this->updateStaffAvailability(
                    $staff_id,
                    $this->template_id,
                    $template['name'],
                    $staff_name,
                    $busy_periods,
                    $remaining_periods
                );
            }

            // Clean up any staff who are no longer assigned to courses
            $this->cleanUnassignedStaff();

            return true;
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw $e;
        }
    }

    // Count actual busy periods from both lab and regular assignments
    private function countBusyPeriods($busy_periods) {
        $count = 0;
        foreach ($busy_periods as $period) {
            if ($period['type'] === 'lab' && isset($period['periods']) && is_array($period['periods'])) {
                $count += count($period['periods']);
            } elseif (isset($period['periods']) && is_numeric($period['periods'])) {
                $count += (int)$period['periods'];
            }
        }
        return $count;
    }

    // Group busy periods by timetable
    private function groupBusyPeriodsByTimetable($busy_periods) {
        $grouped = [];
        
        foreach ($busy_periods as $period) {
            if (!isset($period['timetable_id'])) {
                continue;
            }
            
            $timetable_id = $period['timetable_id'];
            $timetable_name = isset($period['timetable_name']) ? $period['timetable_name'] : 'Timetable #' . $timetable_id;
            
            if (!isset($grouped[$timetable_id])) {
                $grouped[$timetable_id] = [
                    'timetable_name' => $timetable_name,
                    'periods' => []
                ];
            }
            
            $grouped[$timetable_id]['periods'][] = $period;
        }
        
        return $grouped;
    }

    // Display staff availability
    public function displayStaffAvailability() {
        try {
            $this->processStaffAvailability();
            $availability_data = $this->getAllStaffAvailability();
            $template = $this->getTemplateDetails();
            
            $total_template_periods = $this->calculateTotalPeriods($template);

            ob_start();
            ?>
            <div class="staff-availability">
                <h2>Staff Availability for Template: <?php echo htmlspecialchars($template['name']); ?></h2>
                <p>Total possible periods in template: <?php echo $total_template_periods; ?></p>
                
                <table class="availability-table">
                    <thead>
                        <tr>
                            <th>Staff Member</th>
                            <th>Busy Periods</th>
                            <th>Available Periods</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($availability_data as $staff): ?>
                            <?php 
                                $busy_periods = json_decode($staff['busy_periods'], true) ?: [];
                                $grouped_timetables = $this->groupBusyPeriodsByTimetable($busy_periods);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($staff['staff_name']); ?></td>
                                <td><?php echo count($busy_periods) > 0 ? $this->countBusyPeriods($busy_periods) : 0; ?></td>
                                <td><?php echo $staff['remaining_periods']; ?></td>
                                <td>
                                    <?php foreach ($grouped_timetables as $timetable_id => $timetable): ?>
                                        <div class="timetable-entry">
                                            <?php echo htmlspecialchars($timetable['timetable_name']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <button class="details-btn" onclick="showDetails('<?php echo $staff['id']; ?>')">
                                        View Details
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="5" class="details-panel" id="details-<?php echo $staff['id']; ?>" style="display: none;">
                                    <div class="details-content">
                                        <h3>Busy Periods for <?php echo htmlspecialchars($staff['staff_name']); ?></h3>
                                        <?php foreach ($grouped_timetables as $timetable_id => $timetable): ?>
                                            <div class="timetable-details">
                                                <h4><?php echo htmlspecialchars($timetable['timetable_name']); ?></h4>
                                                <table class="inner-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Course</th>
                                                            <th>Type</th>
                                                            <th>Day/Period</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($timetable['periods'] as $period): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($period['course_name']); ?></td>
                                                                <td><?php echo ucfirst($period['type']); ?></td>
                                                                <td>
                                                                    <?php if ($period['type'] === 'lab' && isset($period['day'])): ?>
                                                                        <?php echo htmlspecialchars($period['day']); ?> - 
                                                                        <?php 
                                                                            $period_keys = array_keys($period['periods']);
                                                                            echo implode(', ', $period_keys);
                                                                        ?>
                                                                    <?php elseif (isset($period['days_periods']) && !empty($period['days_periods'])): ?>
                                                                        <?php foreach ($period['days_periods'] as $idx => $day_period): ?>
                                                                            <?php echo htmlspecialchars($day_period['day'] . ' - ' . $day_period['period']); ?>
                                                                            <?php if ($idx < count($period['days_periods']) - 1) echo ', '; ?>
                                                                        <?php endforeach; ?>
                                                                    <?php else: ?>
                                                                        Not yet assigned (<?php echo $period['periods']; ?> periods needed)
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
                function showDetails(staffId) {
                    const panel = document.getElementById(`details-${staffId}`);
                    if (panel.style.display === "none") {
                        panel.style.display = "block";
                    } else {
                        panel.style.display = "none";
                    }
                }
            </script>
            <?php
            return ob_get_clean();
        } catch (Exception $e) {
            error_log($e->getMessage());
            return "<div class='error'>Error displaying staff availability: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Main processing
try {
    // Database connection
    $db = DatabaseConfig::getInstance();
    $conn = $db->getConnection();

    // Create manager instance
    $manager = new StaffAvailabilityManager($conn, $template_id);

    // Store current URL parameters for next page (Generate Timetable)
    if (isset($_GET['timetable_id'])) {
        $_SESSION['timetable_id'] = $_GET['timetable_id'];
    }

    // If timetable_id is not set or is for selection only, show selection form
    if (!isset($_GET['timetable_id'])) {
        // Get all timetable IDs associated with this template for selection
        $timetables = $manager->getAssociatedTimetableIds();
    }

    // Get template details for display
    $template = $manager->getTemplateDetails();

    // Title based on whether a timetable is selected
    $pageTitle = isset($_GET['timetable_id']) ? 
        "Staff Availability - " . $_GET['timetable_id'] : 
        "Select Timetable for Staff Availability";

} catch (Exception $e) {
    error_log($e->getMessage());
    $error = "An error occurred: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
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
    </style>
    <style>
        .availability-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            margin-bottom: 20px;
        }
        
        .availability-table th, 
        .availability-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .availability-table th {
            background-color: #4CAF50;
            color: white;
        }
        
        .availability-table tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        
        .availability-table tr:hover {
            background-color: #ddd;
        }
        
        .timetable-entry {
            margin-bottom: 5px;
            padding: 3px;
            background-color: #e9f7ef;
            border-radius: 3px;
            display: inline-block;
            margin-right: 5px;
        }
        
        .details-btn {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 3px;
        }
        
        .details-panel {
            display: none;
            background-color: #f9f9f9;
            padding: 15px;
        }
        
        .details-content {
            margin-left: 20px;
        }
        
        .timetable-details {
            margin-bottom: 20px;
        }
        
        .inner-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .inner-table th, 
        .inner-table td {
            border: 1px solid #ccc;
            padding: 5px;
            text-align: left;
        }
        
        .inner-table th {
            background-color: #e0e0e0;
        }
        
        .error {
            color: red;
            font-weight: bold;
            padding: 10px;
            margin: 10px 0;
            background-color: #ffe6e6;
            border: 1px solid #ff9999;
            border-radius: 5px;
        }
        
        /* Timetable selection styles */
        .timetable-selection {
            margin: 20px 0;
        }
        
        .timetable-card {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .timetable-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .timetable-card h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .timetable-card .btn {
            margin-top: 10px;
        }
        
        /* Progress steps */
        .steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        
        .steps::before {
            content: '';
            position: absolute;
            top: 14px;
            left: 0;
            right: 0;
            height: 2px;
            background: #ddd;
            z-index: 0;
        }
        
        .step {
            background: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
            border: 2px solid #ddd;
            font-weight: bold;
        }
        
        .step.active {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        
        .step.completed {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        
        .step-label {
            position: absolute;
            top: 35px;
            width: 120px;
            text-align: center;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="title">Autotime - Staff Availability</div>
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
        <div class="steps">
            <div class="step completed">
                1
                <div class="step-label">Course Assignment</div>
            </div>
            <div class="step completed">
                2
                <div class="step-label">Timetable Summary</div>
            </div>
            <div class="step active">
                3
                <div class="step-label">Staff Availability</div>
            </div>
            <div class="step">
                4
                <div class="step-label">Generate Timetable</div>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <h1><?php echo $pageTitle; ?></h1>
        <p>Template: <?php echo htmlspecialchars($template['name']); ?></p>
        
        <?php if (!isset($_GET['timetable_id']) && isset($timetables) && !empty($timetables)): ?>
            <!-- Timetable Selection -->
            <div class="timetable-selection">
                <h2>Select a Timetable to View Staff Availability</h2>
                <div class="row">
                    <?php foreach ($timetables as $timetable_data): ?>
                        <?php 
                            $timetable_id = $timetable_data['timetable_id'];
                            $timetable = $manager->getTimetableDetails($timetable_id);
                        ?>
                        <div class="col-md-4">
                            <div class="timetable-card">
                                <h3><?php echo htmlspecialchars($timetable['name']); ?></h3>
                                <p>
                                    <strong>Department:</strong> <?php echo htmlspecialchars($timetable['department']); ?><br>
                                    <strong>Year:</strong> <?php echo htmlspecialchars($timetable['year']); ?><br>
                                    <strong>Section:</strong> <?php echo htmlspecialchars($timetable['section']); ?>
                                </p>
                                <a href="?timetable_id=<?php echo urlencode($timetable_id); ?>&template_id=<?php echo urlencode($template_id); ?>" class="btn btn-primary">Select</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif (isset($_GET['timetable_id'])): ?>
            <!-- Staff Availability Display -->
            <?php echo $manager->displayStaffAvailability(); ?>
            
            <div class="actions">
                <a href="next_22.php" class="btn btn-secondary">Back to Timetable Summary</a>
                <a href="next_44.php" class="btn btn-primary">Proceed to Generate Timetable</a>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                No timetables found for this template. Please <a href="next_11.php">assign courses to staff</a> first.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>