<?php
// Database Connection
$conn = new mysqli("localhost", "root", "", "autotime2");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if class_id is provided
if (!isset($_GET['class_id']) || empty($_GET['class_id'])) {
    die("<div style='color: red; padding: 20px;'>Error: Class ID is required.</div>");
}

$class_id = $conn->real_escape_string($_GET['class_id']);

// Get class details
$classQuery = "SELECT * FROM classes WHERE id = '$class_id'";
$classResult = $conn->query($classQuery);

if ($classResult->num_rows === 0) {
    die("<div style='color: red; padding: 20px;'>Error: Class not found.</div>");
}

$classDetails = $classResult->fetch_assoc();

// Get timetable slots directly
// This approach matches how next_44.php loads data from timetable_slots
// We need to find timetable slots that belong to a class's timetable

// Following your suggestion:
// Format the class identifier in the format "dept-section-year-semester-batch_start-batch_end"
$dept = $classDetails['dept'];
$section = $classDetails['section'];
$year = $classDetails['year'];
$semester = $classDetails['semester'];
$batch_start = $classDetails['batch_start'];
$batch_end = $classDetails['batch_end'];

// Create the class identifier in the format you specified
$class_identifier = "{$dept}-{$section}-{$year}-{$semester}-{$batch_start}-{$batch_end}";
echo "<!-- Looking for timetable with class identifier: $class_identifier -->";

// Now let's check if there's a timetable_id in the timetable_slots table that EXACTLY matches this pattern
// We're using exact matching since that's what you want - no fallbacks
$check_timetable_query = "SELECT DISTINCT timetable_id FROM timetable_slots 
                         WHERE timetable_id = '$class_identifier' 
                         LIMIT 1";

$timetable_result = $conn->query($check_timetable_query);

if ($timetable_result && $timetable_result->num_rows > 0) {
    // Found an EXACT matching timetable - this is the only case where we show a timetable
    $timetable_row = $timetable_result->fetch_assoc();
    $timetable_id = $timetable_row['timetable_id'];
    echo "<!-- Found exact matching timetable ID: $timetable_id for class: $class_id with identifier: $class_identifier -->";
} else {
    // No exact match found - set timetable_id to null so we show "Not assigned yet"
    $timetable_id = null;
    echo "<!-- No exact matching timetable found for class identifier: $class_identifier -->";
}

// We've already set $timetable_id in the code above
// Now we just need to display a message if no timetable was found

if (!$timetable_id) {
    echo "<div style='text-align: center; padding: 20px;'>
            <div style='font-size: 18px; color: #666; margin-bottom: 10px;'>No Schedule Assigned</div>
            <div style='color: #888;'>There is no timetable assigned to this class yet.</div>
          </div>";
}

