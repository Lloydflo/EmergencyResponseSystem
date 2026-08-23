<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');
$userId = op_query_int('user_id');
op_require_positive($userId, 'user_id');

/** @param list<string> $tables @return array<string,array<string,true>> */
function ia_groups_schema(PDO $pdo, array $tables): array
{
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

/** @param array<string,array<string,true>> $schema */
function ia_groups_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]);
}

/** @param array<string,array<string,true>> $schema @param list<string> $columns */
function ia_groups_has_columns(array $schema, string $table, array $columns): bool
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

/** @param list<int> $ids */
function ia_groups_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

try {
    $pdo = db();
    $tables = [
        'users',
        'interagency_group_threads',
        'interagency_group_members',
        'interagency_group_member_requests',
        'interagency_group_thread_reads',
        'interagency_groups_threads_read',
    ];
    $schema = ia_groups_schema($pdo, $tables);

    if (!ia_groups_has_columns($schema, 'users', ['id', 'name'])) {
        op_error('Responder accounts are not initialized on the server.', 503, [
            'error_code' => 'RESPONDER_SCHEMA_MISSING',
        ]);
    }
    if (!ia_groups_has_columns($schema, 'interagency_group_threads', ['id', 'name', 'is_active'])
        || !ia_groups_has_columns($schema, 'interagency_group_members', ['id', 'group_id', 'user_id', 'is_active'])) {
        op_error('Department channels are not initialized on the server.', 503, [
            'error_code' => 'INTERAGENCY_GROUP_SCHEMA_MISSING',
        ]);
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
        'SELECT id, name FROM users WHERE ' . implode(' AND ', $responderWhere) . ' LIMIT 1'
    );
    $responderStatement->execute([$userId]);
    $responder = $responderStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($responder)) {
        op_error('Responder account was not found or is inactive.', 403);
    }

    $hasRequests = ia_groups_has_columns($schema, 'interagency_group_member_requests', [
        'id', 'group_id', 'requested_user_id', 'status',
    ]);
    $hasReads = ia_groups_has_columns($schema, 'interagency_group_thread_reads', [
        'group_id', 'user_id', 'last_read_id',
    ]);
    $hasMessages = ia_groups_has_columns($schema, 'interagency_groups_threads_read', [
        'id', 'group_id', 'sender_user_id', 'message_details', 'created_at',
    ]);

    $requestSelect = $hasRequests
        ? 'CASE WHEN req.id IS NULL THEN 0 ELSE 1 END AS request_pending'
        : '0 AS request_pending';
    $requestJoin = $hasRequests
        ? "LEFT JOIN interagency_group_member_requests req
             ON req.group_id = gt.id
            AND req.requested_user_id = ?
            AND req.status = 'pending'"
        : '';
    $readSelect = $hasReads
        ? 'COALESCE(rd.last_read_id, 0) AS last_read_id'
        : '0 AS last_read_id';
    $readJoin = $hasReads
        ? 'LEFT JOIN interagency_group_thread_reads rd
             ON rd.group_id = gt.id
            AND rd.user_id = ?'
        : '';

    $groupSql = "SELECT gt.id, gt.name,
            CASE WHEN gm.id IS NULL THEN 0 ELSE 1 END AS is_member,
            {$requestSelect}, {$readSelect}
        FROM interagency_group_threads gt
        LEFT JOIN interagency_group_members gm
          ON gm.group_id = gt.id
         AND gm.user_id = ?
         AND gm.is_active = 1
        {$requestJoin}
        {$readJoin}
        WHERE gt.is_active = 1
        ORDER BY gt.name ASC, gt.id ASC";

    $groupParams = [$userId];
    if ($hasRequests) {
        $groupParams[] = $userId;
    }
    if ($hasReads) {
        $groupParams[] = $userId;
    }
    $groupStatement = $pdo->prepare($groupSql);
    $groupStatement->execute($groupParams);
    $groupRows = $groupStatement->fetchAll(PDO::FETCH_ASSOC);

    $groupIds = [];
    $memberGroupIds = [];
    foreach ($groupRows as $row) {
        $groupId = (int)($row['id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $groupIds[] = $groupId;
        if ((int)($row['is_member'] ?? 0) === 1) {
            $memberGroupIds[] = $groupId;
        }
    }

    $latestByGroup = [];
    if ($hasMessages && $groupIds !== []) {
        $in = ia_groups_placeholders($groupIds);
        $latestStatement = $pdo->prepare(
            "SELECT m.group_id, m.id, m.message_details, m.created_at
             FROM interagency_groups_threads_read m
             INNER JOIN (
                 SELECT group_id, MAX(id) AS latest_id
                 FROM interagency_groups_threads_read
                 WHERE group_id IN ({$in})
                 GROUP BY group_id
             ) latest
               ON latest.group_id = m.group_id
              AND latest.latest_id = m.id"
        );
        $latestStatement->execute($groupIds);
        foreach ($latestStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupId = (int)($row['group_id'] ?? 0);
            if ($groupId > 0) {
                $latestByGroup[$groupId] = $row;
            }
        }
    }

    $unreadByGroup = [];
    if ($hasMessages && $memberGroupIds !== []) {
        $in = ia_groups_placeholders($memberGroupIds);
        $readJoinForUnread = '';
        $lastReadExpression = '0';
        $unreadParams = [];
        if ($hasReads) {
            $readJoinForUnread = 'LEFT JOIN interagency_group_thread_reads mine
                ON mine.group_id = msg.group_id AND mine.user_id = ?';
            $lastReadExpression = 'COALESCE(mine.last_read_id, 0)';
            $unreadParams[] = $userId;
        }
        array_push($unreadParams, ...$memberGroupIds);
        $unreadParams[] = (string)$userId;
        $unreadParams[] = trim((string)($responder['name'] ?? ''));

        $unreadStatement = $pdo->prepare(
            "SELECT msg.group_id, COUNT(*) AS unread_count
             FROM interagency_groups_threads_read msg
             {$readJoinForUnread}
             WHERE msg.group_id IN ({$in})
               AND msg.id > {$lastReadExpression}
               AND msg.sender_user_id <> ?
               AND msg.sender_user_id <> ?
             GROUP BY msg.group_id"
        );
        $unreadStatement->execute($unreadParams);
        foreach ($unreadStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupId = (int)($row['group_id'] ?? 0);
            if ($groupId > 0) {
                $unreadByGroup[$groupId] = max(0, (int)($row['unread_count'] ?? 0));
            }
        }
    }

    $groups = [];
    foreach ($groupRows as $row) {
        $groupId = (int)($row['id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $isMember = (int)($row['is_member'] ?? 0) === 1;
        $requestPending = (int)($row['request_pending'] ?? 0) === 1;
        $latest = $latestByGroup[$groupId] ?? null;
        $latestText = '';
        $latestMessageId = 0;
        $latestMessageTime = 0;

        if ($isMember && is_array($latest)) {
            $latestMessageId = (int)($latest['id'] ?? 0);
            $timestamp = strtotime((string)($latest['created_at'] ?? ''));
            $latestMessageTime = $timestamp !== false ? $timestamp * 1000 : 0;
            $details = json_decode((string)($latest['message_details'] ?? ''), true);
            if (is_array($details)) {
                $latestText = trim((string)($details['text'] ?? ''));
                $latestText = (string)(preg_replace('/^\[ROUTINE\]\s*/u', '', $latestText) ?? $latestText);
                if ($latestText === '' && isset($details['attachments'][0]) && is_array($details['attachments'][0])) {
                    $attachment = $details['attachments'][0];
                    $mimeType = strtolower(trim((string)($attachment['mime_type'] ?? $attachment['mimeType'] ?? '')));
                    $isImage = (int)($attachment['is_image'] ?? 0) === 1 || str_starts_with($mimeType, 'image/');
                    $name = trim((string)($attachment['name'] ?? $attachment['file_name'] ?? $attachment['fileName'] ?? 'File'));
                    $latestText = $isImage ? '📷 Image' : '📎 ' . ($name !== '' ? $name : 'File');
                }
            }
        }

        $name = trim((string)($row['name'] ?? ''));
        $groups[] = [
            'id' => $groupId,
            'name' => $name,
            'displayName' => $name,
            'isMember' => $isMember,
            'requestPending' => $requestPending,
            'lastMessage' => $isMember
                ? ($latestText !== '' ? $latestText : 'Tap to open group chat')
                : ($requestPending ? 'Request pending approval' : 'Request access to join'),
            'lastMessageTime' => $latestMessageTime,
            'unreadCount' => $isMember ? ($unreadByGroup[$groupId] ?? 0) : 0,
            'lastReadId' => (int)($row['last_read_id'] ?? 0),
            'latestMessageId' => $latestMessageId,
        ];
    }

    op_success(['groups' => $groups]);
} catch (Throwable $error) {
    $errorId = substr(hash('sha256', $error->getMessage() . '|' . microtime(true)), 0, 12);
    error_log('get-interagency-groups [' . $errorId . ']: ' . $error->getMessage());
    op_error('Unable to load department channels.', 500, [
        'error_code' => 'INTERAGENCY_GROUP_QUERY_FAILED',
        'error_id' => $errorId,
    ]);
}
