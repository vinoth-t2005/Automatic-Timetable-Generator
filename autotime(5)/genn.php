<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "autotime";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$classes = [];
$templates = [];
$departments = [];
$courses = [];
$staff = [];
$message = "";
$timetable = [];
$generatedId = "";

// Fetch classes
$sql = "SELECT * FROM classes";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
}

// Fetch templates
$sql = "SELECT * FROM templates";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $templates[] = $row;
    }
}

// Fetch departments
$sql = "SELECT DISTINCT dept FROM courses";
$result = $conn->query($sql);
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $departments[] = $row['dept'];
    }
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['generate_id'])) {
        // Generate ID
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $year = $_POST['year'];
        $sem = $_POST['sem'];
        $batch_start = $_POST['batch_start'];
        $batch_end = $_POST['batch_end'];
        
        $generatedId = "$dept-$section-$year-$sem-$batch_start-$batch_end";
    }
    
    if (isset($_POST['generate_timetable'])) {
        $classId = $_POST['class_id'];
        $templateId = $_POST['template_id'];
        
        // Get template data
        $sql = "SELECT * FROM templates WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $templateId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $template = $result->fetch_assoc();
            $periods_data = json_decode($template['periods_data'], true);
            $breaks_data = json_decode($template['breaks_data'], true);
            
            // Initialize timetable structure
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $day) {
                $timetable[$day] = [];
                for ($i = 1; $i <= count($periods_data); $i++) {
                    $timetable[$day][$i] = null;
                }
            }
            
            // Get courses for the selected class
            $sql = "SELECT c.* FROM courses c 
                    JOIN classes cl ON c.dept = cl.dept 
                    WHERE cl.id = ? 
                    ORDER BY c.credits DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $classId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $courses = [];
            if ($result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $courses[] = $row;
                }
            }
            
            // Calculate total available periods
            $totalPeriods = count($days) * count($periods_data);
            $availablePeriods = $totalPeriods;
            
            // Count breaks
            if (!empty($breaks_data)) {
                $availablePeriods -= count($breaks_data);
            }
            
            // Process course assignments
            $selectedCourses = isset($_POST['selected_courses']) ? $_POST['selected_courses'] : [];
            $selectedStaff = isset($_POST['selected_staff']) ? $_POST['selected_staff'] : [];
            $coursePeriods = isset($_POST['course_periods']) ? $_POST['course_periods'] : [];
            $autoAssign = isset($_POST['auto_assign']) ? $_POST['auto_assign'] : [];
            
            // Process lab assignments
            $selectedLabs = isset($_POST['selected_labs']) ? $_POST['selected_labs'] : [];
            $selectedLabStaff = isset($_POST['selected_lab_staff']) ? $_POST['selected_lab_staff'] : [];
            $labDays = isset($_POST['lab_days']) ? $_POST['lab_days'] : [];
            $labPeriods = isset($_POST['lab_periods']) ? $_POST['lab_periods'] : [];
            $labHalf = isset($_POST['lab_half']) ? $_POST['lab_half'] : [];
            
            // Calculate lab periods
            $labPeriodsTotal = 0;
            for ($i = 0; $i < count($selectedLabs); $i++) {
                $labPeriodsTotal += intval($labPeriods[$i]);
            }
            
            // Adjust available periods after accounting for labs
            $availablePeriods -= $labPeriodsTotal;
            
            // Auto-assign periods to courses if requested
            $totalCredits = 0;
            $autoAssignCourses = [];
            
            for ($i = 0; $i < count($selectedCourses); $i++) {
                if (isset($autoAssign[$i]) && $autoAssign[$i] == 1) {
                    $courseId = $selectedCourses[$i];
                    
                    // Get course details
                    $sql = "SELECT * FROM courses WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $courseId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $course = $result->fetch_assoc();
                    
                    // Add to auto-assign list
                    $autoAssignCourses[] = [
                        'index' => $i,
                        'id' => $courseId,
                        'credits' => $course['credits']
                    ];
                    
                    // Track total credits
                    $totalCredits += $course['credits'];
                }
            }
            
            // Calculate periods for auto-assigned courses
            if ($totalCredits > 0 && !empty($autoAssignCourses)) {
                foreach ($autoAssignCourses as &$course) {
                    // Calculate periods based on credits ratio
                    $periodShare = ($course['credits'] / $totalCredits) * $availablePeriods;
                    $assignedPeriods = max(1, round($periodShare));
                    
                    // Update coursePeriods array
                    $coursePeriods[$course['index']] = $assignedPeriods;
                }
            }
            
            // Staff availability tracking
            $staffAvailability = [];
            
            // Assign labs first (they have more constraints)
            for ($i = 0; $i < count($selectedLabs); $i++) {
                $labId = $selectedLabs[$i];
                $staffId = $selectedLabStaff[$i];
                $day = $labDays[$i];
                $periodsNeeded = $labPeriods[$i];
                $half = $labHalf[$i];
                
                // Get lab details
                $sql = "SELECT * FROM courses WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $labId);
                $stmt->execute();
                $result = $stmt->get_result();
                $lab = $result->fetch_assoc();
                
                // Get staff details
                $sql = "SELECT * FROM staff WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $staffId);
                $stmt->execute();
                $result = $stmt->get_result();
                $staffMember = $result->fetch_assoc();
                
                // Determine periods to allocate based on half
                $startPeriod = ($half == 'first') ? 1 : (count($periods_data) - $periodsNeeded + 1);
                $endPeriod = $startPeriod + $periodsNeeded - 1;
                
                // Check staff availability
                $staffIsAvailable = true;
                for ($p = $startPeriod; $p <= $endPeriod; $p++) {
                    if (isset($staffAvailability[$staffId][$day][$p]) && $staffAvailability[$staffId][$day][$p]) {
                        $staffIsAvailable = false;
                        break;
                    }
                }
                
                // Assign lab if staff is available
                if ($staffIsAvailable) {
                    for ($p = $startPeriod; $p <= $endPeriod; $p++) {
                        $timetable[$day][$p] = [
                            'type' => 'lab',
                            'name' => $lab['name'],
                            'code' => $lab['course_code'],
                            'staff' => $staffMember['name'],
                            'span' => ($p == $startPeriod) ? $periodsNeeded : 0 // Mark span only on first period
                        ];
                        
                        // Mark staff as unavailable for these periods
                        if (!isset($staffAvailability[$staffId])) {
                            $staffAvailability[$staffId] = [];
                        }
                        if (!isset($staffAvailability[$staffId][$day])) {
                            $staffAvailability[$staffId][$day] = [];
                        }
                        $staffAvailability[$staffId][$day][$p] = true;
                    }
                } else {
                    $message .= "Could not allocate lab {$lab['name']} due to staff availability conflicts.<br>";
                }
            }
            
            // Assign regular courses
            for ($i = 0; $i < count($selectedCourses); $i++) {
                $courseId = $selectedCourses[$i];
                $staffId = $selectedStaff[$i];
                $periodsPerWeek = $coursePeriods[$i];
                
                // Get course details
                $sql = "SELECT * FROM courses WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $courseId);
                $stmt->execute();
                $result = $stmt->get_result();
                $course = $result->fetch_assoc();
                
                // Get staff details
                $sql = "SELECT * FROM staff WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $staffId);
                $stmt->execute();
                $result = $stmt->get_result();
                $staffMember = $result->fetch_assoc();
                
                // Try to spread the course across different days
                $periodsAssigned = 0;
                $daysToTry = $days;
                shuffle($daysToTry); // Randomize days for better distribution
                
                while ($periodsAssigned < $periodsPerWeek && !empty($daysToTry)) {
                    $day = array_shift($daysToTry);
                    
                    // Find available period on this day
                    for ($p = 1; $p <= count($periods_data); $p++) {
                        // Skip if period is already allocated
                        if ($timetable[$day][$p] !== null) {
                            continue;
                        }
                        
                        // Check staff availability
                        if (isset($staffAvailability[$staffId][$day][$p]) && $staffAvailability[$staffId][$day][$p]) {
                            continue;
                        }
                        
                        // Assign course to period
                        $timetable[$day][$p] = [
                            'type' => 'course',
                            'name' => $course['name'],
                            'code' => $course['course_code'],
                            'staff' => $staffMember['name'],
                            'span' => 1
                        ];
                        
                        // Mark staff as unavailable for this period
                        if (!isset($staffAvailability[$staffId])) {
                            $staffAvailability[$staffId] = [];
                        }
                        if (!isset($staffAvailability[$staffId][$day])) {
                            $staffAvailability[$staffId][$day] = [];
                        }
                        $staffAvailability[$staffId][$day][$p] = true;
                        
                        $periodsAssigned++;
                        break;
                    }
                    
                    // If we couldn't find a slot on this day, put it back at the end of the queue
                    // but only if we still need more periods
                    if ($periodsAssigned < $periodsPerWeek && !in_array($day, $daysToTry)) {
                        $daysToTry[] = $day;
                    }
                }
                
                if ($periodsAssigned < $periodsPerWeek) {
                    $message .= "Could only allocate $periodsAssigned out of $periodsPerWeek periods for course {$course['name']}.<br>";
                }
            }
            
            // Add breaks to timetable
            foreach ($breaks_data as $break) {
                $day = $break['day'];
                $period = $break['period'];
                $timetable[$day][$period] = [
                    'type' => 'break',
                    'name' => 'Break',
                    'span' => 1
                ];
            }
        }
    }
}

