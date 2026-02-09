<?php
require_once __DIR__ . '/config.php';

// Store last DB connection error for diagnostics (read-only)
if (!isset($GLOBALS['DB_LAST_ERROR'])) {
    $GLOBALS['DB_LAST_ERROR'] = null;
}

function get_db_connection(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // Return null if database constants are not defined (allows pages to load without DB)
    if (!defined('DB_HOST') || !defined('DB_NAME')) {
        $GLOBALS['DB_LAST_ERROR'] = 'DB constants missing (DB_HOST/DB_NAME)';
        return null;
    }
    $host = DB_HOST;
    $portFromHost = null;
    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host, 2);
        $host = $parts[0];
        if (isset($parts[1]) && ctype_digit($parts[1])) {
            $portFromHost = $parts[1];
        }
    }

    $dsn = 'mysql:host=' . $host;
    $port = (defined('DB_PORT') && DB_PORT) ? DB_PORT : $portFromHost;
    if ($port) {
        $dsn .= ';port=' . $port;
    }
    $dsn .= ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        if (!extension_loaded('pdo_mysql')) {
            $GLOBALS['DB_LAST_ERROR'] = 'pdo_mysql extension not loaded';
            return null;
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $GLOBALS['DB_LAST_ERROR'] = null;
        return $pdo;
    } catch (PDOException $e) {
        // Don't die - return null instead so pages can still load
        $GLOBALS['DB_LAST_ERROR'] = $e->getMessage();
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}
