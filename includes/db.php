<?php
require_once __DIR__ . '/config.php';

function get_db_connection(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // Get DB config array from config.php
    $config = require __DIR__ . '/config.php';
    if (!isset($config['DB_HOST'], $config['DB_NAME'], $config['DB_USER'], $config['DB_PASS'])) {
        return null;
    }
    $dsn = 'mysql:host=' . $config['DB_HOST'] . ';dbname=' . $config['DB_NAME'] . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

function fetch_all_from_table($table) {
    $pdo = get_db_connection();
    if (!$pdo) return [];
    $stmt = $pdo->prepare("SELECT * FROM `" . $table . "`");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Usage example (for testing):
// $rows = fetch_all_from_table('your_table_name');
// print_r($rows);
?>
