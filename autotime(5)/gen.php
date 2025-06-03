<?php
// Include database connection
require_once 'config.php';

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

// Function to get templates
function getTemplates($conn) {
    $sql = "SELECT id, name FROM templates";
    $result = $conn->query($sql);
    $templates = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $templates[$row['id']] = $row['name'];
        }
    }
    return $templates;
}

// Function to get courses by department
function getCoursesByDept($conn, $dept) {
    $sql = "SELECT id, name, course_code, credits FROM courses WHERE dept = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $dept);
    $stmt->execute();
    $result = $stmt->get_result();
    $courses = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $courses[$row['id']] = [
                'name' => $row['name'],
                'code' => $row['course_code'],
                'credits' => $row['credits']
            ];
        }
    }
    $stmt->close();
    return $courses;
}
// Function to get staff by course
function getStaffByCourse($conn, $course_id) {
    $sql = "SELECT s.id, s.name FROM staff s JOIN courses c ON s.id = c.staff WHERE c.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $staff = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $staff[$row['id']] = $row['name'];
        }
    }
    $stmt->close();
    return $staff;
}

// Function to get template details
function getTemplateDetails($conn, $template_id) {
    $sql = "SELECT periods_data, breaks_data, week_start, week_end FROM templates WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $template_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $template = [];
    
    if ($result->num_rows > 0) {
        $template = $result->fetch_assoc();
    }
    $stmt->close();
    return $template;
}

// Function to check staff availability
function checkStaffAvailability($conn, $staff_id, $day, $period) {
    $sql = "SELECT * FROM dayperiod WHERE staff_id = ? AND day = ? AND period = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $staff_id, $day, $period);
    $stmt->execute();
    $result = $stmt->get_result();
    $isAvailable = ($result->num_rows == 0);
    $stmt->close();
    return $isAvailable;
}

// Function to generate timetable
function generateTimetable($conn, $dept, $section, $year, $semester, $batch, $template_id, $courses) {
    // Get template details
    $template = getTemplateDetails($conn, $template_id);
    $periods_data = json_decode($template['periods_data'], true);
    $days = array_keys($periods_data);
    
    // Create timetable ID
    $batch_parts = explode('-', $batch);
    $timetable_id = $dept . '-' . $section . '-' . $year . '-' . $semester . '-' . $batch_parts[0] . '-' . $batch_parts[1];
    
    // Sort courses by credits (descending)
    usort($courses, function($a, $b) {
        return $b['credits'] - $a['credits'];
    });
    
    // Create summary table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS summary (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timetable_id VARCHAR(50),
        course_id INT,
        course_name VARCHAR(100),
        credits INT,
        allocated_periods INT
    )");
    
    // Create dayperiod table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS dayperiod (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timetable_id VARCHAR(50),
        day VARCHAR(20),
        period VARCHAR(20),
        course_id INT,
        staff_id INT,
        is_lab BOOLEAN,
        dept VARCHAR(50),
        section VARCHAR(10)
    )");
    
    // Initialize allocation tracking
    $allocated = [];
    foreach ($courses as $course) {
        $allocated[$course['id']] = 0;
    }
    
    // Distribute courses across the week
    foreach ($courses as $course) {
        $course_id = $course['id'];
        $staff_id = $course['staff_id'];
        $is_lab = $course['is_lab'];
        $required_periods = $is_lab ? ($course['periods'] * 1) : ceil($course['credits']);
        
        // For labs, handle as blocks
        if ($is_lab) {
            $day = $course['day'];
            $start_period = $course['half'] == 'first' ? 1 : 4;
            $end_period = $start_period + $course['periods'] - 1;
            
            // Check if all periods are available
            $all_available = true;
            for ($p = $start_period; $p <= $end_period; $p++) {
                if (!checkStaffAvailability($conn, $staff_id, $day, $p)) {
                    $all_available = false;
                    break;
                }
            }
            
            if ($all_available) {
                // Insert into dayperiod table
                for ($p = $start_period; $p <= $end_period; $p++) {
                    $period = $periods_data[$day][$p-1]; // Adjust index
                    $stmt = $conn->prepare("INSERT INTO dayperiod (timetable_id, day, period, course_id, staff_id, is_lab, dept, section) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssiibss", $timetable_id, $day, $period, $course_id, $staff_id, $is_lab, $dept, $section);
                    $stmt->execute();
                    $stmt->close();
                }
                $allocated[$course_id] += $course['periods'];
            }
        } 
        // For regular courses, distribute across days
        else {
            $allocated_days = [];
            foreach ($days as $day) {
                if ($allocated[$course_id] >= $required_periods) break;
                
                if (!in_array($day, $allocated_days)) {
                    foreach ($periods_data[$day] as $p_index => $period) {
                        if (checkStaffAvailability($conn, $staff_id, $day, $p_index + 1)) {
                            // Insert into dayperiod table
                            $stmt = $conn->prepare("INSERT INTO dayperiod (timetable_id, day, period, course_id, staff_id, is_lab, dept, section) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssiibss", $timetable_id, $day, $period, $course_id, $staff_id, $is_lab, $dept, $section);
                            $stmt->execute();
                            $stmt->close();
                            
                            $allocated[$course_id]++;
                            $allocated_days[] = $day;
                            break;
                        }
                    }
                }
            }
            
            // If we still need more periods
            if ($allocated[$course_id] < $required_periods) {
                foreach ($days as $day) {
                    if ($allocated[$course_id] >= $required_periods) break;
                    
                    foreach ($periods_data[$day] as $p_index => $period) {
                        if ($allocated[$course_id] >= $required_periods) break;
                        
                        if (checkStaffAvailability($conn, $staff_id, $day, $p_index + 1)) {
                            // Insert into dayperiod table
                            $stmt = $conn->prepare("INSERT INTO dayperiod (timetable_id, day, period, course_id, staff_id, is_lab, dept, section) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssiibss", $timetable_id, $day, $period, $course_id, $staff_id, $is_lab, $dept, $section);
                            $stmt->execute();
                            $stmt->close();
                            
                            $allocated[$course_id]++;
                        }
                    }
                }
            }
        }
        
        // Update summary table
        $stmt = $conn->prepare("INSERT INTO summary (timetable_id, course_id, course_name, credits, allocated_periods) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sisii", $timetable_id, $course_id, $course['name'], $course['credits'], $allocated[$course_id]);
        $stmt->execute();
        $stmt->close();
    }
    
    return $timetable_id;
}

