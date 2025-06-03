<?php
session_start();
// Database connection
$host = 'localhost';
$dbname = 'autotime2';
$username = 'root';
$password = '';
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
// Create course_assignments table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS course_assignments (
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
        )
    ");
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}
// Check for missing columns and add them if needed
try {
    // Check if lab_day column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM course_assignments LIKE 'lab_day'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing lab_day column
        $conn->exec("ALTER TABLE course_assignments ADD COLUMN lab_day VARCHAR(20) DEFAULT NULL");
    }
    
    // Check if lab_periods column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM course_assignments LIKE 'lab_periods'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing lab_periods column
        $conn->exec("ALTER TABLE course_assignments ADD COLUMN lab_periods TEXT DEFAULT NULL");
    }
    
    // Check if template_id column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM course_assignments LIKE 'template_id'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing template_id column
        $conn->exec("ALTER TABLE course_assignments ADD COLUMN template_id INT DEFAULT NULL");
    }
} catch (PDOException $e) {
    die("Error checking/adding columns: " . $e->getMessage());
}

// Create course_assignments table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS course_assignments (
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
        )
    ");
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}

// Fetch departments
$departments = [];
$stmt = $conn->query("SELECT DISTINCT dept FROM courses");
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch all courses (will be filtered via JavaScript)
$allCourses = [];
$stmt = $conn->query("SELECT id, name, course_code, credits, dept, staff FROM courses");
$allCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all staff (will be filtered via JavaScript)
$allStaff = [];
$stmt = $conn->query("SELECT id, name, unique_id, dept FROM staff");
$allStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all templates
$allTemplates = [];
try {
    $stmt = $conn->query("SELECT * FROM templates");
    $allTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist
    $allTemplates = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we're removing an assignment
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'remove') {
            $assignment_id = $_POST['assignment_id'];
            
            // Delete the assignment
            $query = "DELETE FROM course_assignments WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->execute(['id' => $assignment_id]);
            
            // Set success message
            $successMessage = "Course assignment removed successfully!";
        } elseif ($_POST['action'] === 'update_template') {
            // Update template for all assignments in this timetable
            $timetable_id = $_SESSION['timetable_id'] ?? null;
            $template_id = $_POST['template_id'] ?? null;
            
            if ($timetable_id && $template_id) {
                $query = "UPDATE course_assignments SET template_id = :template_id WHERE timetable_id = :timetable_id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    'template_id' => $template_id,
                    'timetable_id' => $timetable_id
                ]);
                
                $successMessage = "Template updated for all courses in this timetable!";
            }
        }
    } else {
        // This is a new assignment
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
                    $start_time = $_POST["period_start_$period_id"] ?? '';
                    $end_time = $_POST["period_end_$period_id"] ?? '';
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
        
        // Get the template_id - either from the lab selection or existing assignments
        $template_id = null;
        if ($is_lab && isset($_POST['template'])) {
            $template_id = $_POST['template'];
            
            // Update template for all assignments in this timetable
            if (isset($_SESSION['timetable_id'])) {
                $query = "UPDATE course_assignments SET template_id = :template_id WHERE timetable_id = :timetable_id";
                $stmt = $conn->prepare($query);
                $stmt->execute([
                    'template_id' => $template_id,
                    'timetable_id' => $_SESSION['timetable_id']
                ]);
            }
        } else {
            // For non-lab or when no template selected, get existing template_id for this timetable
            if (isset($_SESSION['timetable_id'])) {
                $stmt = $conn->prepare("SELECT template_id FROM course_assignments WHERE timetable_id = :timetable_id LIMIT 1");
                $stmt->execute(['timetable_id' => $_SESSION['timetable_id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $template_id = $result['template_id'] ?? null;
            }
        }
        
        if ($is_lab) {
            // Lab assignment
            $query = "INSERT INTO course_assignments (
                course_id, staff_id, periods, is_lab, lab_day, lab_periods, 
                timetable_id, template_id
            ) VALUES (
                :course_id, :staff_id, :periods, :is_lab, :lab_day, :lab_periods,
                :timetable_id, :template_id
            )";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                'course_id' => $course_id,
                'staff_id' => $staff_id,
                'periods' => $periods,
                'is_lab' => $is_lab,
                'lab_day' => $lab_day,
                'lab_periods' => $lab_periods_json,
                'timetable_id' => $_SESSION['timetable_id'] ?? null,
                'template_id' => $template_id
            ]);
        } else {
            // Regular course assignment
            $query = "INSERT INTO course_assignments (
                course_id, staff_id, periods, is_lab, 
                timetable_id, template_id
            ) VALUES (
                :course_id, :staff_id, :periods, :is_lab,
                :timetable_id, :template_id
            )";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                'course_id' => $course_id, 
                'staff_id' => $staff_id,
                'periods' => $periods,
                'is_lab' => $is_lab,
                'timetable_id' => $_SESSION['timetable_id'] ?? null,
                'template_id' => $template_id
            ]);
        }
        
        // Get course and staff details for the success message
        $courseQuery = $conn->prepare("SELECT name, course_code, credits FROM courses WHERE id = :id");
        $courseQuery->execute(['id' => $course_id]);
        $courseDetails = $courseQuery->fetch(PDO::FETCH_ASSOC);
        
        $staffQuery = $conn->prepare("SELECT name FROM staff WHERE id = :id");
        $staffQuery->execute(['id' => $staff_id]);
        $staffDetails = $staffQuery->fetch(PDO::FETCH_ASSOC);
        
        // Set success message with details
        $successMessage = "Course '{$courseDetails['name']} ({$courseDetails['course_code']})' " . 
                         ($is_lab ? "lab " : "") . 
                         "assigned to {$staffDetails['name']} successfully!";
    }
}

