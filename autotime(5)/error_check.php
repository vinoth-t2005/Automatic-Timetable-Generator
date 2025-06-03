<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Error Log Check</h1>";

$errorLog = "error.log";
if (file_exists($errorLog)) {
    echo "<h2>Error log file exists</h2>";
    $logSize = filesize($errorLog);
    echo "<p>Log size: " . $logSize . " bytes</p>";
    
    if ($logSize > 0) {
        $content = file_get_contents($errorLog);
        $lines = explode("\n", $content);
        
        // Get the last 100 lines
        $numLines = count($lines);
        $displayLines = array_slice($lines, max(0, $numLines - 100));
        
        echo "<h3>Last " . count($displayLines) . " lines:</h3>";
        echo "<pre style='max-height: 500px; overflow-y: scroll; background-color: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
        foreach ($displayLines as $line) {
            // Highlight important error patterns
            $line = preg_replace('/error/i', '<span style="color: red; font-weight: bold;">$0</span>', htmlspecialchars($line));
            $line = preg_replace('/warning/i', '<span style="color: orange; font-weight: bold;">$0</span>', $line);
            $line = preg_replace('/exception/i', '<span style="color: red; font-weight: bold;">$0</span>', $line);
            echo $line . "\n";
        }
        echo "</pre>";
        
        // Search for specific errors
        $searchTerms = [
            'timetable_slots' => 'Database table issues',
            'template_id' => 'Template ID problems',
            'timetable_id' => 'Timetable ID problems',
            'constraint' => 'Database constraint issues',
            'transaction' => 'Transaction problems',
            'insert' => 'Insert operation issues',
            'delete' => 'Delete operation issues',
            'query' => 'General query problems'
        ];
        
        echo "<h3>Error Patterns Found:</h3>";
        echo "<ul>";
        foreach ($searchTerms as $term => $description) {
            $count = substr_count(strtolower($content), strtolower($term));
            echo "<li>" . htmlspecialchars($description) . ": <strong>" . $count . "</strong> occurrences</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>Error log file is empty</p>";
    }
} else {
    echo "<p>Error log file does not exist</p>";
    
    // Create a test error to see if logging works
    error_log("Test error message");
    
    echo "<p>Created test error log entry. Refresh this page to see if the file appears.</p>";
}
?>

<h2>PHP Environment</h2>
<pre>
PHP Version: <?php echo phpversion(); ?>

Loaded Extensions:
<?php
$extensions = get_loaded_extensions();
sort($extensions);
echo implode(", ", $extensions);
?>

Include Path: <?php echo get_include_path(); ?>

Error Reporting: <?php echo error_reporting(); ?>

Display Errors: <?php echo ini_get('display_errors'); ?>

Log Errors: <?php echo ini_get('log_errors'); ?>

Error Log: <?php echo ini_get('error_log'); ?>
</pre>

<p><a href="next_44.php">Return to Timetable Generator</a></p>