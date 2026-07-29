<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/../../includes/user_presence.php';

op_require_method('GET');
$groupId = op_query_int('group_id');
$userId = op_query_int('user_id');
op_require_positive($groupId, 'group_id');
op_require_positive($userId, 'user_id');

try {
    $pdo = db();
    op_require_active_responder($pdo, $userId);
    if (!op_active_group_exists($pdo, $groupId)) {
        op_error('Department channel was not found or is inactive.', 404);
    }
    if (!op_is_group_member($pdo, $groupId, $userId)) {
        op_error('You do not have access to this department channel.', 403);
    }
    touch_user_presence($pdo, $userId);

    // For the sender's own bubbles, expose whether at least one other approved
    // member has read through a given message. Incoming messages remain
    // delivered until this client explicitly advances its own read cursor.
    $readStatement = $pdo->prepare(
        'SELECT COALESCE(MAX(r.last_read_id), 0) '
        . 'FROM interagency_group_thread_reads r '
        . 'INNER JOIN interagency_group_members gm '
        . 'ON gm.group_id = r.group_id AND gm.user_id = r.user_id AND gm.is_active = 1 '
        . 'WHERE r.group_id = ? AND r.user_id <> ?'
    );
    $readStatement->execute([$groupId, $userId]);
    $othersReadUpTo = (int)$readStatement->fetchColumn();

    $statement = $pdo->prepare(
        'SELECT m.id, m.group_id, m.sender_user_id, m.message_details, m.created_at, u.id AS resolved_sender_id, '
        . 'COALESCE(u.name, m.sender_user_id) AS sender_name, '
        . 'COALESCE(u.department, \'\') AS department, '
        . 'a.file_name, a.file_url, a.mime_type, a.is_image '
        . 'FROM interagency_groups_threads_read m '
        . 'LEFT JOIN users u ON u.id = ('
        . 'SELECT MAX(u2.id) FROM users u2 WHERE '
        . 'CAST(u2.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci '
        . '= m.sender_user_id COLLATE utf8mb4_unicode_ci '
        . 'OR u2.name COLLATE utf8mb4_unicode_ci '
        . '= m.sender_user_id COLLATE utf8mb4_unicode_ci'
        . ') '
        . 'LEFT JOIN interagency_message_attachments a ON a.id = ('
        . 'SELECT MIN(a2.id) FROM interagency_message_attachments a2 WHERE a2.message_id = m.id'
        . ') '
        . 'WHERE m.group_id = ? '
        . 'ORDER BY m.created_at ASC, m.id ASC'
    );
    $statement->execute([$groupId]);

    $messages = [];
    foreach (op_fetch_all($statement) as $row) {
        $details = op_decode_object(isset($row['message_details']) ? (string)$row['message_details'] : null);
        $text = trim((string)($details['text'] ?? ''));
        $text = (string)preg_replace('/^\[ROUTINE\]\s*/u', '', $text);

        $fileUrl = trim((string)($row['file_url'] ?? ''));
        $fileName = trim((string)($row['file_name'] ?? ''));
        $mimeType = trim((string)($row['mime_type'] ?? ''));
        $isImage = (int)($row['is_image'] ?? 0) === 1;

        // Older rows stored the attachment only inside message_details. Keep
        // them readable while newer writes also use the normalized table.
        $attachments = $details['attachments'] ?? [];
        $firstAttachment = is_array($attachments) && isset($attachments[0]) && is_array($attachments[0])
            ? $attachments[0]
            : [];
        if ($fileUrl === '' && $firstAttachment !== []) {
            $fileUrl = trim((string)(
                $firstAttachment['file_url']
                ?? $firstAttachment['fileUrl']
                ?? $firstAttachment['url']
                ?? ''
            ));
            $fileName = trim((string)(
                $firstAttachment['file_name']
                ?? $firstAttachment['fileName']
                ?? $firstAttachment['name']
                ?? $fileName
            ));
            $mimeType = trim((string)(
                $firstAttachment['mime_type']
                ?? $firstAttachment['mimeType']
                ?? $mimeType
            ));
            $isImage = (int)($firstAttachment['is_image'] ?? 0) === 1
                || str_starts_with(strtolower($mimeType), 'image/');
        }

        if ($fileUrl !== '' && !$isImage && $fileName !== '') {
            $text = $fileName;
        }

        $messageId = (int)($row['id'] ?? 0);
        $rawSenderId = trim((string)($row['sender_user_id'] ?? ''));
        $resolvedSenderId = (int)($row['resolved_sender_id'] ?? 0);
        $senderId = $resolvedSenderId > 0 ? (string)$resolvedSenderId : $rawSenderId;
        $isOwn = $senderId === (string)$userId;
        $status = $isOwn && $othersReadUpTo >= $messageId ? 'read' : 'delivered';
        $timestamp = strtotime((string)($row['created_at'] ?? ''));

        $messages[] = [
            'id' => (string)$messageId,
            'groupId' => (int)($row['group_id'] ?? $groupId),
            'senderId' => $senderId,
            'senderName' => trim((string)($row['sender_name'] ?? '')) ?: 'Unknown',
            'role' => trim((string)($row['department'] ?? '')),
            'text' => $text,
            'type' => $fileUrl !== '' ? ($isImage ? 'IMAGE' : 'FILE') : 'TEXT',
            // Keep the stored relative/absolute URL. Android resolves relative
            // values with BuildConfig.BASE_URL instead of a hardcoded domain.
            'attachmentUri' => $fileUrl !== '' ? $fileUrl : null,
            'attachmentName' => $fileName !== '' ? $fileName : null,
            'createdAt' => $timestamp !== false ? $timestamp * 1000 : 0,
            'status' => $status,
        ];
    }

    op_success(['messages' => $messages]);
} catch (Throwable $error) {
    error_log('get-interagency-group-messages: ' . $error->getMessage());
    op_error('Unable to load department messages.', 500);
}
