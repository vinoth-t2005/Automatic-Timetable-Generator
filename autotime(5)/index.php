<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time Table Management</title>
    <style>
        /* General Styling */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        body {
            display: flex;
            background-color: #f5f5f5;
            transition: background 0.3s ease-in-out;
        }
        .dark-mode {
            background-color: #222;
            color: white;
        }

        /* Navbar */
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
            z-index: 1000;
        }
        .navbar .title {
            font-size: 20px;
            font-weight: bold;
        }
        .navbar button {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: white;
        }

        /* Sidebar */
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

        /* Main Content */
        .content {
            margin-left: 270px;
            padding: 80px 20px 20px; /* Adjusted padding to fix hidden text issue */
            flex-grow: 1;
        }
        .content h1 {
            color: #333;
        }

        /* Cards */
        .cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 20px;
        }
        .card {
            background-color: white;
            padding: 20px;
            width: 250px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s ease-in-out;
        }
        .card:hover {
            transform: scale(1.05);
        }
        .card h2 {
            margin: 0;
            color: #007bff;
        }
        .card a {
            text-decoration: none;
            color: inherit;
            display: block;
        }

        /* Dark Mode Styling */
        .dark-mode .navbar, .dark-mode .sidebar {
            background-color: #333;
        }
        .dark-mode .card {
            background-color: #444;
            color: white;
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="title">Time Table Management</div>
        <button id="darkModeToggle">🌙</button>
    </nav>

    <!-- Sidebar -->
    <aside class="sidebar">
        <ul>
            <li><a href="addstaff.php">➕ Add Staff</a></li>
            <li><a href="addcourse.php">📚 Add Courses</a></li>
            <li><a href="addclass.php">🏫 Add Classes</a></li>
	    <li><a href="template.php">📑 Add Templates</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="content">
        <h1>Welcome to Time Table Management</h1>
        <p>Manage staff, courses, and classes with ease.</p>

        <div class="cards">
            <a href="addstaff.php" class="card">
                <h2>👨‍🏫 Add Staff</h2>
                <p>Manage staff details & workload.</p>
            </a>
            <a href="addcourse.php" class="card">
                <h2>📖 Add Courses</h2>
                <p>Define subjects & credit hours.</p>
            </a>
            <a href="addclass.php" class="card">
                <h2>🏫 Add Classes</h2>
                <p>Assign advisors, sections & manage schedules.</p>
            </a>
        </div>
    </main>

    <!-- JavaScript for Dark Mode -->
    <script>
        document.getElementById('darkModeToggle').addEventListener('click', function () {
            document.body.classList.toggle('dark-mode');
        });
    </script>

</body>
</html>
