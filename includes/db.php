<?php
require_once __DIR__ . '/config.php';

function get_db_connection(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // Return null if database constants are not defined (allows pages to load without DB)
    if (!defined('DB_HOST') || !defined('DB_NAME')) {
        return null;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Don't die - return null instead so pages can still load
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}
