<?php
$servername = "localhost";
$username = "root"; // Change if needed
$password = ""; // Change if needed
$dbname = "timetable_db";

// Create connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS $dbname";
$conn->query($sql);

// Select the database
$conn->select_db($dbname);

// Create timetable table
$sql = "CREATE TABLE IF NOT EXISTS timetables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day VARCHAR(20),
    time_slot VARCHAR(20),
    subject VARCHAR(50),
    duration INT
)";
$conn->query($sql);

// Handle POST request to insert data
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $day = $_POST['day'];
    $time_slot = $_POST['time_slot'];
    $subject = $_POST['subject'];
    $duration = $_POST['duration'];

    $stmt = $conn->prepare("INSERT INTO timetables (day, time_slot, subject, duration) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $day, $time_slot, $subject, $duration);

    if ($stmt->execute()) {
        echo "Timetable entry added successfully!";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
}

// Handle GET request to fetch and display timetable
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $result = $conn->query("SELECT * FROM timetables");

    echo "<tr><th>Day</th><th>Time Slot</th><th>Subject</th><th>Duration</th></tr>";

    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['day']}</td><td>{$row['time_slot']}</td><td>{$row['subject']}</td><td>{$row['duration']} mins</td></tr>";
    }
}

$conn->close();
?>
