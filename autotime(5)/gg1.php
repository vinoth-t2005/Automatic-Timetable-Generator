<?php
// Database connection
$host = 'localhost';
$dbname = 'autotime';
$username = 'root';
$password = '';
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Create course_assignments table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS course_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            staff_id INT NOT NULL,
            periods VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}

// Fetch departments
$departments = [];
$stmt = $conn->query("SELECT DISTINCT dept FROM courses");
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch all courses (will be filtered via JavaScript)
$allCourses = [];
$stmt = $conn->query("SELECT id, name, course_code, credits, dept, staff FROM courses");
$allCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all staff (will be filtered via JavaScript)
$allStaff = [];
$stmt = $conn->query("SELECT id, name, unique_id, dept FROM staff");
$allStaff = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we're removing an assignment
    if (isset($_POST['action']) && $_POST['action'] === 'remove') {
        $assignment_id = $_POST['assignment_id'];
        
        // Delete the assignment
        $query = "DELETE FROM course_assignments WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->execute(['id' => $assignment_id]);
        
        // Set success message
        $successMessage = "Course assignment removed successfully!";
    } else {
        // This is a new assignment
        $course_id = $_POST['course'];
        $staff_id = $_POST['staff'];
        $periods = isset($_POST['auto_periods']) ? 'auto' : $_POST['periods'];
        
        // Insert into database
        $query = "INSERT INTO course_assignments (course_id, staff_id, periods) VALUES (:course_id, :staff_id, :periods)";
        $stmt = $conn->prepare($query);
        $stmt->execute([
            'course_id' => $course_id, 
            'staff_id' => $staff_id,
            'periods' => $periods
        ]);
        
        // Get course and staff details for the success message
        $courseQuery = $conn->prepare("SELECT name, course_code, credits FROM courses WHERE id = :id");
        $courseQuery->execute(['id' => $course_id]);
        $courseDetails = $courseQuery->fetch(PDO::FETCH_ASSOC);
        
        $staffQuery = $conn->prepare("SELECT name FROM staff WHERE id = :id");
        $staffQuery->execute(['id' => $staff_id]);
        $staffDetails = $staffQuery->fetch(PDO::FETCH_ASSOC);
        
        // Set success message with details
        $successMessage = "Course '{$courseDetails['name']} ({$courseDetails['course_code']})' assigned to {$staffDetails['name']} successfully!";
    }
}

