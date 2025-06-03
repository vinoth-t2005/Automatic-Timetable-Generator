<?php
// Combined Course/Lab Assignment and Timetable Summary Generator

// Error reporting and logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/error.log');

// Secure session start
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Database configuration with enhanced security
class DatabaseConfig {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $config = [
            'host' => 'localhost',
            'user' => 'root',
            'password' => '',
            'database' => 'autotime',
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

class TimetableSummaryGenerator {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    // Enhanced input validation
    private function validateTimetableId($timetable_id) {
        if (empty($timetable_id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $timetable_id)) {
            throw new Exception("Invalid Timetable ID");
        }
    }

    // Improved period allocation method - now excludes lab courses from allocation
    private function allocatePeriods($courses, $total_periods) {
        // Separate lab and non-lab courses
        $non_lab_courses = array_filter($courses, function($course) {
            return !$course['is_lab'];
        });

        // Get total lab periods (these are fixed and won't be changed)
        $lab_periods = array_sum(array_column(
            array_filter($courses, function($course) { 
                return $course['is_lab']; 
            }), 
            'lab_periods'
        ));

        // Calculate available periods for non-lab courses
        $available_periods = $total_periods - $lab_periods;

        // Sort non-lab courses by credits in descending order
        usort($non_lab_courses, function($a, $b) {
            return $b['credits'] - $a['credits'];
        });

        // Allocate periods based on credits with weight
        $weighted_allocation = [];
        $total_weighted_credits = 0;

        // Calculate weighted credits (heavier weight for higher credits)
        foreach ($non_lab_courses as $course) {
            $weighted_credit = $course['credits'] * $course['credits'];
            $weighted_allocation[] = [
                'course' => $course,
                'weighted_credit' => $weighted_credit
            ];
            $total_weighted_credits += $weighted_credit;
        }

        // Allocate periods
        $remaining_periods = $available_periods;
        foreach ($weighted_allocation as &$allocation) {
            // Calculate periods proportional to weighted credits
            $allocation['periods'] = floor(
                ($allocation['weighted_credit'] / $total_weighted_credits) * $available_periods
            );
            $remaining_periods -= $allocation['periods'];
        }

        // Distribute any remaining periods
        $i = 0;
        while ($remaining_periods > 0) {
            $weighted_allocation[$i % count($weighted_allocation)]['periods']++;
            $remaining_periods--;
            $i++;
        }

        // Update original courses array with allocated periods (only for non-lab courses)
        $updated_courses = [];
        foreach ($courses as $course) {
            if (!$course['is_lab']) {
                // Find corresponding allocated periods
                $matching_allocation = array_filter($weighted_allocation, function($allocation) use ($course) {
                    return $allocation['course']['course_id'] === $course['course_id'];
                });
                
                if (!empty($matching_allocation)) {
                    $course['periods'] = reset($matching_allocation)['periods'];
                }
            }
            $updated_courses[] = $course;
        }

        return $updated_courses;
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

    // Check if summary exists for timetable
    private function summaryExists($timetable_id) {
        $stmt = $this->conn->prepare("
            SELECT id FROM summary_table 
            WHERE timetable_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("s", $timetable_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0 ? $result->fetch_assoc()['id'] : false;
    }

    // Update course assignments with new periods (only for non-lab courses)
    private function updateCourseAssignments($timetable_id, $courses) {
        try {
            $this->conn->begin_transaction();

            foreach ($courses as $course) {
                if (!$course['is_lab']) {
                    // Only update periods for non-lab courses
                    $stmt = $this->conn->prepare("
                        UPDATE course_assignments 
                        SET periods = ? 
                        WHERE timetable_id = ? AND course_id = ? AND id = ?
                    ");
                    $stmt->bind_param("isii", $course['periods'], $timetable_id, $course['course_id'], $course['assignment_id']);
                    $stmt->execute();
                }
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log($e->getMessage());
            throw new Exception("Failed to update course assignments: " . $e->getMessage());
        }
    }

    // Main function to generate timetable summary
    public function generateTimetableSummary($timetable_id) {
        try {
            // Validate input
            $this->validateTimetableId($timetable_id);

            // Prepare statements to prevent SQL injection
            $template_query = "SELECT t.* FROM t1 
                              JOIN templates t ON t1.template_id = t.id 
                              WHERE t1.timetable_id = ?";
            $stmt = $this->conn->prepare($template_query);
            $stmt->bind_param("s", $timetable_id);
            $stmt->execute();
            $template = $stmt->get_result()->fetch_assoc();
            
            if (!$template) {
                throw new Exception("Template not found for this timetable");
            }
            
            // Calculate total periods
            $days = $this->getDaysBetween($template['week_start'], $template['week_end']);
            $periods_data = json_decode($template['periods_data'], true);
            $total_periods = count($periods_data) * count($days);
            
            // Get course assignments (grouped by course_id to prevent duplicates)
            $assignments_query = "SELECT ca.*, c.name as course_name, c.credits, 
                                 s.name as staff_name 
                                 FROM course_assignments ca
                                 JOIN courses c ON ca.course_id = c.id
                                 JOIN staff s ON ca.staff_id = s.id
                                 WHERE ca.timetable_id = ?
                                 GROUP BY ca.course_id, ca.is_lab";
            $stmt = $this->conn->prepare($assignments_query);
            $stmt->bind_param("s", $timetable_id);
            $stmt->execute();
            $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Process assignments
            $total_lab_periods = 0;
            $courses = [];
            
            foreach ($assignments as $assignment) {
                $lab_periods = $assignment['is_lab'] ? 
                              count(json_decode($assignment['lab_periods'] ?? '[]', true)) : 0;
                $total_lab_periods += $lab_periods;
                
                $courses[] = [
                    'course_id' => $assignment['course_id'],
                    'course_name' => $assignment['course_name'],
                    'credits' => $assignment['credits'],
                    'periods' => $assignment['is_lab'] ? 0 : $assignment['periods'],
                    'staff_id' => $assignment['staff_id'],
                    'staff_name' => $assignment['staff_name'],
                    'is_lab' => $assignment['is_lab'],
                    'lab_periods' => $lab_periods,
                    'lab_day' => $assignment['lab_day'] ?? null,
                    'assignment_id' => $assignment['id']
                ];
            }
            
            // Allocate periods intelligently (only affects non-lab courses)
            $courses = $this->allocatePeriods($courses, $total_periods);

            // Recalculate totals
            $total_lab_periods = array_sum(array_column(
                array_filter($courses, function($course) { 
                    return $course['is_lab']; 
                }), 
                'lab_periods'
            ));
            $total_non_lab_periods = array_sum(array_column(
                array_filter($courses, function($course) { 
                    return !$course['is_lab']; 
                }), 
                'periods'
            ));
            
            // Calculate remainder
            $remainder = $total_periods - ($total_lab_periods + $total_non_lab_periods);
            
            // Prepare return data
            return [
                'summary' => [
                    'total_periods' => $total_periods,
                    'total_lab_periods' => $total_lab_periods,
                    'total_non_lab_periods' => $total_non_lab_periods,
                    'remainder' => $remainder,
                    'timetable_id' => $timetable_id,
                    'template_id' => $template['id']
                ],
                'courses' => $courses
            ];
            
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw $e;
        }
    }

    // Save summary to database
    public function saveSummary($summary_data) {
        try {
            // Begin transaction
            $this->conn->begin_transaction();

            // Check if summary exists
            $summary_id = $this->summaryExists($summary_data['summary']['timetable_id']);

            if ($summary_id) {
                // Update existing summary
                $stmt = $this->conn->prepare("
                    UPDATE summary_table 
                    SET template_id = ?, 
                        total_periods = ?, 
                        total_lab_periods = ?, 
                        remainder_periods = ?,
                        created_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    "iiiii", 
                    $summary_data['summary']['template_id'],
                    $summary_data['summary']['total_periods'],
                    $summary_data['summary']['total_lab_periods'],
                    $summary_data['summary']['remainder'],
                    $summary_id
                );
                $stmt->execute();

                // Delete old course details
                $stmt = $this->conn->prepare("
                    DELETE FROM summary_course_details 
                    WHERE summary_id = ?
                ");
                $stmt->bind_param("i", $summary_id);
                $stmt->execute();
            } else {
                // Insert new summary
                $stmt = $this->conn->prepare("
                    INSERT INTO summary_table 
                    (timetable_id, template_id, total_periods, total_lab_periods, remainder_periods) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "siiii", 
                    $summary_data['summary']['timetable_id'],
                    $summary_data['summary']['template_id'],
                    $summary_data['summary']['total_periods'],
                    $summary_data['summary']['total_lab_periods'],
                    $summary_data['summary']['remainder']
                );
                $stmt->execute();
                $summary_id = $this->conn->insert_id;
            }

            // Insert course details
            $stmt = $this->conn->prepare("
                INSERT INTO summary_course_details 
                (summary_id, course_id, course_name, credits, periods_allotted, staff_id, staff_name, is_lab) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($summary_data['courses'] as $course) {
                $periods = $course['is_lab'] ? $course['lab_periods'] : $course['periods'];
                $stmt->bind_param(
                    "iisiiisi", 
                    $summary_id,
                    $course['course_id'],
                    $course['course_name'],
                    $course['credits'],
                    $periods,
                    $course['staff_id'],
                    $course['staff_name'],
                    $course['is_lab']
                );
                $stmt->execute();
            }

            // Update course assignments (only for non-lab courses)
            $this->updateCourseAssignments(
                $summary_data['summary']['timetable_id'],
                $summary_data['courses']
            );

            // Commit transaction
            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log($e->getMessage());
            throw new Exception("Failed to save summary: " . $e->getMessage());
        }
    }

    // Load summary from database
    public function loadSummary($timetable_id) {
        try {
            $this->validateTimetableId($timetable_id);

            // Get the latest summary
            $stmt = $this->conn->prepare("
                SELECT * FROM summary_table 
                WHERE timetable_id = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->bind_param("s", $timetable_id);
            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc();

            if (!$summary) {
                return null;
            }

            // Get course details
            $stmt = $this->conn->prepare("
                SELECT * FROM summary_course_details 
                WHERE summary_id = ?
            ");
            $stmt->bind_param("i", $summary['id']);
            $stmt->execute();
            $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            return [
                'summary' => [
                    'total_periods' => $summary['total_periods'],
                    'total_lab_periods' => $summary['total_lab_periods'],
                    'total_non_lab_periods' => $summary['total_periods'] - $summary['total_lab_periods'] - $summary['remainder_periods'],
                    'remainder' => $summary['remainder_periods'],
                    'timetable_id' => $summary['timetable_id'],
                    'template_id' => $summary['template_id']
                ],
                'courses' => array_map(function($course) {
                    return [
                        'course_id' => $course['course_id'],
                        'course_name' => $course['course_name'],
                        'credits' => $course['credits'],
                        'periods' => $course['is_lab'] ? 0 : $course['periods_allotted'],
                        'staff_id' => $course['staff_id'],
                        'staff_name' => $course['staff_name'],
                        'is_lab' => $course['is_lab'],
                        'lab_periods' => $course['is_lab'] ? $course['periods_allotted'] : 0,
                        'lab_day' => $course['lab_day'] ?? null
                    ];
                }, $courses)
            ];
        } catch (Exception $e) {
            error_log($e->getMessage());
            throw $e;
        }
    }

    // Generate HTML summary with editable periods (only for non-lab courses)
    public function displaySummary($summary_data) {
        // Sanitize and validate input
        if (!isset($summary_data['summary']) || !isset($summary_data['courses'])) {
            throw new Exception("Invalid summary data");
        }

        // Sort courses by credits and periods
        usort($summary_data['courses'], function($a, $b) {
            $credit_diff = $b['credits'] - $a['credits'];
            if ($credit_diff !== 0) return $credit_diff;
            return $b['periods'] - $a['periods'];
        });

        // Start output buffering for safer HTML generation
        ob_start();
        ?>
        <div class="timetable-summary">
            <h2>Timetable Summary</h2>
            
            <div class="summary-stats">
                <p><strong>Total Periods:</strong> <span id="total-periods"><?php echo htmlspecialchars($summary_data['summary']['total_periods']); ?></span></p>
                <p><strong>Lab Periods:</strong> <span id="total-lab-periods"><?php echo htmlspecialchars($summary_data['summary']['total_lab_periods']); ?></span></p>
                <p><strong>Lecture Periods:</strong> <span id="total-lecture-periods"><?php echo htmlspecialchars($summary_data['summary']['total_non_lab_periods']); ?></span></p>
                <p><strong>Remaining Periods:</strong> <span id="remaining-periods"><?php echo htmlspecialchars($summary_data['summary']['remainder']); ?></span></p>
            </div>
            
            <form id="summary-form" method="POST" action="">
                <input type="hidden" name="save_summary" value="1">
                <input type="hidden" name="timetable_id" value="<?php echo htmlspecialchars($summary_data['summary']['timetable_id']); ?>">
                
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Credits</th>
                            <th>Periods Allocated</th>
                            <th>Staff</th>
                            <th>Type</th>
                            <th>Lab Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $displayed_courses = [];
                        foreach ($summary_data['courses'] as $course): 
                            // Skip duplicate courses
                            $course_key = $course['course_id'] . '_' . ($course['is_lab'] ? 'lab' : 'lecture');
                            if (in_array($course_key, $displayed_courses)) continue;
                            $displayed_courses[] = $course_key;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($course['course_name']); ?>
                                    <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][course_id]" value="<?php echo $course['course_id']; ?>">
                                    <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][course_name]" value="<?php echo htmlspecialchars($course['course_name']); ?>">
                                    <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][credits]" value="<?php echo $course['credits']; ?>">
                                    <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][staff_id]" value="<?php echo $course['staff_id']; ?>">
                                    <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][staff_name]" value="<?php echo htmlspecialchars($course['staff_name']); ?>">
                                    <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][is_lab]" value="<?php echo $course['is_lab'] ? 1 : 0; ?>">
                                    <?php if ($course['is_lab']): ?>
                                        <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][lab_day]" value="<?php echo htmlspecialchars($course['lab_day'] ?? ''); ?>">
                                        <input type="hidden" name="courses[<?php echo $course['course_id']; ?>][lab_periods]" value="<?php echo $course['lab_periods']; ?>">
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($course['credits']); ?></td>
                                <td>
                                    <?php if ($course['is_lab']): ?>
                                        <?php echo htmlspecialchars($course['lab_periods']); ?>
                                    <?php else: ?>
                                        <input type="number" min="0" class="period-input" 
                                               name="courses[<?php echo $course['course_id']; ?>][periods]" 
                                               value="<?php echo $course['periods']; ?>"
                                               data-original-value="<?php echo $course['periods']; ?>"
                                               onchange="updateRemainingPeriods()">
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($course['staff_name']); ?></td>
                                <td><?php echo $course['is_lab'] ? 'Lab' : 'Lecture'; ?></td>
                                <td>
                                    <?php if ($course['is_lab'] && !empty($course['lab_day'])): ?>
                                        Day: <?php echo htmlspecialchars($course['lab_day']); ?><br>
                                        Periods: 
                                        <?php 
                                        if ($course['lab_periods'] > 0) {
                                            $period_numbers = range(1, $course['lab_periods']);
                                            $period_strings = array_map(function($num) {
                                                return "P$num";
                                            }, $period_numbers);
                                            echo implode(', ', $period_strings);
                                        } else {
                                            echo 'Not assigned';
                                        }
                                        ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div class="form-actions" style="margin-top: 20px;">
                    <button type="button" onclick="resetPeriods()" class="reset-btn">Reset to Original</button>
                    <button type="submit" class="save-btn">Save Changes</button>
                </div>
            </form>
        </div>

        <script>
            function updateRemainingPeriods() {
                const totalPeriods = parseInt(document.getElementById('total-periods').textContent);
                const totalLabPeriods = parseInt(document.getElementById('total-lab-periods').textContent);
                
                // Calculate total lecture periods from inputs (only non-lab courses)
                let totalLecturePeriods = 0;
                const periodInputs = document.querySelectorAll('.period-input');
                periodInputs.forEach(input => {
                    totalLecturePeriods += parseInt(input.value) || 0;
                });
                
                // Update display
                document.getElementById('total-lecture-periods').textContent = totalLecturePeriods;
                const remaining = totalPeriods - (totalLabPeriods + totalLecturePeriods);
                document.getElementById('remaining-periods').textContent = remaining;
                
                // Highlight if negative
                const remainingElement = document.getElementById('remaining-periods');
                if (remaining < 0) {
                    remainingElement.style.color = 'red';
                    remainingElement.style.fontWeight = 'bold';
                } else {
                    remainingElement.style.color = '';
                    remainingElement.style.fontWeight = '';
                }
            }
            
            function resetPeriods() {
                const periodInputs = document.querySelectorAll('.period-input');
                periodInputs.forEach(input => {
                    input.value = input.dataset.originalValue;
                });
                updateRemainingPeriods();
            }
            
            // Initialize on page load
            document.addEventListener('DOMContentLoaded', updateRemainingPeriods);
        </script>
        <?php
        return ob_get_clean();
    }
}

// Main processing
try {
    // Database connection
    $db = DatabaseConfig::getInstance();
    $conn = $db->getConnection();

    // Create all necessary tables if they don't exist
    $create_tables = [
        "CREATE TABLE IF NOT EXISTS course_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            staff_id INT NOT NULL,
            periods VARCHAR(20) NOT NULL,
            is_lab BOOLEAN DEFAULT FALSE,
            lab_day VARCHAR(20) DEFAULT NULL,
            lab_periods TEXT DEFAULT NULL,
            timetable_id VARCHAR(50) DEFAULT NULL,  
            template_id INT DEFAULT NULL,  
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS summary_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            timetable_id VARCHAR(255) NOT NULL,
            template_id INT NOT NULL,
            total_periods INT NOT NULL,
            total_lab_periods INT NOT NULL,
            remainder_periods INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (timetable_id)
        )",
        
        "CREATE TABLE IF NOT EXISTS summary_course_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            summary_id INT NOT NULL,
            course_id INT NOT NULL,
            course_name VARCHAR(255) NOT NULL,
            credits INT NOT NULL,
            periods_allotted INT NOT NULL,
            staff_id INT NOT NULL,
            staff_name VARCHAR(255) NOT NULL,
            is_lab BOOLEAN DEFAULT FALSE,
            FOREIGN KEY (summary_id) REFERENCES summary_table(id) ON DELETE CASCADE,
            INDEX (summary_id, course_id)
        )"
    ];

    foreach ($create_tables as $query) {
        if (!$conn->query($query)) {
            throw new Exception("Error creating table: " . $conn->error);
        }
    }

    // Check for missing columns in course_assignments and add them if needed
    $check_columns = [
        ['name' => 'lab_day', 'type' => 'VARCHAR(20) DEFAULT NULL'],
        ['name' => 'lab_periods', 'type' => 'TEXT DEFAULT NULL'],
        ['name' => 'template_id', 'type' => 'INT DEFAULT NULL']
    ];

    foreach ($check_columns as $column) {
        $result = $conn->query("SHOW COLUMNS FROM course_assignments LIKE '{$column['name']}'");
        if ($result->num_rows == 0) {
            $conn->query("ALTER TABLE course_assignments ADD COLUMN {$column['name']} {$column['type']}");
        }
    }

    // Initialize variables
    $departments = [];
    $allCourses = [];
    $allStaff = [];
    $allTemplates = [];
    $existingAssignments = [];
    $successMessage = null;
    $error_message = null;
    $summary_result = null;

    // Fetch common data
    $result = $conn->query("SELECT DISTINCT dept FROM courses");
    $departments = $result->fetch_all(MYSQLI_ASSOC);

    $result = $conn->query("SELECT id, name, course_code, credits, dept, staff FROM courses");
    $allCourses = $result->fetch_all(MYSQLI_ASSOC);

    $result = $conn->query("SELECT id, name, unique_id, dept FROM staff");
    $allStaff = $result->fetch_all(MYSQLI_ASSOC);

    $result = $conn->query("SELECT * FROM templates");
    $allTemplates = $result->fetch_all(MYSQLI_ASSOC);

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check which form was submitted
        if (isset($_POST['action']) && $_POST['action'] === 'remove') {
            // Handle course assignment removal
            $assignment_id = $_POST['assignment_id'];
            
            $query = "DELETE FROM course_assignments WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $assignment_id);
            $stmt->execute();
            
            $successMessage = "Course assignment removed successfully!";
        } elseif (isset($_POST['save_summary'])) {
            // Handle summary save action
            $generator = new TimetableSummaryGenerator($conn);
            $timetable_id = $_POST['timetable_id'];
            $courses = $_POST['courses'];
            
            // Reconstruct the summary data from form inputs
            $summary_data = $generator->generateTimetableSummary($timetable_id);
            
            // Update periods with user-edited values (only for non-lab courses)
            foreach ($summary_data['courses'] as &$course) {
                $course_id = $course['course_id'];
                if (isset($courses[$course_id])) {
                    if (!$course['is_lab']) {
                        $course['periods'] = (int)$courses[$course_id]['periods'];
                    }
                    // Lab courses keep their original values
                }
            }
            
            // Recalculate totals
            $summary_data['summary']['total_lab_periods'] = array_sum(array_column(
                array_filter($summary_data['courses'], function($course) { 
                    return $course['is_lab']; 
                }), 
                'lab_periods'
            ));
            $summary_data['summary']['total_non_lab_periods'] = array_sum(array_column(
                array_filter($summary_data['courses'], function($course) { 
                    return !$course['is_lab']; 
                }), 
                'periods'
            ));
            $summary_data['summary']['remainder'] = $summary_data['summary']['total_periods'] - 
                ($summary_data['summary']['total_lab_periods'] + $summary_data['summary']['total_non_lab_periods']);
            
            // Save to database
            if ($generator->saveSummary($summary_data)) {
                $successMessage = "Summary saved successfully!";
            }
            
            // Display updated summary
            $summary_result = $generator->displaySummary($summary_data);
        } elseif (isset($_POST['timetable_id'])) {
            // Handle summary generation
            $generator = new TimetableSummaryGenerator($conn);
            $timetable_id = $_POST['timetable_id'];
            
            // Try to load existing summary first
            $summary_data = $generator->loadSummary($timetable_id);
            
            if (!$summary_data) {
                // Generate new summary if none exists
                $summary_data = $generator->generateTimetableSummary($timetable_id);
            }
            
            $summary_result = $generator->displaySummary($summary_data);
        } else {
            // Handle new course assignment
            $course_id = $_POST['course'];
            $staff_id = $_POST['staff'];
            $is_lab = isset($_POST['is_lab']) ? 1 : 0;
            $periods = $_POST['periods'] ?? 'auto';
            
            // Initialize lab-related variables
            $lab_day = null;
            $lab_periods_json = null;
            
            if ($is_lab) {
                // Get lab-specific data from form
                $lab_day = $_POST['lab_day'] ?? null;
                
                // Process selected lab periods
                $lab_periods = [];
                if (isset($_POST['lab_periods'])) {
                    foreach ($_POST['lab_periods'] as $period_id) {
                        $start_time = $_POST["period_start_{$period_id}"] ?? '';
                        $end_time = $_POST["period_end_{$period_id}"] ?? '';
                        if ($start_time && $end_time) {
                            $lab_periods[$period_id] = [
                                'start_time' => $start_time,
                                'end_time' => $end_time
                            ];
                        }
                    }
                }
                $lab_periods_json = !empty($lab_periods) ? json_encode($lab_periods) : null;
            }
            
            if ($is_lab) {
                // Lab assignment
                $query = "INSERT INTO course_assignments (
                    course_id, staff_id, periods, is_lab, lab_day, lab_periods, 
                    timetable_id, template_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param(
                    "iisissis", 
                    $course_id, 
                    $staff_id,
                    $periods,
                    $is_lab,
                    $lab_day,
                    $lab_periods_json,
                    $_SESSION['timetable_id'] ?? null,
                    $_POST['template'] ?? null
                );
                $stmt->execute();
            } else {
                // Regular course assignment
                $query = "INSERT INTO course_assignments (
                    course_id, staff_id, periods, is_lab, 
                    timetable_id, template_id
                ) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param(
                    "iisisi", 
                    $course_id, 
                    $staff_id,
                    $periods,
                    $is_lab,
                    $_SESSION['timetable_id'] ?? null,
                    $_POST['template'] ?? null
                );
                $stmt->execute();
            }
            
            // Get course and staff details for the success message
            $courseQuery = $conn->prepare("SELECT name, course_code, credits FROM courses WHERE id = ?");
            $courseQuery->bind_param("i", $course_id);
            $courseQuery->execute();
            $courseDetails = $courseQuery->get_result()->fetch_assoc();
            
            $staffQuery = $conn->prepare("SELECT name FROM staff WHERE id = ?");
            $staffQuery->bind_param("i", $staff_id);
            $staffQuery->execute();
            $staffDetails = $staffQuery->get_result()->fetch_assoc();
            
            // Set success message with details
            $successMessage = "Course '{$courseDetails['name']} ({$courseDetails['course_code']})' " . 
                             ($is_lab ? "lab " : "") . 
                             "assigned to {$staffDetails['name']} successfully!";
        }
    }

