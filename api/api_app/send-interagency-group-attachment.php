<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_fcm.php';
require_once __DIR__ . '/../../includes/user_presence.php';

op_require_method('POST');
$groupId = op_post_int('group_id');
$senderId = op_post_int('sender_user_id');
$fileUrl = op_post_string('file_url', '', 500);
$fileName = op_post_string('file_name', '', 255);
$mimeType = op_post_string('mime_type', 'application/octet-stream', 150);
$fileSize = max(0, op_post_int('file_size'));
$isImage = op_post_bool('is_image', false);

op_require_positive($groupId, 'group_id');
op_require_positive($senderId, 'sender_user_id');
op_require_text($fileUrl, 'file_url');
op_require_text($fileName, 'file_name');

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
        error_log('send-interagency-group-attachment presence update skipped: ' . $presenceError->getMessage());
    }

    $groupStatement = $pdo->prepare('SELECT name FROM interagency_group_threads WHERE id = ? LIMIT 1');
    $groupStatement->execute([$groupId]);
    $groupName = trim((string)$groupStatement->fetchColumn());
    $messageText = $isImage ? 'Image' : $fileName;
    $messageDetails = json_encode(
        [
            'text' => $messageText,
            'attachments' => [[
                'name' => $fileName,
                'url' => $fileUrl,
                'mime_type' => $mimeType,
                'size' => $fileSize,
                'is_image' => $isImage ? 1 : 0,
            ]],
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    $pdo->beginTransaction();
    $log = $pdo->prepare(
        "INSERT INTO activity_log
         (user_id, action, entity_type, entity_id, details, created_at)
         VALUES (?, 'chat_attachment', 'agency_group_chat', ?, ?, NOW())"
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

    $attachment = $pdo->prepare(
        'INSERT INTO interagency_message_attachments '
        . '(message_id, file_name, file_url, file_path, mime_type, file_size, is_image, created_at) '
        . 'VALUES (?, ?, ?, NULL, ?, ?, ?, NOW())'
    );
    $attachment->execute([
        $messageId,
        $fileName,
        $fileUrl,
        $mimeType,
        $fileSize,
        $isImage ? 1 : 0,
    ]);
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
            'message_type' => $isImage ? 'image' : 'file',
            'body' => $isImage ? 'Sent an image' : 'Sent a file: ' . ers_notification_preview($fileName, 160),
        ]);
    } catch (Throwable $pushError) {
        error_log('department attachment push skipped: ' . $pushError->getMessage());
    }

    op_success([
        'message' => 'Attachment sent.',
        'message_id' => $messageId,
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
    error_log('send-interagency-group-attachment: ' . $error->getMessage());
    op_error('Unable to send the department attachment.', 500);
}
