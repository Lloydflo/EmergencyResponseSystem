<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

$response = [
    'db' => [
        'host' => defined('DB_HOST') ? DB_HOST : null,
        'port' => defined('DB_PORT') ? DB_PORT : null,
        'name' => defined('DB_NAME') ? DB_NAME : null,
        'charset' => defined('DB_CHARSET') ? DB_CHARSET : null,
        // Do not expose username or password in health output
    ],
    'status' => 'unknown',
    'error' => null,
];

try {
    $pdo = get_db_connection();
    if ($pdo instanceof PDO) {
        // Basic query to confirm connectivity
        $pdo->query('SELECT 1');
        $response['status'] = 'ok';
    } else {
        $response['status'] = 'failed';
        $response['error'] = isset($GLOBALS['DB_LAST_ERROR']) ? $GLOBALS['DB_LAST_ERROR'] : 'Connection object is null. Check credentials and server reachability.';
        // Offer hints based on common causes
        if (!extension_loaded('pdo_mysql')) {
            $response['hint'] = 'Enable pdo_mysql in PHP configuration (php.ini).';
        }
    }
} catch (Throwable $e) {
    $response['status'] = 'failed';
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
