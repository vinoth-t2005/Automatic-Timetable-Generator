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

// Add missing columns to the course_assignments table (if they don't exist)
$sql = "ALTER TABLE course_assignments
        ADD COLUMN IF NOT EXISTS timetable_id VARCHAR(255) NOT NULL,
        ADD COLUMN IF NOT EXISTS template_id INT NOT NULL;";

if ($conn->query($sql) === TRUE) {
    // Columns added successfully (or already exist)
} else {
    echo "Error adding columns: " . $conn->error;
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

// Example usage:
$departments = getDepartments($conn);
$timetable_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dept = $_POST['dept'];
    $section = $_POST['section'];
    $year = $_POST['year'];
    $semester = $_POST['semester'];
    $batch = $_POST['batch'];
    $template_id = $_POST['template_id'];
    
    $timetable_id = generateTimetableId($dept, $section, $year, $semester, $batch);
    
    // Insert the generated timetable ID and template ID into the course_assignments table
    $stmt = $conn->prepare("INSERT INTO course_assignments (timetable_id, template_id) VALUES (?, ?)");
    $stmt->bind_param("si", $timetable_id, $template_id);
    if ($stmt->execute()) {
        echo "<p>Timetable ID and Template ID successfully stored in the database.</p>";
    } else {
        echo "<p>Error storing data in the database: " . $stmt->error . "</p>";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable ID Generation and Template Preview</title>
    <style>
        /* Add your CSS styles here */
        .template-preview table {
            border-collapse: collapse;
            width: 100%;
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
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        // Function to fetch and display template preview
        function fetchTemplatePreview(templateId) {
            if (!templateId) {
                document.getElementById("template-preview-container").innerHTML = "<p>Please select a template to preview.</p>";
                return;
            }

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

        $(document).ready(function() {
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
        
        <label for="template-select">Select Template:</label>
        <select id="template-select" name="template_id" required>
            <option value="">-- Select a Template --</option>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <option value="<?php echo $row['id']; ?>"><?php echo $row['name']; ?></option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>
        
        <button type="submit">Generate Timetable ID</button>
    </form>

    <?php if ($timetable_id): ?>
        <h2>Generated Timetable ID: <?php echo $timetable_id; ?></h2>
    <?php endif; ?>

    <h1>Timetable Template Preview</h1>
    <!-- Template Selection Dropdown -->
    <label for="template-select">Select Template:</label>
    <select id="template-select" onchange="fetchTemplatePreview(this.value)">
        <option value="">-- Select a Template --</option>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <option value="<?php echo $row['id']; ?>"><?php echo $row['name']; ?></option>
            <?php endwhile; ?>
        <?php endif; ?>
    </select>

    <!-- Template Preview Section -->
    <div id="template-preview-container">
        <p>Please select a template to preview.</p>
    </div>
</body>
</html>

<?php
$conn->close();
?>