// Fetch existing assignments
$existingAssignments = [];
try {
    $stmt = $conn->query("
        SELECT ca.id, c.name AS course_name, c.course_code, c.credits, s.name AS staff_name, ca.periods
        FROM course_assignments ca
        JOIN courses c ON ca.course_id = c.id
        JOIN staff s ON ca.staff_id = s.id
        ORDER BY c.dept, c.course_code
    ");
    $existingAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist or be empty - that's fine
    $existingAssignments = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Course</title>
    <style>
        .success {
            color: green;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        select:disabled {
            background-color: #f0f0f0;
            cursor: not-allowed;
        }
        input:disabled {
            background-color: #f0f0f0;
            cursor: not-allowed;
        }
        #assignmentList {
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 15px;
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
    </style>
</head>
<body>
    <h1>Add Course</h1>
    
    <?php if (isset($successMessage)): ?>
        <div class="success" id="successMessage"><?= $successMessage ?></div>
    <?php endif; ?>
    
    <form id="courseForm" method="POST" action="">
        <div class="form-group">
            <label for="dept">Department:</label>
            <select name="dept" id="dept">
                <option value="">Select Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept ?>">
                        <?= $dept ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="course">Course:</label>
            <select name="course" id="course" disabled>
                <option value="">Select Course</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="staff">Staff:</label>
            <select name="staff" id="staff" disabled>
                <option value="">Select Staff</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Number of Periods in Week:</label>
            <div class="period-group">
                <input type="number" name="periods" id="periods" min="1" max="20" value="1" disabled>
                <label class="checkbox-label">
                    <input type="checkbox" name="auto_periods" id="auto_periods" disabled>
                    Auto (System will determine based on credits)
                </label>
            </div>
        </div>
        
        <div class="form-group">
            <button type="submit" id="addCourseBtn" disabled>Add Course</button>
        </div>
    </form>
    
    <div id="assignmentList">
        <h2>Added Course Assignments</h2>
        <table>
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Staff</th>
                    <th>Credits</th>
                    <th>Number of Periods</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="assignmentTable">
                <?php if (empty($existingAssignments)): ?>
                <tr>
                    <td colspan="5" class="empty-table">No course assignments yet.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($existingAssignments as $assignment): ?>
                    <tr data-assignment-id="<?= $assignment['id'] ?>">
                        <td><?= $assignment['course_name'] ?> (<?= $assignment['course_code'] ?>)</td>
                        <td><?= $assignment['staff_name'] ?></td>
                        <td><?= $assignment['credits'] ?></td>
                        <td><?= $assignment['periods'] === 'auto' ? 'Auto' : $assignment['periods'] ?></td>
                        <td>
                            <button class="remove-btn" onclick="removeAssignment(<?= $assignment['id'] ?>)">Remove</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Form for removal operation -->
    <form id="removeForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="assignment_id" id="assignment_id_input">
    </form>

    <script>
        // Store all courses and staff as JavaScript objects
        const allCourses = <?= json_encode($allCourses) ?>;
        const allStaff = <?= json_encode($allStaff) ?>;
        
        // DOM elements
        const courseForm = document.getElementById('courseForm');
        const deptSelect = document.getElementById('dept');
        const courseSelect = document.getElementById('course');
        const staffSelect = document.getElementById('staff');
        const periodsInput = document.getElementById('periods');
        const autoPeriodsCheckbox = document.getElementById('auto_periods');
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
        
        // Function to remove an assignment
        function removeAssignment(assignmentId) {
            if (confirm('Are you sure you want to remove this assignment?')) {
                document.getElementById('assignment_id_input').value = assignmentId;
                removeForm.submit();
            }
        }
        
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
        
        // Form submission handler
        courseForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (selectedCourseId && selectedStaffId) {
                // Determine periods value
                const periodsValue = autoPeriodsCheckbox.checked ? 'auto' : periodsInput.value;
                
                // Create form data and submit via fetch API
                const formData = new FormData();
                formData.append('course', selectedCourseId);
                formData.append('staff', selectedStaffId);
                if (autoPeriodsCheckbox.checked) {
                    formData.append('auto_periods', 'on');
                } else {
                    formData.append('periods', periodsInput.value);
                }
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    // Create a new row element
                    const row = document.createElement('tr');
                    
                    // Generate a temporary ID (will be replaced on page reload)
                    const tempId = 'temp_' + new Date().getTime();
                    row.dataset.assignmentId = tempId;
                    
                    const courseCell = document.createElement('td');
                    courseCell.textContent = `${selectedCourseName} (${selectedCourseCode})`;
                    row.appendChild(courseCell);
                    
                    const staffCell = document.createElement('td');
                    staffCell.textContent = selectedStaffName;
                    row.appendChild(staffCell);
                    
                    const creditsCell = document.createElement('td');
                    creditsCell.textContent = selectedCourseCredits;
                    row.appendChild(creditsCell);
                    
                    const periodsCell = document.createElement('td');
                    periodsCell.textContent = periodsValue === 'auto' ? 'Auto' : periodsValue;
                    row.appendChild(periodsCell);
                    
                    const actionCell = document.createElement('td');
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'remove-btn';
                    removeBtn.textContent = 'Remove';
                    removeBtn.onclick = function() {
                        // Just remove the row for temporary entries
                        if (tempId.startsWith('temp_')) {
                            if (confirm('Are you sure you want to remove this assignment?')) {
                                assignmentTable.removeChild(row);
                                // If table is empty, add the "No assignments" message
                                if (assignmentTable.childElementCount === 0) {
                                    const emptyRow = document.createElement('tr');
                                    const emptyCell = document.createElement('td');
                                    emptyCell.colSpan = 5;
                                    emptyCell.className = 'empty-table';
                                    emptyCell.textContent = 'No course assignments yet.';
                                    emptyRow.appendChild(emptyCell);
                                    assignmentTable.appendChild(emptyRow);
                                }
                            }
                        }
                    };
                    actionCell.appendChild(removeBtn);
                    row.appendChild(actionCell);
                    
                    // Check if table has the "No assignments" message and remove it
                    const emptyMessage = assignmentTable.querySelector('.empty-table');
                    if (emptyMessage) {
                        assignmentTable.innerHTML = '';
                    }
                    
                    // Add the new row to the table
                    assignmentTable.appendChild(row);
                    
                    // Show success message
                    const successMsg = document.getElementById('successMessage');
                    if (!successMsg) {
                        const newSuccessMsg = document.createElement('div');
                        newSuccessMsg.id = 'successMessage';
                        newSuccessMsg.className = 'success';
                        newSuccessMsg.textContent = `Course '${selectedCourseName} (${selectedCourseCode})' assigned to ${selectedStaffName} successfully!`;
                        courseForm.parentNode.insertBefore(newSuccessMsg, courseForm);
                    } else {
                        successMsg.textContent = `Course '${selectedCourseName} (${selectedCourseCode})' assigned to ${selectedStaffName} successfully!`;
                    }
                    
                    // Reset form for next entry but keep department selected
                    staffSelect.value = "";
                    selectedStaffId = "";
                    selectedStaffName = "";
                    courseSelect.value = "";
                    selectedCourseId = "";
                    selectedCourseName = "";
                    selectedCourseCode = "";
                    selectedCourseCredits = "";
                    periodsInput.value = "1";
                    periodsInput.disabled = true;
                    autoPeriodsCheckbox.checked = false;
                    autoPeriodsCheckbox.disabled = true;
                    addCourseBtn.disabled = true;
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error adding course. Please try again.');
                });
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
                            if (assignmentTable.childElementCount === 0) {
                                const emptyRow = document.createElement('tr');
                                const emptyCell = document.createElement('td');
                                emptyCell.colSpan = 5;
                                emptyCell.className = 'empty-table';
                                emptyCell.textContent = 'No course assignments yet.';
                                emptyRow.appendChild(emptyCell);
                                assignmentTable.appendChild(emptyRow);
                            }
                        }
                    } else {
                        removeAssignment(assignmentId);
                    }
                };
            });
        }
        
        // Initialize remove button event listeners
        setupRemoveButtons();
        
        // If PHP set success message, keep the department selected
        <?php if (isset($successMessage) && isset($_POST['course']) && isset($_POST['staff'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            // Find the course to get its department
            <?php if (!isset($_POST['action'])): ?>
            const courseId = <?= json_encode($_POST['course']) ?>;
            const course = allCourses.find(c => c.id === courseId);
            
            if (course) {
                // Set the department dropdown to keep the department selected
                deptSelect.value = course.dept;
                
                // Trigger change event to populate courses
                const event = new Event('change');
                deptSelect.dispatchEvent(event);
            }
            <?php endif; ?>
        });
        <?php endif; ?>
    </script>
</body>
</html>