<?php
// test.php - Database connection test


$db_config = require_once 'db.config.php';
$conn = new mysqli(
    $db_config['DB_HOST'],
    $db_config['DB_USER'],
    $db_config['DB_PASS'],
    $db_config['DB_NAME']
);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// Success message

echo 'Database connection successful!';

$conn->close();
