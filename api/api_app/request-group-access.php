<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');
$groupId = op_post_int('group_id');
$userId = op_post_int('user_id');
op_require_positive($groupId, 'group_id');
op_require_positive($userId, 'user_id');

try {
    $pdo = db();
    $responder = op_require_active_responder($pdo, $userId);
    if (!op_table_exists($pdo, 'interagency_group_threads')
        || !op_table_exists($pdo, 'interagency_group_members')
        || !op_table_exists($pdo, 'interagency_group_member_requests')) {
        op_error('Department access requests are not initialized on the server.', 503, [
            'error_code' => 'INTERAGENCY_GROUP_SCHEMA_MISSING',
        ]);
    }
    if (!op_active_group_exists($pdo, $groupId)) {
        op_error('Department channel was not found or is inactive.', 404);
    }
    if (op_is_group_member($pdo, $groupId, $userId)) {
        op_success(['message' => 'You already have access to this department channel.', 'already_member' => true]);
    }

    $pdo->beginTransaction();
    $select = $pdo->prepare(
        'SELECT id, status FROM interagency_group_member_requests '
        . 'WHERE group_id = ? AND requested_user_id = ? LIMIT 1 FOR UPDATE'
    );
    $select->execute([$groupId, $userId]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);

    if (is_array($existing) && (string)($existing['status'] ?? '') === 'pending') {
        $pdo->commit();
        op_success(['message' => 'Request already pending.', 'request_pending' => true]);
    }

    if (is_array($existing)) {
        $update = $pdo->prepare(
            "UPDATE interagency_group_member_requests
             SET requested_by_user_id = ?, status = 'pending',
                 reviewed_by_user_id = NULL, reviewed_at = NULL,
                 created_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        );
        $update->execute([$userId, (int)$existing['id']]);
        $requestId = (int)$existing['id'];
    } else {
        $insert = $pdo->prepare(
            "INSERT INTO interagency_group_member_requests
             (group_id, requested_user_id, requested_by_user_id, status, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', NOW(), NOW())"
        );
        $insert->execute([$groupId, $userId, $userId]);
        $requestId = (int)$pdo->lastInsertId();
    }
    $pdo->commit();

    op_success([
        'message' => 'Request submitted.',
        'request_id' => $requestId,
        'request_pending' => true,
        'requester_name' => (string)($responder['name'] ?? ''),
    ], 201);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('request-group-access: ' . $error->getMessage());
    op_error('Unable to submit the department access request.', 500);
}
