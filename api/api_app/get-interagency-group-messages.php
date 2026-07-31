<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');
$groupId = op_query_int('group_id');
$userId = op_query_int('user_id');
$afterId = max(0, op_query_int('after_id', 0));
$limit = max(1, min(200, op_query_int('limit', 100)));
op_require_positive($groupId, 'group_id');
op_require_positive($userId, 'user_id');

/** @param list<string> $tables @return array<string,array<string,true>> */
function ia_messages_schema(PDO $pdo, array $tables): array
{
    $in = implode(',', array_fill(0, count($tables), '?'));
    $statement = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})"
    );
    $statement->execute($tables);
    $schema = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $schema[$table][$column] = true;
        }
    }
    return $schema;
}

/** @param array<string,array<string,true>> $schema @param list<string> $columns */
function ia_messages_has(array $schema, string $table, array $columns): bool
{
    if (!isset($schema[$table])) {
        return false;
    }
    foreach ($columns as $column) {
        if (!isset($schema[$table][$column])) {
            return false;
        }
    }
    return true;
}

try {
    $pdo = db();
    $schema = ia_messages_schema($pdo, [
        'users', 'interagency_group_threads', 'interagency_group_members',
        'interagency_groups_threads_read', 'interagency_group_thread_reads',
        'interagency_message_attachments',
    ]);

    foreach ([
        ['users', ['id', 'name']],
        ['interagency_group_threads', ['id', 'is_active']],
        ['interagency_group_members', ['group_id', 'user_id', 'is_active']],
        ['interagency_groups_threads_read', ['id', 'group_id', 'sender_user_id', 'message_details', 'created_at']],
    ] as [$table, $columns]) {
        if (!ia_messages_has($schema, $table, $columns)) {
            op_error('Department messaging is not initialized on the server.', 503, [
                'error_code' => 'INTERAGENCY_GROUP_SCHEMA_MISSING',
            ]);
        }
    }

    $responderWhere = ['id = ?'];
    if (isset($schema['users']['role'])) {
        $responderWhere[] = "LOWER(COALESCE(role, '')) = 'responder'";
    }
    if (isset($schema['users']['status'])) {
        $responderWhere[] = "LOWER(COALESCE(status, '')) = 'active'";
    }
    if (isset($schema['users']['is_active'])) {
        $responderWhere[] = 'COALESCE(is_active, 1) = 1';
    }
    $responderStatement = $pdo->prepare(
        'SELECT id FROM users WHERE ' . implode(' AND ', $responderWhere) . ' LIMIT 1'
    );
    $responderStatement->execute([$userId]);
    if (!(bool)$responderStatement->fetchColumn()) {
        op_error('Responder account was not found or is inactive.', 403);
    }

    $groupStatement = $pdo->prepare(
        'SELECT 1 FROM interagency_group_threads WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $groupStatement->execute([$groupId]);
    if (!(bool)$groupStatement->fetchColumn()) {
        op_error('Department channel was not found or is inactive.', 404);
    }
    $memberStatement = $pdo->prepare(
        'SELECT 1 FROM interagency_group_members WHERE group_id = ? AND user_id = ? AND is_active = 1 LIMIT 1'
    );
    $memberStatement->execute([$groupId, $userId]);
    if (!(bool)$memberStatement->fetchColumn()) {
        op_error('You do not have access to this department channel.', 403);
    }

    $hasReads = ia_messages_has($schema, 'interagency_group_thread_reads', ['group_id', 'user_id', 'last_read_id']);
    $othersReadUpTo = 0;
    if ($hasReads) {
        $readStatement = $pdo->prepare(
            'SELECT COALESCE(MAX(r.last_read_id), 0)
             FROM interagency_group_thread_reads r
             INNER JOIN interagency_group_members gm
               ON gm.group_id = r.group_id AND gm.user_id = r.user_id AND gm.is_active = 1
             WHERE r.group_id = ? AND r.user_id <> ?'
        );
        $readStatement->execute([$groupId, $userId]);
        $othersReadUpTo = (int)$readStatement->fetchColumn();
    }

    $hasAttachments = ia_messages_has($schema, 'interagency_message_attachments', [
        'id', 'message_id', 'file_name', 'file_url', 'mime_type', 'is_image',
    ]);
    $attachmentSelect = $hasAttachments
        ? 'att.file_name, att.file_url, att.mime_type, att.is_image'
        : 'NULL AS file_name, NULL AS file_url, NULL AS mime_type, 0 AS is_image';
    $attachmentJoin = $hasAttachments
        ? 'LEFT JOIN interagency_message_attachments att
             ON att.id = (SELECT MIN(a2.id) FROM interagency_message_attachments a2 WHERE a2.message_id = m.id)'
        : '';
    $departmentSelect = isset($schema['users']['department']) ? "COALESCE(u.department, '')" : "''";

    $where = 'm.group_id = ?';
    $params = [$groupId];
    if ($afterId > 0) {
        $where .= ' AND m.id > ?';
        $params[] = $afterId;
    }

    $sql = "SELECT m.id, m.group_id, m.sender_user_id, m.message_details, m.created_at,
            u.id AS resolved_sender_id,
            COALESCE(NULLIF(u.name, ''), m.sender_user_id) AS sender_name,
            {$departmentSelect} AS department,
            {$attachmentSelect}
        FROM interagency_groups_threads_read m
        LEFT JOIN users u ON u.id = (
            SELECT MAX(u2.id) FROM users u2
             WHERE CAST(u2.id AS CHAR) = m.sender_user_id OR u2.name = m.sender_user_id
        )
        {$attachmentJoin}
        WHERE {$where}
        ORDER BY m.id DESC
        LIMIT {$limit}";
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = array_reverse($statement->fetchAll(PDO::FETCH_ASSOC));

    $messages = [];
    foreach ($rows as $row) {
        $details = json_decode((string)($row['message_details'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        $text = trim((string)($details['text'] ?? ''));
        $text = (string)(preg_replace('/^\[ROUTINE\]\s*/u', '', $text) ?? $text);

        $fileUrl = trim((string)($row['file_url'] ?? ''));
        $fileName = trim((string)($row['file_name'] ?? ''));
        $mimeType = trim((string)($row['mime_type'] ?? ''));
        $isImage = (int)($row['is_image'] ?? 0) === 1;
        $first = isset($details['attachments'][0]) && is_array($details['attachments'][0])
            ? $details['attachments'][0]
            : [];
        if ($fileUrl === '' && $first !== []) {
            $fileUrl = trim((string)($first['file_url'] ?? $first['fileUrl'] ?? $first['url'] ?? ''));
            $fileName = trim((string)($first['file_name'] ?? $first['fileName'] ?? $first['name'] ?? $fileName));
            $mimeType = trim((string)($first['mime_type'] ?? $first['mimeType'] ?? $mimeType));
            $isImage = (int)($first['is_image'] ?? 0) === 1 || str_starts_with(strtolower($mimeType), 'image/');
        }
        if ($fileUrl !== '' && !$isImage && $fileName !== '') {
            $text = $fileName;
        }

        $messageId = (int)($row['id'] ?? 0);
        $rawSenderId = trim((string)($row['sender_user_id'] ?? ''));
        $resolvedSenderId = (int)($row['resolved_sender_id'] ?? 0);
        $senderId = $resolvedSenderId > 0 ? (string)$resolvedSenderId : $rawSenderId;
        $timestamp = strtotime((string)($row['created_at'] ?? ''));
        $isOwn = $senderId === (string)$userId;

        $messages[] = [
            'id' => (string)$messageId,
            'groupId' => (int)($row['group_id'] ?? $groupId),
            'senderId' => $senderId,
            'senderName' => trim((string)($row['sender_name'] ?? '')) ?: 'Unknown',
            'role' => trim((string)($row['department'] ?? '')),
            'text' => $text,
            'type' => $fileUrl !== '' ? ($isImage ? 'IMAGE' : 'FILE') : 'TEXT',
            'attachmentUri' => $fileUrl !== '' ? $fileUrl : null,
            'attachmentName' => $fileName !== '' ? $fileName : null,
            'createdAt' => $timestamp !== false ? $timestamp * 1000 : 0,
            'status' => $isOwn && $othersReadUpTo >= $messageId ? 'read' : 'delivered',
        ];
    }

    op_success([
        'messages' => $messages,
        'has_more' => count($messages) >= $limit,
        'last_message_id' => $messages !== []
            ? (int)$messages[array_key_last($messages)]['id']
            : $afterId,
    ]);
} catch (Throwable $error) {
    $errorId = substr(hash('sha256', $error->getMessage() . '|' . microtime(true)), 0, 12);
    error_log('get-interagency-group-messages [' . $errorId . ']: ' . $error->getMessage());
    op_error('Unable to load department messages.', 500, [
        'error_code' => 'INTERAGENCY_GROUP_MESSAGES_FAILED',
        'error_id' => $errorId,
    ]);
}