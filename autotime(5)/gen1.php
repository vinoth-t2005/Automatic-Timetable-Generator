<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = ""; // Empty password as specified
$dbname = "autotime";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to get unique values from a column
function getUniqueValues($conn, $table, $column) {
    $sql = "SELECT DISTINCT $column FROM $table";
    $result = $conn->query($sql);
    $values = array();
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $values[] = $row[$column];
        }
    }
    
    return $values;
}

// Get departments
$departments = getUniqueValues($conn, "classes", "dept");

// Handle AJAX requests
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'get_sections' && isset($_POST['dept'])) {
        $dept = $_POST['dept'];
        $sql = "SELECT DISTINCT sectionadvisor FROM classes WHERE dept = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $dept);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $sections = array();
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $sections[] = $row['sectionadvisor'];
            }
        }
        echo json_encode($sections);
        exit;
    } else if ($_POST['action'] == 'get_years' && isset($_POST['dept']) && isset($_POST['section'])) {
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $sql = "SELECT DISTINCT year FROM classes WHERE dept = ? AND sectionadvisor = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $dept, $section);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $years = array();
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $years[] = $row['year'];
            }
        }
        echo json_encode($years);
        exit;
    } else if ($_POST['action'] == 'get_semesters' && isset($_POST['dept']) && isset($_POST['section']) && isset($_POST['year'])) {
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $year = $_POST['year'];
        $sql = "SELECT DISTINCT semester FROM classes WHERE dept = ? AND sectionadvisor = ? AND year = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $dept, $section, $year);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $semesters = array();
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $semesters[] = $row['semester'];
            }
        }
        echo json_encode($semesters);
        exit;
    } else if ($_POST['action'] == 'get_batches' && isset($_POST['dept']) && isset($_POST['section']) && isset($_POST['year']) && isset($_POST['semester'])) {
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $year = $_POST['year'];
        $semester = $_POST['semester'];
        $sql = "SELECT DISTINCT batch_start, batch_end FROM classes WHERE dept = ? AND sectionadvisor = ? AND year = ? AND semester = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssii", $dept, $section, $year, $semester);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $batches = array();
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $batches[] = $row['batch_start'] . "-" . $row['batch_end'];
            }
        }
        echo json_encode($batches);
        exit;
    }
}

// Generate ID
$generatedId = "";
if (isset($_POST['generate']) && isset($_POST['dept']) && isset($_POST['section']) && isset($_POST['year']) && isset($_POST['semester']) && isset($_POST['batch'])) {
    $dept = $_POST['dept'];
    $section = $_POST['section'];
    $year = $_POST['year'];
    $semester = $_POST['semester'];
    $batch = $_POST['batch'];
    
    $generatedId = "$dept-$section-$year-$semester-$batch";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate ID</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // When department is selected
            $('#dept').change(function() {
                var dept = $(this).val();
                if (dept != '') {
                    $.ajax({
                        url: 'gen.php',
                        method: 'POST',
                        data: {action: 'get_sections', dept: dept},
                        dataType: 'json',
                        success: function(data) {
                            $('#section').empty();
                            $('#section').append('<option value="">Select Section</option>');
                            $.each(data, function(index, value) {
                                $('#section').append('<option value="' + value + '">' + value + '</option>');
                            });
                            $('#year').empty().append('<option value="">Select Year</option>');
                            $('#semester').empty().append('<option value="">Select Semester</option>');
                            $('#batch').empty().append('<option value="">Select Batch</option>');
                        }
                    });
                } else {
                    $('#section').empty().append('<option value="">Select Section</option>');
                    $('#year').empty().append('<option value="">Select Year</option>');
                    $('#semester').empty().append('<option value="">Select Semester</option>');
                    $('#batch').empty().append('<option value="">Select Batch</option>');
                }
            });

            // When section is selected
            $('#section').change(function() {
                var dept = $('#dept').val();
                var section = $(this).val();
                if (section != '') {
                    $.ajax({
                        url: 'gen.php',
                        method: 'POST',
                        data: {action: 'get_years', dept: dept, section: section},
                        dataType: 'json',
                        success: function(data) {
                            $('#year').empty();
                            $('#year').append('<option value="">Select Year</option>');
                            $.each(data, function(index, value) {
                                $('#year').append('<option value="' + value + '">' + value + '</option>');
                            });
                            $('#semester').empty().append('<option value="">Select Semester</option>');
                            $('#batch').empty().append('<option value="">Select Batch</option>');
                        }
                    });
                } else {
                    $('#year').empty().append('<option value="">Select Year</option>');
                    $('#semester').empty().append('<option value="">Select Semester</option>');
                    $('#batch').empty().append('<option value="">Select Batch</option>');
                }
            });

            // When year is selected
            $('#year').change(function() {
                var dept = $('#dept').val();
                var section = $('#section').val();
                var year = $(this).val();
                if (year != '') {
                    $.ajax({
                        url: 'gen.php',
                        method: 'POST',
                        data: {action: 'get_semesters', dept: dept, section: section, year: year},
                        dataType: 'json',
                        success: function(data) {
                            $('#semester').empty();
                            $('#semester').append('<option value="">Select Semester</option>');
                            $.each(data, function(index, value) {
                                $('#semester').append('<option value="' + value + '">' + value + '</option>');
                            });
                            $('#batch').empty().append('<option value="">Select Batch</option>');
                        }
                    });
                } else {
                    $('#semester').empty().append('<option value="">Select Semester</option>');
                    $('#batch').empty().append('<option value="">Select Batch</option>');
                }
            });

            // When semester is selected
            $('#semester').change(function() {
                var dept = $('#dept').val();
                var section = $('#section').val();
                var year = $('#year').val();
                var semester = $(this).val();
                if (semester != '') {
                    $.ajax({
                        url: 'gen.php',
                        method: 'POST',
                        data: {action: 'get_batches', dept: dept, section: section, year: year, semester: semester},
                        dataType: 'json',
                        success: function(data) {
                            $('#batch').empty();
                            $('#batch').append('<option value="">Select Batch</option>');
                            $.each(data, function(index, value) {
                                $('#batch').append('<option value="' + value + '">' + value + '</option>');
                            });
                        }
                    });
                } else {
                    $('#batch').empty().append('<option value="">Select Batch</option>');
                }
            });
        });
    </script>
