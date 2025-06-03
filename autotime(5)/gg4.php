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

// Create templates table if it doesn't exist
$createTemplatesTableSql = "CREATE TABLE IF NOT EXISTS templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($createTemplatesTableSql);

// Create ttid table if it doesn't exist
$createTtidTableSql = "CREATE TABLE IF NOT EXISTS ttid (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timetable_id VARCHAR(255) NOT NULL,
    template_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES templates(id)
)";
$conn->query($createTtidTableSql);

// If templates table is empty, insert some default templates
$checkTemplatesQuery = "SELECT COUNT(*) as count FROM templates";
$result = $conn->query($checkTemplatesQuery);
$row = $result->fetch_assoc();

if ($row['count'] == 0) {
    $insertTemplatesSql = "INSERT INTO templates (name, description) VALUES 
    ('Standard Layout', 'Default timetable template'),
    ('Compact Layout', 'Condensed timetable design'),
    ('Detailed Layout', 'Comprehensive timetable with extra details')";
    $conn->query($insertTemplatesSql);
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

// Function to save timetable ID and template to database
function saveTimetableIdAndTemplate($conn, $timetable_id, $template_id) {
    // First, check if the timetable ID already exists
    $checkSql = "SELECT id FROM ttid WHERE timetable_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $timetable_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    // If timetable ID already exists, update the template
    if ($checkResult->num_rows > 0) {
        $updateSql = "UPDATE ttid SET template_id = ?, created_at = CURRENT_TIMESTAMP WHERE timetable_id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("is", $template_id, $timetable_id);
        $result = $updateStmt->execute();
        $updateStmt->close();
        return $result;
    }
    
    // If timetable ID doesn't exist, insert new record
    $sql = "INSERT INTO ttid (timetable_id, template_id) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $timetable_id, $template_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'getSections':
            $dept = $_POST['dept'];
            $sections = getSectionsByDept($conn, $dept);
            echo json_encode($sections);
            exit;
            
        case 'getYears':
            $dept = $_POST['dept'];
            $section = $_POST['section'];
            $years = getYearsBySectionDept($conn, $dept, $section);
            echo json_encode($years);
            exit;
            
        case 'getSemesters':
            $dept = $_POST['dept'];
            $section = $_POST['section'];
            $year = $_POST['year'];
            $semesters = getSemestersByYearSectionDept($conn, $dept, $section, $year);
            echo json_encode($semesters);
            exit;
            
        case 'getBatches':
            $dept = $_POST['dept'];
            $section = $_POST['section'];
            $year = $_POST['year'];
            $semester = $_POST['semester'];
            $batches = getBatchesByDeptSectionYearSem($conn, $dept, $section, $year, $semester);
            echo json_encode($batches);
            exit;
            
        case 'saveTimetableData':
            $timetable_id = $_POST['timetable_id'];
            $template_id = $_POST['template_id'];
            
            $result = saveTimetableIdAndTemplate($conn, $timetable_id, $template_id);
            
            header('Content-Type: application/json');
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Data saved successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error saving data: ' . $conn->error]);
            }
            exit;
    }
}

