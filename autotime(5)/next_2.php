<?php
// Improved Timetable Summary Generator with Edit and Save functionality

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

    // Create summary tables if they don't exist
    $create_tables = [
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

    // Handle form submission
    $summary_result = null;
    $error_message = null;
    $success_message = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $generator = new TimetableSummaryGenerator($conn);
            
            if (isset($_POST['save_summary']) && isset($_POST['timetable_id']) && isset($_POST['courses'])) {
                // Handle save action
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
                    $success_message = "Summary saved successfully!";
                }
                
                // Display updated summary
                $summary_result = $generator->displaySummary($summary_data);
            } elseif (isset($_POST['timetable_id'])) {
                // Handle initial generation
                $timetable_id = $_POST['timetable_id'];
                
                // Try to load existing summary first
                $summary_data = $generator->loadSummary($timetable_id);
                
                if (!$summary_data) {
                    // Generate new summary if none exists
                    $summary_data = $generator->generateTimetableSummary($timetable_id);
                }
                
                $summary_result = $generator->displaySummary($summary_data);
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
} catch (Exception $e) {
    $error_message = "System error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Summary Generator</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: inline-block;
            width: 150px;
            font-weight: bold;
        }
        input[type="text"], select {
            padding: 8px;
            width: 300px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Timetable Summary Generator</h1>
        
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
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if ($summary_result): ?>
            <?php echo $summary_result; ?>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>