<?php
/**
 * Database Setup Script for Emergency Response System
 * Run this script once to initialize the database
 */

require_once 'includes/db.php';

// Read the schema file
$schemaFile = 'database_schema.sql';
$sampleDataFile = 'sample_data.sql';

echo "<h1>ERS Database Setup</h1>";

// Function to execute SQL file
function executeSqlFile($filePath, $conn) {
    if (!file_exists($filePath)) {
        return "Error: File $filePath not found";
    }

    $sql = file_get_contents($filePath);

    // Split into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    $errors = [];
    $successCount = 0;

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            if (mysqli_query($conn, $statement)) {
                $successCount++;
            } else {
                $errors[] = "Error executing: " . mysqli_error($conn);
            }
        }
    }

    return [
        'success' => $successCount,
        'errors' => $errors
    ];
}

// Execute schema
echo "<h2>Creating Database Schema...</h2>";
$schemaResult = executeSqlFile($schemaFile, $conn);

if (!empty($schemaResult['errors'])) {
    echo "<div style='color: red;'><strong>Schema Errors:</strong><br>";
    foreach ($schemaResult['errors'] as $error) {
        echo htmlspecialchars($error) . "<br>";
    }
    echo "</div>";
} else {
    echo "<div style='color: green;'>✓ Schema created successfully (" . $schemaResult['success'] . " statements executed)</div>";
}

// Execute sample data
echo "<h2>Loading Sample Data...</h2>";
$sampleResult = executeSqlFile($sampleDataFile, $conn);

if (!empty($sampleResult['errors'])) {
    echo "<div style='color: red;'><strong>Sample Data Errors:</strong><br>";
    foreach ($sampleResult['errors'] as $error) {
        echo htmlspecialchars($error) . "<br>";
    }
    echo "</div>";
} else {
    echo "<div style='color: green;'>✓ Sample data loaded successfully (" . $sampleResult['success'] . " statements executed)</div>";
}

echo "<h2>Setup Complete!</h2>";
echo "<p>The Emergency Response System database has been initialized.</p>";
echo "<p><strong>Default login credentials:</strong></p>";
echo "<ul>";
echo "<li>Username: admin | Password: admin123 | Role: Administrator</li>";
echo "<li>Username: dispatcher1 | Password: admin123 | Role: Dispatcher</li>";
echo "</ul>";
echo "<p><em>Important: Change default passwords in production!</em></p>";
echo "<p><a href='index.php'>Go to Dashboard</a></p>";

mysqli_close($conn);
?>