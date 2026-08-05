<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

header('X-ERS-Endpoint-Version: 2026-08-05-v5');
op_require_method('GET');

$groupId = op_query_int('group_id');
$userId = op_query_int('user_id');
$afterId = max(0, op_query_int('after_id', 0));
$limit = max(1, min(200, op_query_int('limit', 100)));

op_require_positive($groupId, 'group_id');
op_require_positive($userId, 'user_id');

/**
 * Loads the available columns for the tables needed by this endpoint.
 *
 * @param list<string> $tables
 * @return array<string,array<string,true>>
 */
function ers_group_messages_schema(PDO $pdo, array $tables): array
{
    if ($tables === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $statement = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ({$placeholders})"
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

/**
 * @param array<string,array<string,true>> $schema
 * @param list<string> $columns
 */
function ers_group_messages_has(
    array $schema,
    string $table,
    array $columns
): bool {
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

/** @param list<mixed> $values */
function ers_group_messages_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

function ers_group_messages_fold(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

$stage = 'bootstrap';

try {
    $stage = 'database_connection';
    $pdo = db();

    $stage = 'schema_check';
    $schema = ers_group_messages_schema($pdo, [
        'users',
        'interagency_group_threads',
        'interagency_group_members',
        'interagency_groups_threads_read',
        'interagency_group_thread_reads',
        'interagency_message_attachments',
    ]);

    $requiredSchema = [
        'users' => ['id', 'name'],
        'interagency_group_threads' => ['id', 'is_active'],
        'interagency_group_members' => ['group_id', 'user_id', 'is_active'],
        'interagency_groups_threads_read' => [
            'id',
            'group_id',
            'sender_user_id',
            'message_details',
            'created_at',
        ],
    ];

    $missing = [];
    foreach ($requiredSchema as $table => $columns) {
        foreach ($columns as $column) {
            if (!isset($schema[$table][$column])) {
                $missing[] = $table . '.' . $column;
            }
        }
    }

    if ($missing !== []) {
        error_log(
            'get-interagency-group-messages schema incomplete: '
            . implode(', ', $missing)
        );
        op_error(
            'Department messaging is not initialized on the server.',
            503,
            [
                'error_code' => 'INTERAGENCY_GROUP_SCHEMA_MISSING',
                'missing' => $missing,
            ]
        );
    }

    $stage = 'responder_check';
    $responderSelect = ['id', 'name'];
    if (isset($schema['users']['department'])) {
        $responderSelect[] = 'department';
    } else {
        $responderSelect[] = "'' AS department";
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
        'SELECT ' . implode(', ', $responderSelect)
        . ' FROM users WHERE ' . implode(' AND ', $responderWhere)
        . ' LIMIT 1'
    );
    $responderStatement->execute([$userId]);
    $responder = $responderStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($responder)) {
        op_error('Responder account was not found or is inactive.', 403);
    }

    $stage = 'group_check';
    $groupStatement = $pdo->prepare(
        'SELECT 1
         FROM interagency_group_threads
         WHERE id = ? AND is_active = 1
         LIMIT 1'
    );
    $groupStatement->execute([$groupId]);
    if (!(bool)$groupStatement->fetchColumn()) {
        op_error('Department channel was not found or is inactive.', 404);
    }

    $stage = 'membership_check';
    $memberStatement = $pdo->prepare(
        'SELECT 1
         FROM interagency_group_members
         WHERE group_id = ? AND user_id = ? AND is_active = 1
         LIMIT 1'
    );
    $memberStatement->execute([$groupId, $userId]);
    if (!(bool)$memberStatement->fetchColumn()) {
        op_error('You do not have access to this department channel.', 403);
    }

    $stage = 'read_state';
    $hasReads = ers_group_messages_has(
        $schema,
        'interagency_group_thread_reads',
        ['group_id', 'user_id', 'last_read_id']
    );

    $othersReadUpTo = 0;
    if ($hasReads) {
        $readStatement = $pdo->prepare(
            'SELECT COALESCE(MAX(r.last_read_id), 0)
             FROM interagency_group_thread_reads r
             INNER JOIN interagency_group_members gm
               ON gm.group_id = r.group_id
              AND gm.user_id = r.user_id
              AND gm.is_active = 1
             WHERE r.group_id = ?
               AND r.user_id <> ?'
        );
        $readStatement->execute([$groupId, $userId]);
        $othersReadUpTo = (int)$readStatement->fetchColumn();
    }

    $stage = 'message_query';
    $hasAttachments = ers_group_messages_has(
        $schema,
        'interagency_message_attachments',
        ['id', 'message_id', 'file_name', 'file_url', 'mime_type', 'is_image']
    );

    $attachmentSelect = $hasAttachments
        ? 'att.file_name, att.file_url, att.mime_type, att.is_image, '
            . (isset($schema['interagency_message_attachments']['file_size'])
                ? 'att.file_size'
                : '0 AS file_size')
        : 'NULL AS file_name, NULL AS file_url, NULL AS mime_type, '
            . '0 AS is_image, 0 AS file_size';
    $attachmentJoin = $hasAttachments
        ? 'LEFT JOIN interagency_message_attachments att
             ON att.id = (
                 SELECT MIN(a2.id)
                 FROM interagency_message_attachments a2
                 WHERE a2.message_id = m.id
             )'
        : '';

    $fetchLimit = $limit + 1;
    $parameters = [$groupId];
    $where = 'm.group_id = ?';
    $order = 'm.id DESC';

    if ($afterId > 0) {
        $where .= ' AND m.id > ?';
        $parameters[] = $afterId;
        $order = 'm.id ASC';
    }

    // The message query intentionally does not compare users.name with
    // sender_user_id. Older production schemas can use different collations,
    // and a column-to-column comparison can fail with an illegal collation mix.
    $messageSql = "SELECT
            m.id,
            m.group_id,
            m.sender_user_id,
            m.message_details,
            m.created_at,
            {$attachmentSelect}
        FROM interagency_groups_threads_read m
        {$attachmentJoin}
        WHERE {$where}
        ORDER BY {$order}
        LIMIT {$fetchLimit}";

    $messageStatement = $pdo->prepare($messageSql);
    $messageStatement->execute($parameters);
    $rows = $messageStatement->fetchAll(PDO::FETCH_ASSOC);
    $rows = is_array($rows) ? $rows : [];

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }
    if ($afterId === 0) {
        $rows = array_reverse($rows);
    }

    $stage = 'sender_resolution';
    $senderIds = [];
    $senderNames = [];
    foreach ($rows as $row) {
        $rawSender = trim((string)($row['sender_user_id'] ?? ''));
        if ($rawSender === '') {
            continue;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $rawSender) === 1) {
            $senderIds[(int)$rawSender] = (int)$rawSender;
        } else {
            $senderNames[$rawSender] = $rawSender;
        }
    }

    /** @var array<int,array<string,mixed>> $usersById */
    $usersById = [];
    /** @var array<string,array<string,mixed>> $usersByName */
    $usersByName = [];

    if ($senderIds !== [] || $senderNames !== []) {
        $userClauses = [];
        $userParameters = [];

        if ($senderIds !== []) {
            $ids = array_values($senderIds);
            $userClauses[] = 'id IN (' . ers_group_messages_placeholders($ids) . ')';
            array_push($userParameters, ...$ids);
        }
        if ($senderNames !== []) {
            $names = array_values($senderNames);
            // This compares one column with bound values, not two differently
            // collated columns, so it is safe across legacy schema collations.
            $userClauses[] = 'name IN (' . ers_group_messages_placeholders($names) . ')';
            array_push($userParameters, ...$names);
        }

        $departmentSelect = isset($schema['users']['department'])
            ? "COALESCE(department, '') AS department"
            : "'' AS department";

        $userStatement = $pdo->prepare(
            'SELECT id, name, ' . $departmentSelect
            . ' FROM users WHERE ' . implode(' OR ', $userClauses)
            . ' ORDER BY id DESC'
        );
        $userStatement->execute($userParameters);

        foreach ($userStatement->fetchAll(PDO::FETCH_ASSOC) as $userRow) {
            $resolvedId = (int)($userRow['id'] ?? 0);
            $resolvedName = trim((string)($userRow['name'] ?? ''));
            if ($resolvedId > 0 && !isset($usersById[$resolvedId])) {
                $usersById[$resolvedId] = $userRow;
            }
            if ($resolvedName !== '') {
                $exactKey = $resolvedName;
                $foldedKey = ers_group_messages_fold($resolvedName);
                if (!isset($usersByName[$exactKey])) {
                    $usersByName[$exactKey] = $userRow;
                }
                if (!isset($usersByName[$foldedKey])) {
                    $usersByName[$foldedKey] = $userRow;
                }
            }
        }
    }

    $stage = 'response_mapping';
    $messages = [];
    $currentResponderName = trim((string)($responder['name'] ?? ''));

    foreach ($rows as $row) {
        $details = json_decode((string)($row['message_details'] ?? ''), true);
        $details = is_array($details) ? $details : [];

        $text = trim((string)($details['text'] ?? ''));
        $text = (string)(preg_replace('/^\[ROUTINE\]\s*/u', '', $text) ?? $text);

        $fileUrl = trim((string)($row['file_url'] ?? ''));
        $fileName = trim((string)($row['file_name'] ?? ''));
        $mimeType = trim((string)($row['mime_type'] ?? ''));
        $fileSize = max(0, (int)($row['file_size'] ?? 0));
        $isImage = (int)($row['is_image'] ?? 0) === 1;
        $isAudio = false;
        $audioDurationMs = 0;

        $firstAttachment = isset($details['attachments'][0])
            && is_array($details['attachments'][0])
            ? $details['attachments'][0]
            : [];

        // The attachment table stores the URL and MIME type, while voice-note
        // metadata (including duration) lives in message_details for backward
        // compatibility. Merge both sources instead of reading the JSON only
        // when the attachment-table URL is absent.
        if ($firstAttachment !== []) {
            if ($fileUrl === '') {
                $fileUrl = trim((string)(
                    $firstAttachment['file_url']
                    ?? $firstAttachment['fileUrl']
                    ?? $firstAttachment['url']
                    ?? ''
                ));
            }
            if ($fileName === '') {
                $fileName = trim((string)(
                    $firstAttachment['file_name']
                    ?? $firstAttachment['fileName']
                    ?? $firstAttachment['name']
                    ?? ''
                ));
            }
            if ($mimeType === '') {
                $mimeType = trim((string)(
                    $firstAttachment['mime_type']
                    ?? $firstAttachment['mimeType']
                    ?? ''
                ));
            }
            if ($fileSize <= 0) {
                $fileSize = max(0, (int)(
                    $firstAttachment['size']
                    ?? $firstAttachment['file_size']
                    ?? $firstAttachment['fileSize']
                    ?? 0
                ));
            }

            $isImage = $isImage
                || (int)($firstAttachment['is_image'] ?? 0) === 1
                || str_starts_with(strtolower($mimeType), 'image/');
            $isAudio = (int)(
                $firstAttachment['is_audio']
                ?? $firstAttachment['isAudio']
                ?? 0
            ) === 1;
            $audioDurationMs = max(0, (int)(
                $firstAttachment['duration_ms']
                ?? $firstAttachment['audio_duration_ms']
                ?? $firstAttachment['durationMs']
                ?? $firstAttachment['audioDurationMs']
                ?? 0
            ));
        }

        $normalizedMime = strtolower($mimeType);
        $fileExtension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
        $isAudio = $fileUrl !== '' && (
            $isAudio
            || str_starts_with($normalizedMime, 'audio/')
            || in_array($fileExtension, ['m4a', 'aac', '3gp', 'ogg', 'opus', 'wav', 'mp3'], true)
        );
        if ($isAudio) {
            $isImage = false;
            $text = 'Voice message';
        } elseif ($fileUrl !== '' && !$isImage && $fileName !== '') {
            $text = $fileName;
        }

        $rawSender = trim((string)($row['sender_user_id'] ?? ''));
        $resolvedUser = null;
        if (preg_match('/^[1-9][0-9]*$/D', $rawSender) === 1) {
            $resolvedUser = $usersById[(int)$rawSender] ?? null;
        } elseif ($rawSender !== '') {
            $resolvedUser = $usersByName[$rawSender]
                ?? $usersByName[ers_group_messages_fold($rawSender)]
                ?? null;
        }

        $resolvedSenderId = is_array($resolvedUser)
            ? (int)($resolvedUser['id'] ?? 0)
            : 0;
        $senderId = $resolvedSenderId > 0
            ? (string)$resolvedSenderId
            : $rawSender;
        $senderName = is_array($resolvedUser)
            ? trim((string)($resolvedUser['name'] ?? ''))
            : $rawSender;
        $department = is_array($resolvedUser)
            ? trim((string)($resolvedUser['department'] ?? ''))
            : '';

        $messageId = (int)($row['id'] ?? 0);
        $isOwn = $senderId === (string)$userId
            || ($rawSender !== '' && $rawSender === (string)$userId)
            || ($currentResponderName !== '' && strcasecmp($rawSender, $currentResponderName) === 0);
        $timestamp = strtotime((string)($row['created_at'] ?? ''));

        $messages[] = [
            'id' => (string)$messageId,
            'groupId' => (int)($row['group_id'] ?? $groupId),
            'senderId' => $senderId,
            'senderName' => $senderName !== '' ? $senderName : 'Unknown',
            'role' => $department,
            'text' => $text,
            'type' => $fileUrl !== ''
                ? ($isAudio ? 'AUDIO' : ($isImage ? 'IMAGE' : 'FILE'))
                : 'TEXT',
            'attachmentUri' => $fileUrl !== '' ? $fileUrl : null,
            'attachmentName' => $fileName !== '' ? $fileName : null,
            'attachmentMimeType' => $mimeType !== '' ? $mimeType : null,
            'attachmentSize' => $fileSize,
            'audioDurationMs' => $isAudio ? min(120000, $audioDurationMs) : 0,
            'createdAt' => $timestamp !== false ? $timestamp * 1000 : 0,
            'status' => $isOwn && $othersReadUpTo >= $messageId
                ? 'read'
                : 'delivered',
        ];
    }

    op_success([
        'messages' => $messages,
        'has_more' => $hasMore,
        'last_message_id' => $messages !== []
            ? (int)$messages[array_key_last($messages)]['id']
            : $afterId,
    ]);
} catch (Throwable $error) {
    try {
        $errorId = substr(bin2hex(random_bytes(8)), 0, 12);
    } catch (Throwable) {
        $errorId = substr(hash('sha256', microtime(true) . $error->getMessage()), 0, 12);
    }

    error_log(
        'get-interagency-group-messages [' . $errorId . '] stage=' . $stage
        . ' ' . get_class($error) . ': ' . $error->getMessage()
    );

    op_error('Unable to load department messages.', 500, [
        'error_code' => 'INTERAGENCY_GROUP_MESSAGES_FAILED',
        'error_id' => $errorId,
        'stage' => $stage,
    ]);
}