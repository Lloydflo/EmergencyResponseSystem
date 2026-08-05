<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_fcm.php';
require_once __DIR__ . '/../../includes/user_presence.php';

op_require_method('POST');
$groupId = op_post_int('group_id');
$senderId = op_post_int('sender_user_id');
$text = op_post_string('text', '', 10000);
op_require_positive($groupId, 'group_id');
op_require_positive($senderId, 'sender_user_id');
op_require_text($text, 'text');

try {
    $pdo = db();
    $sender = op_require_active_responder($pdo, $senderId);
    if (!op_active_group_exists($pdo, $groupId)) {
        op_error('Department channel was not found or is inactive.', 404);
    }
    if (!op_is_group_member($pdo, $groupId, $senderId)) {
        op_error('You do not have access to this department channel.', 403);
    }
    try {
        touch_user_presence($pdo, $senderId);
    } catch (Throwable $presenceError) {
        error_log('send-interagency-group-message presence update skipped: ' . $presenceError->getMessage());
    }

    $groupStatement = $pdo->prepare('SELECT name FROM interagency_group_threads WHERE id = ? LIMIT 1');
    $groupStatement->execute([$groupId]);
    $groupName = trim((string)$groupStatement->fetchColumn());
    $messageDetails = json_encode(
        ['text' => $text, 'attachments' => []],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    $pdo->beginTransaction();
    $log = $pdo->prepare(
        "INSERT INTO activity_log
         (user_id, action, entity_type, entity_id, details, created_at)
         VALUES (?, 'chat', 'agency_group_chat', ?, ?, NOW())"
    );
    $log->execute([$senderId, $groupId, $messageDetails]);
    $activityLogId = (int)$pdo->lastInsertId();

    $message = $pdo->prepare(
        'INSERT INTO interagency_groups_threads_read '
        . '(activity_log_id, group_id, sender_user_id, message_details, created_at) '
        . 'VALUES (?, ?, ?, ?, NOW())'
    );
    $message->execute([$activityLogId, $groupId, (string)$senderId, $messageDetails]);
    $messageId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $push = ['attempted' => 0, 'delivered' => 0, 'failed' => 0];
    try {
        $push = ers_fcm_send_to_group($pdo, $groupId, $senderId, [
            'type' => 'department_chat',
            'group_id' => $groupId,
            'group_name' => $groupName,
            'message_id' => $messageId,
            'sender_id' => $senderId,
            'sender_name' => (string)($sender['name'] ?? 'Responder'),
            'message_type' => 'text',
            'body' => ers_notification_preview($text, 240),
        ]);
    } catch (Throwable $pushError) {
        error_log('department text push skipped: ' . $pushError->getMessage());
    }

    op_success([
        'message' => 'Message sent.',
        'message_id' => $messageId,
        'created_at_ms' => (int)round(microtime(true) * 1000),
        'push' => [
            'attempted' => (int)$push['attempted'],
            'delivered' => (int)$push['delivered'],
            'failed' => (int)$push['failed'],
        ],
    ], 201);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('send-interagency-group-message: ' . $error->getMessage());
    op_error('Unable to send the department message.', 500);
}
