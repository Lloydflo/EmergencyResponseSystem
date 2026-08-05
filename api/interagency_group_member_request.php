<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/interagency_group_member_requests.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
if (current_session_role() !== 'dispatcher') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only dispatchers can submit member requests']);
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
$groupId = (int)($payload['group_id'] ?? 0);
$requestedUserId = (int)($payload['user_id'] ?? 0);
$actor = get_logged_in_user();
$actorId = (int)($actor['id'] ?? 0);

if ($groupId <= 0 || $requestedUserId <= 0 || $actorId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid group or member']);
    exit;
}

try {
    ensure_interagency_group_member_requests_table($pdo);

    $accessStmt = $pdo->prepare(
        "SELECT 1
         FROM interagency_group_threads g
         INNER JOIN interagency_group_members gm ON gm.group_id = g.id
         WHERE g.id = ? AND g.is_active = 1 AND gm.user_id = ? AND gm.is_active = 1
         LIMIT 1"
    );
    $accessStmt->execute([$groupId, $actorId]);
    if (!$accessStmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You are not an active member of this group']);
        exit;
    }

    $userStmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND LOWER(status) = 'active' LIMIT 1");
    $userStmt->execute([$requestedUserId]);
    $requestedUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$requestedUser) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Select an active user']);
        exit;
    }

    $memberStmt = $pdo->prepare(
        "SELECT 1 FROM interagency_group_members WHERE group_id = ? AND user_id = ? AND is_active = 1 LIMIT 1"
    );
    $memberStmt->execute([$groupId, $requestedUserId]);
    if ($memberStmt->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'This user is already a group member']);
        exit;
    }

    $upsert = $pdo->prepare(
        "INSERT INTO interagency_group_member_requests
            (group_id, requested_user_id, requested_by_user_id, status, reviewed_by_user_id, reviewed_at, created_at, updated_at)
         VALUES (?, ?, ?, 'pending', NULL, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            requested_by_user_id = VALUES(requested_by_user_id),
            status = 'pending',
            reviewed_by_user_id = NULL,
            reviewed_at = NULL,
            updated_at = NOW()"
    );
    $upsert->execute([$groupId, $requestedUserId, $actorId]);

    echo json_encode(['ok' => true, 'message' => 'Member request sent for admin approval']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to submit member request']);
}
