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

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($groupId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid group']);
    exit;
}

try {
    ensure_interagency_group_member_requests_table($pdo);
    $groupStmt = $pdo->prepare("SELECT id, name FROM interagency_group_threads WHERE id = ? AND is_active = 1 LIMIT 1");
    $groupStmt->execute([$groupId]);
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Group not found']);
        exit;
    }

    $requestStmt = $pdo->prepare(
        "SELECT r.id, r.created_at,
                target.id AS user_id, target.name AS user_name, target.email AS user_email, target.role AS user_role,
                requester.id AS requested_by_id, requester.name AS requested_by_name
         FROM interagency_group_member_requests r
         INNER JOIN users target ON target.id = r.requested_user_id
         INNER JOIN users requester ON requester.id = r.requested_by_user_id
         WHERE r.group_id = ? AND r.status = 'pending'
         ORDER BY r.created_at ASC, r.id ASC"
    );
    $requestStmt->execute([$groupId]);
    echo json_encode(['ok' => true, 'group' => $group, 'items' => $requestStmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load member requests']);
}
