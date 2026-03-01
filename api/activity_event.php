<?php
// Logs a system activity event into activity_log
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

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

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$input = is_array($input) ? $input : [];

// Fallback for non-JSON POST payloads
if (!$input && !empty($_POST)) {
    $input = $_POST;
}

$action = isset($input['action']) ? trim($input['action']) : '';
$entity_type = isset($input['entity_type']) ? trim($input['entity_type']) : 'system';
$entity_id = isset($input['entity_id']) ? (int)$input['entity_id'] : null;
$details = isset($input['details']) ? trim($input['details']) : '';
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : null;

if (($user_id === null || $user_id <= 0) && isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if ($user_id !== null && $user_id <= 0) {
    $user_id = null;
}

if ($action === '' && $entity_type === 'agency_chat') {
    $action = 'chat';
}

if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing action']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $entity_type, $entity_id, $details]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $msg = (string)$e->getMessage();
    $isDuplicateZeroPrimary = (
        strpos($msg, "Duplicate entry '0' for key 'PRIMARY'") !== false ||
        strpos($msg, "Duplicate entry '0' for key 'PRIMARY'") !== false
    );

    if ($isDuplicateZeroPrimary) {
        try {
            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nextId, $user_id, $action, $entity_type, $entity_id, $details]);
            echo json_encode(['ok' => true, 'fallback' => 'manual_id']);
            exit;
        } catch (Throwable $fallbackError) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'Log failed',
                'detail' => $fallbackError->getMessage()
            ]);
            exit;
        }
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Log failed',
        'detail' => $msg
    ]);
}
