<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/interagency_group_member_requests.php';

if (!is_logged_in() || current_session_role() !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : $_POST;
$requestId = (int)($payload['request_id'] ?? 0);
$action = strtolower(trim((string)($payload['action'] ?? '')));
$actor = get_logged_in_user();
$actorId = (int)($actor['id'] ?? 0);

if ($requestId <= 0 || $actorId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid member request action']);
    exit;
}

try {
    ensure_interagency_group_member_requests_table($pdo);
    $pdo->beginTransaction();

    $requestStmt = $pdo->prepare(
        "SELECT r.id, r.group_id, r.requested_user_id
         FROM interagency_group_member_requests r
         INNER JOIN interagency_group_threads g ON g.id = r.group_id AND g.is_active = 1
         WHERE r.id = ? AND r.status = 'pending'
         LIMIT 1 FOR UPDATE"
    );
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new RuntimeException('This member request is no longer pending');
    }

    if ($action === 'approve') {
        $userStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND LOWER(status) = 'active' LIMIT 1");
        $userStmt->execute([(int)$request['requested_user_id']]);
        if (!$userStmt->fetchColumn()) {
            throw new RuntimeException('Requested user is no longer active');
        }

        $memberStmt = $pdo->prepare(
            "INSERT INTO interagency_group_members (group_id, user_id, added_by, is_active, created_at, updated_at)
             VALUES (?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE added_by = VALUES(added_by), is_active = 1, updated_at = NOW()"
        );
        $memberStmt->execute([(int)$request['group_id'], (int)$request['requested_user_id'], $actorId]);
    }

    $updateStmt = $pdo->prepare(
        "UPDATE interagency_group_member_requests
         SET status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(), updated_at = NOW()
         WHERE id = ? AND status = 'pending'"
    );
    $updateStmt->execute([$action === 'approve' ? 'approved' : 'rejected', $actorId, $requestId]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'action' => $action, 'group_id' => (int)$request['group_id']]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage() ?: 'Unable to review member request']);
}