// Handle form submission for generating timetable ID
$departments = getDepartments($conn);
$timetable_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dept'])) {
    $dept = $_POST['dept'];
    $section = $_POST['section'];
    $year = $_POST['year'];
    $semester = $_POST['semester'];
    $batch = $_POST['batch'];
    
    $timetable_id = generateTimetableId($dept, $section, $year, $semester, $batch);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Generator</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            line-height: 1.6;
        }
        label {
            display: block;
            margin-top: 10px;
        }
        select, button {
            margin-bottom: 15px;
            padding: 5px;
            width: 200px;
        }
        button {
            cursor: pointer;
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
        }
        button:hover {
            background-color: #45a049;
        }
        #save-button {
            background-color: #2196F3;
        }
        #save-button:hover {
            background-color: #0b7dda;
        }
        .message {
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        .template-preview {
            margin-top: 20px;
            border: 1px solid #ddd;
            padding: 15px;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Department change handler
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
                            // Reset dependent fields
                            $('#year, #semester, #batch').empty().append('<option value="">Select...</option>');
                        }
                    });
                } else {
                    // Reset all fields if no department selected
                    $('#section, #year, #semester, #batch').empty().append('<option value="">Select...</option>');
                }
            });

            // Section change handler
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
                            // Reset dependent fields
                            $('#semester, #batch').empty().append('<option value="">Select...</option>');
                        }
                    });
                } else {
                    // Reset dependent fields
                    $('#year, #semester, #batch').empty().append('<option value="">Select...</option>');
                }
            });

            // Year change handler
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
                            // Reset dependent field
                            $('#batch').empty().append('<option value="">Select...</option>');
                        }
                    });
                } else {
                    // Reset dependent field
                    $('#semester, #batch').empty().append('<option value="">Select...</option>');
                }
            });

            // Semester change handler
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
                } else {
                    // Reset dependent field
                    $('#batch').empty().append('<option value="">Select...</option>');
                }
            });
        });

        // Function to save timetable data
        function saveTimetableData() {
            const timetableId = $('#timetable-id-display').data('timetable-id');
            const templateId = $('#template-select').val();
            
            if (!timetableId) {
                showMessage('Please generate a timetable ID first.', 'error');
                return;
            }
            
            if (!templateId) {
                showMessage('Please select a template first.', 'error');
                return;
            }
            
            $.ajax({
                url: 'gen.php',
                type: 'POST',
                data: {
                    action: 'saveTimetableData',
                    timetable_id: timetableId,
                    template_id: templateId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showMessage(response.message, 'success');
                    } else {
                        showMessage(response.message, 'error');
                    }
                },
                error: function() {
                    showMessage('Error occurred while saving data.', 'error');
                }
            });
        }
        
        // Function to display messages
        function showMessage(message, type) {
            const messageContainer = $('#message-container');
            messageContainer.html(`<div class="message ${type}">${message}</div>`);
            
            // Auto-hide message after 5 seconds
            setTimeout(() => {
                messageContainer.html('');
            }, 5000);
        }
    </script>
</head>
<body>
    <h1>Generate Timetable ID</h1>
    <form method="POST" action="">
        <label for="dept">Department:</label>
        <select id="dept" name="dept" required>
            <option value="">Select Department</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept; ?>"><?php echo $dept; ?></option>
            <?php endforeach; ?>
        </select>
        
        <label for="section">Section:</label>
        <select id="section" name="section" required>
            <option value="">Select Section</option>
        </select>
        
        <label for="year">Year:</label>
        <select id="year" name="year" required>
            <option value="">Select Year</option>
        </select>
        
        <label for="semester">Semester:</label>
        <select id="semester" name="semester" required>
            <option value="">Select Semester</option>
        </select>
        
        <label for="batch">Batch:</label>
        <select id="batch" name="batch" required>
            <option value="">Select Batch</option>
        </select>
        
        <button type="submit">Generate Timetable ID</button>
    </form>

    <?php if ($timetable_id): ?>
        <h2>Generated Timetable ID: <span id="timetable-id-display" data-timetable-id="<?php echo $timetable_id; ?>"><?php echo $timetable_id; ?></span></h2>
    <?php else: ?>
        <h2>Generated Timetable ID: <span id="timetable-id-display" data-timetable-id="">None</span></h2>
    <?php endif; ?>

    <h1>Select Template</h1>
    <label for="template-select">Template:</label>
    <select id="template-select">
        <option value="">-- Select a Template --</option>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <option value="<?php echo $row['id']; ?>"><?php echo $row['name']; ?></option>
            <?php endwhile; ?>
        <?php endif; ?>
    </select>

    <div style="margin-top: 20px;">
        <button id="save-button" onclick="saveTimetableData()">Save Timetable</button>
    </div>

    <div id="message-container"></div>
</body>
</html>

<?php
$conn->close();
?>