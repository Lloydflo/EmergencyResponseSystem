<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/../../includes/user_presence.php';

op_require_method('GET');
$userId = op_query_int('user_id');
op_require_positive($userId, 'user_id');

/**
 * Read the required table state with one INFORMATION_SCHEMA query.
 *
 * @param list<string> $tableNames
 * @return array<string,true>
 */
function op_group_existing_tables(PDO $pdo, array $tableNames): array
{
    if ($tableNames === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($tableNames), '?'));
    $statement = $pdo->prepare(
        "SELECT TABLE_NAME
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ({$placeholders})"
    );
    $statement->execute($tableNames);

    $existing = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $name = (string)$tableName;
        if ($name !== '') {
            $existing[$name] = true;
        }
    }

    return $existing;
}

/** @param list<int> $ids */
function op_group_in_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

try {
    $pdo = db();
    $responder = op_require_active_responder($pdo, $userId);

    // Presence updates must not prevent department channels from loading.
    // A presence-table/resource-sync issue is logged independently instead.
    try {
        touch_user_presence($pdo, $userId);
    } catch (Throwable $presenceError) {
        error_log('get-interagency-groups presence update skipped: ' . $presenceError->getMessage());
    }

    $knownTables = [
        'interagency_group_threads',
        'interagency_group_members',
        'interagency_group_member_requests',
        'interagency_group_thread_reads',
        'interagency_groups_threads_read',
    ];
    $existingTables = op_group_existing_tables($pdo, $knownTables);

    $requiredTables = [
        'interagency_group_threads',
        'interagency_group_members',
    ];
    $missingRequired = array_values(array_filter(
        $requiredTables,
        static fn(string $table): bool => !isset($existingTables[$table])
    ));

    if ($missingRequired !== []) {
        error_log(
            'get-interagency-groups schema incomplete; missing required tables: '
            . implode(', ', $missingRequired)
        );
        op_error(
            'Department channels are not initialized on the server.',
            503,
            ['code' => 'INTERAGENCY_GROUP_SCHEMA_MISSING']
        );
    }

    $hasRequests = isset($existingTables['interagency_group_member_requests']);
    $hasReads = isset($existingTables['interagency_group_thread_reads']);
    $hasMessages = isset($existingTables['interagency_groups_threads_read']);

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
        ? 'COALESCE(r.last_read_id, 0) AS last_read_id'
        : '0 AS last_read_id';
    $readJoin = $hasReads
        ? 'LEFT JOIN interagency_group_thread_reads r
             ON r.group_id = gt.id
            AND r.user_id = ?'
        : '';

    $groupSql = "SELECT
            gt.id,
            gt.name,
            CASE WHEN gm.id IS NULL THEN 0 ELSE 1 END AS is_member,
            {$requestSelect},
            {$readSelect}
        FROM interagency_group_threads gt
        LEFT JOIN interagency_group_members gm
          ON gm.group_id = gt.id
         AND gm.user_id = ?
         AND gm.is_active = 1
        {$requestJoin}
        {$readJoin}
        WHERE gt.is_active = 1
        ORDER BY gt.name ASC";

    $groupParams = [$userId];
    if ($hasRequests) {
        $groupParams[] = $userId;
    }
    if ($hasReads) {
        $groupParams[] = $userId;
    }

    $groupStatement = $pdo->prepare($groupSql);
    $groupStatement->execute($groupParams);
    $groupRows = op_fetch_all($groupStatement);

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

    /** @var array<int,array<string,mixed>> $latestByGroup */
    $latestByGroup = [];
    if ($hasMessages && $groupIds !== []) {
        $in = op_group_in_placeholders($groupIds);
        $latestStatement = $pdo->prepare(
            "SELECT
                message.group_id,
                message.id,
                message.message_details,
                message.created_at
             FROM interagency_groups_threads_read message
             INNER JOIN (
                SELECT group_id, MAX(id) AS latest_id
                FROM interagency_groups_threads_read
                WHERE group_id IN ({$in})
                GROUP BY group_id
             ) latest
                ON latest.latest_id = message.id"
        );
        $latestStatement->execute($groupIds);
        foreach (op_fetch_all($latestStatement) as $row) {
            $groupId = (int)($row['group_id'] ?? 0);
            if ($groupId > 0) {
                $latestByGroup[$groupId] = $row;
            }
        }
    }

    /** @var array<int,int> $unreadByGroup */
    $unreadByGroup = [];
    if ($hasMessages && $memberGroupIds !== []) {
        $in = op_group_in_placeholders($memberGroupIds);
        $readJoinForUnread = '';
        $lastReadExpression = '0';
        $unreadParams = [];

        if ($hasReads) {
            $readJoinForUnread = 'LEFT JOIN interagency_group_thread_reads read_state
                ON read_state.group_id = message.group_id
               AND read_state.user_id = ?';
            $lastReadExpression = 'COALESCE(read_state.last_read_id, 0)';
            $unreadParams[] = $userId;
        }

        array_push($unreadParams, ...$memberGroupIds);
        $unreadParams[] = $userId;
        $unreadParams[] = (string)($responder['name'] ?? '');

        $unreadStatement = $pdo->prepare(
            "SELECT message.group_id, COUNT(*) AS unread_count
             FROM interagency_groups_threads_read message
             {$readJoinForUnread}
             WHERE message.group_id IN ({$in})
               AND message.id > {$lastReadExpression}
               AND message.sender_user_id <> CAST(? AS CHAR)
               AND message.sender_user_id <> ?
             GROUP BY message.group_id"
        );
        $unreadStatement->execute($unreadParams);
        foreach (op_fetch_all($unreadStatement) as $row) {
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

        if ($isMember && is_array($latest)) {
            $details = json_decode((string)($latest['message_details'] ?? ''), true);
            if (is_array($details)) {
                $latestText = trim((string)($details['text'] ?? ''));
                if ($latestText === '' && isset($details['attachments'][0]) && is_array($details['attachments'][0])) {
                    $attachment = $details['attachments'][0];
                    $isImage = (int)($attachment['is_image'] ?? 0) === 1;
                    $attachmentName = trim((string)($attachment['name'] ?? $attachment['file_name'] ?? 'File'));
                    $latestText = $isImage ? '📷 Image' : '📎 ' . ($attachmentName !== '' ? $attachmentName : 'File');
                }
            }
        }

        $latestText = preg_replace('/^\[ROUTINE\]\s*/', '', $latestText) ?? $latestText;
        $latestAt = is_array($latest) ? trim((string)($latest['created_at'] ?? '')) : '';
        $latestTimestamp = $latestAt !== '' ? strtotime($latestAt) : false;

        $groups[] = [
            'id' => $groupId,
            'name' => (string)($row['name'] ?? ''),
            'displayName' => (string)($row['name'] ?? ''),
            'isMember' => $isMember,
            'requestPending' => $requestPending,
            'lastMessage' => $isMember
                ? ($latestText !== '' ? $latestText : 'Tap to open group chat')
                : ($requestPending ? 'Request pending approval' : 'Request access to join'),
            'lastMessageTime' => $isMember && $latestTimestamp !== false ? $latestTimestamp * 1000 : 0,
            'unreadCount' => $isMember ? ($unreadByGroup[$groupId] ?? 0) : 0,
            'lastReadId' => (int)($row['last_read_id'] ?? 0),
            'latestMessageId' => is_array($latest) ? (int)($latest['id'] ?? 0) : 0,
        ];
    }

    op_success(['groups' => $groups]);
} catch (Throwable $error) {
    error_log('get-interagency-groups: ' . $error->getMessage());
    op_error('Unable to load department channels.', 500);
}
