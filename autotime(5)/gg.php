<?php
// Database connection settings
$host = "localhost";
$user = "root";
$password = "";
$database = "autotime";

// Create connection to MySQL server
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch existing templates for dropdown
$result = $conn->query("SELECT * FROM templates");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable Template Preview</title>
    <style>
        /* Add your CSS styles here */
        .template-preview table {
            border-collapse: collapse;
            width: 100%;
        }
        .template-preview th, .template-preview td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .template-preview th {
            background-color: #f2f2f2;
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
    </style>
    <script>
        // Function to fetch and display template preview
        function fetchTemplatePreview(templateId) {
            if (!templateId) {
                document.getElementById("template-preview-container").innerHTML = "<p>Please select a template to preview.</p>";
                return;
            }

            // Send AJAX request to fetch template data
            const xhr = new XMLHttpRequest();
            xhr.open("GET", `fetch_template.php?preview_id=${templateId}`, true);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    const templateData = JSON.parse(xhr.responseText);
                    displayTemplatePreview(templateData, "template-preview-container");
                } else {
                    document.getElementById("template-preview-container").innerHTML = "<p>Error fetching template data.</p>";
                }
            };
            xhr.send();
        }

        // Function to display template preview
        function displayTemplatePreview(templateData, containerId) {
            let container = document.getElementById(containerId);
            if (!container) return;
            
            let template = null;
            try {
                template = templateData;
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
    </script>
</head>
<body>
    <h1>Timetable Template Preview</h1>

    <!-- Template Selection Dropdown -->
    <label for="template-select">Select Template:</label>
    <select id="template-select" onchange="fetchTemplatePreview(this.value)">
        <option value="">-- Select a Template --</option>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <option value="<?php echo $row['id']; ?>"><?php echo $row['name']; ?></option>
            <?php endwhile; ?>
        <?php endif; ?>
    </select>

    <!-- Template Preview Section -->
    <div id="template-preview-container">
        <p>Please select a template to preview.</p>
    </div>
</body>
</html>