<?php
// Connect to database
$conn = new mysqli("localhost", "root", "", "autotime2");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create 'staff' table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    dept VARCHAR(255) NOT NULL,
    unique_id VARCHAR(50) NOT NULL UNIQUE
)";
$conn->query($createTableQuery);

// Initialize variables for edit mode
$edit_mode = false;
$edit_id = "";
$edit_name = "";
$edit_dept = "";
$edit_unique_id = "";

// Handle form submission
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Handle delete action
    if (isset($_POST['delete_id'])) {
        $delete_id = $conn->real_escape_string($_POST['delete_id']);
        $deleteQuery = "DELETE FROM staff WHERE id = '$delete_id'";
        
        if ($conn->query($deleteQuery) === TRUE) {
            // Reorder IDs after deletion
            $conn->query("SET @count = 0");
            $conn->query("UPDATE staff SET id = @count:= @count + 1");
            $conn->query("ALTER TABLE staff AUTO_INCREMENT = 1");
            
            $message = "<p style='color:green;'>✅ Staff deleted successfully!</p>";
        } else {
            $message = "<p style='color:red;'>❌ Error deleting staff: " . $conn->error . "</p>";
        }
    } 
    // Handle update staff action
    elseif (isset($_POST['update_id'])) {
        $update_id = $conn->real_escape_string($_POST['update_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $dept = $conn->real_escape_string($_POST['dept']);
        $unique_id = $conn->real_escape_string($_POST['unique_id']);
        
        // Check if unique ID already exists and belongs to different record
        $checkQuery = "SELECT * FROM staff WHERE unique_id = '$unique_id' AND id != '$update_id'";
        $result = $conn->query($checkQuery);
        
        if ($result->num_rows > 0) {
            $message = "<p style='color:red;'>⚠️ Unique ID already exists!</p>";
            
            // Re-populate form with submitted values for correction
            $edit_mode = true;
            $edit_id = $update_id;
            $edit_name = $name;
            $edit_dept = $dept;
            $edit_unique_id = $unique_id;
            
            // Get staff details for edit form
            $editQuery = "SELECT * FROM staff WHERE id = '$update_id'";
            $editResult = $conn->query($editQuery);
            $editData = $editResult->fetch_assoc();
            
        } else {
            $updateQuery = "UPDATE staff SET name = '$name', dept = '$dept', unique_id = '$unique_id' WHERE id = '$update_id'";
            if ($conn->query($updateQuery) === TRUE) {
                $message = "<p style='color:green;'>✅ Staff updated successfully!</p>";
            } else {
                $message = "<p style='color:red;'>❌ Error updating staff: " . $conn->error . "</p>";
            }
        }
    }
    // Handle add staff action
    else {
        $name = $conn->real_escape_string($_POST['name']);
        $dept = $conn->real_escape_string($_POST['dept']);
        $unique_id = $conn->real_escape_string($_POST['unique_id']);

        // Check if unique ID already exists
        $checkQuery = "SELECT * FROM staff WHERE unique_id = '$unique_id'";
        $result = $conn->query($checkQuery);

        if ($result->num_rows > 0) {
            $message = "<p style='color:red;'>⚠️ Unique ID already exists!</p>";
        } else {
            $insertQuery = "INSERT INTO staff (name, dept, unique_id) VALUES ('$name', '$dept', '$unique_id')";
            if ($conn->query($insertQuery) === TRUE) {
                $message = "<p style='color:green;'>✅ Staff added successfully!</p>";
            } else {
                $message = "<p style='color:red;'>❌ Error: " . $conn->error . "</p>";
            }
        }
    }
}

// Handle edit request (via GET)
if (isset($_GET['edit_id'])) {
    $edit_id = $conn->real_escape_string($_GET['edit_id']);
    $editQuery = "SELECT * FROM staff WHERE id = '$edit_id'";
    $editResult = $conn->query($editQuery);
    
    if ($editResult->num_rows > 0) {
        $editData = $editResult->fetch_assoc();
        $edit_mode = true;
        $edit_name = $editData['name'];
        $edit_dept = $editData['dept'];
        $edit_unique_id = $editData['unique_id'];
    }
}

// Fetch staff list
$staffList = $conn->query("SELECT * FROM staff ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Staff</title>
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
        .content h1 {
            color: #333;
        }
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 400px;
            margin-top: 20px;
        }
        input, select, button {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        button {
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        .staff-table {
            margin-top: 20px;
            width: 100%;
            border-collapse: collapse;
        }
        .staff-table th, .staff-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .staff-table th {
            background-color: #007bff;
            color: white;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .edit-btn, .delete-btn, .view-btn {
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
        .view-btn {
            color: #17a2b8;
        }
        .view-btn:hover {
            color: #138496;
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
        <h1>Add Staff</h1>
        <p>Enter staff details below.</p>

        <?= $message; ?> <!-- Display success/error messages -->

        <div class="form-container">
            <div class="form-header">
                <h2><?= $edit_mode ? 'Edit Staff' : 'Add Staff' ?></h2>
                <?php if ($edit_mode): ?>
                    <a href="addstaff.php" class="cancel-edit-btn">Cancel</a>
                <?php endif; ?>
            </div>
            <form method="POST">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="update_id" value="<?= $edit_id ?>">
                <?php endif; ?>
                
                <label for="name">Staff Name:</label>
                <input type="text" id="name" name="name" value="<?= $edit_mode ? $edit_name : '' ?>" required>

                <label for="dept">Department:</label>
                <input type="text" id="dept" name="dept" value="<?= $edit_mode ? $edit_dept : '' ?>" required>

                <label for="unique_id">Unique ID:</label>
                <input type="text" id="unique_id" name="unique_id" value="<?= $edit_mode ? $edit_unique_id : '' ?>" required>

                <button type="submit"><?= $edit_mode ? 'Update Staff' : 'Add Staff' ?></button>
            </form>
        </div>

        <!-- Staff List Table -->
        <h2>Staff List</h2>
        <table class="staff-table">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Department</th>
                <th>Unique ID</th>
                <th>Actions</th>
            </tr>
            <?php while ($row = $staffList->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['id']; ?></td>
                    <td><?= $row['name']; ?></td>
                    <td><?= $row['dept']; ?></td>
                    <td><?= $row['unique_id']; ?></td>
                    <td class="action-buttons">
                        <a href="addstaff.php?edit_id=<?= $row['id']; ?>" class="edit-btn" title="Edit">✏️</a>
                        <a href="view_staff_schedule.php?staff_id=<?= $row['id']; ?>" class="view-btn" title="View Schedule">👁️</a>
                        <button class="delete-btn" onclick="confirmDelete(<?= $row['id']; ?>, '<?= $row['name']; ?>')" title="Delete">🗑️</button>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    </main>

    <!-- Confirmation Modal -->
    <div id="deleteModal" class="confirmation-modal">
        <div class="modal-content">
            <h3>Confirm Deletion</h3>
            <p>Are you sure you want to delete <span id="staffName"></span>?</p>
            <div class="modal-buttons">
                <button class="cancel-btn" onclick="closeModal()">Cancel</button>
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="delete_id" id="deleteId">
                    <button type="submit" class="confirm-delete-btn">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(id, name) {
            document.getElementById('deleteId').value = id;
            document.getElementById('staffName').textContent = name;
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

</body>
</html>