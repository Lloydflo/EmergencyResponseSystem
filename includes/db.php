<?php
require_once __DIR__ . '/config.php';

function get_db_connection(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    if (empty($config['DB_HOST']) || empty($config['DB_NAME']) || empty($config['DB_USER'])) {
        error_log('Database connection failed: missing DB_HOST, DB_NAME, or DB_USER in live configuration.');
        return null;
    }

    $port = isset($config['DB_PORT']) && $config['DB_PORT'] !== '' ? (string)$config['DB_PORT'] : '3306';
    $dsn = 'mysql:host=' . $config['DB_HOST'] . ';port=' . $port . ';dbname=' . $config['DB_NAME'] . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'] ?? '', $options);
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
?>