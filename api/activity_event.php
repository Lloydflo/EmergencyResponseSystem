<?php
// Logs a system activity event into activity_log
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = isset($input['action']) ? trim($input['action']) : '';
$entity_type = isset($input['entity_type']) ? trim($input['entity_type']) : 'system';
$entity_id = isset($input['entity_id']) ? (int)$input['entity_id'] : null;
$details = isset($input['details']) ? trim($input['details']) : '';
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : null;

if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing action']);
    exit;
}

try {
    log_activity($pdo, $user_id, $action, $entity_type, $entity_id, $details);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Log failed']);
}
