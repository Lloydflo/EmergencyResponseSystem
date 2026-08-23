<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_fcm.php';

op_require_method('POST');
$senderId = op_post_int('sender_id');
$recipientId = op_post_int('recipient_id');
$threadId = op_post_string('thread_id', '', 190);
$messageId = op_post_string('message_id', '', 190);
$senderName = op_post_string('sender_name', 'Responder', 150);
$messageType = strtolower(op_post_string('message_type', 'text', 20));
$preview = op_post_string('preview', 'New private message', 240);

op_require_positive($senderId, 'sender_id');
op_require_positive($recipientId, 'recipient_id');
op_require_text($threadId, 'thread_id');
op_require_text($messageId, 'message_id');
if ($senderId === $recipientId) {
    op_error('Sender and recipient must be different.', 422);
}
if (!preg_match('/^pm_[A-Za-z0-9_-]+$/', $threadId)) {
    op_error('Invalid private thread identifier.', 422);
}
$participantIds = [(string)$senderId, (string)$recipientId];
sort($participantIds, SORT_STRING);
$expectedThreadId = 'pm_' . $participantIds[0] . '_' . $participantIds[1];
if (!hash_equals($expectedThreadId, $threadId)) {
    op_error('Private thread does not match the sender and recipient.', 422);
}

try {
    $pdo = db();
    $sender = op_require_active_responder($pdo, $senderId);
    op_require_active_responder($pdo, $recipientId);

    $name = trim($senderName) !== '' ? $senderName : (string)($sender['name'] ?? 'Responder');
    $result = ers_fcm_send_to_user($pdo, $recipientId, [
        'type' => 'private_chat',
        'sender_id' => $senderId,
        'recipient_id' => $recipientId,
        'thread_id' => $threadId,
        'message_id' => $messageId,
        'sender_name' => $name,
        'message_type' => $messageType,
        'body' => $preview,
    ]);

    op_success([
        'message' => 'Private-message notification processed.',
        'attempted' => $result['attempted'],
        'delivered' => $result['delivered'],
        'failed' => $result['failed'],
    ]);
} catch (Throwable $error) {
    error_log('notify-private-message: ' . $error->getMessage());
    op_error('The message was sent, but its push notification could not be processed.', 502);
}
