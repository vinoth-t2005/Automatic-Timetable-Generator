<?php
// Database connection
$host = 'localhost';
$dbname = 'autotime';
$username = 'root';
$password = '';
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
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
    
    // Check if timetable_id column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM course_assignments LIKE 'timetable_id'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing timetable_id column
        $conn->exec("ALTER TABLE course_assignments ADD COLUMN timetable_id VARCHAR(100) DEFAULT NULL");
    }
} catch (PDOException $e) {
    die("Error checking/adding columns: " . $e->getMessage());
}

// Create course_assignments table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS course_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            timetable_id VARCHAR(100) DEFAULT NULL,
            course_id INT NOT NULL,
            staff_id INT NOT NULL,
            periods VARCHAR(20) NOT NULL,
            is_lab BOOLEAN DEFAULT FALSE,
            lab_day VARCHAR(20) DEFAULT NULL,
            lab_periods TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}

// Create ttid table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ttid (
            id INT AUTO_INCREMENT PRIMARY KEY,
            timetable_id VARCHAR(100) NOT NULL,
            template_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (timetable_id)
        )
    ");
} catch (PDOException $e) {
    die("Error creating ttid table: " . $e->getMessage());
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

// Initialize variables for timetable ID generation
$timetable_id = null;
$template_id = null;
$save_message = "";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we're generating a timetable ID
    if (isset($_POST['generate'])) {
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $year = $_POST['year'];
        $semester = $_POST['semester'];
        $batch = $_POST['batch'];
        
        $batch_parts = explode('-', $batch);
        $timetable_id = $dept . '-' . $section . '-' . $year . '-' . $semester . '-' . $batch_parts[0] . '-' . $batch_parts[1];
        
        // Also set the template ID if it was selected
        if (isset($_POST['template_id']) && !empty($_POST['template_id'])) {
            $template_id = $_POST['template_id'];
        }
    }
    
    // Check if we're saving timetable ID with template
    if (isset($_POST['save']) && isset($_POST['timetable_id']) && isset($_POST['template_id'])) {
        $timetable_id = $_POST['timetable_id'];
        $template_id = $_POST['template_id'];
        
        if (!empty($timetable_id) && !empty($template_id)) {
            // Check if this combination already exists
            $check_sql = "SELECT * FROM ttid WHERE timetable_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->execute([$timetable_id]);
            
            if ($check_stmt->rowCount() > 0) {
                // Update existing record
                $update_sql = "UPDATE ttid SET template_id = ? WHERE timetable_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                
                if ($update_stmt->execute([$template_id, $timetable_id])) {
                    $save_message = "Record updated successfully!";
                } else {
                    $save_message = "Error updating record: " . $conn->error;
                }
            } else {
                // Insert new record
                $insert_sql = "INSERT INTO ttid (timetable_id, template_id) VALUES (?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                
                if ($insert_stmt->execute([$timetable_id, $template_id])) {
                    $save_message = "Record saved successfully!";
                } else {
                    $save_message = "Error saving record: " . $conn->error;
                }
            }
        } else {
            $save_message = "Both timetable ID and template must be selected!";
        }
    }
    
    // Check if we're removing an assignment
    if (isset($_POST['action']) && $_POST['action'] === 'remove') {
        $assignment_id = $_POST['assignment_id'];
        
        // Delete the assignment
        $query = "DELETE FROM course_assignments WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->execute(['id' => $assignment_id]);
        
        // Set success message
        $successMessage = "Course assignment removed successfully!";
    } 
    // Handle course/lab assignment
    elseif (isset($_POST['course']) && isset($_POST['staff']) && isset($_POST['timetable_id'])) {
        $course_id = $_POST['course'];
        $staff_id = $_POST['staff'];
        $timetable_id = $_POST['timetable_id'];
        $is_lab = isset($_POST['is_lab']) ? 1 : 0;
        
        if ($is_lab) {
            // Lab assignment
            $periods = isset($_POST['auto_periods']) ? 'auto' : $_POST['periods'];
            $lab_day = $_POST['lab_day'];
            
            // Handle lab periods (selected periods with their time slots)
            $lab_periods = [];
            if (isset($_POST['lab_periods'])) {
                foreach ($_POST['lab_periods'] as $period_id) {
                    if (isset($_POST['period_start_'.$period_id]) && isset($_POST['period_end_'.$period_id])) {
                        $lab_periods[$period_id] = [
                            'start_time' => $_POST['period_start_'.$period_id],
                            'end_time' => $_POST['period_end_'.$period_id]
                        ];
                    }
                }
            }
            $lab_periods_json = !empty($lab_periods) ? json_encode($lab_periods) : null;
            
            // Insert into database
            $query = "INSERT INTO course_assignments (timetable_id, course_id, staff_id, periods, is_lab, lab_day, lab_periods) 
                      VALUES (:timetable_id, :course_id, :staff_id, :periods, :is_lab, :lab_day, :lab_periods)";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                'timetable_id' => $timetable_id,
                'course_id' => $course_id,
                'staff_id' => $staff_id,
                'periods' => $periods,
                'is_lab' => $is_lab,
                'lab_day' => $lab_day,
                'lab_periods' => $lab_periods_json
            ]);
        } else {
            // Regular course assignment
            $periods = isset($_POST['auto_periods']) ? 'auto' : $_POST['periods'];
            
            // Insert into database
            $query = "INSERT INTO course_assignments (timetable_id, course_id, staff_id, periods, is_lab) 
                      VALUES (:timetable_id, :course_id, :staff_id, :periods, :is_lab)";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                'timetable_id' => $timetable_id,
                'course_id' => $course_id, 
                'staff_id' => $staff_id,
                'periods' => $periods,
                'is_lab' => $is_lab
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

// Fetch existing assignments for the current timetable ID (if set)
$existingAssignments = [];
if (isset($timetable_id)) {
    try {
        $stmt = $conn->prepare("
            SELECT ca.id, c.name AS course_name, c.course_code, c.credits, s.name AS staff_name, 
                   ca.periods, ca.is_lab, ca.lab_day, ca.lab_periods
            FROM course_assignments ca
            JOIN courses c ON ca.course_id = c.id
            JOIN staff s ON ca.staff_id = s.id
            WHERE ca.timetable_id = :timetable_id
            ORDER BY c.dept, c.course_code
        ");
        $stmt->execute(['timetable_id' => $timetable_id]);
        $existingAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist or be empty - that's fine
        $existingAssignments = [];
    }
}

// Function to get sections by department
function getSectionsByDept($conn, $dept) {
    $sql = "SELECT DISTINCT section FROM classes WHERE dept = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$dept]);
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $sections;
}

// Function to get years by department and section
function getYearsBySectionDept($conn, $dept, $section) {
    $sql = "SELECT DISTINCT year FROM classes WHERE dept = ? AND section = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$dept, $section]);
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $years;
}

// Function to get semesters by department, section, and year
function getSemestersByYearSectionDept($conn, $dept, $section, $year) {
    $sql = "SELECT DISTINCT semester FROM classes WHERE dept = ? AND section = ? AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$dept, $section, $year]);
    $semesters = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $semesters;
}

// Function to get batches by department, section, year, and semester
function getBatchesByDeptSectionYearSem($conn, $dept, $section, $year, $semester) {
    $sql = "SELECT DISTINCT batch_start, batch_end FROM classes WHERE dept = ? AND section = ? AND year = ? AND semester = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$dept, $section, $year, $semester]);
    $batches = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $batches[] = $row['batch_start'] . '-' . $row['batch_end'];
    }
    return $batches;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Management System</title>
    <style>
        /* General Styles */
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1, h2, h3 {
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: inline-block;
            width: 150px;
            margin-right: 10px;
        }
        select, input, button {
            padding: 8px;
            margin-right: 10px;
        }
        .success {
            color: green;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .error {
            color: red;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        /* Course Assignment Styles */
        .form-group.checkbox-label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        .period-group {
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        /* Table Styles */
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
        .empty-table {
            text-align: center;
            padding: 15px;
            color: #666;
            font-style: italic;
        }
        
        /* Badge Styles */
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
        
        /* Button Styles */
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
        .btn {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn:hover {
            background-color: #45a049;
        }
        
        /* Template Preview Styles */
        .template-preview table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
        }
        .template-preview th, .template-preview td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .period-cell {
            background-color: #f9f9f9;
        }
        .break-cell {
            background-color: #e9f7ef;
        }
        .lunch-cell {
            background-color: #ffeaa7;
        }
        
        /* Tabs */
        .tab {
            overflow: hidden;
            border: 1px solid #ccc;
            background-color: #f1f1f1;
            margin-bottom: 20px;
        }
        .tab button {
            background-color: inherit;
            float: left;
            border: none;
            outline: none;
            cursor: pointer;
            padding: 14px 16px;
            transition: 0.3s;
        }
        .tab button:hover {
            background-color: #ddd;
        }
        .tab button.active {
            background-color: #4CAF50;
            color: white;
        }
        .tabcontent {
            display: none;
            padding: 6px 12px;
            border: 1px solid #ccc;
            border-top: none;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>Timetable Management System</h1>
        
        <div class="tab">
            <button class="tablinks active" onclick="openTab(event, 'generateTab')">Generate Timetable ID</button>
            <button class="tablinks" onclick="openTab(event, 'assignTab')" <?= $timetable_id ? '' : 'disabled' ?>>Assign Courses</button>
        </div>
        
        <!-- Generate Timetable ID Tab -->
        <div id="generateTab" class="tabcontent" style="display: block;">
            <h2>Generate Timetable ID</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="dept">Department:</label>
                    <select id="dept" name="dept" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept ?>" <?= (isset($_POST['dept']) && $_POST['dept'] == $dept) ? 'selected' : '' ?>>
                                <?= $dept ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="section">Section:</label>
                    <select id="section" name="section" required>
                        <option value="">Select Section</option>
                        <?php if (isset($_POST['dept']) && $_POST['dept']): ?>
                            <?php $sections = getSectionsByDept($conn, $_POST['dept']); ?>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= $section ?>" <?= (isset($_POST['section']) && $_POST['section'] == $section) ? 'selected' : '' ?>>
                                    <?= $section ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="year">Year:</label>
                    <select id="year" name="year" required>
                        <option value="">Select Year</option>
                        <?php if (isset($_POST['dept']) && isset($_POST['section']) && $_POST['dept'] && $_POST['section']): ?>
                            <?php $years = getYearsBySectionDept($conn, $_POST['dept'], $_POST['section']); ?>
                            <?php foreach ($years as $year): ?>
                                <option value="<?= $year ?>" <?= (isset($_POST['year']) && $_POST['year'] == $year) ? 'selected' : '' ?>>
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="semester">Semester:</label>
                    <select id="semester" name="semester" required>
                        <option value="">Select Semester</option>
                        <?php if (isset($_POST['dept']) && isset($_POST['section']) && isset($_POST['year']) && $_POST['dept'] && $_POST['section'] && $_POST['year']): ?>
                            <?php $semesters = getSemestersByYearSectionDept($conn, $_POST['dept'], $_POST['section'], $_POST['year']); ?>
                            <?php foreach ($semesters as $semester): ?>
                                <option value="<?= $semester ?>" <?= (isset($_POST['semester']) && $_POST['semester'] == $semester) ? 'selected' : '' ?>>
                                    <?= $semester ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="batch">Batch:</label>
                    <select id="batch" name="batch" required>
                        <option value="">Select Batch</option>
                        <?php if (isset($_POST['dept']) && isset($_POST['section']) && isset($_POST['year']) && isset($_POST['semester']) && $_POST['dept'] && $_POST['section'] && $_POST['year'] && $_POST['semester']): ?>
                            <?php $batches = getBatchesByDeptSectionYearSem($conn, $_POST['dept'], $_POST['section'], $_POST['year'], $_POST['semester']); ?>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?= $batch ?>" <?= (isset($_POST['batch']) && $_POST['batch'] == $batch) ? 'selected' : '' ?>>
                                    <?= $batch ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" name="generate" class="btn">Generate Timetable ID</button>
                </div>
            </form>

            <?php if ($timetable_id): ?>
                <h2>Generated Timetable ID: <?= $timetable_id ?></h2>
                
                <h2>Select Template and Save</h2>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="template-select">Template:</label>
                        <select id="template-select" name="template_id" onchange="fetchTemplatePreview(this.value)" required>
                            <option value="">-- Select a Template --</option>
                            <?php foreach ($allTemplates as $template): ?>
                                <option value="<?= $template['id'] ?>" <?= ($template_id == $template['id']) ? 'selected' : '' ?>>
                                    <?= $template['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <input type="hidden" name="timetable_id" value="<?= $timetable_id ?>">
                        <button type="submit" name="save" class="btn">Save Timetable ID with Template</button>
                    </div>
                    
                    <?php if ($save_message): ?>
                        <div class="<?= strpos($save_message, 'successfully') !== false ? 'success' : 'error' ?>">
                            <?= $save_message ?>
                        </div>
                    <?php endif; ?>
                </form>
                
                <h2>Timetable Template Preview</h2>
                <div id="template-preview-container" class="template-preview">
                    <p>Please select a template to preview.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Assign Courses Tab -->
        <div id="assignTab" class="tabcontent">
            <?php if ($timetable_id): ?>
                <h2>Assign Courses to Timetable: <?= $timetable_id ?></h2>
                
                <?php if (isset($successMessage)): ?>
                    <div class="success" id="successMessage"><?= $successMessage ?></div>
                <?php endif; ?>
                
                <form id="courseForm" method="POST" action="">
                    <input type="hidden" name="timetable_id" value="<?= $timetable_id ?>">
                    
                    <div class="form-group">
                        <label for="dept_assign">Department:</label>
                        <select name="dept_assign" id="dept_assign">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept ?>"><?= $dept ?></option>
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
                                    <option value="<?= $template['id'] ?>"><?= $template['name'] ?></option>
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
                        <button type="submit" id="addCourseBtn" disabled class="btn">Add Course</button>
                    </div>
                </form>
                
                <div id="assignmentList">
                    <h2>Current Course & Lab Assignments</h2>
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
                                <tr data-assignment-id="<?= $assignment['id'] ?>">
                                    <td><?= $assignment['course_name'] ?> (<?= $assignment['course_code'] ?>)</td>
                                    <td><?= $assignment['staff_name'] ?></td>
                                    <td><?= $assignment['credits'] ?></td>
                                    <td>
                                        <?php if ($assignment['is_lab']): ?>
                                            <span class="badge badge-lab">Lab</span>
                                        <?php else: ?>
                                            <span class="badge badge-lecture">Lecture</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $assignment['periods'] === 'auto' ? 'Auto' : $assignment['periods'] ?></td>
                                    <td>
                                        <?php if ($assignment['is_lab'] && $assignment['lab_day']): ?>
                                            <strong>Day:</strong> <?= $assignment['lab_day'] ?><br>
                                            <?php if ($assignment['lab_periods']): 
                                                $lab_periods = json_decode($assignment['lab_periods'], true);
                                                if (!empty($lab_periods)): ?>
                                                    <strong>Periods:</strong> 
                                                    <?php 
                                                    $period_list = [];
                                                    foreach ($lab_periods as $period_id => $period) {
                                                        $period_list[] = "P$period_id ({$period['start_time']}-{$period['end_time']})";
                                                    }
                                                    echo implode(', ', $period_list);
                                                    ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="remove-btn" onclick="removeAssignment(<?= $assignment['id'] ?>)">Remove</button>
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
            <?php else: ?>
                <p>Please generate a timetable ID first before assigning courses.</p>
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
        const deptSelect = document.getElementById('dept_assign');
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
        
        // Keep track of selected values
        let selectedDept = "";
        let selectedCourseId = "";
        let selectedCourseName = "";
        let selectedCourseCode = "";
        let selectedCourseCredits = "";
        let selectedStaffId = "";
        let selectedStaffName = "";
        let selectedTemplateId = "";
        
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
        
        // Function to open tabs
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            
            // Hide all tab content
            tabcontent = document.getElementsByClassName("tabcontent");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            
            // Remove active class from all tab buttons
            tablinks = document.getElementsByClassName("tablinks");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            
            // Show the current tab and add active class to the button
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
        }
        
        // Function to fetch and display template preview
        function fetchTemplatePreview(templateId) {
            if (!templateId) {
                document.getElementById("template-preview-container").innerHTML = "<p>Please select a template to preview.</p>";
                return;
            }

            // Update hidden template ID field
            document.getElementById("selected_template_id").value = templateId;

            // Send AJAX request to fetch template data
            const xhr = new XMLHttpRequest();
            xhr.open("GET", `fetch_template.php?preview_id=${templateId}`, true);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    const templateData = JSON.parse(xhr.responseText);
                    displayTemplatePreview(templateData, "template-preview-container");
                } else {
                    document.getElementById("template-preview-container").innerHTML = "<p>Error fetching template data.</p>";
                }
            };
            xhr.send();
        }

        // Function to display template preview
        function displayTemplatePreview(templateData, containerId) {
            let container = document.getElementById(containerId);
            if (!container) return;
            
            let template = null;
            try {
                template = templateData;
            } catch (e) {
                container.innerHTML = "<p>Error parsing template data.</p>";
                return;
            }
            
            // Extract week start and end
            const weekStart = template.week_start || "Monday";
            const weekEnd = template.week_end || "Friday";
            
            // Define weekdays and filter based on start/end day
            const allWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const startIdx = allWeekdays.indexOf(weekStart);
            const endIdx = allWeekdays.indexOf(weekEnd);
            
            let weekdays = [];
            if (startIdx <= endIdx) {
                weekdays = allWeekdays.slice(startIdx, endIdx + 1);
            } else {
                // Handle case where week wraps (e.g., Friday to Tuesday)
                weekdays = [...allWeekdays.slice(startIdx), ...allWeekdays.slice(0, endIdx + 1)];
            }
            
            // Get periods and breaks
            let periods = template.periods_data ? JSON.parse(template.periods_data) : [];
            let breaks = template.breaks_data ? JSON.parse(template.breaks_data) : [];
            
            // Combine into time slots
            let timeSlots = [];
            
            // Process periods
            for (let periodId in periods) {
                let period = periods[periodId];
                timeSlots.push({
                    type: 'period',
                    id: periodId,
                    startTime: period.start_time,
                    endTime: period.end_time,
                    label: `Period ${periodId}`
                });
            }
            
            // Process breaks
            for (let breakId in breaks) {
                let breakData = breaks[breakId];
                // Check if it's marked as lunch
                const isLunch = breakData.is_lunch === "on" || breakData.is_lunch === true;
                const breakLabel = isLunch ? "Lunch" : "Break";
                
                timeSlots.push({
                    type: 'break',
                    id: breakId,
                    startTime: breakData.start_time,
                    endTime: breakData.end_time,
                    label: breakLabel,
                    isLunch: isLunch,
                    afterPeriod: breakData.after_period
                });
            }
            
            // Sort timeSlots by start time
            timeSlots.sort((a, b) => {
                return new Date("2000-01-01T" + a.startTime) - new Date("2000-01-01T" + b.startTime);
            });
            
            // Generate the table HTML with days as rows and periods as columns
            let tableHTML = '<table border="1"><thead><tr><th>Day/Time</th>';
            
            // Add time slots as column headers
            for (let slot of timeSlots) {
                let slotClass = slot.type === 'period' ? 'period-cell' : (slot.isLunch ? 'lunch-cell' : 'break-cell');
                tableHTML += `<th class="${slotClass}">${slot.label}<br>${slot.startTime} - ${slot.endTime}</th>`;
            }
            
            tableHTML += '</tr></thead><tbody>';
            
            // Generate rows for each weekday
            for (let day of weekdays) {
                tableHTML += `<tr><td><strong>${day}</strong></td>`;
                
                // Add cells for each time slot
                for (let slot of timeSlots) {
                    if (slot.type === 'period') {
                        tableHTML += `<td class="period-cell">Class</td>`;
                    } else {
                        let cellClass = slot.isLunch ? 'lunch-cell' : 'break-cell';
                        tableHTML += `<td class="${cellClass}">${slot.label}</td>`;
                    }
                }
                
                tableHTML += '</tr>';
            }
            
            tableHTML += '</tbody></table>';
            container.innerHTML = tableHTML;
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
            
            // Enable Assign Courses tab if we have a timetable ID
            <?php if ($timetable_id): ?>
                document.querySelector('.tablinks[disabled]').disabled = false;
            <?php endif; ?>
            
            // Set up AJAX for department, section, year, semester, batch selection
            $('#dept').change(function() {
                const dept = $(this).val();
                if (dept) {
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getSections', dept: dept },
                        dataType: 'json',
                        success: function(sections) {
                            $('#section').empty().append('<option value="">Select Section</option>');
                            sections.forEach(section => {
                                $('#section').append(`<option value="${section}">${section}</option>`);
                            });
                        }
                    });
                }
            });

            $('#section').change(function() {
                const dept = $('#dept').val();
                const section = $(this).val();
                if (dept && section) {
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getYears', dept: dept, section: section },
                        dataType: 'json',
                        success: function(years) {
                            $('#year').empty().append('<option value="">Select Year</option>');
                            years.forEach(year => {
                                $('#year').append(`<option value="${year}">${year}</option>`);
                            });
                        }
                    });
                }
            });

            $('#year').change(function() {
                const dept = $('#dept').val();
                const section = $('#section').val();
                const year = $(this).val();
                if (dept && section && year) {
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getSemesters', dept: dept, section: section, year: year },
                        dataType: 'json',
                        success: function(semesters) {
                            $('#semester').empty().append('<option value="">Select Semester</option>');
                            semesters.forEach(semester => {
                                $('#semester').append(`<option value="${semester}">${semester}</option>`);
                            });
                        }
                    });
                }
            });

            $('#semester').change(function() {
                const dept = $('#dept').val();
                const section = $('#section').val();
                const year = $('#year').val();
                const semester = $(this).val();
                if (dept && section && year && semester) {
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getBatches', dept: dept, section: section, year: year, semester: semester },
                        dataType: 'json',
                        success: function(batches) {
                            $('#batch').empty().append('<option value="">Select Batch</option>');
                            batches.forEach(batch => {
                                $('#batch').append(`<option value="${batch}">${batch}</option>`);
                            });
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>