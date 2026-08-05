<?php
declare(strict_types=1);

/**
 * Shared database connection for responder APIs.
 *
 * The parent application already owns environment loading and PDO creation.
 * Reusing it prevents the mobile API from accidentally connecting to a
 * different database when .env files are placed in different directories.
 */
require_once __DIR__ . '/../../includes/db.php';

date_default_timezone_set('Asia/Manila');

function db(): PDO
{
    $pdo = get_db_connection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }

    static $timezoneConfigured = false;
    if (!$timezoneConfigured) {
        try {
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (Throwable $error) {
            // Keep the endpoint usable even when the DB account cannot change
            // its session time zone. PHP still uses Asia/Manila.
            error_log('[api_app] database time-zone setup skipped: ' . $error->getMessage());
        }
        $timezoneConfigured = true;
    }

    return $pdo;
}
