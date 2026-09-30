<?php
declare(strict_types=1);

/**
 * Shared database connection for responder APIs.
 */

// 1. Dynamic path checking para mahanap nang maayos ang includes/db.php
$dbPath = __DIR__ . '/../../includes/db.php';
if (!file_exists($dbPath)) {
    $dbPath = __DIR__ . '/../includes/db.php';
}
if (!file_exists($dbPath)) {
    $dbPath = $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';
}

if (file_exists($dbPath)) {
    require_once $dbPath;
} else {
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Database configuration file (includes/db.php) not found."
    ]);
    exit;
}

date_default_timezone_set('Asia/Manila');

function db(): PDO
{
    try {
        if (!function_exists('get_db_connection')) {
            throw new RuntimeException('Function get_db_connection() is not defined in includes/db.php.');
        }

        $pdo = get_db_connection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection unavailable.');
        }

        // Pinipigilan nito ang 504 Gateway Timeout sa pamamagitan ng pag-limit sa connection wait time
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 3);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        static $timezoneConfigured = false;
        if (!$timezoneConfigured) {
            try {
                $pdo->exec("SET time_zone = '+08:00'");
            } catch (Throwable $error) {
                error_log('[api_app] database time-zone setup skipped: ' . $error->getMessage());
            }
            $timezoneConfigured = true;
        }

        return $pdo;

    } catch (Throwable $e) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Database Error: " . $e->getMessage()
        ]);
        exit;
    }
}