    // Fetch existing assignments
    $query = "
        SELECT ca.id, c.name AS course_name, c.course_code, c.credits, 
               s.name AS staff_name, ca.periods, ca.is_lab, 
               ca.lab_day, ca.lab_periods
        FROM course_assignments ca
        JOIN courses c ON ca.course_id = c.id
        JOIN staff s ON ca.staff_id = s.id
    ";
    
    // Add WHERE clause if timetable_id is set
    if (isset($_SESSION['timetable_id'])) {
        $query .= " WHERE ca.timetable_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $_SESSION['timetable_id']);
        $stmt->execute();
        $existingAssignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($query);
        $existingAssignments = $result->fetch_all(MYSQLI_ASSOC);
    }

} catch (Exception $e) {
    $error_message = "System error: " . $e->getMessage();
}

// Function to get days of the week
function getDaysOfWeek() {
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

// Function to generate sample periods if templates table doesn't exist
function getSamplePeriods() {
    $periods = [];
    for ($i = 1; $i <= 8; $i++) {
        $start_hour = 8 + floor(($i-1) / 2);
        $start_min = ($i % 2 == 1) ? "00" : "30";
        $end_hour = 8 + floor($i / 2);
        $end_min = ($i % 2 == 0) ? "00" : "30";
        if ($i == 8) $end_hour = 12;
        
        $periods[$i] = [
            'start_time' => sprintf("%02d:%s", $start_hour, $start_min),
            'end_time' => sprintf("%02d:%s", $end_hour, $end_min)
        ];
    }
    return $periods;
}

$templateDetails = null;
if (isset($_SESSION['template_id'])) {
    try {
        $templateQuery = $conn->prepare("SELECT * FROM templates WHERE id = ?");
        $templateQuery->bind_param("i", $_SESSION['template_id']);
        $templateQuery->execute();
        $templateDetails = $templateQuery->get_result()->fetch_assoc();
    } catch (Exception $e) {
        // Template not found, but continue with the page
        $templateDetails = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Management System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        .tabs {
            display: flex;
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 20px;
            background-color: #f1f1f1;
            border: 1px solid #ddd;
            cursor: pointer;
            margin-right: 5px;
        }
        .tab.active {
            background-color: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        
        /* Course Assignment Styles */
        .success {
            color: green;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        select:disabled, input:disabled {
            background-color: #f0f0f0;
            cursor: not-allowed;
        }
        #assignmentList {
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f0f0f0;
        }
        .period-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        .remove-btn {
            background-color: #ff4d4d;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        .remove-btn:hover {
            background-color: #ff3333;
        }
        .empty-table {
            text-align: center;
            padding: 15px;
            color: #666;
            font-style: italic;
        }
        .lab-section {
            border: 1px solid #ddd;
            padding: 15px;
            margin-top: 15px;
            display: none;
            background-color: #f9f9f9;
        }
        .lab-periods-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            margin-top: 5px;
        }
        .lab-period-item {
            padding: 5px;
            border-bottom: 1px solid #eee;
        }
        .lab-period-item:last-child {
            border-bottom: none;
        }
        .badge {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-lab {
            background-color: #3498db;
            color: white;
        }
        .badge-lecture {
            background-color: #2ecc71;
            color: white;
        }
        .timetable-info {
            background-color: #f4f4f4;
            border: 1px solid #ddd;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        
        /* Summary Generator Styles */
        .error {
            color: red;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid red;
            background-color: #ffeeee;
        }
        .success {
            color: green;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid green;
            background-color: #eeffee;
        }
        .timetable-summary {
            margin-top: 30px;
            border: 1px solid #ddd;
            padding: 20px;
            background-color: #f9f9f9;
        }
        .summary-stats {
            background-color: #e9f7ef;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .summary-table th, .summary-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .summary-table th {
            background-color: #f2f2f2;
        }
        .summary-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .period-input {
            width: 60px;
            padding: 5px;
            text-align: center;
        }
        .form-actions {
            text-align: right;
        }
        button {
            padding: 10px 15px;
            color: white;
            border: none;
            cursor: pointer;
            margin-right: 10px;
        }
        .generate-btn {
            background-color: #4CAF50;
        }
        .generate-btn:hover {
            background-color: #45a049;
        }
        .save-btn {
            background-color: #2196F3;
        }
        .save-btn:hover {
            background-color: #0b7dda;
        }
        .reset-btn {
            background-color: #ff9800;
        }
        .reset-btn:hover {
            background-color: #e68a00;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Timetable Management System</h1>
            <?php if (isset($_SESSION['timetable_id']) || $templateDetails): ?>
            <div class="timetable-info">
                <?php if (isset($_SESSION['timetable_id'])): ?>
                    <p><strong>Timetable ID:</strong> <?= htmlspecialchars($_SESSION['timetable_id']) ?></p>
                <?php endif; ?>
                
                <?php if ($templateDetails): ?>
                    <p><strong>Template:</strong> <?= htmlspecialchars($templateDetails['name']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="tabs">
            <div class="tab active" onclick="switchTab('course-assignment')">Course & Lab Assignment</div>
            <div class="tab" onclick="switchTab('summary-generator')">Timetable Summary Generator</div>
        </div>
        
        <?php if (isset($successMessage)): ?>
            <div class="success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        
        <!-- Course Assignment Tab -->
        <div id="course-assignment" class="tab-content active">
            <form id="courseForm" method="POST" action="">
                <div class="form-group">
                    <label for="dept">Department:</label>
                    <select name="dept" id="dept">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept['dept']) ?>">
                                <?= htmlspecialchars($dept['dept']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="course">Course:</label>
                    <select name="course" id="course" disabled>
                        <option value="">Select Course</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="staff">Staff:</label>
                    <select name="staff" id="staff" disabled>
                        <option value="">Select Staff</option>
                    </select>
                </div>
                
                <div class="form-group checkbox-label">
                    <input type="checkbox" id="is_lab" name="is_lab">
                    <label for="is_lab">This is a Lab</label>
                </div>
                
                <div class="form-group">
                    <label>Number of Periods in Week:</label>
                    <div class="period-group">
                        <input type="number" name="periods" id="periods" min="1" max="20" value="1" disabled>
                        <label class="checkbox-label">
                            <input type="checkbox" name="auto_periods" id="auto_periods" disabled>
                            Auto (System will determine based on credits)
                        </label>
                    </div>
                </div>
                
                <!-- Lab-specific fields (hidden by default) -->
                <div id="labSection" class="lab-section">
                    <h3>Lab Details</h3>
                    
                    <!-- Template selector - always display this first -->
                    <div class="form-group">
                        <label for="template">Template:</label>
                        <select name="template" id="template">
                            <option value="">Select Template</option>
                            <?php foreach ($allTemplates as $template): ?>
                                <option value="<?= htmlspecialchars($template['id']) ?>"><?= htmlspecialchars($template['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="lab_day">Lab Day:</label>
                        <select name="lab_day" id="lab_day" disabled>
                            <option value="">Select Template First</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Lab Periods:</label>
                        <div class="lab-periods-list" id="labPeriodsList">
                            <div class="empty-table">Select a template to view periods</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" id="addCourseBtn" disabled>Add Course</button>
                </div>
            </form>
            
            <div id="assignmentList">
                <h2>Added Course & Lab Assignments</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Staff</th>
                            <th>Credits</th>
                            <th>Type</th>
                            <th>Periods</th>
                            <th>Lab Details</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="assignmentTable">
                        <?php if (empty($existingAssignments)): ?>
                        <tr>
                            <td colspan="7" class="empty-table">No course assignments yet.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($existingAssignments as $assignment): ?>
                            <tr data-assignment-id="<?= htmlspecialchars($assignment['id']) ?>">
                                <td><?= htmlspecialchars($assignment['course_name']) ?> (<?= htmlspecialchars($assignment['course_code']) ?>)</td>
                                <td><?= htmlspecialchars($assignment['staff_name']) ?></td>
                                <td><?= htmlspecialchars($assignment['credits']) ?></td>
                                <td>
                                    <?php if ($assignment['is_lab']): ?>
                                        <span class="badge badge-lab">Lab</span>
                                    <?php else: ?>
                                        <span class="badge badge-lecture">Lecture</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $assignment['periods'] === 'auto' ? 'Auto' : htmlspecialchars($assignment['periods']) ?></td>
                                <td>
                                    <?php if ($assignment['is_lab'] && $assignment['lab_day']): ?>
                                        <strong>Day:</strong> <?= htmlspecialchars($assignment['lab_day']) ?><br>
                                        <?php if ($assignment['lab_periods']): 
                                            $lab_periods = json_decode($assignment['lab_periods'], true);
                                            if (!empty($lab_periods)): ?>
                                                <strong>Periods:</strong> 
                                                <?php 
                                                $period_list = [];
                                                foreach ($lab_periods as $period_id => $period) {
                                                    $period_list[] = "P$period_id ({$period['start_time']}-{$period['end_time']})";
                                                }
                                                echo htmlspecialchars(implode(', ', $period_list));
                                                ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="remove-btn" onclick="removeAssignment(<?= htmlspecialchars($assignment['id']) ?>)">Remove</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Form for removal operation -->
            <form id="removeForm" method="POST" action="" style="display: none;">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="assignment_id" id="assignment_id_input">
            </form>
        </div>
        
        <!-- Summary Generator Tab -->
        <div id="summary-generator" class="tab-content">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="timetable_id">Timetable ID:</label>
                    <input type="text" id="timetable_id" name="timetable_id" required 
                           pattern="[a-zA-Z0-9_-]+" 
                           title="Use only letters, numbers, underscores, and hyphens"
                           value="<?php echo isset($_POST['timetable_id']) ? htmlspecialchars($_POST['timetable_id']) : ''; ?>">
                </div>
                
                <button type="submit" class="generate-btn">Generate Summary</button>
            </form>
            
            <?php if (isset($summary_result)): ?>
                <?php echo $summary_result; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Store all courses and staff as JavaScript objects
        const allCourses = <?= json_encode($allCourses) ?>;
        const allStaff = <?= json_encode($allStaff) ?>;
        const allTemplates = <?= json_encode($allTemplates) ?>;
        
        // DOM elements
        const courseForm = document.getElementById('courseForm');
        const deptSelect = document.getElementById('dept');
        const courseSelect = document.getElementById('course');
        const staffSelect = document.getElementById('staff');
        const periodsInput = document.getElementById('periods');
        const autoPeriodsCheckbox = document.getElementById('auto_periods');
        const isLabCheckbox = document.getElementById('is_lab');
        const labSection = document.getElementById('labSection');
        const templateSelect = document.getElementById('template');
        const labDaySelect = document.getElementById('lab_day');
        const labPeriodsList = document.getElementById('labPeriodsList');
        const addCourseBtn = document.getElementById('addCourseBtn');
        const assignmentTable = document.getElementById('assignmentTable');
        const removeForm = document.getElementById('removeForm');
        
        // Function to switch between tabs
        function switchTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Update tab styling
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
        }
        
        // Function to remove an assignment
        function removeAssignment(assignmentId) {
            if (confirm('Are you sure you want to remove this assignment?')) {
                document.getElementById('assignment_id_input').value = assignmentId;
                removeForm.submit();
            }
        }
        
        // Handle lab checkbox change
        isLabCheckbox.addEventListener('change', function() {
            if (this.checked) {
                labSection.style.display = 'block';
            } else {
                labSection.style.display = 'none';
            }
        });
        
        // Department change event
        deptSelect.addEventListener('change', function() {
            selectedDept = this.value;
            
            // Reset course and staff selections
            courseSelect.innerHTML = '<option value="">Select Course</option>';
            staffSelect.innerHTML = '<option value="">Select Staff</option>';
            
            // Enable/disable course select based on department selection
            if (selectedDept) {
                courseSelect.disabled = false;
                
                // Filter and populate courses based on selected department
                const filteredCourses = allCourses.filter(course => course.dept === selectedDept);
                filteredCourses.forEach(course => {
                    const option = document.createElement('option');
                    option.value = course.id;
                    option.dataset.name = course.name;
                    option.dataset.code = course.course_code;
                    option.dataset.credits = course.credits;
                    option.textContent = `${course.name} (${course.course_code})`;
                    courseSelect.appendChild(option);
                });
            } else {
                courseSelect.disabled = true;
                staffSelect.disabled = true;
                periodsInput.disabled = true;
                autoPeriodsCheckbox.disabled = true;
                addCourseBtn.disabled = true;
            }
        });
        
        // Course change event
        courseSelect.addEventListener('change', function() {
            selectedCourseId = this.value;
            
            if (selectedCourseId && this.selectedOptions[0]) {
                selectedCourseName = this.selectedOptions[0].dataset.name;
                selectedCourseCode = this.selectedOptions[0].dataset.code;
                selectedCourseCredits = this.selectedOptions[0].dataset.credits;
            } else {
                selectedCourseName = "";
                selectedCourseCode = "";
                selectedCourseCredits = "";
            }
            
            // Reset staff selection
            staffSelect.innerHTML = '<option value="">Select Staff</option>';
            
            // Enable/disable staff select based on course selection
            if (selectedCourseId) {
                staffSelect.disabled = false;
                
                // Filter and populate staff based on selected department
                const filteredStaff = allStaff.filter(staffMember => staffMember.dept === selectedDept);
                filteredStaff.forEach(staffMember => {
                    const option = document.createElement('option');
                    option.value = staffMember.id;
                    option.dataset.name = staffMember.name;
                    option.textContent = staffMember.name;
                    staffSelect.appendChild(option);
                });
            } else {
                staffSelect.disabled = true;
                periodsInput.disabled = true;
                autoPeriodsCheckbox.disabled = true;
                addCourseBtn.disabled = true;
            }
        });
        
        // Staff change event
        staffSelect.addEventListener('change', function() {
            selectedStaffId = this.value;
            
            if (selectedStaffId && this.selectedOptions[0]) {
                selectedStaffName = this.selectedOptions[0].dataset.name;
                
                // Enable periods input and auto checkbox
                periodsInput.disabled = false;
                autoPeriodsCheckbox.disabled = false;
            } else {
                selectedStaffName = "";
                periodsInput.disabled = true;
                autoPeriodsCheckbox.disabled = true;
            }
            
            // Enable/disable submit button based on staff selection
            addCourseBtn.disabled = !selectedStaffId;
        });
        
        // Auto periods checkbox event
        autoPeriodsCheckbox.addEventListener('change', function() {
            periodsInput.disabled = this.checked;
        });
        
        // Template selection change event - load days and periods from template
        templateSelect.addEventListener('change', function() {
            selectedTemplateId = this.value;
            
            // Reset lab day selection
            labDaySelect.innerHTML = '<option value="">Select Day</option>';
            labDaySelect.disabled = true;
            
            // Reset lab periods list
            labPeriodsList.innerHTML = '<div class="empty-table">Select a template to view periods</div>';
            
            if (selectedTemplateId) {
                const template = allTemplates.find(t => t.id == selectedTemplateId);
                if (template) {
                    try {
                        // Load template days if available
                        if (template.days) {
                            const days = JSON.parse(template.days);
                            if (Array.isArray(days) && days.length > 0) {
                                labDaySelect.innerHTML = '<option value="">Select Day</option>';
                                days.forEach(day => {
                                    const option = document.createElement('option');
                                    option.value = day;
                                    option.textContent = day;
                                    labDaySelect.appendChild(option);
                                });
                                labDaySelect.disabled = false;
                            } else {
                                // If days not found in template, use default days
                                populateDefaultDays();
                            }
                        } else {
                            // If days not in template structure, use default days
                            populateDefaultDays();
                        }
                        
                        // Load periods
                        if (template.periods_data) {
                            generatePeriodItems(template.periods_data);
                        }
                    } catch (e) {
                        console.error('Error parsing template data:', e);
                        // Fallback to default days
                        populateDefaultDays();
                    }
                }
            }
        });
        
        // Function to populate default days of week
        function populateDefaultDays() {
            labDaySelect.innerHTML = '<option value="">Select Day</option>';
            const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            days.forEach(day => {
                const option = document.createElement('option');
                option.value = day;
                option.textContent = day;
                labDaySelect.appendChild(option);
            });
            labDaySelect.disabled = false;
        }
        
        // Function to generate period items based on template data
        function generatePeriodItems(periodsData) {
            try {
                let periods;
                if (typeof periodsData === 'string') {
                    periods = JSON.parse(periodsData);
                } else {
                    periods = periodsData;
                }
                
                if (!periods || Object.keys(periods).length === 0) {
                    labPeriodsList.innerHTML = '<div class="empty-table">No periods found in this template</div>';
                    return;
                }
                
                let html = '';
                Object.entries(periods).forEach(([period_id, period]) => {
                    html += `
                        <div class="lab-period-item">
                            <input type="checkbox" id="period_${period_id}" name="lab_periods[]" value="${period_id}">
                            <label for="period_${period_id}">
                                Period ${period_id} (${period.start_time} - ${period.end_time})
                            </label>
                            <input type="hidden" name="period_start_${period_id}" value="${period.start_time}">
                            <input type="hidden" name="period_end_${period_id}" value="${period.end_time}">
                        </div>
                    `;
                });
                
                labPeriodsList.innerHTML = html || '<div class="empty-table">No periods found in this template</div>';
            } catch (e) {
                console.error('Error generating periods:', e);
                labPeriodsList.innerHTML = '<div class="empty-table">Error loading periods</div>';
            }
        }
        
        // Form submission handler
        courseForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (selectedCourseId && selectedStaffId) {
                // Check if lab is selected but no template is selected
                if (isLabCheckbox.checked && !selectedTemplateId) {
                    alert('Please select a template for lab assignment.');
                    return;
                }
                
                // Check if lab is selected but no day is selected
                if (isLabCheckbox.checked && !labDaySelect.value) {
                    alert('Please select a lab day.');
                    return;
                }
                
                // If lab is selected, check if at least one period is selected
                if (isLabCheckbox.checked) {
                    const selectedPeriods = document.querySelectorAll('input[name="lab_periods[]"]:checked');
                    if (selectedPeriods.length === 0) {
                        alert('Please select at least one lab period.');
                        return;
                    }
                }
                
                // Create form data and submit
                this.submit();
            }
        });
        
        // Function to set up remove button event listeners
        function setupRemoveButtons() {
            document.querySelectorAll('.remove-btn').forEach(button => {
                button.onclick = function(e) {
                    e.preventDefault();
                    const assignmentId = this.closest('tr').dataset.assignmentId;
                    
                    // If it's a temporary ID (client-side only), just remove the row
                    if (assignmentId.startsWith('temp_')) {
                        if (confirm('Are you sure you want to remove this assignment?')) {
                            const row = this.closest('tr');
                            assignmentTable.removeChild(row);
                            
                            // If table is empty, add the "No assignments" message
                            if (assignmentTable.children.length === 0) {
                                assignmentTable.innerHTML = '<tr><td colspan="7" class="empty-table">No course assignments yet.</td></tr>';
                            }
                        }
                    } else {
                        // For permanent assignments, use the removeAssignment function
                        removeAssignment(assignmentId);
                    }
                };
            });
        }
        
        // Summary generator functions
        function updateRemainingPeriods() {
            const totalPeriods = parseInt(document.getElementById('total-periods').textContent);
            const totalLabPeriods = parseInt(document.getElementById('total-lab-periods').textContent);
            
            // Calculate total lecture periods from inputs (only non-lab courses)
            let totalLecturePeriods = 0;
            const periodInputs = document.querySelectorAll('.period-input');
            periodInputs.forEach(input => {
                totalLecturePeriods += parseInt(input.value) || 0;
            });
            
            // Update display
            document.getElementById('total-lecture-periods').textContent = totalLecturePeriods;
            const remaining = totalPeriods - (totalLabPeriods + totalLecturePeriods);
            document.getElementById('remaining-periods').textContent = remaining;
            
            // Highlight if negative
            const remainingElement = document.getElementById('remaining-periods');
            if (remaining < 0) {
                remainingElement.style.color = 'red';
                remainingElement.style.fontWeight = 'bold';
            } else {
                remainingElement.style.color = '';
                remainingElement.style.fontWeight = '';
            }
        }
        
        function resetPeriods() {
            const periodInputs = document.querySelectorAll('.period-input');
            periodInputs.forEach(input => {
                input.value = input.dataset.originalValue;
            });
            updateRemainingPeriods();
        }
        
        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Set up remove buttons
            setupRemoveButtons();
            
            // Initialize summary generator if on that tab
            if (document.getElementById('summary-generator').classList.contains('active')) {
                updateRemainingPeriods();
            }
        });
    </script>
</body>
</html>

<?php
// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>