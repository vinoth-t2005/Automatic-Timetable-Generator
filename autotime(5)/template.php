<?php
// Database connection settings
$host = "localhost";
$user = "root";
$password = "";
$database = "autotime2";

// Create connection to MySQL server
$conn = new mysqli($host, $user, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS $database";
$conn->query($sql);
$conn->select_db($database);

// Create templates table if it does not exist (with updated structure)
$tableQuery = "CREATE TABLE IF NOT EXISTS templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    periods_data TEXT,
    breaks_data TEXT,
    week_start VARCHAR(10),
    week_end VARCHAR(10),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($tableQuery);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if delete action
    if (isset($_POST['delete_id'])) {
        $delete_id = $_POST['delete_id'];
        $delete_stmt = $conn->prepare("DELETE FROM templates WHERE id = ?");
        $delete_stmt->bind_param("i", $delete_id);
        
        if ($delete_stmt->execute()) {
            echo "<p style='color:green;'>Template deleted successfully!</p>";
        } else {
            echo "<p style='color:red;'>Error deleting template: " . $delete_stmt->error . "</p>";
        }
        $delete_stmt->close();
    } else {
        // Handle template creation
        $name = $_POST['name'];
        $week_start = $_POST['week_start'];
        $week_end = $_POST['week_end'];
        
        // Process periods and breaks data
        $periods_data = isset($_POST['periods']) ? json_encode($_POST['periods']) : null;
        $breaks_data = isset($_POST['breaks']) ? json_encode($_POST['breaks']) : null;
        
        // Insert template into database
        $stmt = $conn->prepare("INSERT INTO templates (name, periods_data, breaks_data, week_start, week_end) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $name, $periods_data, $breaks_data, $week_start, $week_end);
        
        if ($stmt->execute()) {
            echo "<p style='color:green;'>Template created successfully!</p>";
        } else {
            echo "<p style='color:red;'>Error: " . $stmt->error . "</p>";
        }
        $stmt->close();
    }
}

// Fetch existing templates
$result = $conn->query("SELECT * FROM templates");

// Handle template preview request
if (isset($_GET['preview_id'])) {
    $preview_id = $_GET['preview_id'];
    $preview_stmt = $conn->prepare("SELECT * FROM templates WHERE id = ?");
    $preview_stmt->bind_param("i", $preview_id);
    $preview_stmt->execute();
    $template = $preview_stmt->get_result()->fetch_assoc();
    $preview_stmt->close();
}

$conn->close();
function downloadTemplate($templateId, $format = 'csv') {
    global $host, $user, $password, $database;
    
    // Create connection
    $conn = new mysqli($host, $user, $password, $database);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Fetch template data
    $stmt = $conn->prepare("SELECT * FROM templates WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        die("Template not found");
    }
    
    $template = $result->fetch_assoc();
    $stmt->close();
    
    // Parse template data
    $weekStart = $template['week_start'];
    $weekEnd = $template['week_end'];
    $periodsData = json_decode($template['periods_data'], true);
    $breaksData = json_decode($template['breaks_data'], true);
    
    // Define weekdays
    $allWeekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $startIdx = array_search($weekStart, $allWeekdays);
    $endIdx = array_search($weekEnd, $allWeekdays);
    
    // Get weekdays based on start/end
    $weekdays = [];
    if ($startIdx <= $endIdx) {
        $weekdays = array_slice($allWeekdays, $startIdx, $endIdx - $startIdx + 1);
    } else {
        // Handle week wrap (e.g., Friday to Tuesday)
        $weekdays = array_merge(
            array_slice($allWeekdays, $startIdx),
            array_slice($allWeekdays, 0, $endIdx + 1)
        );
    }
    
    // Combine periods and breaks
    $timeSlots = [];
    
    // Add periods
    foreach ($periodsData as $periodId => $period) {
        $timeSlots[] = [
            'type' => 'period',
            'id' => $periodId,
            'startTime' => $period['start_time'],
            'endTime' => $period['end_time'],
            'label' => "Period $periodId"
        ];
    }
    
    // Add breaks
    foreach ($breaksData as $breakId => $breakData) {
        $breakLabel = isset($breakData['is_lunch']) && $breakData['is_lunch'] ? "Lunch" : "Break";
        $timeSlots[] = [
            'type' => 'break',
            'id' => $breakId,
            'startTime' => $breakData['start_time'],
            'endTime' => $breakData['end_time'],
            'label' => $breakLabel
        ];
    }
    
    // Sort time slots by start time
    usort($timeSlots, function($a, $b) {
        return strtotime("2000-01-01 " . $a['startTime']) - strtotime("2000-01-01 " . $b['startTime']);
    });
    
    // Prepare CSV data
    $csvData = [];
    
    // Header row
    $headerRow = ['Day/Time'];
    foreach ($timeSlots as $slot) {
        $headerRow[] = $slot['label'] . ' (' . $slot['startTime'] . ' - ' . $slot['endTime'] . ')';
    }
    $csvData[] = $headerRow;
    
    // Data rows
    foreach ($weekdays as $day) {
        $row = [$day];
        foreach ($timeSlots as $slot) {
            $row[] = ($slot['type'] === 'period') ? 'Class' : $slot['label'];
        }
        $csvData[] = $row;
    }
    
    // Set headers for download
    $filename = sanitizeFilename($template['name']) . '_template';
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        // Output CSV
        $output = fopen('php://output', 'w');
        foreach ($csvData as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
    } 
    else if ($format === 'excel') {
        // For Excel, we'll use CSV with specific headers
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        // Output Excel-friendly CSV
        echo "<table border='1'>";
        foreach ($csvData as $row) {
            echo "<tr>";
            foreach ($row as $cell) {
                echo "<td>" . htmlspecialchars($cell) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
    $conn->close();
    exit();
}

// Helper function to sanitize filename
function sanitizeFilename($name) {
    // Remove special characters and replace spaces with underscores
    $name = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
    // Remove leading/trailing underscores
    return trim($name, '_');
}

// Handle download request
if (isset($_GET['download_id']) && isset($_GET['format'])) {
    $downloadId = $_GET['download_id'];
    $format = $_GET['format'];
    
    if ($format === 'csv' || $format === 'excel') {
        downloadTemplate($downloadId, $format);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Timetable Template</title>
        <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        body {
            background-color: #f5f5f5;
            display: flex;
            min-height: 100vh;
        }
        .navbar {
            position: fixed;
            width: 100%;
            background-color: #007bff;
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.2);
            z-index: 100;
        }
        .navbar .title {
            font-size: 20px;
            font-weight: bold;
        }
        .sidebar {
            width: 250px;
            height: 100vh;
            background-color: #004080;
            padding-top: 80px;
            position: fixed;
            left: 0;
            top: 0;
            display: flex;
            flex-direction: column;
            z-index: 90;
        }
        .sidebar ul {
            list-style: none;
            padding: 0;
        }
        .sidebar li {
            padding: 5px 0;
        }
        .sidebar a {
            text-decoration: none;
            color: white;
            display: block;
            font-size: 16px;
            padding: 10px 15px;
            transition: background 0.3s;
        }
        .sidebar a:hover {
            background-color: #0066cc;
        }
        .content {
            margin-left: 250px;
            padding: 80px 20px 20px 20px;
            flex-grow: 1;
            width: calc(100% - 250px);
        }
        h2 {
            margin-top: 20px;
            margin-bottom: 15px;
        }
        h3 {
            margin-top: 15px;
            margin-bottom: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: inline-block;
            width: 180px;
        }
        .period-row, .break-row {
            background-color: #f9f9f9;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
        }
        .break-row {
            background-color: #e9f7ef;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 20px;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
        .hidden {
            display: none;
        }
        #preview-table, .template-preview {
            margin-top: 20px;
            margin-bottom: 20px;
        }
        button {
            margin: 5px;
            padding: 5px 10px;
        }
        .delete-btn {
            background-color: #ff6b6b;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 3px;
        }
        .delete-btn:hover {
            background-color: #ff4f4f;
        }
        .preview-btn {
            background-color: #4a90e2;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 3px;
        }
        .preview-btn:hover {
            background-color: #3a70c2;
        }
        .add-period-container {
            margin: 15px 0;
            text-align: left;
        }
        .add-period-btn {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 8px 16px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 16px;
        }
        .add-period-btn:hover {
            background-color: #45a049;
        }
        .template-preview {
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
            background-color: #f9f9f9;
        }
        .template-preview h3 {
            margin-top: 0;
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
        .lunch-checkbox {
            margin-left: 10px;
            vertical-align: middle;
        }
        .lunch-label {
            margin-left: 5px;
            font-weight: bold;
        }
        .download-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 3px;
            display: inline-block;
            margin: 2px;
            text-decoration: none;
            font-size: 0.9em;
        }
        .download-btn:hover {
            background-color: #218838;
        }
        input[type="text"], input[type="time"], input[type="number"], select {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button[type="submit"] {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            border-radius: 4px;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        button[type="submit"]:hover {
            background-color: #0069d9;
        }
        .remove-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 3px;
        }
        .remove-btn:hover {
            background-color: #c82333;
        }
    </style>
    <script>
        // Global variables
        let periodCount = 0;
        let breakCount = 0;
        
        // Initialize when the page loads
        window.onload = function() {
            // Add first period by default
            addPeriod();
            
            // Check for preview template parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('preview_id')) {
                // Scroll to the preview section
                document.getElementById('template-preview-section').scrollIntoView();
            }
        };
        
        // Add a new period to the form
        function addPeriod() {
            periodCount++;
            let periodsContainer = document.getElementById('periods_container');
            
            let periodDiv = document.createElement('div');
            periodDiv.className = 'period-row';
            periodDiv.id = `period_${periodCount}`;
            
            // Get the end time of the previous period as start time for this period
            let startTime = '';
            if (periodCount > 1) {
                let prevPeriodEndTime = document.getElementById(`period_${periodCount-1}_end_time`).value;
                if (prevPeriodEndTime) {
                    startTime = prevPeriodEndTime;
                }
            }
            
            periodDiv.innerHTML = `
                <h4>Period ${periodCount}</h4>
                <div class="form-group">
                    <label>Start Time:</label>
                    <input type="time" id="period_${periodCount}_start_time" name="periods[${periodCount}][start_time]" 
                           value="${startTime}" onchange="updateDuration(${periodCount})">
                </div>
                <div class="form-group">
                    <label>End Time:</label>
                    <input type="time" id="period_${periodCount}_end_time" name="periods[${periodCount}][end_time]" 
                           onchange="updateDuration(${periodCount})">
                </div>
                <div class="form-group">
                    <label>Duration (minutes):</label>
                    <input type="number" id="period_${periodCount}_duration" name="periods[${periodCount}][duration]" 
                           min="1" onchange="updateEndTime(${periodCount})">
                </div>
                <button type="button" onclick="addBreakAfter(${periodCount})">+ Add Break After</button>
                <button type="button" onclick="removePeriod(${periodCount})" class="remove-btn">Remove Period</button>
            `;
            
            periodsContainer.appendChild(periodDiv);
            updatePreview();
        }
        
        // Remove a period
        // Remove a period
function removePeriod(periodId) {
    if (periodCount <= 1) {
        alert("You must have at least one period!");
        return;
    }
    
    let periodElement = document.getElementById(`period_${periodId}`);
    if (periodElement) {
        periodElement.parentNode.removeChild(periodElement);
        
        // Re-index remaining periods
        reindexPeriods();
        periodCount--;
        updatePreview();
    }
}

// Re-index periods after removal
function reindexPeriods() {
    let periodsContainer = document.getElementById('periods_container');
    let periods = periodsContainer.getElementsByClassName('period-row');
    
    for (let i = 0; i < periods.length; i++) {
        let periodId = i + 1;
        let oldId = periods[i].id.split('_')[1];
        
        periods[i].id = `period_${periodId}`;
        periods[i].querySelector('h4').innerText = `Period ${periodId}`;
        
        // Update input names and ids
        let inputs = periods[i].querySelectorAll('input');
        for (let input of inputs) {
            let fieldName = input.name.split('][')[1].replace(']', '');
            input.name = `periods[${periodId}][${fieldName}]`;
            input.id = input.id.replace(`period_${oldId}`, `period_${periodId}`);
        }
        
        // Update button onclick
        let addBreakBtn = periods[i].querySelector('button');
        addBreakBtn.setAttribute('onclick', `addBreakAfter(${periodId})`);
        
        let removeBtn = periods[i].querySelectorAll('button')[1];
        removeBtn.setAttribute('onclick', `removePeriod(${periodId})`);
    }
}

// Add a break after specified period
function addBreakAfter(periodId) {
    breakCount++;
    let periodsContainer = document.getElementById('periods_container');
    let periodElement = document.getElementById(`period_${periodId}`);
    
    let breakDiv = document.createElement('div');
    breakDiv.className = 'break-row';
    breakDiv.id = `break_${breakCount}`;
    
    // Get the end time of the period as start time for this break
    let startTime = document.getElementById(`period_${periodId}_end_time`).value;
    
    breakDiv.innerHTML = `
        <h4>Break after Period ${periodId}</h4>
        <input type="hidden" name="breaks[${breakCount}][after_period]" value="${periodId}">
        <div class="form-group">
            <label>Start Time:</label>
            <input type="time" id="break_${breakCount}_start_time" name="breaks[${breakCount}][start_time]" 
                   value="${startTime}" onchange="updateBreakDuration(${breakCount})">
        </div>
        <div class="form-group">
            <label>End Time:</label>
            <input type="time" id="break_${breakCount}_end_time" name="breaks[${breakCount}][end_time]" 
                   onchange="updateBreakDuration(${breakCount})">
        </div>
        <div class="form-group">
            <label>Duration (minutes):</label>
            <input type="number" id="break_${breakCount}_duration" name="breaks[${breakCount}][duration]" 
                   min="1" onchange="updateBreakEndTime(${breakCount})">
        </div>
        <div class="form-group">
            <input type="checkbox" id="break_${breakCount}_is_lunch" name="breaks[${breakCount}][is_lunch]" 
                   class="lunch-checkbox" onchange="updatePreview()">
            <label for="break_${breakCount}_is_lunch" class="lunch-label">Mark as Lunch Break</label>
        </div>
        <button type="button" onclick="removeBreak(${breakCount})" class="remove-btn">Remove Break</button>
    `;
    
    // Insert break after the period
    if (periodElement.nextSibling) {
        periodsContainer.insertBefore(breakDiv, periodElement.nextSibling);
    } else {
        periodsContainer.appendChild(breakDiv);
    }
    
    updatePreview();
}

// Remove a break
function removeBreak(breakId) {
    let breakElement = document.getElementById(`break_${breakId}`);
    if (breakElement) {
        breakElement.parentNode.removeChild(breakElement);
        updatePreview();
    }
}

// Calculate and update duration when start/end time changes
function updateDuration(periodId) {
    let startTime = document.getElementById(`period_${periodId}_start_time`).value;
    let endTime = document.getElementById(`period_${periodId}_end_time`).value;
    
    if (startTime && endTime) {
        let duration = calculateMinutesBetween(startTime, endTime);
        document.getElementById(`period_${periodId}_duration`).value = duration;
    }
    
    updateNextPeriodStartTime(periodId);
    updatePreview();
}

// Update end time when duration changes
function updateEndTime(periodId) {
    let startTime = document.getElementById(`period_${periodId}_start_time`).value;
    let duration = document.getElementById(`period_${periodId}_duration`).value;
    
    if (startTime && duration) {
        let endTime = addMinutesToTime(startTime, parseInt(duration));
        document.getElementById(`period_${periodId}_end_time`).value = endTime;
    }
    
    updateNextPeriodStartTime(periodId);
    updatePreview();
}

// Update next period's start time
function updateNextPeriodStartTime(periodId) {
    if (periodId < periodCount) {
        let currentEndTime = document.getElementById(`period_${periodId}_end_time`).value;
        
        // Check if there's a break after this period
        let breakAfterThisPeriod = null;
        let breaks = document.getElementsByClassName('break-row');
        for (let i = 0; i < breaks.length; i++) {
            let afterPeriodInput = breaks[i].querySelector('input[name^="breaks"][name$="[after_period]"]');
            if (afterPeriodInput && parseInt(afterPeriodInput.value) === periodId) {
                breakAfterThisPeriod = breaks[i];
                break;
            }
        }
        
        if (breakAfterThisPeriod) {
            // Update break start time
            let breakId = breakAfterThisPeriod.id.split('_')[1];
            document.getElementById(`break_${breakId}_start_time`).value = currentEndTime;
            updateBreakDuration(breakId);
            
            // Next period starts at break end time
            let breakEndTime = document.getElementById(`break_${breakId}_end_time`).value;
            if (breakEndTime) {
                document.getElementById(`period_${periodId+1}_start_time`).value = breakEndTime;
                updateDuration(periodId+1);
            }
        } else {
            // No break, next period starts right after this one
            document.getElementById(`period_${periodId+1}_start_time`).value = currentEndTime;
            updateDuration(periodId+1);
        }
    }
}

// Calculate and update break duration
function updateBreakDuration(breakId) {
    let startTime = document.getElementById(`break_${breakId}_start_time`).value;
    let endTime = document.getElementById(`break_${breakId}_end_time`).value;
    
    if (startTime && endTime) {
        let duration = calculateMinutesBetween(startTime, endTime);
        document.getElementById(`break_${breakId}_duration`).value = duration;
    }
    
    // Update next period start time
    let afterPeriodInput = document.querySelector(`#break_${breakId} input[name$="[after_period]"]`);
    if (afterPeriodInput) {
        let periodId = parseInt(afterPeriodInput.value);
        if (periodId < periodCount) {
            let breakEndTime = document.getElementById(`break_${breakId}_end_time`).value;
            if (breakEndTime) {
                document.getElementById(`period_${periodId+1}_start_time`).value = breakEndTime;
                updateDuration(periodId+1);
            }
        }
    }
    
    updatePreview();
}

// Update break end time when duration changes
function updateBreakEndTime(breakId) {
    let startTime = document.getElementById(`break_${breakId}_start_time`).value;
    let duration = document.getElementById(`break_${breakId}_duration`).value;
    
    if (startTime && duration) {
        let endTime = addMinutesToTime(startTime, parseInt(duration));
        document.getElementById(`break_${breakId}_end_time`).value = endTime;
    }
    
    // Update next period start time
    let afterPeriodInput = document.querySelector(`#break_${breakId} input[name$="[after_period]"]`);
    if (afterPeriodInput) {
        let periodId = parseInt(afterPeriodInput.value);
        if (periodId < periodCount) {
            let breakEndTime = document.getElementById(`break_${breakId}_end_time`).value;
            if (breakEndTime) {
                document.getElementById(`period_${periodId+1}_start_time`).value = breakEndTime;
                updateDuration(periodId+1);
            }
        }
    }
    
    updatePreview();
}

// Helper function to calculate minutes between two times
function calculateMinutesBetween(startTime, endTime) {
    let start = new Date("2000-01-01T" + startTime + ":00");
    let end = new Date("2000-01-01T" + endTime + ":00");
    
    // Handle end time on the next day
    if (end < start) {
        end.setDate(end.getDate() + 1);
    }
    
    let diff = (end - start) / 60000; // Convert milliseconds to minutes
    return diff;
}

// Helper function to add minutes to a time
function addMinutesToTime(timeStr, minutes) {
    let time = new Date("2000-01-01T" + timeStr + ":00");
    time.setMinutes(time.getMinutes() + minutes);
    
    // Format as HH:MM
    let hours = time.getHours().toString().padStart(2, '0');
    let mins = time.getMinutes().toString().padStart(2, '0');
    
    return `${hours}:${mins}`;
}

// Update the preview timetable
function updatePreview() {
    let previewTable = document.getElementById('preview-table');
    let weekStart = document.getElementById('week_start').value;
    let weekEnd = document.getElementById('week_end').value;
    
    if (!weekStart || !weekEnd) {
        previewTable.innerHTML = "<p>Please select week start and week end to see preview.</p>";
        return;
    }
    
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
    
    // Get all periods and breaks in order
    let timeSlots = [];
    
    // Add periods
    let periodRows = document.getElementsByClassName('period-row');
    for (let i = 0; i < periodRows.length; i++) {
        let periodId = periodRows[i].id.split('_')[1];
        let startTimeInput = document.getElementById(`period_${periodId}_start_time`);
        let endTimeInput = document.getElementById(`period_${periodId}_end_time`);
        
        if (startTimeInput && startTimeInput.value && endTimeInput && endTimeInput.value) {
            timeSlots.push({
                type: 'period',
                id: periodId,
                startTime: startTimeInput.value,
                endTime: endTimeInput.value,
                label: `Period ${periodId}`
            });
        }
    }
    
    // Add breaks
    let breakRows = document.getElementsByClassName('break-row');
    for (let i = 0; i < breakRows.length; i++) {
        let breakId = breakRows[i].id.split('_')[1];
        let startTimeInput = document.getElementById(`break_${breakId}_start_time`);
        let endTimeInput = document.getElementById(`break_${breakId}_end_time`);
        let afterPeriodInput = breakRows[i].querySelector('input[name$="[after_period]"]');
        let isLunchCheckbox = document.getElementById(`break_${breakId}_is_lunch`);
        
        if (startTimeInput && startTimeInput.value && endTimeInput && endTimeInput.value && afterPeriodInput) {
            let periodId = afterPeriodInput.value;
            let isLunch = isLunchCheckbox && isLunchCheckbox.checked;
            
            timeSlots.push({
                type: 'break',
                id: breakId,
                startTime: startTimeInput.value,
                endTime: endTimeInput.value,
                label: isLunch ? 'Lunch' : 'Break',
                isLunch: isLunch,
                afterPeriod: periodId
            });
        }
    }
    
    // Sort timeSlots by start time
    timeSlots.sort((a, b) => {
        return new Date("2000-01-01T" + a.startTime) - new Date("2000-01-01T" + b.startTime);
    });
    
    // Generate the table HTML - MODIFIED layout with days as rows and periods as columns
    let tableHTML = '<table border="1"><thead><tr><th>Day/Time</th>';
    
    // Add time slots as column headers
    for (let slot of timeSlots) {
        let slotClass = slot.type === 'period' ? 'period-cell' : 
                       (slot.isLunch ? 'lunch-cell' : 'break-cell');
        
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
    previewTable.innerHTML = tableHTML;
}

// Function to display template preview 
function displayTemplatePreview(templateData, containerId) {
  let container = document.getElementById(containerId);
  if (!container) return;
  
  let template = null;
  try {
    template = JSON.parse(templateData);
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

// Delete template confirmation function 
function confirmDelete(id) { 
  if (confirm("Are you sure you want to delete this template?")) { 
    document.getElementById("delete_form_" + id).submit(); 
  } 
}

// Function to display template preview 
function displayTemplatePreview(templateData, containerId) {
  let container = document.getElementById(containerId);
  if (!container) return;
  
  let template = null;
  try {
    template = JSON.parse(templateData);
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

// Delete template confirmation
function confirmDelete(id) {
  if (confirm("Are you sure you want to delete this template?")) {
    document.getElementById("delete_form_" + id).submit();
  }
}
</script>
</head>
<body>
    <nav class="navbar">
        <div class="title">Time Table Management</div>
    </nav>

    <!-- Sidebar -->
    <aside class="sidebar">
        <ul>
            <li><a href="index.php">🏠 Home</a></li>
            <li><a href="addstaff.php">➕ Add Staff</a></li>
            <li><a href="addcourse.php">📚 Add Courses</a></li>
            <li><a href="addclass.php">🏫 Add Classes</a></li>
            <li><a href="template.php">📑 Add Templates</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="content">
        <h2>Create New Timetable Template</h2>
        <form method="POST" id="templateForm">
            <div class="form-group">
                <label>Template Name:</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>Week Start:</label>
                <select name="week_start" id="week_start" onchange="updatePreview()">
                    <option value="Monday">Monday</option>
                    <option value="Tuesday">Tuesday</option>
                    <option value="Wednesday">Wednesday</option>
                    <option value="Thursday">Thursday</option>
                    <option value="Friday">Friday</option>
                    <option value="Saturday">Saturday</option>
                    <option value="Sunday">Sunday</option>
                </select>
            </div>
            <div class="form-group">
                <label>Week End:</label>
                <select name="week_end" id="week_end" onchange="updatePreview()">
                    <option value="Friday" selected>Friday</option>
                    <option value="Saturday">Saturday</option>
                    <option value="Sunday">Sunday</option>
                    <option value="Monday">Monday</option>
                    <option value="Tuesday">Tuesday</option>
                    <option value="Wednesday">Wednesday</option>
                    <option value="Thursday">Thursday</option>
                </select>
            </div>
            <h3>Periods and Breaks</h3>
            <div id="periods_container"></div>
            <div class="add-period-container">
                <button type="button" onclick="addPeriod()" class="add-period-btn">+ Add Period</button>
            </div>
            <h3>Timetable Preview</h3>
            <div id="preview-table">
                <p>Please select week start and week end to see preview.</p>
            </div>
            <button type="submit">Create Template</button>
        </form>
        
        <h2>Available Templates</h2>
        <table border="1">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row["id"]; ?></td>
                        <td><?php echo $row["name"]; ?></td>
                        <td><?php echo $row["created_at"]; ?></td>
                        <td>
                            <a href="?preview_id=<?php echo $row["id"]; ?>" class="preview-btn">Preview Template</a>
                            <a href="?download_id=<?php echo $row["id"]; ?>&format=csv" class="download-btn">Download CSV</a>
                            <a href="?download_id=<?php echo $row["id"]; ?>&format=excel" class="download-btn">Download Excel</a>
                            <form id="delete_form_<?php echo $row["id"]; ?>" method="POST" style="display:inline;">
                                <input type="hidden" name="delete_id" value="<?php echo $row["id"]; ?>">
                                <button type="button" onclick="confirmDelete(<?php echo $row["id"]; ?>)" class="delete-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No templates available</td>
                </tr>
            <?php endif; ?>
        </table>
        
        <?php if (isset($template)): ?>
            <div id="template-preview-section" class="template-preview">
                <h3>Preview of "<?php echo htmlspecialchars($template['name']); ?>" Template</h3>
                <div id="template-preview-container"></div>
                <script>
                    // Create template object with data from PHP
                    const templateData = {
                        week_start: "<?php echo $template['week_start']; ?>",
                        week_end: "<?php echo $template['week_end']; ?>",
                        periods_data: <?php echo json_encode($template['periods_data']); ?>,
                        breaks_data: <?php echo json_encode($template['breaks_data']); ?>
                    };
                    
                    // Display the template preview
                    displayTemplatePreview(JSON.stringify(templateData), "template-preview-container");
                </script>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>