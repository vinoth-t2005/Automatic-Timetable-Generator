<?php
// Database Connection
$conn = new mysqli("localhost", "root", "", "autotime");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create classes table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS classes (
    id VARCHAR(50) PRIMARY KEY,
    year INT NOT NULL,
    semester INT NOT NULL,
    batch_start INT NOT NULL,
    batch_end INT NOT NULL,
    dept VARCHAR(50) NOT NULL,
    section VARCHAR(10) NOT NULL,
    advisor VARCHAR(255) NOT NULL,
    assistant_advisor VARCHAR(255) NOT NULL,
    class_id VARCHAR(100) NOT NULL UNIQUE
)";
$conn->query($createTableQuery);

// Function to generate full class ID
function generateClassId($dept, $section, $year, $semester, $batch_start, $batch_end) {
    return strtoupper($dept . '-' . $section . '-' . $year . '-' . $semester . '-' . $batch_start . '-' . $batch_end);
}

// Handle Class CRUD operations
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle delete action
    if (isset($_POST['delete_id'])) {
        $delete_id = $conn->real_escape_string($_POST['delete_id']);
        $deleteQuery = "DELETE FROM classes WHERE id = '$delete_id'";
        
        if ($conn->query($deleteQuery) === TRUE) {
            $message = "<p style='color:green;'>✅ Class deleted successfully!</p>";
        } else {
            $message = "<p style='color:red;'>❌ Error deleting class: " . $conn->error . "</p>";
        }
    } 
    // Handle update action
    else if (isset($_POST['update_id'])) {
        $update_id = $conn->real_escape_string($_POST['update_id']);
        $year = $_POST['year'];
        $semester = $_POST['semester'];
        $batch_start = $_POST['batch_start'];
        $batch_end = $_POST['batch_end'];
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $advisor = $_POST['advisor'];
        $assistant_advisor = $_POST['assistant_advisor'];
        $new_class_id = strtoupper($dept . "-" . $section);
        $new_full_class_id = generateClassId($dept, $section, $year, $semester, $batch_start, $batch_end);
        
        $updateQuery = "UPDATE classes SET 
                        id = '$new_class_id',
                        year = '$year', 
                        semester = '$semester', 
                        batch_start = '$batch_start', 
                        batch_end = '$batch_end', 
                        dept = '$dept', 
                        section = '$section', 
                        advisor = '$advisor', 
                        assistant_advisor = '$assistant_advisor',
                        class_id = '$new_full_class_id'
                        WHERE id = '$update_id'";
        
        if ($conn->query($updateQuery) === TRUE) {
            $message = "<p style='color:green;'>✅ Class updated successfully!</p>";
        } else {
            $message = "<p style='color:red;'>❌ Error updating class: " . $conn->error . "</p>";
        }
    }
    // Handle add class action
    else {
        $year = $_POST['year'];
        $semester = $_POST['semester'];
        $batch_start = $_POST['batch_start'];
        $batch_end = $_POST['batch_end'];
        $dept = $_POST['dept'];
        $section = $_POST['section'];
        $advisor = $_POST['advisor'];
        $assistant_advisor = $_POST['assistant_advisor'];
        $class_id = strtoupper($dept . "-" . $section);
        $full_class_id = generateClassId($dept, $section, $year, $semester, $batch_start, $batch_end);

        // Insert into database
        $sql = "INSERT INTO classes (id, year, semester, batch_start, batch_end, dept, section, advisor, assistant_advisor, class_id) 
                VALUES ('$class_id', '$year', '$semester', '$batch_start', '$batch_end', '$dept', '$section', '$advisor', '$assistant_advisor', '$full_class_id')";

        if ($conn->query($sql) === TRUE) {
            $message = "<p style='color:green;'>✅ Class added successfully!</p>";
        } else {
            $message = "<p style='color:red;'>❌ Error: " . $conn->error . "</p>";
        }
    }
}

// Fetch Departments
$deptQuery = "SELECT DISTINCT dept FROM staff";
$deptResult = $conn->query($deptQuery);

