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
    op_require_tables($pdo, [
        'interagency_group_threads',
        'interagency_group_members',
        'interagency_group_member_requests',
    ]);
    op_require_columns($pdo, 'interagency_group_member_requests', [
        'group_id', 'requested_user_id', 'requested_by_user_id', 'status',
        'reviewed_by_user_id', 'reviewed_at', 'created_at', 'updated_at',
    ]);
    op_require_active_responder($pdo, $userId);

    if (!op_active_group_exists($pdo, $groupId)) {
        op_error('Department channel was not found or is inactive.', 404);
    }
    if (op_is_group_member($pdo, $groupId, $userId)) {
        op_success([
            'message' => 'You are already a member of this department channel.',
            'already_member' => true,
            'request_pending' => false,
        ]);
    }

    $existing = $pdo->prepare(
        'SELECT status FROM interagency_group_member_requests '
        . 'WHERE group_id = ? AND requested_user_id = ? LIMIT 1'
    );
    $existing->execute([$groupId, $userId]);
    $existingStatus = strtolower(trim((string)$existing->fetchColumn()));
    if ($existingStatus === 'approved') {
        op_success([
            'message' => 'The access request was already approved.',
            'already_approved' => true,
            'request_pending' => false,
        ]);
    }

    $statement = $pdo->prepare(
        "INSERT INTO interagency_group_member_requests "
        . '(group_id, requested_user_id, requested_by_user_id, status, created_at, updated_at) '
        . "VALUES (?, ?, ?, 'pending', NOW(), NOW()) "
        . 'ON DUPLICATE KEY UPDATE '
        . 'requested_by_user_id = VALUES(requested_by_user_id), '
        . "status = 'pending', reviewed_by_user_id = NULL, reviewed_at = NULL, updated_at = NOW()"
    );
    $statement->execute([$groupId, $userId, $userId]);

    op_success([
        'message' => $existingStatus === 'pending'
            ? 'Request is already pending.'
            : 'Request submitted',
        'request_pending' => true,
        'idempotent' => $existingStatus === 'pending',
    ]);
} catch (Throwable $error) {
    error_log('[request-group-access] ' . $error->getMessage());
    op_error('Unable to submit the department access request.', 500);
}
