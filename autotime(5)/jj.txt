
<?php
// Database connection settings
$host = "localhost";
$user = "root";
$password = "";
$database = "autotime";

// Create connection to MySQL server
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch existing templates for dropdown
$result = $conn->query("SELECT * FROM templates");

// Function to get departments
function getDepartments($conn) {
    $sql = "SELECT DISTINCT dept FROM classes";
    $result = $conn->query($sql);
    $departments = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $departments[] = $row['dept'];
        }
    }
    return $departments;
}

// Function to get sections by department
function getSectionsByDept($conn, $dept) {
    $sql = "SELECT DISTINCT section FROM classes WHERE dept = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dept);
    $stmt->execute();
    $result = $stmt->get_result();
    $sections = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $sections[] = $row['section'];
        }
    }
    $stmt->close();
    return $sections;
}

// Function to get years by department and section
function getYearsBySectionDept($conn, $dept, $section) {
    $sql = "SELECT DISTINCT year FROM classes WHERE dept = ? AND section = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $dept, $section);
    $stmt->execute();
    $result = $stmt->get_result();
    $years = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $years[] = $row['year'];
        }
    }
    $stmt->close();
    return $years;
}

// Function to get semesters by department, section, and year
function getSemestersByYearSectionDept($conn, $dept, $section, $year) {
    $sql = "SELECT DISTINCT semester FROM classes WHERE dept = ? AND section = ? AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $dept, $section, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $semesters = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $semesters[] = $row['semester'];
        }
    }
    $stmt->close();
    return $semesters;
}

// Function to get batches by department, section, year, and semester
function getBatchesByDeptSectionYearSem($conn, $dept, $section, $year, $semester) {
    $sql = "SELECT DISTINCT batch_start, batch_end FROM classes WHERE dept = ? AND section = ? AND year = ? AND semester = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $dept, $section, $year, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $batches = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $batches[] = $row['batch_start'] . '-' . $row['batch_end'];
        }
    }
    $stmt->close();
    return $batches;
}

// Function to generate timetable ID
function generateTimetableId($dept, $section, $year, $semester, $batch) {
    $batch_parts = explode('-', $batch);
    return $dept . '-' . $section . '-' . $year . '-' . $semester . '-' . $batch_parts[0] . '-' . $batch_parts[1];
}

// Initialize variables
$departments = getDepartments($conn);
$timetable_id = isset($_GET['timetable_id']) ? $_GET['timetable_id'] : null;
$template_id = null;
$save_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle timetable ID generation
    if (isset($_POST['generate'])) {
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $year = $_POST['year'];
        $semester = $_POST['semester'];
        $batch = $_POST['batch'];
        
        $timetable_id = generateTimetableId($dept, $section, $year, $semester, $batch);
        
        // Also set the template ID if it was selected
        if (isset($_POST['template_id']) && !empty($_POST['template_id'])) {
            $template_id = $_POST['template_id'];
        }
    }
    
    // Handle saving to database
    if (isset($_POST['save']) && isset($_POST['timetable_id']) && isset($_POST['template_id'])) {
        $timetable_id = $_POST['timetable_id'];
        $template_id = $_POST['template_id'];
        
        // Check if both values are provided
        if (!empty($timetable_id) && !empty($template_id)) {
            // Check if this combination already exists
            $check_sql = "SELECT * FROM ttid WHERE timetable_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $timetable_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update existing record
                $update_sql = "UPDATE ttid SET template_id = ? WHERE timetable_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("is", $template_id, $timetable_id);
                
                if ($update_stmt->execute()) {
                    $save_message = "Record updated successfully!";
                } else {
                    $save_message = "Error updating record: " . $conn->error;
                }
                $update_stmt->close();
            } else {
                // Insert new record
                $insert_sql = "INSERT INTO ttid (timetable_id, template_id) VALUES (?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("si", $timetable_id, $template_id);
                
                if ($insert_stmt->execute()) {
                    $save_message = "Record saved successfully!";
                } else {
                    $save_message = "Error saving record: " . $conn->error;
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        } else {
            $save_message = "Both timetable ID and template must be selected!";
        }
    }
}

// Now include the course assignment code
// Database connection for PDO (course assignments)
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check for missing columns and add them if needed
try {
    // Check if lab_day column exists
    $checkColumn = $pdo->query("SHOW COLUMNS FROM course_assignments LIKE 'lab_day'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing lab_day column
        $pdo->exec("ALTER TABLE course_assignments ADD COLUMN lab_day VARCHAR(20) DEFAULT NULL");
    }
    
    // Check if lab_periods column exists
    $checkColumn = $pdo->query("SHOW COLUMNS FROM course_assignments LIKE 'lab_periods'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing lab_periods column
        $pdo->exec("ALTER TABLE course_assignments ADD COLUMN lab_periods TEXT DEFAULT NULL");
    }
    
    // Check if timetable_id column exists
    $checkColumn = $pdo->query("SHOW COLUMNS FROM course_assignments LIKE 'timetable_id'");
    if ($checkColumn->rowCount() == 0) {
        // Add the missing timetable_id column
        $pdo->exec("ALTER TABLE course_assignments ADD COLUMN timetable_id VARCHAR(100) DEFAULT NULL");
    }
} catch (PDOException $e) {
    die("Error checking/adding columns: " . $e->getMessage());
}

// Create course_assignments table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS course_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            staff_id INT NOT NULL,
            periods VARCHAR(20) NOT NULL,
            is_lab BOOLEAN DEFAULT FALSE,
            lab_day VARCHAR(20) DEFAULT NULL,
            lab_periods TEXT DEFAULT NULL,
            timetable_id VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}