</head>
<body>
    <h1>Generate ID</h1>
    <form method="post" action="">
        <div class="form-group">
            <label for="dept">Department</label>
            <select id="dept" name="dept" required>
                <option value="">Select Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept; ?>" <?php echo (isset($_POST['dept']) && $_POST['dept'] == $dept) ? 'selected' : ''; ?>><?php echo $dept; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="section">Section</label>
            <select id="section" name="section" required>
                <option value="">Select Section</option>
                <?php
                if (isset($_POST['dept'])) {
                    $sections = array();
                    $sql = "SELECT DISTINCT sectionadvisor FROM classes WHERE dept = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $_POST['dept']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $section = $row['sectionadvisor'];
                            echo '<option value="' . $section . '" ' . ((isset($_POST['section']) && $_POST['section'] == $section) ? 'selected' : '') . '>' . $section . '</option>';
                        }
                    }
                }
                ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="year">Year</label>
            <select id="year" name="year" required>
                <option value="">Select Year</option>
                <?php
                if (isset($_POST['dept']) && isset($_POST['section'])) {
                    $years = array();
                    $sql = "SELECT DISTINCT year FROM classes WHERE dept = ? AND sectionadvisor = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ss", $_POST['dept'], $_POST['section']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $year = $row['year'];
                            echo '<option value="' . $year . '" ' . ((isset($_POST['year']) && $_POST['year'] == $year) ? 'selected' : '') . '>' . $year . '</option>';
                        }
                    }
                }
                ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="semester">Semester</label>
            <select id="semester" name="semester" required>
                <option value="">Select Semester</option>
                <?php
                if (isset($_POST['dept']) && isset($_POST['section']) && isset($_POST['year'])) {
                    $semesters = array();
                    $sql = "SELECT DISTINCT semester FROM classes WHERE dept = ? AND sectionadvisor = ? AND year = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssi", $_POST['dept'], $_POST['section'], $_POST['year']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $semester = $row['semester'];
                            echo '<option value="' . $semester . '" ' . ((isset($_POST['semester']) && $_POST['semester'] == $semester) ? 'selected' : '') . '>' . $semester . '</option>';
                        }
                    }
                }
                ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="batch">Batch</label>
            <select id="batch" name="batch" required>
                <option value="">Select Batch</option>
                <?php
                if (isset($_POST['dept']) && isset($_POST['section']) && isset($_POST['year']) && isset($_POST['semester'])) {
                    $batches = array();
                    $sql = "SELECT DISTINCT batch_start, batch_end FROM classes WHERE dept = ? AND sectionadvisor = ? AND year = ? AND semester = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssii", $_POST['dept'], $_POST['section'], $_POST['year'], $_POST['semester']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $batch = $row['batch_start'] . "-" . $row['batch_end'];
                            echo '<option value="' . $batch . '" ' . ((isset($_POST['batch']) && $_POST['batch'] == $batch) ? 'selected' : '') . '>' . $batch . '</option>';
                        }
                    }
                }
                ?>
            </select>
        </div>
        
        <button type="submit" name="generate">Generate ID</button>
    </form>
    
    <?php if (!empty($generatedId)): ?>
    <div class="result">
        <h2>Generated ID</h2>
        <p><strong><?php echo htmlspecialchars($generatedId); ?></strong></p>
    </div>
    <?php endif; ?>
</body>
</html>