// Handle form submission
$departments = getDepartments($conn);
$templates = getTemplates($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Handle AJAX requests for dependent dropdowns
        $action = $_POST['action'];
        
        switch ($action) {
            case 'getSections':
                $dept = $_POST['dept'];
                $sections = getSectionsByDept($conn, $dept);
                echo json_encode($sections);
                break;
                
            case 'getYears':
                $dept = $_POST['dept'];
                $section = $_POST['section'];
                $years = getYearsBySectionDept($conn, $dept, $section);
                echo json_encode($years);
                break;
                
            case 'getSemesters':
                $dept = $_POST['dept'];
                $section = $_POST['section'];
                $year = $_POST['year'];
                $semesters = getSemestersByYearSectionDept($conn, $dept, $section, $year);
                echo json_encode($semesters);
                break;
                
            case 'getBatches':
                $dept = $_POST['dept'];
                $section = $_POST['section'];
                $year = $_POST['year'];
                $semester = $_POST['semester'];
                $batches = getBatchesByDeptSectionYearSem($conn, $dept, $section, $year, $semester);
                echo json_encode($batches);
                break;
                
            case 'getCourses':
                $dept = $_POST['dept'];
                $courses = getCoursesByDept($conn, $dept);
                echo json_encode($courses);
                break;
                
            case 'getStaff':
                $course_id = $_POST['course_id'];
                $staff = getStaffByCourse($conn, $course_id);
                echo json_encode($staff);
                break;
                
            case 'getTemplateDetails':
                $template_id = $_POST['template_id'];
                $template = getTemplateDetails($conn, $template_id);
                echo json_encode($template);
                break;
                
            case 'generateTimetable':
                $dept = $_POST['dept'];
                $section = $_POST['section'];
                $year = $_POST['year'];
                $semester = $_POST['semester'];
                $batch = $_POST['batch'];
                $template_id = $_POST['template_id'];
                $courses = json_decode($_POST['courses'], true);
                
                $timetable_id = generateTimetable($conn, $dept, $section, $year, $semester, $batch, $template_id, $courses);
                echo json_encode(['success' => true, 'timetable_id' => $timetable_id]);
                break;
        }
        exit;
    } else {
        // Process main form submission
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $year = $_POST['year'];
        $semester = $_POST['semester'];
        $batch = $_POST['batch'];
        $template_id = $_POST['template_id'];
        $courses = [];
        
        if (isset($_POST['courses']) && is_array($_POST['courses'])) {
            foreach ($_POST['courses'] as $course) {
                $courses[] = [
                    'id' => $course['id'],
                    'name' => $course['name'],
                    'credits' => $course['credits'],
                    'staff_id' => $course['staff_id'],
                    'is_lab' => isset($course['is_lab']) ? $course['is_lab'] : false,
                    'day' => isset($course['day']) ? $course['day'] : null,
                    'periods' => isset($course['periods']) ? $course['periods'] : null,
                    'half' => isset($course['half']) ? $course['half'] : null,
                    'custom_periods' => isset($course['custom_periods']) ? $course['custom_periods'] : null
                ];
            }
        }
        
        // Generate timetable
        $timetable_id = generateTimetable($conn, $dept, $section, $year, $semester, $batch, $template_id, $courses);
        
        // Redirect to view timetable
        header("Location: view_timetable.php?id=" . $timetable_id);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Timetable</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/css/bootstrap.min.css">
    <style>
        .hidden {
            display: none;
        }
        .course-item, .lab-item {
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        .remove-btn {
            float: right;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h1>Generate Timetable</h1>
        <form id="timetableForm" method="POST" action="">
            <div class="card mb-4">
                <div class="card-header">
                    <h5>Class Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="dept" class="form-label">Department</label>
                            <select id="dept" name="dept" class="form-select" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="section" class="form-label">Section</label>
                            <select id="section" name="section" class="form-select" required disabled>
                                <option value="">Select Section</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="year" class="form-label">Year</label>
                            <select id="year" name="year" class="form-select" required disabled>
                                <option value="">Select Year</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="semester" class="form-label">Semester</label>
                            <select id="semester" name="semester" class="form-select" required disabled>
                                <option value="">Select Semester</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="batch" class="form-label">Batch</label>
                            <select id="batch" name="batch" class="form-select" required disabled>
                                <option value="">Select Batch</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="timetable_id" class="form-label">Timetable ID</label>
                            <input type="text" id="timetable_id" class="form-control" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5>Template</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="template_id" class="form-label">Select Template</label>
                            <select id="template_id" name="template_id" class="form-select" required>
                                <option value="">Select Template</option>
                                <?php foreach ($templates as $id => $name): ?>
                                    <option value="<?php echo $id; ?>"><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Template Details</label>
                            <div id="template_details" class="form-control" style="height: 100px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between">
                    <h5>Courses</h5>
                    <div>
                        <button type="button" id="addCourseBtn" class="btn btn-primary me-2">Add Course</button>
                        <button type="button" id="addLabBtn" class="btn btn-secondary">Add Lab</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="coursesList"></div>
                </div>
            </div>

            <div class="mb-4">
                <button type="submit" class="btn btn-success">Generate Timetable</button>
            </div>
        </form>
    </div>

    <!-- Course Template (Hidden) -->
    <div id="courseTemplate" class="hidden">
        <div class="course-item">
            <button type="button" class="btn btn-sm btn-danger remove-btn">Remove</button>
            <h6>Course</h6>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Department</label>
                    <select name="courses[{index}][dept]" class="form-select course-dept" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Course</label>
                    <select name="courses[{index}][id]" class="form-select course-name" required disabled>
                        <option value="">Select Course</option>
                    </select>
                    <input type="hidden" name="courses[{index}][name]" class="course-name-input">
                    <input type="hidden" name="courses[{index}][credits]" class="course-credits-input">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Staff</label>
                    <select name="courses[{index}][staff_id]" class="form-select course-staff" required disabled>
                        <option value="">Select Staff</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="courses[{index}][is_lab]" value="0">
        </div>
    </div>

    <!-- Lab Template (Hidden) -->
    <div id="labTemplate" class="hidden">
        <div class="lab-item">
            <button type="button" class="btn btn-sm btn-danger remove-btn">Remove</button>
            <h6>Lab</h6>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Department</label>
                    <select name="courses[{index}][dept]" class="form-select course-dept" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Lab Course</label>
                    <select name="courses[{index}][id]" class="form-select course-name" required disabled>
                        <option value="">Select Lab Course</option>
                    </select>
                    <input type="hidden" name="courses[{index}][name]" class="course-name-input">
                    <input type="hidden" name="courses[{index}][credits]" class="course-credits-input">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Staff</label>
                    <select name="courses[{index}][staff_id]" class="form-select course-staff" required disabled>
                        <option value="">Select Staff</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Day</label>
                    <select name="courses[{index}][day]" class="form-select lab-day" required disabled>
                        <option value="">Select Day</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Number of Periods</label>
                    <select name="courses[{index}][periods]" class="form-select lab-periods" required disabled>
                        <option value="">Select Periods</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Half</label>
                    <select name="courses[{index}][half]" class="form-select lab-half" required disabled>
                        <option value="">Select Half</option>
                        <option value="first">First Half</option>
                        <option value="second">Second Half</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12 mb-3">
                    <div class="form-check">
                        <input class="form-check-input lab-custom-check" type="checkbox" id="customCheck{index}">
                        <label class="form-check-label" for="customCheck{index}">
                            Custom Periods
                        </label>
                    </div>
                </div>
            </div>
            <div class="row lab-custom-periods hidden">
                <div class="col-md-12 mb-3">
                    <label class="form-label">Select Periods</label>
                    <div id="customPeriods{index}" class="form-control" style="height: 100px; overflow-y: auto;"></div>
                </div>
            </div>
            <input type="hidden" name="courses[{index}][is_lab]" value="1">
            <input type="hidden" name="courses[{index}][custom_periods]" class="lab-custom-periods-input">
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            let courseIndex = 0;
            let templateData = null;

            // Update Timetable ID
            function updateTimetableId() {
                const dept = $('#dept').val();
                const section = $('#section').val();
                const year = $('#year').val();
                const semester = $('#semester').val();
                const batch = $('#batch').val();
                
                if (dept && section && year && semester && batch) {
                    const batchParts = batch.split('-');
                    const timetableId = `${dept}-${section}-${year}-${semester}-${batchParts[0]}-${batchParts[1]}`;
                    $('#timetable_id').val(timetableId);
                }
            }

            // Department change handler
            $('#dept').change(function() {
                const dept = $(this).val();
                
                if (dept) {
                    // Get sections for the selected department
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getSections', dept: dept },
                        dataType: 'json',
                        success: function(sections) {
                            $('#section').empty().append('<option value="">Select Section</option>');
                            
                            $.each(sections, function(i, section) {
                                $('#section').append(`<option value="${section}">${section}</option>`);
                            });
                            
                            $('#section').prop('disabled', false);
                            $('#year, #semester, #batch').val('').prop('disabled', true);
                            updateTimetableId();
                        }
                    });
                } else {
                    $('#section, #year, #semester, #batch').val('').prop('disabled', true);
                    updateTimetableId();
                }
            });

            // Section change handler
            $('#section').change(function() {
                const dept = $('#dept').val();
                const section = $(this).val();
                
                if (dept && section) {
                    // Get years for the selected department and section
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getYears', dept: dept, section: section },
                        dataType: 'json',
                        success: function(years) {
                            $('#year').empty().append('<option value="">Select Year</option>');
                            
                            $.each(years, function(i, year) {
                                $('#year').append(`<option value="${year}">${year}</option>`);
                            });
                            
                            $('#year').prop('disabled', false);
                            $('#semester, #batch').val('').prop('disabled', true);
                            updateTimetableId();
                        }
                    });
                } else {
                    $('#year, #semester, #batch').val('').prop('disabled', true);
                    updateTimetableId();
                }
            });

            // Year change handler
            $('#year').change(function() {
                const dept = $('#dept').val();
                const section = $('#section').val();
                const year = $(this).val();
                
                if (dept && section && year) {
                    // Get semesters for the selected department, section, and year
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getSemesters', dept: dept, section: section, year: year },
                        dataType: 'json',
                        success: function(semesters) {
                            $('#semester').empty().append('<option value="">Select Semester</option>');
                            
                            $.each(semesters, function(i, semester) {
                                $('#semester').append(`<option value="${semester}">${semester}</option>`);
                            });
                            
                            $('#semester').prop('disabled', false);
                            $('#batch').val('').prop('disabled', true);
                            updateTimetableId();
                        }
                    });
                } else {
                    $('#semester, #batch').val('').prop('disabled', true);
                    updateTimetableId();
                }
            });

            // Semester change handler
            $('#semester').change(function() {
                const dept = $('#dept').val();
                const section = $('#section').val();
                const year = $('#year').val();
                const semester = $(this).val();
                
                if (dept && section && year && semester) {
                    // Get batches for the selected department, section, year, and semester
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getBatches', dept: dept, section: section, year: year, semester: semester },
                        dataType: 'json',
                        success: function(batches) {
                            $('#batch').empty().append('<option value="">Select Batch</option>');
                            
                            $.each(batches, function(i, batch) {
                                $('#batch').append(`<option value="${batch}">${batch}</option>`);
                            });
                            
                            $('#batch').prop('disabled', false);
                            updateTimetableId();
                        }
                    });
                } else {
                    $('#batch').val('').prop('disabled', true);
                    updateTimetableId();
                }
            });

            // Batch change handler
            $('#batch').change(function() {
                updateTimetableId();
            });

            // Template change handler
            $('#template_id').change(function() {
                const templateId = $(this).val();
                
                if (templateId) {
                    // Get template details
                    $.ajax({
                        url: 'gen.php',
                        type: 'POST',
                        data: { action: 'getTemplateDetails', template_id: templateId },
                        dataType: 'json',
                        success: function(template) {
                            templateData = template;
                            
                            // Display template details
                            let details = `<p>Week Days: ${template.week_start} to ${template.week_end}</p>`;
                            
                            const periodsData = JSON.parse(template.periods_data);
                            let periodsInfo = '<p>Periods:<br/>';
                            
                            $.each(periodsData, function(day, periods) {
                                periodsInfo += `${day}: ${periods.length} periods<br/>`;
                            });
                            
                            periodsInfo += '</p>';
                            details += periodsInfo;
                            
                            const breaksData = JSON.parse(template.breaks_data);
                            if (Object.keys(breaksData).length > 0) {
                                let breaksInfo = '<p>Breaks:<br/>';
                                
                                $.each(breaksData, function(day, breaks) {
                                    breaksInfo += `${day}: ${breaks.length} breaks<br/>`;
                                });
                                
                                breaksInfo += '</p>';
                                details += breaksInfo;
                            }
                            
                            $('#template_details').html(details);
                            
                            // Update lab day dropdowns with template days
                            $('.lab-day').each(function() {
                                $(this).empty().append('<option value="">Select Day</option>');
                                
                                $.each(Object.keys(periodsData), function(i, day) {
                                    $('.lab-day').append(`<option value="${day}">${day}</option>`);
                                });
                            });
                        }
                    });
                } else {
                    $('#template_details').html('');
                    templateData = null;
                }
            });

            // Add Course button handler
            $('#addCourseBtn').click(function() {
                const courseItem = $('#courseTemplate').html().replace(/{index}/g, courseIndex);
                $('#coursesList').append(courseItem);
                courseIndex++;
                bindCourseEvents();
            });

            // Add Lab button handler
            $('#addLabBtn').click(function() {
                const labItem = $('#labTemplate').html().replace(/{index}/g, courseIndex);
                $('#coursesList').append(labItem);
                courseIndex++;
                bindLabEvents();
            });

            // Bind events for course items
            function bindCourseEvents() {
                // Department change handler for courses
                $('.course-dept').last().change(function() {
                    const dept = $(this).val();
                    const courseSelect = $(this).closest('.course-item').find('.course-name');
                    
                    if (dept) {
                        // Get courses for the selected department
                        $.ajax({
                            url: 'gen.php',
                            type: 'POST',
                            data: { action: 'getCourses', dept: dept },
                            dataType: 'json',
                            success: function(courses) {
                                courseSelect.empty().append('<option value="">Select Course</option>');
                                
                                $.each(courses, function(id, course) {
                                    courseSelect.append(`<option value="${id}" data-name="${course.name}" data-credits="${course.credits}">${course.name} (${course.code})</option>`);
                                });
                                
                                courseSelect.prop('disabled', false);
                                courseSelect.closest('.course-item').find('.course-staff').val('').prop('disabled', true);
                            }
                        });
                    } else {
                        courseSelect.val('').prop('disabled', true);
                        courseSelect.closest('.course-item').find('.course-staff').val('').prop('disabled', true);
                    }
                });

                // Course change handler
                $('.course-name').last().change(function() {
                    const courseId = $(this).val();
                    const courseItem = $(this).closest('.course-item');
                    const staffSelect = courseItem.find('.course-staff');
                    const courseName = $(this).find('option:selected').data('name');
                    const courseCredits = $(this).find('option:selected').data('credits');
                    
                    // Store course name and credits in hidden inputs
                    courseItem.find('.course-name-input').val(courseName);
                    courseItem.find('.course-credits-input').val(courseCredits);
                    
                    if (courseId) {
                        // Get staff for the selected course
                        $.ajax({
                            url: 'gen.php',
                            type: 'POST',
                            data: { action: 'getStaff', course_id: courseId },
                            dataType: 'json',
                            success: function(staff) {
                                staffSelect.empty().append('<option value="">Select Staff</option>');
                                
                                $.each(staff, function(id, name) {
                                    staffSelect.append(`<option value="${id}">${name}</option>`);
                                });
                                
                                staffSelect.prop('disabled', false);
                            }
                        });
                    } else {
                        staffSelect.val('').prop('disabled', true);
                    }
                });

                // Remove button handler
                $('.remove-btn').last().click(function() {
                    $(this).closest('.course-item, .lab-item').remove();
                });
            }

            // Bind events for lab items
            function bindLabEvents() {
                // Department change handler for labs
                $('.course-dept').last().change(function() {
                    const dept = $(this).val();
                    const courseSelect = $(this).closest('.lab-item').find('.course-name');
                    
                    if (dept) {
                        // Get courses for the selected department
                        $.ajax({
                            url: 'gen.php',
                            type: 'POST',
                            data: { action: 'getCourses', dept: dept },
                            dataType: 'json',
                            success: function(courses) {
                                courseSelect.empty().append('<option value="">Select Lab Course</option>');
                                
                                // Filter for lab courses (can be improved with backend filtering)
                                $.each(courses, function(id, course) {
                                    if (course.name.toLowerCase().includes('lab')) {
                                        courseSelect.append(`<option value="${id}" data-name="${course.name}" data-credits="${course.credits}">${course.name} (${course.code})</option>`);
                                    }
                                });
                                
                                courseSelect.prop('disabled', false);
                                const labItem = courseSelect.closest('.lab-item');
                                labItem.find('.course-staff').val('').prop('disabled', true);
                                labItem.find('.lab-day, .lab-periods, .lab-half').val('').prop('disabled', true);
                            }
                        });
                    } else {
                        const labItem = $(this).closest('.lab-item');
                        labItem.find('.course-name, .course-staff').val('').prop('disabled', true);
                        labItem.find('.lab-day, .lab-periods, .lab-half').val('').prop('disabled', true);
                    }
                });

                // Lab course change handler
                $('.course-name').last().change(function() {
                    const courseId = $(this).val();
                    const labItem = $(this).closest('.lab-item');
                    const staffSelect = labItem.find('.course-staff');
                    const courseName = $(this).find('option:selected').data('name');
                    const courseCredits = $(this).find('option:selected').data('credits');
                    
                    // Store course name and credits in hidden inputs
                    labItem.find('.course-name-input').val(courseName);
                    labItem.find('.course-credits-input').val(courseCredits);
                    
                    if (courseId) {
                        // Get staff for the selected course
                        $.ajax({
                            url: 'gen.php',
                            type: 'POST',
                            data: { action: 'getStaff', course_id: courseId },
                            dataType: 'json',
                            success: function(staff) {
                                staffSelect.empty().append('<option value="">Select Staff</option>');
                                
                                $.each(staff, function(id, name) {
                                    staffSelect.append(`<option value="${id}">${name}</option>`);
                                });
                                
                                staffSelect.prop('disabled', false);
                                labItem.find('.lab-day, .lab-periods, .lab-half').val('').prop('disabled', true);
                            }
                        });
                    } else {
                        staffSelect.val('').prop('disabled', true);
                        labItem.find('.lab-day, .lab-periods, .lab-half').val('').prop('disabled', true);
                    }
                });

                // Staff change handler for labs
                $('.course-staff').last().change(function() {
                    const staffId = $(this).val();
                    const labItem = $(this).closest('.lab-item');
                    
                    if (staffId && templateData) {
                        labItem.find('.lab-day').prop('disabled', false);
                    } else {
                        labItem.find('.lab-day, .lab-periods, .lab-half').val('').prop('disabled', true);
                    }
                });

                // Day change handler for labs
                $('.lab-day').last().change(function() {
                    const day = $(this).val();
                    const labItem = $(this).closest('.lab-item');
                    
                    if (day) {
                        labItem.find('.lab-periods').prop('disabled', false);
                    } else {
                        labItem.find('.lab-periods, .lab-half').val('').prop('disabled', true);
                    }
                });

                // Periods change handler for labs
                $('.lab-periods').last().change(function() {
                    const periods = $(this).val();
                    const labItem = $(this).closest('.lab-item');
                    
                    if (periods) {
                        labItem.find('.lab-half').prop('disabled', false);
                    } else {
                        labItem.find('.lab-half').val('').prop('disabled', true);
                    }
                });

                // Custom periods checkbox handler
                $('.lab-custom-check').last().change(function() {
                    const labItem = $(this).closest('.lab-item');
                    const customPeriods = labItem.find('.lab-custom-periods');
                    
                    if ($(this).is(':checked')) {
                        customPeriods.removeClass('hidden');
                        labItem.find('.lab-half').prop('disabled', true);
                        
                        // Generate custom periods checkboxes based on template data
                        const day = labItem.find('.lab-day').val();
                        if (day && templateData) {
                            const periodsData = JSON.parse(templateData.periods_data);
                            const dayPeriods = periodsData[day];
                            
                            const customPeriodsContainer = labItem.find('[id^="customPeriods"]');
                            customPeriodsContainer.empty();
                            
                            $.each(dayPeriods, function(i, period) {
                                const checkboxId = `period_${labItem.index()}_${i}`;
                                customPeriodsContainer.append(`
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input custom-period-checkbox" type="checkbox" id="${checkboxId}" value="${i+1}">
                                        <label class="form-check-label" for="${checkboxId}">${period}</label>
                                    </div>
                                `);
                            });
                            
                            // Bind change event to update hidden input with selected periods
                            $('.custom-period-checkbox').change(function() {
                                const selectedPeriods = [];
                                labItem.find('.custom-period-checkbox:checked').each(function() {
                                    selectedPeriods.push($(this).val());
                                });
                                labItem.find('.lab-custom-periods-input').val(JSON.stringify(selectedPeriods));
                            });
                        }
                    } else {
                        customPeriods.addClass('hidden');
                        labItem.find('.lab-half').prop('disabled', false);
                        labItem.find('.lab-custom-periods-input').val('');
                    }
                });

                // Remove button handler
                $('.remove-btn').last().click(function() {
                    $(this).closest('.course-item, .lab-item').remove();
                });
            }

            // Form submit handler
            $('#timetableForm').submit(function(e) {
                e.preventDefault();
                
                const formData = $(this).serialize();
                
                $.ajax({
                    url: 'gen.php',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        try {
                            const result = JSON.parse(response);
                            if (result.success) {
                                window.location.href = 'view_timetable.php?id=' + result.timetable_id;
                            } else {
                                alert('Error generating timetable: ' + (result.message || 'Unknown error'));
                            }
                        } catch (e) {
                            // If response is not JSON, it might be a redirect
                            // Just reload the page to follow redirection
                            window.location.reload();
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error: ' + error);
                    }
                });
            });
        });
    </script>
</body>
</html>