<?php
// Connect to database
$conn = new mysqli("localhost", "root", "", "autotime2");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get staff ID from query parameter
$staff_id = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;

// Get staff details
$staff = null;
if ($staff_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $staff_result = $stmt->get_result();
    if ($staff_result->num_rows > 0) {
        $staff = $staff_result->fetch_assoc();
    }
    $stmt->close();
}

// Get templates and assignments for this staff
$availability = [];
if ($staff_id > 0) {
    $stmt = $conn->prepare("
        SELECT DISTINCT 
            sa.*, 
            tt.name as template_name, 
            tt.periods_data, 
            tt.breaks_data,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    tts.timetable_id, ':', 
                    c.name, ':', 
                    tts.day, ':', 
                    tts.period
                )
            ) as assignments
        FROM staff_availability sa
        JOIN templates tt ON sa.template_id = tt.id
        LEFT JOIN timetable_slots tts ON sa.staff_id = tts.staff_id AND tts.template_id = sa.template_id
        LEFT JOIN courses c ON tts.course_id = c.id
        WHERE sa.staff_id = ?
        GROUP BY sa.template_id
    ");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $availability[] = $row;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Schedule</title>
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
        }
        .navbar .title {
            font-size: 20px;
            font-weight: bold;
        }
        .sidebar {
            width: 250px;
            height: 100vh;
            background-color: #004080;
            padding-top: 60px;
            position: fixed;
            left: 0;
            display: flex;
            flex-direction: column;
        }
        .sidebar ul {
            list-style: none;
            padding: 0;
        }
        .sidebar li {
            padding: 15px;
        }
        .sidebar a {
            text-decoration: none;
            color: white;
            display: block;
            font-size: 16px;
            padding: 10px;
            transition: background 0.3s;
        }
        .sidebar a:hover {
            background-color: #0066cc;
        }
        .content {
            margin-left: 270px;
            padding: 20px;
            flex-grow: 1;
            margin-top: 60px;
        }
        h1, h2, h3 {
            color: #333;
            margin-bottom: 15px;
        }
        .staff-info {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .back-button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            text-decoration: none;
            margin-bottom: 20px;
        }
        .back-button:hover {
            background-color: #0056b3;
        }
        .template-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        .template-title {
            background-color: #f0f0f0;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-weight: bold;
        }
        .timetable {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .timetable th, .timetable td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        .timetable th {
            background-color: #007bff;
            color: white;
            position: sticky;
            top: 0;
        }
        .timetable th:first-child {
            width: 100px;
        }
        .timetable tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .course-cell {
            background-color: #e3f2fd;
            padding: 5px;
            border-radius: 4px;
            margin-bottom: 5px;
            font-size: 0.9em;
        }
        .course-lab {
            background-color: #d1e7dd;
        }
        .period-header {
            font-size: 0.8em;
            display: block;
            margin-bottom: 3px;
            color: #555;
        }
        .no-data {
            text-align: center;
            padding: 20px;
            color: #777;
        }
        .no-schedule {
            color: #999;
            font-style: italic;
        }
        .timetable-info {
            background-color: #f8f9fa;
            padding: 5px;
            border-radius: 3px;
            font-size: 0.85em;
            margin-bottom: 2px;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
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
    <main class="content">
        <a href="addstaff.php" class="back-button">← Back to Staff List</a>

        <?php if ($staff): ?>
            <div class="staff-info">
                <h1>Schedule for <?= htmlspecialchars($staff['name']) ?></h1>
                <p><strong>Department:</strong> <?= htmlspecialchars($staff['dept']) ?></p>
                <p><strong>ID:</strong> <?= htmlspecialchars($staff['unique_id']) ?></p>
            </div>

            <?php if (empty($availability)): ?>
                <p class="no-data">No scheduled assignments found for this staff member.</p>
            <?php else: ?>
                <?php foreach ($availability as $record): ?>
                    <div class="template-section">
                        <div class="template-title">
                            Template: <?= htmlspecialchars($record['template_name']) ?>
                        </div>

                        <?php
                        $periods_data = json_decode($record['periods_data'], true) ?: [];
                        $working_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

                        // Parse assignments
                        $assignments = [];
                        if ($record['assignments']) {
                            foreach (explode(',', $record['assignments']) as $assignment) {
                                list($timetable_id, $course_name, $day, $period) = explode(':', $assignment);
                                if (!isset($assignments[$day])) {
                                    $assignments[$day] = [];
                                }
                                $assignments[$day][$period] = [
                                    'timetable_id' => $timetable_id,
                                    'course_name' => $course_name
                                ];
                            }
                        }
                        ?>

                        <table class="timetable">
                            <thead>
                                <tr>
                                    <th>Day / Period</th>
                                    <?php foreach ($periods_data as $period_id => $period_info): ?>
                                        <th>
                                            <span class="period-header">Period <?= $period_id ?></span>
                                            <?= $period_info['start_time'] ?> - <?= $period_info['end_time'] ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($working_days as $day): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($day) ?></td>
                                        <?php foreach ($periods_data as $period_id => $period_info): ?>
                                            <td>
                                                <?php
                                                if (isset($assignments[$day][$period_id])) {
                                                    $assignment = $assignments[$day][$period_id];
                                                    echo '<div class="course-cell">';
                                                    echo htmlspecialchars($assignment['course_name']);
                                                    echo '<div class="timetable-info">TT#' . 
                                                         htmlspecialchars($assignment['timetable_id']) . '</div>';
                                                    echo '</div>';
                                                } else {
                                                    echo '&nbsp;';
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php else: ?>
            <div class="no-data">
                <h2>Staff Not Found</h2>
                <p>The requested staff member could not be found.</p>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>