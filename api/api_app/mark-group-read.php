<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/../../includes/user_presence.php';

op_require_method('POST');
$groupId = op_post_int('group_id');
$userId = op_post_int('user_id');
$requestedReadId = op_post_int('last_read_id');
op_require_positive($groupId, 'group_id');
op_require_positive($userId, 'user_id');
op_require_positive($requestedReadId, 'last_read_id');

try {
    $pdo = db();
    op_require_active_responder($pdo, $userId);
    if (!op_active_group_exists($pdo, $groupId)) {
        op_error('Department channel was not found or is inactive.', 404);
    }
    if (!op_is_group_member($pdo, $groupId, $userId)) {
        op_error('You do not have access to this department channel.', 403);
    }
    try {
        touch_user_presence($pdo, $userId);
    } catch (Throwable $presenceError) {
        error_log('mark-group-read presence update skipped: ' . $presenceError->getMessage());
    }

    // Never allow a message ID from another channel to advance this channel's
    // cursor. A value beyond the latest message is safely clamped to the latest
    // valid row in the requested group.
    $validStatement = $pdo->prepare(
        'SELECT COALESCE(MAX(id), 0) FROM interagency_groups_threads_read '
        . 'WHERE group_id = ? AND id <= ?'
    );
    $validStatement->execute([$groupId, $requestedReadId]);
    $validReadId = (int)$validStatement->fetchColumn();
    if ($validReadId <= 0) {
        op_error('No readable message was found in this department channel.', 404);
    }

    $statement = $pdo->prepare(
        'INSERT INTO interagency_group_thread_reads '
        . '(user_id, group_id, last_read_id, updated_at) VALUES (?, ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE '
        . 'last_read_id = GREATEST(last_read_id, VALUES(last_read_id)), updated_at = NOW()'
    );
    $statement->execute([$userId, $groupId, $validReadId]);

    op_success([
        'message' => 'Department messages marked read.',
        'last_read_id' => $validReadId,
    ]);
} catch (Throwable $error) {
    error_log('mark-group-read: ' . $error->getMessage());
    op_error('Unable to update the department read position.', 500);
}