// Fetch departments for course assignments
$dept_courses = [];
$stmt = $pdo->query("SELECT DISTINCT dept FROM courses");
$dept_courses = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch all courses (will be filtered via JavaScript)
$allCourses = [];
$stmt = $pdo->query("SELECT id, name, course_code, credits, dept, staff FROM courses");
$allCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all staff (will be filtered via JavaScript)
$allStaff = [];
$stmt = $pdo->query("SELECT id, name, unique_id, dept FROM staff");
$allStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all templates
$allTemplates = [];
try {
    $stmt = $pdo->query("SELECT * FROM templates");
    $allTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist
    $allTemplates = [];
}

// Handle form submission for course assignments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course'])) {
    // Check if we're removing an assignment
    if (isset($_POST['action']) && $_POST['action'] === 'remove') {
        $assignment_id = $_POST['assignment_id'];
        
        // Delete the assignment
        $query = "DELETE FROM course_assignments WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['id' => $assignment_id]);
        
        // Set success message
        $successMessage = "Course assignment removed successfully!";
    } else {
        // This is a new assignment
        $course_id = $_POST['course'];
        $staff_id = $_POST['staff'];
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
            $query = "INSERT INTO course_assignments (course_id, staff_id, periods, is_lab, lab_day, lab_periods, timetable_id) 
                      VALUES (:course_id, :staff_id, :periods, :is_lab, :lab_day, :lab_periods, :timetable_id)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                'course_id' => $course_id,
                'staff_id' => $staff_id,
                'periods' => $periods,
                'is_lab' => $is_lab,
                'lab_day' => $lab_day,
                'lab_periods' => $lab_periods_json,
                'timetable_id' => $timetable_id
            ]);
        } else {
            // Regular course assignment
            $periods = isset($_POST['auto_periods']) ? 'auto' : $_POST['periods'];
            
            // Insert into database
            $query = "INSERT INTO course_assignments (course_id, staff_id, periods, is_lab, timetable_id) 
                      VALUES (:course_id, :staff_id, :periods, :is_lab, :timetable_id)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                'course_id' => $course_id, 
                'staff_id' => $staff_id,
                'periods' => $periods,
                'is_lab' => $is_lab,
                'timetable_id' => $timetable_id
            ]);
        }
        
        // Get course and staff details for the success message
        $courseQuery = $pdo->prepare("SELECT name, course_code, credits FROM courses WHERE id = :id");
        $courseQuery->execute(['id' => $course_id]);
        $courseDetails = $courseQuery->fetch(PDO::FETCH_ASSOC);
        
        $staffQuery = $pdo->prepare("SELECT name FROM staff WHERE id = :id");
        $staffQuery->execute(['id' => $staff_id]);
        $staffDetails = $staffQuery->fetch(PDO::FETCH_ASSOC);
        
        // Set success message with details
        $successMessage = "Course '{$courseDetails['name']} ({$courseDetails['course_code']})' " . 
                         ($is_lab ? "lab " : "") . 
                         "assigned to {$staffDetails['name']} successfully!";
    }
}

// Fetch existing assignments for the current timetable ID
$existingAssignments = [];
try {
    $query = "
        SELECT ca.id, c.name AS course_name, c.course_code, c.credits, s.name AS staff_name, 
               ca.periods, ca.is_lab, ca.lab_day, ca.lab_periods
        FROM course_assignments ca
        JOIN courses c ON ca.course_id = c.id
        JOIN staff s ON ca.staff_id = s.id
        WHERE ca.timetable_id = :timetable_id
        ORDER BY c.dept, c.course_code
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute(['timetable_id' => $timetable_id]);
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Management System</title>
    <style>
        /* Combined CSS styles */
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
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
            width: 100px;
            margin-right: 10px;
        }
        select, button, input {
            padding: 8px;
            margin-right: 10px;
        }
        .success {
            color: green;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .error-message {
            color: red;
            font-weight: bold;
        }
        select:disabled, input:disabled {
            background-color: #f0f0f0;
            cursor: not-allowed;
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
        .template-preview th {
            background-color: #f2f2f2;
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
        .tab {
            overflow: hidden;
            border: 1px solid #ccc;
            background-color: #f1f1f1;
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
            background-color: #ccc;
        }
        .tabcontent {
            display: none;
            padding: 6px 12px;
            border: 1px solid #ccc;
            border-top: none;
        }
        .timetable-id-display {
            background-color: #f8f9fa;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        .timetable-id-display strong {
            color: #495057;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        // Function to open a specific tab
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tabcontent");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("tablinks");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.className += " active";
            
            // If switching to the assignments tab, ensure we have a timetable ID
            if (tabName === "assignments" && !document.getElementById("timetable_id").value) {
                alert("Please generate a timetable ID first.");
                document.getElementById("generation").style.display = "block";
                document.getElementById("assignments").style.display = "none";
                document.querySelector(".tablinks.active").className = document.querySelector(".tablinks.active").className.replace(" active", "");
                document.querySelector(".tablinks[onclick=\"openTab(event, 'generation')\"]").className += " active";
                return false;
            }
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

        // Store all courses and staff as JavaScript objects
        const allCourses = <?= json_encode($allCourses) ?>;
        const allStaff = <?= json_encode($allStaff) ?>;
        const allTemplates = <?= json_encode($allTemplates) ?>;
        
        // DOM elements for course assignments
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
        });
    </script>
</body>
</html>