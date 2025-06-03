<?php
// Include database connection
require_once 'config.php';

// Get timetable ID from URL
$timetable_id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($timetable_id)) {
    echo "Error: No timetable ID provided.";
    exit;
}

// Function to get timetable data
function getTimetableData($conn, $timetable_id) {
    // Extract class details from timetable ID
    $parts = explode('-', $timetable_id);
    if (count($parts) < 6) {
        return null;
    }
    
    $dept = $parts[0];
    $section = $parts[1];
    $year = $parts[2];
    $semester = $parts[3];
    $batch_start = $parts[4];
    $batch_end = $parts[5];
    
    // Get class details
    $sql = "SELECT id, sectionadvisor, assistant_advisor FROM classes 
            WHERE dept = ? AND section = ? AND year = ? AND semester = ? 
            AND batch_start = ? AND batch_end = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssiiss", $dept, $section, $year, $semester, $batch_start, $batch_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $class = null;
    
    if ($result->num_rows > 0) {
        $class = $result->fetch_assoc();
    }
    $stmt->close();
    
    // Get periods data
    $sql = "SELECT day, period, course_id, staff_id, is_lab FROM dayperiod WHERE timetable_id = ? ORDER BY day, period";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $timetable_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $periods = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $periods[] = $row;
        }
    }
    $stmt->close();
    
    // Get summary data
    $sql = "SELECT course_id, course_name, credits, allocated_periods FROM summary WHERE timetable_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $timetable_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = [];
    
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $summary[] = $row;
        }
    }
    $stmt->close();
    
    // Get course and staff details
    $courses = [];
    $staff = [];
    
    foreach ($periods as $period) {
        $course_id = $period['course_id'];
        $staff_id = $period['staff_id'];
        
        if (!isset($courses[$course_id])) {
            $sql = "SELECT name, course_code FROM courses WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $course_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $courses[$course_id] = $result->fetch_assoc();
            }
            $stmt->close();
        }
        
        if (!isset($staff[$staff_id])) {
            $sql = "SELECT name FROM staff WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $staff[$staff_id] = $result->fetch_assoc()['name'];
            }
            $stmt->close();
        }
    }
    
    return [
        'timetable_id' => $timetable_id,
        'dept' => $dept,
        'section' => $section,
        'year' => $year,
        'semester' => $semester,
        'batch' => $batch_start . '-' . $batch_end,
        'class' => $class,
        'periods' => $periods,
        'courses' => $courses,
        'staff' => $staff,
        'summary' => $summary
    ];
}

// Function to organize timetable by days and periods
function organizeTimetable($data) {
    $periods = $data['periods'];
    $courses = $data['courses'];
    $staff = $data['staff'];
    $timetable = [];
    $all_periods = [];
    
    // Group periods by day
    foreach ($periods as $period) {
        $day = $period['day'];
        $period_time = $period['period'];
        $course_id = $period['course_id'];
        $staff_id = $period['staff_id'];
        $is_lab = $period['is_lab'];
        
        if (!isset($timetable[$day])) {
            $timetable[$day] = [];
        }
        
        $course_info = isset($courses[$course_id]) ? $courses[$course_id] : ['name' => 'Unknown', 'course_code' => ''];
        $staff_name = isset($staff[$staff_id]) ? $staff[$staff_id] : 'Unknown';
        
        $timetable[$day][$period_time] = [
            'course_name' => $course_info['name'],
            'course_code' => $course_info['course_code'],
            'staff_name' => $staff_name,
            'is_lab' => $is_lab
        ];
        
        if (!in_array($period_time, $all_periods)) {
            $all_periods[] = $period_time;
        }
    }
    
    // Sort periods
    sort($all_periods);
    
    return [
        'timetable' => $timetable,
        'all_periods' => $all_periods
    ];
}

// Get and organize timetable data
$data = getTimetableData($conn, $timetable_id);
if (!$data) {
    echo "Error: Invalid timetable ID or data not found.";
    exit;
}

$organized = organizeTimetable($data);
$timetable = $organized['timetable'];
$all_periods = $organized['all_periods'];

// Sort days of the week in the correct order
$day_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
uksort($timetable, function($a, $b) use ($day_order) {
    return array_search($a, $day_order) - array_search($b, $day_order);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable - <?php echo $data['timetable_id']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/css/bootstrap.min.css">
    <style>
        .timetable-cell {
            height: 80px;
            overflow: hidden;
        }
        .lab-cell {
            background-color: #e7f5ff;
        }
        .course-name {
            font-weight: bold;
        }
        .course-code {
            font-size: 0.8rem;
            color: #666;
        }
        .staff-name {
            font-size: 0.8rem;
            color: #666;
            font-style: italic;
        }
        .summary-table th, .summary-table td {
            padding: 5px 10px;
        }
        @media print {
            .no-print {
                display: none;
            }
            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-3">
        <div class="row mb-4 align-items-center">
            <div class="col-md-8">
                <h1>Timetable</h1>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Department:</strong> <?php echo $data['dept']; ?></p>
                        <p><strong>Section:</strong> <?php echo $data['section']; ?></p>
                        <p><strong>Year:</strong> <?php echo $data['year']; ?></p>
                        <p><strong>Semester:</strong> <?php echo $data['semester']; ?></p>
                        <p><strong>Batch:</strong> <?php echo $data['batch']; ?></p>
                    </div>
                    <div class="col-md-6">
                        <?php if (isset($data['class']) && $data['class']): ?>
                            <p><strong>Section Advisor:</strong> <?php echo $data['class']['sectionadvisor']; ?></p>
                            <p><strong>Assistant Advisor:</strong> <?php echo $data['class']['assistant_advisor']; ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-end no-print">
                <button class="btn btn-primary me-2" onclick="window.print()">Print</button>
                <a href="gen.php" class="btn btn-secondary">Back</a>
            </div>
        </div>
        
        <div class="row">
            <div class="col-12 table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Day / Period</th>
                            <?php foreach ($all_periods as $period): ?>
                                <th><?php echo $period; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timetable as $day => $periods): ?>
                            <tr>
                                <th class="table-light"><?php echo $day; ?></th>
                                <?php foreach ($all_periods as $period): ?>
                                    <?php if (isset($periods[$period])): ?>
                                        <td class="timetable-cell <?php echo $periods[$period]['is_lab'] ? 'lab-cell' : ''; ?>">
                                            <div class="course-name"><?php echo $periods[$period]['course_name']; ?></div>
                                            <div class="course-code"><?php echo $periods[$period]['course_code']; ?></div>
                                            <div class="staff-name"><?php echo $periods[$period]['staff_name']; ?></div>
                                        </td>
                                    <?php else: ?>
                                        <td class="timetable-cell"></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="page-break"></div>
        
        <div class="row mt-5">
            <div class="col-md-6">
                <h3>Summary</h3>
                <table class="table table-bordered summary-table">
                    <thead class="table-light">
                        <tr>
                            <th>Course</th>
                            <th>Credits</th>
                            <th>Allocated Periods</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['summary'] as $item): ?>
                            <tr>
                                <td><?php echo $item['course_name']; ?></td>
                                <td class="text-center"><?php echo $item['credits']; ?></td>
                                <td class="text-center"><?php echo $item['allocated_periods']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>