// Fetch existing assignments
$existingAssignments = [];
$currentTemplateId = null;
try {
    // Add timetable_id filter if available
    $query = "
        SELECT ca.id, c.name AS course_name, c.course_code, c.credits, 
               s.name AS staff_name, ca.periods, ca.is_lab, 
               ca.lab_day, ca.lab_periods, ca.template_id
        FROM course_assignments ca
        JOIN courses c ON ca.course_id = c.id
        JOIN staff s ON ca.staff_id = s.id
    ";
    
    // Add WHERE clause if timetable_id is set
    if (isset($_SESSION['timetable_id'])) {
        $query .= " WHERE ca.timetable_id = :timetable_id";
        $stmt = $conn->prepare($query);
        $stmt->execute(['timetable_id' => $_SESSION['timetable_id']]);
        
        // Get the current template_id for this timetable
        $templateStmt = $conn->prepare("SELECT template_id FROM course_assignments WHERE timetable_id = :timetable_id LIMIT 1");
        $templateStmt->execute(['timetable_id' => $_SESSION['timetable_id']]);
        $templateResult = $templateStmt->fetch(PDO::FETCH_ASSOC);
        $currentTemplateId = $templateResult['template_id'] ?? null;
    } else {
        $stmt = $conn->query($query);
    }
    
    $existingAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist or be empty - that's fine
    $existingAssignments = [];
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
        $templateQuery = $conn->prepare("SELECT * FROM templates WHERE id = :id");
        $templateQuery->execute(['id' => $_SESSION['template_id']]);
        $templateDetails = $templateQuery->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
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
    <title>Add Course & Lab</title>
    <style>
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
        .action-buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .generate-summary-btn {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .generate-summary-btn:hover {
            background-color: #45a049;
        }
        .template-info {
            margin-top: 10px;
            padding: 10px;
            background-color: #e6f7ff;
            border: 1px solid #b3e0ff;
            border-radius: 4px;
        }
        .update-template-btn {
            background-color: #ff9900;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
        }
        .update-template-btn:hover {
            background-color: #e68a00;
        }
    </style>
</head>
<body>
    <h1>Add Course & Lab</h1>
    <?php if (isset($_SESSION['timetable_id']) || isset($templateDetails)): ?>
    <div class="timetable-info">
        <?php if (isset($_SESSION['timetable_id'])): ?>
            <p><strong>Timetable ID:</strong> <?= htmlspecialchars($_SESSION['timetable_id']) ?></p>
        <?php endif; ?>
        
        <?php if ($currentTemplateId && isset($allTemplates)): ?>
            <?php 
            $currentTemplate = array_filter($allTemplates, function($t) use ($currentTemplateId) {
                return $t['id'] == $currentTemplateId;
            });
            $currentTemplate = reset($currentTemplate);
            ?>
            <div class="template-info">
                <p><strong>Current Template:</strong> 
                    <?= $currentTemplate ? htmlspecialchars($currentTemplate['name']) : 'None selected' ?>
                    
                    <?php if (isset($_SESSION['timetable_id'])): ?>
                        <button class="update-template-btn" onclick="showTemplateModal()">Change Template</button>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($successMessage)): ?>
        <div class="success" id="successMessage"><?= $successMessage ?></div>
    <?php endif; ?>
    
    <form id="courseForm" method="POST" action="">
        <div class="form-group">
            <label for="dept">Department:</label>
            <select name="dept" id="dept">
                <option value="">Select Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= htmlspecialchars($dept) ?>">
                        <?= htmlspecialchars($dept) ?>
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
            
            <div class="form-group">
                <label for="template">Template:</label>
                <select name="template" id="template">
                    <option value="">Select Template</option>
                    <?php foreach ($allTemplates as $template): ?>
                        <option value="<?= htmlspecialchars($template['id']) ?>" <?= ($currentTemplateId == $template['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($template['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="lab_day">Lab Day:</label>
                <select name="lab_day" id="lab_day" <?= !$currentTemplateId ? 'disabled' : '' ?>>
                    <option value="">Select Day</option>
                    <?php if ($currentTemplateId): ?>
                        <?php 
                        $currentTemplate = array_filter($allTemplates, function($t) use ($currentTemplateId) {
                            return $t['id'] == $currentTemplateId;
                        });
                        $currentTemplate = reset($currentTemplate);
                        if ($currentTemplate && isset($currentTemplate['days'])) {
                            $days = json_decode($currentTemplate['days'], true);
                            if (is_array($days)) {
                                foreach ($days as $day): ?>
                                    <option value="<?= htmlspecialchars($day) ?>"><?= htmlspecialchars($day) ?></option>
                                <?php endforeach;
                            }
                        } else {
                            foreach (getDaysOfWeek() as $day): ?>
                                <option value="<?= htmlspecialchars($day) ?>"><?= htmlspecialchars($day) ?></option>
                            <?php endforeach;
                        }
                        ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Select Lab Periods:</label>
                <div class="lab-periods-list" id="labPeriodsList">
                    <?php if ($currentTemplateId): ?>
                        <?php 
                        $currentTemplate = array_filter($allTemplates, function($t) use ($currentTemplateId) {
                            return $t['id'] == $currentTemplateId;
                        });
                        $currentTemplate = reset($currentTemplate);
                        if ($currentTemplate && isset($currentTemplate['periods_data'])) {
                            $periods = json_decode($currentTemplate['periods_data'], true);
                            if (is_array($periods) && !empty($periods)): ?>
                                <?php foreach ($periods as $period_id => $period): ?>
                                    <div class="lab-period-item">
                                        <input type="checkbox" id="period_<?= $period_id ?>" name="lab_periods[]" value="<?= $period_id ?>">
                                        <label for="period_<?= $period_id ?>">
                                            Period <?= $period_id ?> (<?= $period['start_time'] ?> - <?= $period['end_time'] ?>)
                                        </label>
                                        <input type="hidden" name="period_start_<?= $period_id ?>" value="<?= $period['start_time'] ?>">
                                        <input type="hidden" name="period_end_<?= $period_id ?>" value="<?= $period['end_time'] ?>">
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-table">No periods found in this template</div>
                            <?php endif; ?>
                        <?php } else { ?>
                            <div class="empty-table">No template selected</div>
                        <?php } ?>
                    <?php else: ?>
                        <div class="empty-table">Select a template to view periods</div>
                    <?php endif; ?>
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
                    <th>Template</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="assignmentTable">
                <?php if (empty($existingAssignments)): ?>
                <tr>
                    <td colspan="8" class="empty-table">No course assignments yet.</td>
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
                            <?php if ($assignment['template_id']): ?>
                                <?php 
                                $template = array_filter($allTemplates, function($t) use ($assignment) {
                                    return $t['id'] == $assignment['template_id'];
                                });
                                $template = reset($template);
                                echo $template ? htmlspecialchars($template['name']) : 'Unknown';
                                ?>
                            <?php else: ?>
                                None
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

    <!-- Form for updating template -->
    <form id="updateTemplateForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="update_template">
        <input type="hidden" name="template_id" id="template_id_input">
    </form>

    <!-- Template Selection Modal -->
    <div id="templateModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
        <div style="background-color: white; padding: 20px; border-radius: 5px; max-width: 500px; width: 90%;">
            <h2>Change Template for All Courses</h2>
            <p>Select a new template to apply to all courses in this timetable:</p>
            
            <select id="modalTemplateSelect" style="width: 100%; padding: 8px; margin-bottom: 15px;">
                <option value="">Select Template</option>
                <?php foreach ($allTemplates as $template): ?>
                    <option value="<?= htmlspecialchars($template['id']) ?>" <?= ($currentTemplateId == $template['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($template['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button onclick="hideTemplateModal()" style="padding: 8px 15px; background-color: #ccc; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button onclick="updateTemplate()" style="padding: 8px 15px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">Update Template</button>
            </div>
        </div>
    </div>

    <!-- Generate Summary Button -->
    <?php if (isset($_SESSION['timetable_id']) && !empty($existingAssignments)): ?>
    <div class="action-buttons">
        <form method="POST" action="next_22.php">
            <button type="submit" class="generate-summary-btn">Generate Timetable Summary</button>
        </form>
    </div>
    <?php endif; ?>

    <script>
        // Store all courses and staff as JavaScript objects
        const allCourses = <?= json_encode($allCourses) ?>;
        const allStaff = <?= json_encode($allStaff) ?>;
        const allTemplates = <?= json_encode($allTemplates) ?>;
        const currentTemplateId = <?= $currentTemplateId ? json_encode($currentTemplateId) : 'null' ?>;
        
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
        const updateTemplateForm = document.getElementById('updateTemplateForm');
        const templateModal = document.getElementById('templateModal');
        const modalTemplateSelect = document.getElementById('modalTemplateSelect');
        
        // Keep track of selected values
        let selectedDept = "";
        let selectedCourseId = "";
        let selectedCourseName = "";
        let selectedCourseCode = "";
        let selectedCourseCredits = "";
        let selectedStaffId = "";
        let selectedStaffName = "";
        let selectedTemplateId = currentTemplateId;
        
        // Function to show template modal
        function showTemplateModal() {
            templateModal.style.display = 'flex';
        }
        
        // Function to hide template modal
        function hideTemplateModal() {
            templateModal.style.display = 'none';
        }
        
        // Function to update template for all courses
        function updateTemplate() {
            const newTemplateId = modalTemplateSelect.value;
            if (!newTemplateId) {
                alert('Please select a template');
                return;
            }
            
            document.getElementById('template_id_input').value = newTemplateId;
            updateTemplateForm.submit();
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
                const filteredStaff = allStaff.filter(staff => staff.dept === selectedDept);
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
                                assignmentTable.innerHTML = '<tr><td colspan="8" class="empty-table">No course assignments yet.</td></tr>';
                            }
                        }
                    } else {
                        // For permanent assignments, use the removeAssignment function
                        removeAssignment(assignmentId);
                    }
                };
            });
        }
        
        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Set up remove buttons
            setupRemoveButtons();
            
            // Auto-hide success message after 5 seconds
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                setTimeout(function() {
                    successMessage.style.display = 'none';
                }, 5000);
            }
            
            // If we have a current template, pre-select it in the template dropdown
            if (currentTemplateId) {
                templateSelect.value = currentTemplateId;
                
                // Also trigger the change event to load days and periods
                const event = new Event('change');
                templateSelect.dispatchEvent(event);
            }
        });
    </script>
</body>
</html>