<?php 
// Database Connection
$conn = new mysqli("localhost", "root", "", "autotime2");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Create courses table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    credits INT NOT NULL,
    dept VARCHAR(100) NOT NULL,
    staff TEXT,
    mnemonic VARCHAR(50)
)";

if (!$conn->query($createTableQuery)) {
    die("Error creating table: " . $conn->error);
}

// Check if the mnemonic field exists in the courses table
$checkMnemonicField = $conn->query("SHOW COLUMNS FROM courses LIKE 'mnemonic'");
if ($checkMnemonicField->num_rows == 0) {
    // Add mnemonic field to courses table
    $conn->query("ALTER TABLE courses ADD COLUMN mnemonic VARCHAR(50)");
}

// Initialize variables for edit mode
$edit_mode = false;
$edit_id = "";
$edit_name = "";
$edit_course_code = "";
$edit_credits = "";
$edit_dept = "";
$edit_staff = [];
$edit_mnemonic = "";

// Message variable for notifications
$message = "";

// Handle Course Addition, Edit, or Deletion
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle delete action
    if (isset($_POST['delete_id'])) {
        $delete_id = $conn->real_escape_string($_POST['delete_id']);
        $deleteQuery = "DELETE FROM courses WHERE id = '$delete_id'";
        
        if ($conn->query($deleteQuery) === TRUE) {
            // Reorder IDs after deletion
            $conn->query("SET @count = 0");
            $conn->query("UPDATE courses SET id = @count:= @count + 1");
            $conn->query("ALTER TABLE courses AUTO_INCREMENT = 1");
            
            $message = "<p style='color:green;'>✅ Course deleted successfully!</p>";
        } else {
            $message = "<p style='color:red;'>❌ Error deleting course: " . $conn->error . "</p>";
        }
    }
    // Handle course update
    else if (isset($_POST['update_id'])) {
        $update_id = $conn->real_escape_string($_POST['update_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $course_code = $conn->real_escape_string($_POST['course_code']);
        $credits = $conn->real_escape_string($_POST['credits']);
        $dept = $conn->real_escape_string($_POST['dept']);
        $mnemonic = $conn->real_escape_string($_POST['mnemonic']);
        
        // Check if staff is selected
        if (isset($_POST['staff']) && is_array($_POST['staff'])) {
            $staff = implode(", ", $_POST['staff']); // Multiple staff selection
        } else {
            $staff = "";
        }

        // Update the course
        $updateQuery = "UPDATE courses SET 
                       name = '$name', 
                       course_code = '$course_code', 
                       credits = '$credits', 
                       dept = '$dept', 
                       staff = '$staff',
                       mnemonic = '$mnemonic'
                       WHERE id = '$update_id'";

        if ($conn->query($updateQuery) === TRUE) {
            $message = "<p style='color:green;'>✅ Course updated successfully!</p>";
        } else {
            $message = "<p style='color:red;'>❌ Error updating course: " . $conn->error . "</p>";
        }
    }
    // Handle course addition
    else if (isset($_POST['name'])) {
        $name = $conn->real_escape_string($_POST['name']);
        $course_code = $conn->real_escape_string($_POST['course_code']);
        $credits = $conn->real_escape_string($_POST['credits']);
        $dept = $conn->real_escape_string($_POST['dept']);
        $mnemonic = $conn->real_escape_string($_POST['mnemonic']);
        
        // Check if staff is selected
        if (isset($_POST['staff']) && is_array($_POST['staff'])) {
            $staff = implode(", ", $_POST['staff']); // Multiple staff selection
        } else {
            $staff = "";
        }

        // Insert into database
        $sql = "INSERT INTO courses (name, course_code, credits, dept, staff, mnemonic) 
                VALUES ('$name', '$course_code', '$credits', '$dept', '$staff', '$mnemonic')";

        if ($conn->query($sql) === TRUE) {
            $message = "<p style='color:green;'>✅ Course added successfully!</p>";
        } else {
            $message = "<p style='color:red;'>❌ Error: " . $conn->error . "</p>";
        }
    }
}

// Handle edit request (via GET)
if (isset($_GET['edit_id'])) {
    $edit_id = $conn->real_escape_string($_GET['edit_id']);
    $editQuery = "SELECT * FROM courses WHERE id = '$edit_id'";
    $editResult = $conn->query($editQuery);
    
    if ($editResult->num_rows > 0) {
        $editData = $editResult->fetch_assoc();
        $edit_mode = true;
        $edit_name = $editData['name'];
        $edit_course_code = $editData['course_code'];
        $edit_credits = $editData['credits'];
        $edit_dept = $editData['dept'];
        $edit_staff = explode(", ", $editData['staff']);
        $edit_mnemonic = isset($editData['mnemonic']) ? $editData['mnemonic'] : '';
    }
}

// Fetch Departments
$deptQuery = "SELECT DISTINCT dept FROM staff";
$deptResult = $conn->query($deptQuery);

// Fetch Staff for the selected department (if in edit mode)
$staffOptions = [];
if ($edit_mode && !empty($edit_dept)) {
    $staffQuery = "SELECT id, name FROM staff WHERE dept = '$edit_dept'";
    $staffResult = $conn->query($staffQuery);
    while ($staffRow = $staffResult->fetch_assoc()) {
        $staffOptions[] = $staffRow;
    }
}

// Fetch Courses for Display
$coursesQuery = "SELECT * FROM courses ORDER BY id ASC";
$coursesResult = $conn->query($coursesQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Course</title>
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
            margin-bottom: 20px;
        }
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .form-header h2 {
            margin: 0;
        }
        .cancel-edit-btn {
            background-color: #6c757d;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
        }
        .cancel-edit-btn:hover {
            background-color: #5a6268;
        }
        label {
            font-weight: bold;
            display: block;
            margin-top: 10px;
        }
        select, input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        select[multiple] {
            height: 120px;
        }
        button {
            width: 100%;
            padding: 10px;
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
        .courses-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .courses-table th, .courses-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .courses-table th {
            background-color: #007bff;
            color: white;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .edit-btn, .delete-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            font-size: 18px;
            width: auto;
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
        .confirmation-modal {
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
            width: 300px;
            text-align: center;
        }
        .modal-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }
        .modal-buttons button, .modal-buttons form button {
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
    </style>
    <script>
        function fetchStaff(dept) {
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "fetch_staffs.php", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.onreadystatechange = function () {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    document.getElementById("staffSelect").innerHTML = xhr.responseText;
                }
            };
            xhr.send("department=" + dept);
        }

        function confirmDelete(id, name) {
            document.getElementById('deleteId').value = id;
            document.getElementById('courseName').textContent = name;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal if user clicks outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
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
        <h1><?= $edit_mode ? 'Edit Course' : 'Add Course' ?></h1>
        
        <?= $message; ?> <!-- Display success/error messages -->

        <div class="form-container">
            <div class="form-header">
                <h2><?= $edit_mode ? 'Edit Course' : 'Add Course' ?></h2>
                <?php if ($edit_mode): ?>
                    <a href="addcourse.php" class="cancel-edit-btn">Cancel</a>
                <?php endif; ?>
            </div>
            <form method="POST">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="update_id" value="<?= $edit_id ?>">
                <?php endif; ?>

                <label for="name">Course Name:</label>
                <input type="text" id="name" name="name" value="<?= $edit_mode ? $edit_name : '' ?>" required>

                <label for="course_code">Course Code:</label>
                <input type="text" id="course_code" name="course_code" value="<?= $edit_mode ? $edit_course_code : '' ?>" required>

                <label for="mnemonic">Mnemonic:</label>
                <input type="text" id="mnemonic" name="mnemonic" value="<?= $edit_mode ? $edit_mnemonic : '' ?>" placeholder="E.g., CS, MATH, BIO">

                <label for="credits">Credits:</label>
<input type="number" id="credits" name="credits" value="<?= $edit_mode ? $edit_credits : '' ?>" required>

<label for="dept">Department:</label>
<select id="dept" name="dept" onchange="fetchStaff(this.value)" required>
    <option value="">Select Department</option>
    <?php 
    if ($deptResult->num_rows > 0) {
        $deptResult->data_seek(0); // Reset pointer
        while ($row = $deptResult->fetch_assoc()) { 
    ?>
        <option value="<?= $row['dept'] ?>" <?= ($edit_mode && $edit_dept == $row['dept']) ? 'selected' : '' ?>><?= $row['dept'] ?></option>
    <?php 
        }
    } 
    ?>
</select>

<label for="staffSelect">Choose Staff:</label>
<select name="staff[]" id="staffSelect" multiple required>
    <?php if ($edit_mode && !empty($staffOptions)): ?>
        <?php foreach ($staffOptions as $staff): ?>
            <option value="<?= $staff['name'] ?>" <?= in_array($staff['name'], $edit_staff) ? 'selected' : '' ?>><?= $staff['name'] ?></option>
        <?php endforeach; ?>
    <?php else: ?>
        <option value="">Select Department First</option>
    <?php endif; ?>
</select>
<small style="display: block; margin-top: 5px; color: #6c757d;">Hold Ctrl/Cmd to select multiple staff</small>

<button type="submit"><?= $edit_mode ? 'Update Course' : 'Add Course' ?></button>
</form>
</div>

<!-- Course List Table -->
<h2>List of Courses</h2>
<table class="courses-table">
    <tr>
        <th>ID</th>
        <th>Course Name</th>
        <th>Code</th>
        <th>Mnemonic</th>
        <th>Credits</th>
        <th>Department</th>
        <th>Staff</th>
        <th>Actions</th>
    </tr>
    <?php 
    if ($coursesResult->num_rows > 0) {
        while ($row = $coursesResult->fetch_assoc()) { 
    ?>
        <tr>
            <td><?= isset($row['id']) ? $row['id'] : 'N/A' ?></td>
            <td><?= $row['name'] ?></td>
            <td><?= $row['course_code'] ?></td>
            <td><?= isset($row['mnemonic']) ? $row['mnemonic'] : 'N/A' ?></td>
            <td><?= $row['credits'] ?></td>
            <td><?= $row['dept'] ?></td>
            <td><?= $row['staff'] ?></td>
            <td class="action-buttons">
                <a href="addcourse.php?edit_id=<?= $row['id'] ?>" class="edit-btn">✏️</a> 
                <button class="delete-btn" onclick="confirmDelete(<?= $row['id'] ?>, '<?= $row['name'] ?>')">🗑️</button>
            </td>
        </tr>
    <?php 
        }
    } else {
        echo "<tr><td colspan='8' style='text-align:center;'>No courses available</td></tr>";
    }
    ?>
</table>
</main>

<!-- Confirmation Modal -->
<div id="deleteModal" class="confirmation-modal">
    <div class="modal-content">
        <h3>Confirm Deletion</h3>
        <p>Are you sure you want to delete course: <span id="courseName"></span>?</p>
        <div class="modal-buttons">
            <button class="cancel-btn" onclick="closeModal()">Cancel</button>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="delete_id" id="deleteId">
                <button type="submit" class="confirm-delete-btn">Delete</button>
            </form>
        </div>
    </div>
</div>

<!-- Create fetch_staff.php file if it doesn't exist -->
<?php
// Create fetch_staff.php file if it doesn't exist
$fetch_staff_file = 'fetch_staff.php';
if (!file_exists($fetch_staff_file)) {
    $fetch_staff_content = '<?php
// Database Connection
$conn = new mysqli("localhost", "root", "", "autotime");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_POST["department"])) {
    $department = $conn->real_escape_string($_POST["department"]);
    $query = "SELECT * FROM staff WHERE dept = \'".$department."\'";
    $result = $conn->query($query);
    
    $output = "";
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $output .= "<option value=\"".$row["name"]."\">".$row["name"]."</option>";
        }
    } else {
        $output = "<option value=\"\">No staff found in this department</option>";
    }
    echo $output;
}
?>';
    file_put_contents($fetch_staff_file, $fetch_staff_content);
}
?>

</body>
</html>