<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = get_logged_in_user() ?? [];
$role = canonical_role((string)($user['role'] ?? ''));
if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/predictive_analytics_helper.php';

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('DB connection unavailable');
    }

    echo json_encode(
        [
            'ok' => true,
            'snapshot' => ers_predictive_build_snapshot($pdo),
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('predictive analytics API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load predictive analytics']);
}