// Fetch Class Details
$classesQuery = "SELECT * FROM classes";
$classesResult = $conn->query($classesQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Class</title>
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
            z-index: 10;
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
        .content h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            margin-top: 20px;
        }
        label {
            font-weight: bold;
            display: block;
            margin-top: 10px;
        }
        select, input {
            width: 100%;
            padding: 10px;
            margin: 5px 0 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .batch-selects {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .batch-selects select {
            width: 45%;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover {
            background-color: #0056b3;
        }
        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #007bff;
            color: white;
        }
        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            font-size: 18px;
            width: auto;
            margin-right: 5px;
        }
        .edit-btn {
            color: #28a745;
        }
        .edit-btn:hover {
            color: #218838;
        }
        .delete-btn {
            color: #dc3545;
        }
        .delete-btn:hover {
            color: #c82333;
        }
        .confirmation-modal, .edit-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 100;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            width: 500px;
            max-width: 90%;
        }
        .modal-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }
        .modal-buttons button {
            width: 45%;
        }
        .confirm-delete-btn {
            background-color: #dc3545;
            color: white;
        }
        .confirm-delete-btn:hover {
            background-color: #c82333;
        }
        .cancel-btn {
            background-color: #6c757d;
        }
        .cancel-btn:hover {
            background-color: #5a6268;
        }
        .action-cell {
            display: flex;
        }
        .full-class-id {
            font-family: monospace;
            background-color: #f8f9fa;
            padding: 2px 5px;
            border-radius: 3px;
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
        <h1>Add Class</h1>
        <p>Enter class details below.</p>

        <?= $message; ?> <!-- Display success/error messages -->

        <div class="form-container">
            <form method="POST" id="addClass">
                <label for="year">Year:</label>
                <select id="year" name="year" required>
                    <option value="">Select Year</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </select>

                <label for="semester">Semester:</label>
                <select id="semester" name="semester" required>
                    <option value="">Select Semester</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                    <option value="6">6</option>
                    <option value="7">7</option>
                    <option value="8">8</option>
                </select>

                <label for="batch">Batch:</label>
                <div class="batch-selects">
                    <select id="batch_start" name="batch_start" required>
                        <option value="">Start Year</option>
                        <?php for ($i = date("Y") - 5; $i <= date("Y") + 5; $i++) { ?>
                            <option value="<?= $i ?>"><?= $i ?></option>
                        <?php } ?>
                    </select>
                    <span>-</span>
                    <select id="batch_end" name="batch_end" required>
                        <option value="">End Year</option>
                        <?php for ($i = date("Y") - 4; $i <= date("Y") + 6; $i++) { ?>
                            <option value="<?= $i ?>"><?= $i ?></option>
                        <?php } ?>
                    </select>
                </div>

                <label for="dept">Department:</label>
                <select id="dept" name="dept" required onchange="loadStaffByDept(this.value)">
                    <option value="">Select Department</option>
                    <?php mysqli_data_seek($deptResult, 0); // Reset dept query result pointer ?>
                    <?php while ($row = $deptResult->fetch_assoc()) { ?>
                        <option value="<?= $row['dept'] ?>"><?= $row['dept'] ?></option>
                    <?php } ?>
                </select>

                <label for="section">Section:</label>
                <input type="text" id="section" name="section" required placeholder="Enter section (A, B, C, etc.)">

                <label for="advisor">Advisor:</label>
                <select id="advisor" name="advisor" required>
                    <option value="">Select Department First</option>
                </select>

                <label for="assistant_advisor">Assistant Advisor:</label>
                <select id="assistant_advisor" name="assistant_advisor" required>
                    <option value="">Select Department First</option>
                </select>

                <button type="submit">➕ Add Class</button>
            </form>
        </div>

        <!-- Class List Table -->
        <h2 style="margin-top: 30px;">List of Classes</h2>
        <table>
            <tr>
                <th>Simple ID</th>
                <th>Full Class ID</th>
                <th>Year</th>
                <th>Semester</th>
                <th>Batch</th>
                <th>Department</th>
                <th>Section</th>
                <th>Advisor</th>
                <th>Assistant Advisor</th>
                <th>Action</th>
            </tr>
            <?php mysqli_data_seek($classesResult, 0); // Reset classes query result pointer ?>
            <?php while ($row = $classesResult->fetch_assoc()) { ?>
                <tr>
                    <td><?= $row['id'] ?></td>
                    <td class="full-class-id"><?= $row['class_id'] ?></td>
                    <td><?= $row['year'] ?></td>
                    <td><?= $row['semester'] ?></td>
                    <td><?= $row['batch_start'] ?> - <?= $row['batch_end'] ?></td>
                    <td><?= $row['dept'] ?></td>
                    <td><?= $row['section'] ?></td>
                    <td><?= $row['advisor'] ?></td>
                    <td><?= $row['assistant_advisor'] ?></td>
                    <td class="action-cell">
                        <button class="action-btn edit-btn" 
                                onclick="openEditModal('<?= $row['id'] ?>', 
                                                     <?= $row['year'] ?>, 
                                                     <?= $row['semester'] ?>, 
                                                     <?= $row['batch_start'] ?>, 
                                                     <?= $row['batch_end'] ?>, 
                                                     '<?= $row['dept'] ?>', 
                                                     '<?= $row['section'] ?>', 
                                                     '<?= $row['advisor'] ?>', 
                                                     '<?= $row['assistant_advisor'] ?>',
                                                     '<?= $row['class_id'] ?>')">
                            ✏️
                        </button>
                        <button class="action-btn delete-btn" onclick="confirmDelete('<?= $row['id'] ?>', '<?= $row['dept'] ?>-<?= $row['section'] ?>')">🗑️</button>
                    </td>
                </tr>
            <?php } ?>
        </table>
    </main>

    <!-- Confirmation Modal -->
    <div id="deleteModal" class="confirmation-modal">
        <div class="modal-content">
            <h3>Confirm Deletion</h3>
            <p>Are you sure you want to delete class <span id="className"></span>?</p>
            <div class="modal-buttons">
                <button class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="delete_id" id="delete_id">
                    <button type="submit" class="confirm-delete-btn">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="edit-modal">
        <div class="modal-content">
            <h3>Edit Class</h3>
            <form method="POST" id="editForm">
                <input type="hidden" name="update_id" id="edit_id">
                
                <label for="edit_year">Year:</label>
                <select id="edit_year" name="year" required>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </select>

                <label for="edit_semester">Semester:</label>
                <select id="edit_semester" name="semester" required>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                    <option value="6">6</option>
                    <option value="7">7</option>
                    <option value="8">8</option>
                </select>

                <label for="batch">Batch:</label>
                <div class="batch-selects">
                    <select id="edit_batch_start" name="batch_start" required>
                        <?php for ($i = date("Y") - 5; $i <= date("Y") + 5; $i++) { ?>
                            <option value="<?= $i ?>"><?= $i ?></option>
                        <?php } ?>
                    </select>
                    <span>-</span>
                    <select id="edit_batch_end" name="batch_end" required>
                        <?php for ($i = date("Y") - 4; $i <= date("Y") + 6; $i++) { ?>
                            <option value="<?= $i ?>"><?= $i ?></option>
                        <?php } ?>
                    </select>
                </div>

                <label for="edit_dept">Department:</label>
                <select id="edit_dept" name="dept" required onchange="loadStaffByDept(this.value, 'edit')">
                    <option value="">Select Department</option>
                    <?php mysqli_data_seek($deptResult, 0); // Reset dept query result pointer ?>
                    <?php while ($row = $deptResult->fetch_assoc()) { ?>
                        <option value="<?= $row['dept'] ?>"><?= $row['dept'] ?></option>
                    <?php } ?>
                </select>

                <label for="edit_section">Section:</label>
                <input type="text" id="edit_section" name="section" required>

                <label for="edit_advisor">Advisor:</label>
                <select id="edit_advisor" name="advisor" required>
                    <option value="">Select Department First</option>
                </select>

                <label for="edit_assistant_advisor">Assistant Advisor:</label>
                <select id="edit_assistant_advisor" name="assistant_advisor" required>
                    <option value="">Select Department First</option>
                </select>

                <label for="edit_full_class_id">Full Class ID:</label>
                <input type="text" id="edit_full_class_id" name="full_class_id" readonly class="full-class-id">

                <div class="modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Load staff by department
        function loadStaffByDept(dept, form_type = '') {
            let prefix = form_type === 'edit' ? 'edit_' : '';
            
            // Create XMLHttpRequest object
            const xhr = new XMLHttpRequest();
            
            // Configure the request
            xhr.open('POST', 'get_staff.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            // Set up event handler for when the request is complete
            xhr.onload = function() {
                if (xhr.status === 200) {
                    let options = '<option value="">Select Staff</option>' + xhr.responseText;
                    document.getElementById(prefix + 'advisor').innerHTML = options;
                    document.getElementById(prefix + 'assistant_advisor').innerHTML = options;
                } else {
                    document.getElementById(prefix + 'advisor').innerHTML = '<option value="">Error loading staff</option>';
                    document.getElementById(prefix + 'assistant_advisor').innerHTML = '<option value="">Error loading staff</option>';
                }
            };
            
            // Handle network errors
            xhr.onerror = function() {
                document.getElementById(prefix + 'advisor').innerHTML = '<option value="">Error loading staff</option>';
                document.getElementById(prefix + 'assistant_advisor').innerHTML = '<option value="">Error loading staff</option>';
            };
            
            // Send the request
            xhr.send('department=' + encodeURIComponent(dept));
        }

        // Delete confirmation
        function confirmDelete(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('className').textContent = name;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Edit modal functions
        function openEditModal(id, year, semester, batch_start, batch_end, dept, section, advisor, assistant_advisor, class_id) {
            // Set form values
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_year').value = year;
            document.getElementById('edit_semester').value = semester;
            document.getElementById('edit_batch_start').value = batch_start;
            document.getElementById('edit_batch_end').value = batch_end;
            document.getElementById('edit_dept').value = dept;
            document.getElementById('edit_section').value = section;
            document.getElementById('edit_full_class_id').value = class_id;
            
            // Load staff for the department
            loadStaffByDept(dept, 'edit');
            
            // We need to set these after staff is loaded
            setTimeout(() => {
                document.getElementById('edit_advisor').value = advisor;
                document.getElementById('edit_assistant_advisor').value = assistant_advisor;
            }, 500);
            
            // Show modal
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modals if clicking outside
        window.onclick = function(event) {
            if (event.target === document.getElementById('deleteModal')) {
                closeDeleteModal();
            }
            if (event.target === document.getElementById('editModal')) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>