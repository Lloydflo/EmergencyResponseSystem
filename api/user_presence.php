<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_presence.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$user = get_logged_in_user();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'touch')));

try {
    if ($action === 'offline' || $action === 'logout') {
        mark_user_offline($pdo, $userId);
        echo json_encode(['ok' => true, 'presence_status' => 'offline']);
        exit;
    }

    if ($action === 'online') {
        mark_user_online($pdo, $userId);
        echo json_encode(['ok' => true, 'presence_status' => 'online']);
        exit;
    }

    touch_user_presence($pdo, $userId);
    echo json_encode(['ok' => true, 'presence_status' => 'online']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update presence']);
}