// Function to get courses by department
function getCoursesByDept($conn, $dept) {
    $sql = "SELECT * FROM courses WHERE dept = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dept);
    $stmt->execute();
    $result = $stmt->get_result();
    $courses = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
    }
    return $courses;
}

// Function to get staff by department
function getStaffByDept($conn, $dept) {
    $sql = "SELECT * FROM staff WHERE dept = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dept);
    $stmt->execute();
    $result = $stmt->get_result();
    $staff = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $staff[] = $row;
        }
    }
    return $staff;
}

// AJAX endpoint to get courses by department
if (isset($_GET['action']) && $_GET['action'] == 'get_courses') {
    $dept = $_GET['dept'];
    $deptCourses = getCoursesByDept($conn, $dept);
    echo json_encode($deptCourses);
    exit;
}

// AJAX endpoint to get staff by department
if (isset($_GET['action']) && $_GET['action'] == 'get_staff') {
    $dept = $_GET['dept'];
    $deptStaff = getStaffByDept($conn, $dept);
    echo json_encode($deptStaff);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Generator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .timetable {
            width: 100%;
            border-collapse: collapse;
        }
        .timetable th, .timetable td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        .timetable th {
            background-color: #f2f2f2;
        }
        .course {
            background-color: #d4edda;
        }
        .lab {
            background-color: #cce5ff;
        }
        .break {
            background-color: #f8d7da;
        }
        .form-section {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1>Timetable Generator</h1>
        
        <?php if (!empty($message)): ?>
        <div class="alert alert-info">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <div class="form-section">
            <h2>Step 1: Generate ID</h2>
            <form method="post">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label for="dept" class="form-label">Department</label>
                        <input type="text" class="form-control" id="dept" name="dept" required>
                    </div>
                    <div class="col-md-2">
                        <label for="section" class="form-label">Section</label>
                        <input type="text" class="form-control" id="section" name="section" required>
                    </div>
                    <div class="col-md-2">
                        <label for="year" class="form-label">Year</label>
                        <input type="number" class="form-control" id="year" name="year" required>
                    </div>
                    <div class="col-md-2">
                        <label for="sem" class="form-label">Semester</label>
                        <input type="number" class="form-control" id="sem" name="sem" required>
                    </div>
                    <div class="col-md-2">
                        <label for="batch_start" class="form-label">Batch Start</label>
                        <input type="number" class="form-control" id="batch_start" name="batch_start" required>
                    </div>
                    <div class="col-md-2">
                        <label for="batch_end" class="form-label">Batch End</label>
                        <input type="number" class="form-control" id="batch_end" name="batch_end" required>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="generate_id" class="btn btn-primary">Generate ID</button>
                </div>
            </form>
            
            <?php if (!empty($generatedId)): ?>
            <div class="alert alert-success mt-3">
                Generated ID: <strong><?php echo $generatedId; ?></strong>
            </div>
            <?php endif; ?>
        </div>
        
        <h2>Step 2: Create Timetable</h2>
            <form method="post" id="timetable_form">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="class_id" class="form-label">Select Class</label>
                        <select class="form-select" id="class_id" name="class_id" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo "{$class['dept']}-{$class['id']} (Year: {$class['year']}, Sem: {$class['semester']}, Batch: {$class['batch_start']}-{$class['batch_end']})"; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="template_id" class="form-label">Select Template</label>
                        <select class="form-select" id="template_id" name="template_id" required>
                            <option value="">Select Template</option>
                            <?php foreach ($templates as $template): ?>
                            <option value="<?php echo $template['id']; ?>">
                                <?php echo $template['name']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h3>Regular Courses</h3>
                            </div>
                            <div class="card-body">
                                <div id="courses_container">
                                    <!-- Course entries will be added here -->
                                </div>
                                <button type="button" id="add_course" class="btn btn-success mt-2">Add Course</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h3>Lab Courses</h3>
                            </div>
                            <div class="card-body">
                                <div id="labs_container">
                                    <!-- Lab entries will be added here -->
                                </div>
                                <button type="button" id="add_lab" class="btn btn-info mt-2">Add Lab</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <button type="submit" name="generate_timetable" class="btn btn-primary">Generate Timetable</button>
                </div>
            </form>
        </div>
        
        <?php if (!empty($timetable)): ?>
        <div class="form-section">
            <h2>Generated Timetable</h2>
            <div class="table-responsive">
                <table class="timetable">
                    <thead>
                        <tr>
                            <th>Day/Period</th>
                            <?php
                            if (isset($periods_data)) {
                                foreach ($periods_data as $index => $period) {
                                    echo "<th>Period " . ($index + 1) . "<br>" . $period['start_time'] . " - " . $period['end_time'] . "</th>";
                                }
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timetable as $day => $periods): ?>
                        <tr>
                            <td><strong><?php echo $day; ?></strong></td>
                            <?php
                            $columnIndex = 1;
                            while ($columnIndex <= count($periods_data)) {
                                $cell = isset($periods[$columnIndex]) ? $periods[$columnIndex] : null;
                                
                                if ($cell !== null) {
                                    if ($cell['span'] > 0) {  // Only render cells with span > 0
                                        $spanClass = strtolower($cell['type']);
                                        echo "<td class='$spanClass' colspan='{$cell['span']}'>";
                                        echo "<strong>{$cell['name']}</strong>";
                                        if (isset($cell['code'])) {
                                            echo "<br>{$cell['code']}";
                                        }
                                        if (isset($cell['staff'])) {
                                            echo "<br>{$cell['staff']}";
                                        }
                                        echo "</td>";
                                        $columnIndex += $cell['span'];
                                    } else {
                                        $columnIndex++; // Skip cells that are part of a span
                                    }
                                } else {
                                    echo "<td></td>";
                                    $columnIndex++;
                                }
                            }
                            ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Course template (hidden) -->
    <template id="course_template">
        <div class="course-entry border p-3 mb-3">
            <h4>Course #index#</h4>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select class="form-select dept-select" name="course_dept[]" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Course</label>
                    <select class="form-select course-select" name="selected_courses[]" required>
                        <option value="">Select Course</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Staff</label>
                    <select class="form-select staff-select" name="selected_staff[]" required>
                        <option value="">Select Staff</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Periods per Week</label>
                    <input type="number" class="form-control" name="course_periods[]" min="1" max="10" value="3" required>
                </div>
                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input class="form-check-input auto-assign-check" type="checkbox" name="auto_assign[]" value="1">
                        <label class="form-check-label">
                            Auto Assign Periods
                        </label>
                    </div>
                </div>
                <div class="col-md-4">
                    <button type="button" class="btn btn-danger remove-entry mt-4">Remove</button>
                </div>
            </div>
        </div>
    </template>
    
    <!-- Lab template (hidden) -->
    <template id="lab_template">
        <div class="lab-entry border p-3 mb-3">
            <h4>Lab #index#</h4>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select class="form-select dept-select" name="lab_dept[]" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Lab Course</label>
                    <select class="form-select course-select" name="selected_labs[]" required>
                        <option value="">Select Lab</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Staff</label>
                    <select class="form-select staff-select" name="selected_lab_staff[]" required>
                        <option value="">Select Staff</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Day</label>
                    <select class="form-select" name="lab_days[]" required>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Number of Periods</label>
                    <select class="form-select" name="lab_periods[]" required>
                        <option value="2">2</option>
                        <option value="3" selected>3</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Time Slot</label>
                    <select class="form-select" name="lab_half[]" required>
                        <option value="first">First Half</option>
                        <option value="second">Second Half</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <button type="button" class="btn btn-danger remove-entry mt-2">Remove</button>
                </div>
            </div>
        </div>
    </template>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            let courseCount = 0;
            let labCount = 0;
            
            // Add course
            $("#add_course").click(function() {
                courseCount++;
                let template = $("#course_template").html();
                template = template.replace(/#index#/g, courseCount);
                $("#courses_container").append(template);
                bindEvents();
            });
            
            // Add lab
            $("#add_lab").click(function() {
                labCount++;
                let template = $("#lab_template").html();
                template = template.replace(/#index#/g, labCount);
                $("#labs_container").append(template);
                bindEvents();
            });
            
            // Bind events for dynamic content
            function bindEvents() {
                // Remove entry
                $(".remove-entry").off("click").on("click", function() {
                    $(this).closest(".course-entry, .lab-entry").remove();
                });
                
                // Department change event
                $(".dept-select").off("change").on("change", function() {
                    const dept = $(this).val();
                    const courseSelect = $(this).closest(".row").find(".course-select");
                    const staffSelect = $(this).closest(".row").find(".staff-select");
                    
                    // Clear current options
                    courseSelect.html('<option value="">Select Course</option>');
                    staffSelect.html('<option value="">Select Staff</option>');
                    
                   // Get courses for selected department
                    if (dept) {
                        $.getJSON("gen.php?action=get_courses&dept=" + dept, function(data) {
                            $.each(data, function(key, course) {
                                courseSelect.append(`<option value="${course.id}">${course.name} (${course.course_code})</option>`);
                            });
                        });
                        
                        // Get staff for selected department
                        $.getJSON("gen.php?action=get_staff&dept=" + dept, function(data) {
                            $.each(data, function(key, member) {
                                staffSelect.append(`<option value="${member.id}">${member.name}</option>`);
                            });
                        });
                    }
                });
                
                // Auto assign checkbox event
                $(".auto-assign-check").off("change").on("change", function() {
                    const periodsInput = $(this).closest(".row").find("input[name='course_periods[]']");
                    if ($(this).is(":checked")) {
                        periodsInput.prop("disabled", true);
                        periodsInput.val("0"); // Set to 0 to indicate auto assign
                    } else {
                        periodsInput.prop("disabled", false);
                        periodsInput.val("3"); // Reset to default
                    }
                });
            }
            
            // Add initial course and lab
            $("#add_course").click();
            $("#add_lab").click();
        });
    </script>
</body>
</html>