// Now get the slots for the timetable
if ($timetable_id) {
    $slotsQuery = "SELECT ts.*, c.name as course_name, s.name as staff_name 
                   FROM timetable_slots ts
                   JOIN courses c ON ts.course_id = c.id
                   JOIN staff s ON ts.staff_id = s.id
                   WHERE ts.timetable_id = ?
                   ORDER BY ts.day, ts.period";
    
    $stmt = $conn->prepare($slotsQuery);
    $stmt->bind_param("s", $timetable_id);
    $stmt->execute();
    $timetableResult = $stmt->get_result();
    
    // Get all working days
    $daysQuery = "SELECT DISTINCT day FROM timetable_slots 
                  WHERE timetable_id = ?
                  ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
    
    $daysStmt = $conn->prepare($daysQuery);
    $daysStmt->bind_param("s", $timetable_id);
    $daysStmt->execute();
    $daysResult = $daysStmt->get_result();
    
    $workingDays = [];
    while ($day = $daysResult->fetch_assoc()) {
        $workingDays[] = $day['day'];
    }
    
    // Get all periods
    $periodsQuery = "SELECT DISTINCT period FROM timetable_slots 
                    WHERE timetable_id = ?
                    ORDER BY period";
    
    $periodsStmt = $conn->prepare($periodsQuery);
    $periodsStmt->bind_param("s", $timetable_id);
    $periodsStmt->execute();
    $periodsResult = $periodsStmt->get_result();
    
    $periods = [];
    while ($period = $periodsResult->fetch_assoc()) {
        $periods[] = $period['period'];
    }
    
    // Organize timetable data by day and period - using the same approach as next_44.php
    $timetableData = [];
    while ($row = $timetableResult->fetch_assoc()) {
        $timetableData[$row['day']][$row['period']] = [
            'course_name' => $row['course_name'],
            'staff_name' => $row['staff_name'],
            'is_lab' => $row['is_lab'] == 1
        ];
    }
} else {
    // No timetable found
    $workingDays = [];
    $periods = [];
    $timetableData = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Timetable - <?= $class_id ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        body {
            background-color: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #007bff;
            margin-bottom: 10px;
            text-align: center;
        }
        .class-details {
            background-color: #f0f8ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .class-details p {
            margin: 5px 0;
        }
        .timetable {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed;
        }
        .timetable th, .timetable td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
        }
        .timetable th {
            background-color: #007bff;
            color: white;
        }
        .timetable .period-header {
            background-color: #4da6ff;
        }
        .course-cell {
            background-color: #e6f3ff;
        }
        .lab-course {
            background-color: #ffe6e6;
        }
        .no-class {
            background-color: #f2f2f2;
            color: #999;
        }
        .back-button {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 15px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .back-button:hover {
            background-color: #0056b3;
        }
        @media print {
            .back-button {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Class Timetable</h1>
        
        <div class="class-details">
            <h2><?= $class_id ?></h2>
            <p><strong>Department:</strong> <?= $classDetails['dept'] ?></p>
            <p><strong>Section:</strong> <?= $classDetails['section'] ?></p>
            <p><strong>Year:</strong> <?= $classDetails['year'] ?> | <strong>Semester:</strong> <?= $classDetails['semester'] ?></p>
            <p><strong>Batch:</strong> <?= $classDetails['batch_start'] ?> - <?= $classDetails['batch_end'] ?></p>
            <p><strong>Advisor:</strong> <?= $classDetails['advisor'] ?> | <strong>Assistant Advisor:</strong> <?= $classDetails['assistant_advisor'] ?></p>
        </div>
        
        <?php if (empty($workingDays) || empty($periods)): ?>
            <div style="text-align: center; padding: 30px; color: #666; background-color: #f9f9f9; border-radius: 8px; margin: 20px 0;">
                <div style="font-size: 18px; margin-bottom: 10px;">
                    <i style="font-size: 24px;">📅</i>
                    <strong>No Schedule Assigned</strong>
                </div>
                <p>The timetable for this class has not been assigned yet.</p>
            </div>
        <?php else: ?>
            <table class="timetable">
                <tr>
                    <th>Day / Period</th>
                    <?php foreach ($periods as $period): ?>
                        <th class="period-header">Period <?= $period ?></th>
                    <?php endforeach; ?>
                </tr>
                
                <?php foreach ($workingDays as $day): ?>
                    <tr>
                        <th><?= $day ?></th>
                        <?php foreach ($periods as $period): ?>
                            <?php if (isset($timetableData[$day][$period])): ?>
                                <td class="course-cell <?= $timetableData[$day][$period]['is_lab'] ? 'lab-course' : '' ?>">
                                    <div><?= $timetableData[$day][$period]['course_name'] ?></div>
                                    <div><small><?= $timetableData[$day][$period]['staff_name'] ?></small></div>
                                    <?php if ($timetableData[$day][$period]['is_lab']): ?>
                                        <div><small>(Lab)</small></div>
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <td class="no-class">-</td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: 20px;">
            <a href="addclass.php" class="back-button">← Back to Classes</a>
        </div>
    </div>
</body>
</html>