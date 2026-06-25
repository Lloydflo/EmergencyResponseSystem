<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/interagency_time.php';
require_once __DIR__ . '/../includes/user_presence.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
interagency_apply_database_timezone($pdo);

function ensure_interagency_reads_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_thread_reads` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `entity_id` INT NOT NULL,
            `last_read_id` INT NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_reads_user_entity` (`user_id`, `entity_id`),
            KEY `idx_interagency_reads_user` (`user_id`),
            KEY `idx_interagency_reads_entity` (`entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_user_threads_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_user_thread_pairs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_user_id` INT UNSIGNED NOT NULL,
            `target_user_id` INT UNSIGNED NOT NULL,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_user_threads_pair` (`owner_user_id`, `target_user_id`),
            KEY `idx_interagency_user_threads_owner` (`owner_user_id`),
            KEY `idx_interagency_user_threads_target` (`target_user_id`),
            KEY `idx_interagency_user_threads_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_user_reads_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_user_thread_reads` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `target_user_id` INT UNSIGNED NOT NULL,
            `last_read_id` INT NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_user_reads_pair` (`user_id`, `target_user_id`),
            KEY `idx_interagency_user_reads_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_group_tables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_group_threads` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_interagency_group_threads_active` (`is_active`),
            KEY `idx_interagency_group_threads_creator` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_group_members` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `added_by` INT UNSIGNED DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_group_member` (`group_id`, `user_id`),
            KEY `idx_interagency_group_members_user` (`user_id`),
            KEY `idx_interagency_group_members_group` (`group_id`),
            KEY `idx_interagency_group_members_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_group_reads_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_group_thread_reads` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `last_read_id` INT NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_group_reads_pair` (`user_id`, `group_id`),
            KEY `idx_interagency_group_reads_user` (`user_id`),
            KEY `idx_interagency_group_reads_group` (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_group_messages_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_groups_threads_read` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activity_log_id` INT NOT NULL,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `sender_user_id` VARCHAR(255) NOT NULL,
            `message_details` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_group_chat_activity_log` (`activity_log_id`),
            KEY `idx_interagency_group_chat_group_created` (`group_id`, `created_at`),
            KEY `idx_interagency_group_chat_sender_created` (`sender_user_id`, `created_at`),
            KEY `idx_interagency_group_chat_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_thread_titles_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_thread_titles` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_user_id` INT UNSIGNED NOT NULL,
            `thread_key` VARCHAR(64) NOT NULL,
            `title` VARCHAR(120) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_thread_title_owner_key` (`owner_user_id`, `thread_key`),
            KEY `idx_interagency_thread_title_owner` (`owner_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function build_thread_key(string $kind, string $department = '', int $userId = 0): string {
    if ($kind === 'group') {
        return 'group:' . max(0, $userId);
    }
    if ($kind === 'user') {
        return 'user:' . max(0, $userId);
    }
    return 'dept:' . strtolower(trim($department));
}

function user_icon_by_role(string $role): string {
    $r = strtolower(trim($role));
    if ($r === 'admin') return 'fa-user-tie';
    if ($r === 'dispatcher' || $r === 'operator') return 'fa-headset';
    return 'fa-user';
}

function parse_message_details(string $raw): array {
    $text = trim($raw);
    $attachments = [];

    if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
        $decoded = json_decode($text, true);
        if (is_array($decoded) && (isset($decoded['text']) || isset($decoded['attachments']))) {
            $text = isset($decoded['text']) ? trim((string)$decoded['text']) : '';
            if (isset($decoded['attachments']) && is_array($decoded['attachments'])) {
                foreach ($decoded['attachments'] as $a) {
                    if (!is_array($a)) continue;
                    $url = trim((string)($a['url'] ?? $a['file_url'] ?? ''));
                    if ($url === '') continue;
                    $attachments[] = [
                        'name' => trim((string)($a['name'] ?? $a['file_name'] ?? basename($url))),
                        'url' => $url
                    ];
                }
            }
        }
    }

    return ['text' => $text, 'attachments' => $attachments];
}

function preview_text_from_details(string $raw): string {
    $parsed = parse_message_details($raw);
    $text = (string)($parsed['text'] ?? '');
    $attachments = is_array($parsed['attachments'] ?? null) ? $parsed['attachments'] : [];
    if ($text !== '') return $text;
    if (!count($attachments)) return '';
    if (count($attachments) === 1) {
        $name = trim((string)($attachments[0]['name'] ?? 'Attachment'));
        return '[Attachment] ' . ($name !== '' ? $name : 'File');
    }
    return '[' . count($attachments) . ' attachments]';
}

function derive_thread_status(?string $accountStatus, ?string $lastActivityAt): string {
    $normalizedStatus = strtolower(trim((string)$accountStatus));
    if ($normalizedStatus !== '' && $normalizedStatus !== 'active') {
        return 'offline';
    }

    $activityAt = trim((string)$lastActivityAt);
    if ($activityAt === '') {
        return 'offline';
    }

    $activityTs = strtotime($activityAt);
    if ($activityTs === false) {
        return 'offline';
    }

    $ageSeconds = time() - $activityTs;
    if ($ageSeconds <= 300) {
        return 'online';
    }
    if ($ageSeconds <= 3600) {
        return 'busy';
    }
    return 'offline';
}

function normalize_account_status(?string $accountStatus): string {
    $status = strtolower(trim((string)$accountStatus));
    return $status === 'active' ? 'active' : 'inactive';
}

$threadDefs = [
    4 => [
        'id' => 'coordinator',
        'department' => 'coordinator',
        'title' => 'Operations Coordinator',
        'kind' => 'department',
        'status' => 'offline',
        'icon' => 'fa-user-tie',
        'tone' => 'coordinator'
    ],
];

try {
    ensure_interagency_reads_table($pdo);
    ensure_interagency_user_threads_table($pdo);
    ensure_interagency_user_reads_table($pdo);
    ensure_interagency_group_tables($pdo);
    ensure_interagency_group_reads_table($pdo);
    ensure_interagency_group_messages_table($pdo);
    ensure_interagency_thread_titles_table($pdo);

    $user = get_logged_in_user();
    $currentUserId = (int)($user['id'] ?? 0);
    touch_user_presence($pdo, $currentUserId);
    $departmentEntityIds = array_keys($threadDefs);
    $departmentEntityIdList = implode(',', array_map('intval', $departmentEntityIds));

    $titleOverrides = [];
    if ($currentUserId > 0) {
        $titleStmt = $pdo->prepare(
            "SELECT thread_key, title
             FROM interagency_thread_titles
             WHERE owner_user_id = ?"
        );
        $titleStmt->execute([$currentUserId]);
        $titleRows = $titleStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($titleRows as $row) {
            $key = trim((string)($row['thread_key'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if ($key !== '' && $title !== '') {
                $titleOverrides[$key] = $title;
            }
        }
    }

    $threads = [];
    foreach ($threadDefs as $entityId => $def) {
        $threadKey = build_thread_key('department', (string)($def['department'] ?? ''));
        $customTitle = $titleOverrides[$threadKey] ?? '';
        if ($customTitle !== '') {
            $def['title'] = $customTitle;
        }
        $threads[$entityId] = array_merge($def, [
            'thread_kind' => 'department',
            'entity_id' => $entityId,
            'last_message_id' => 0,
            'last_text' => '',
            'last_at' => null,
            'last_sender_name' => null,
            'last_sender_role' => null,
            'total_messages' => 0,
            'unread' => 0
        ]);
    }

    $latestStmt = $pdo->query(
        "SELECT a.entity_id, a.id, a.details, a.created_at, a.user_id,
                COALESCE(NULLIF(u.name, ''), 'System') AS sender_name,
                COALESCE(NULLIF(u.role, ''), 'system') AS sender_role
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         INNER JOIN (
             SELECT a2.entity_id, MAX(a2.id) AS max_id
             FROM activity_log a2
             WHERE a2.entity_type='agency_chat'
               AND a2.entity_id IN ($departmentEntityIdList)
               AND NOT EXISTS (
                   SELECT 1
                   FROM interagency_groups_threads_read legacy
                   WHERE legacy.activity_log_id = a2.id
               )
             GROUP BY a2.entity_id
         ) latest ON latest.max_id = a.id"
    );
    $latestRows = $latestStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($latestRows as $row) {
        $eid = (int)$row['entity_id'];
        if (!isset($threads[$eid])) {
            continue;
        }
        $threads[$eid]['last_message_id'] = (int)$row['id'];
        $threads[$eid]['last_text'] = preview_text_from_details((string)$row['details']);
        $threads[$eid]['last_at'] = interagency_manila_iso((string)$row['created_at']);
        $threads[$eid]['last_sender_name'] = (string)$row['sender_name'];
        $threads[$eid]['last_sender_role'] = strtolower((string)$row['sender_role']);
    }

    $departmentActivityStmt = $pdo->query(
        "SELECT a.entity_id, MAX(a.created_at) AS last_activity_at
         FROM activity_log a
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.entity_type = 'agency_chat'
           AND a.entity_id IN ($departmentEntityIdList)
           AND NOT EXISTS (
               SELECT 1
               FROM interagency_groups_threads_read legacy
               WHERE legacy.activity_log_id = a.id
           )
           AND LOWER(u.role) <> 'admin'
         GROUP BY a.entity_id"
    );
    $departmentActivityRows = $departmentActivityStmt->fetchAll(PDO::FETCH_ASSOC);
    $departmentLastActivity = [];
    foreach ($departmentActivityRows as $row) {
        $departmentLastActivity[(int)$row['entity_id']] = (string)($row['last_activity_at'] ?? '');
    }

    foreach ($threads as &$thread) {
        $entityId = (int)($thread['entity_id'] ?? 0);
        $thread['status'] = derive_thread_status(null, $departmentLastActivity[$entityId] ?? null);
    }
    unset($thread);

    $totalStmt = $pdo->query(
        "SELECT entity_id, COUNT(*) AS total_messages
         FROM activity_log
         WHERE entity_type='agency_chat' AND entity_id IN ($departmentEntityIdList)
           AND NOT EXISTS (
               SELECT 1
               FROM interagency_groups_threads_read legacy
               WHERE legacy.activity_log_id = activity_log.id
           )
         GROUP BY entity_id"
    );
    $totalRows = $totalStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($totalRows as $row) {
        $eid = (int)$row['entity_id'];
        if (!isset($threads[$eid])) {
            continue;
        }
        $threads[$eid]['total_messages'] = (int)$row['total_messages'];
    }

    $readsStmt = $pdo->prepare(
        "SELECT entity_id, last_read_id
         FROM interagency_thread_reads
         WHERE user_id = ? AND entity_id IN ($departmentEntityIdList)"
    );
    $readsStmt->execute([$currentUserId]);
    $readRows = $readsStmt->fetchAll(PDO::FETCH_ASSOC);
    $lastReadByEntity = [];
    foreach ($readRows as $row) {
        $lastReadByEntity[(int)$row['entity_id']] = (int)$row['last_read_id'];
    }

    $unreadStmt = $pdo->prepare(
        "SELECT COUNT(*) AS unread_count
         FROM activity_log
         WHERE entity_type='agency_chat' AND entity_id = ? AND id > ?
           AND NOT EXISTS (
               SELECT 1
               FROM interagency_groups_threads_read legacy
               WHERE legacy.activity_log_id = activity_log.id
           )"
    );

    $totalUnread = 0;
    foreach ($threads as $eid => &$thread) {
        $lastReadId = $lastReadByEntity[$eid] ?? 0;
        if ($thread['total_messages'] <= 0) {
            $thread['unread'] = 0;
            continue;
        }
        $unreadStmt->execute([$eid, $lastReadId]);
        $unreadCount = (int)($unreadStmt->fetchColumn() ?: 0);
        $thread['unread'] = $unreadCount;
        $totalUnread += $unreadCount;
    }
    unset($thread);

    $manualThreadStmt = $pdo->prepare(
        "SELECT target_user_id
         FROM interagency_user_thread_pairs
         WHERE owner_user_id = ? AND is_active = 1"
    );
    $manualThreadStmt->execute([$currentUserId]);
    $manualThreadRows = $manualThreadStmt->fetchAll(PDO::FETCH_ASSOC);

    $counterpartIds = [];
    foreach ($manualThreadRows as $row) {
        $id = (int)($row['target_user_id'] ?? 0);
        if ($id > 0 && $id !== $currentUserId) {
            $counterpartIds[$id] = true;
        }
    }

    $messageCounterpartStmt = $pdo->prepare(
        "SELECT DISTINCT
             CASE WHEN a.user_id = ? THEN a.entity_id ELSE a.user_id END AS counterpart_id
         FROM activity_log a
         WHERE a.entity_type = 'agency_user_chat'
           AND (
               (a.user_id = ? AND a.entity_id IS NOT NULL AND a.entity_id > 0)
               OR
               (a.entity_id = ? AND a.user_id IS NOT NULL AND a.user_id > 0)
           )"
    );
    $messageCounterpartStmt->execute([$currentUserId, $currentUserId, $currentUserId]);
    $messageCounterpartRows = $messageCounterpartStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($messageCounterpartRows as $row) {
        $id = (int)($row['counterpart_id'] ?? 0);
        if ($id > 0 && $id !== $currentUserId) {
            $counterpartIds[$id] = true;
        }
    }

    $counterpartIds = array_keys($counterpartIds);
    $counterpartUsers = [];
    if (count($counterpartIds) > 0) {
        sort($counterpartIds);
        $placeholders = implode(',', array_fill(0, count($counterpartIds), '?'));
        $usersStmt = $pdo->prepare(
            "SELECT id, name, role, status
             FROM users
             WHERE id IN ($placeholders)"
        );
        $usersStmt->execute($counterpartIds);
        $rows = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $counterpartUsers[(int)$row['id']] = $row;
        }
    }

    $userReadStmt = $pdo->prepare(
        "SELECT target_user_id, last_read_id
         FROM interagency_user_thread_reads
         WHERE user_id = ?"
    );
    $userReadStmt->execute([$currentUserId]);
    $userReadRows = $userReadStmt->fetchAll(PDO::FETCH_ASSOC);
    $userReadByTarget = [];
    foreach ($userReadRows as $row) {
        $userReadByTarget[(int)$row['target_user_id']] = (int)$row['last_read_id'];
    }

    $userLatestStmt = $pdo->prepare(
        "SELECT a.id, a.details, a.created_at, a.user_id,
                COALESCE(NULLIF(u.name, ''), 'System') AS sender_name,
                COALESCE(NULLIF(u.role, ''), 'system') AS sender_role
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity_type = 'agency_user_chat'
           AND (
               (a.user_id = ? AND a.entity_id = ?)
               OR
               (a.user_id = ? AND a.entity_id = ?)
           )
         ORDER BY a.id DESC
         LIMIT 1"
    );
    $userTotalStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM activity_log
         WHERE entity_type = 'agency_user_chat'
           AND (
               (user_id = ? AND entity_id = ?)
               OR
               (user_id = ? AND entity_id = ?)
           )"
    );
    $userUnreadStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM activity_log
         WHERE entity_type = 'agency_user_chat'
           AND user_id = ?
           AND entity_id = ?
           AND id > ?"
    );

    foreach ($counterpartIds as $targetUserId) {
        $counterpart = $counterpartUsers[$targetUserId] ?? null;
        $displayName = trim((string)($counterpart['name'] ?? ''));
        $displayRole = (string)($counterpart['role'] ?? 'user');
        $accountStatus = normalize_account_status(isset($counterpart['status']) ? (string)$counterpart['status'] : null);
        $threadKey = build_thread_key('user', '', $targetUserId);
        $customTitle = $titleOverrides[$threadKey] ?? '';
        if ($customTitle !== '') {
            $displayName = $customTitle;
        }

        $userLatestStmt->execute([$currentUserId, $targetUserId, $targetUserId, $currentUserId]);
        $latest = $userLatestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $userTotalStmt->execute([$currentUserId, $targetUserId, $targetUserId, $currentUserId]);
        $totalMessages = (int)($userTotalStmt->fetchColumn() ?: 0);

        $lastReadId = $userReadByTarget[$targetUserId] ?? 0;
        $userUnreadStmt->execute([$targetUserId, $currentUserId, $lastReadId]);
        $unreadCount = (int)($userUnreadStmt->fetchColumn() ?: 0);

        $threads[] = [
            'id' => 'user-' . $targetUserId,
            'department' => 'user',
            'thread_kind' => 'user',
            'user_id' => $targetUserId,
            'entity_id' => $targetUserId,
            'title' => ($displayName !== '' ? $displayName : ('User #' . $targetUserId)),
            'kind' => 'responder',
            'role' => strtolower($displayRole),
            'status' => $accountStatus,
            'icon' => user_icon_by_role($displayRole),
            'tone' => 'responder',
            'last_message_id' => $latest ? (int)$latest['id'] : 0,
            'last_text' => $latest ? preview_text_from_details((string)$latest['details']) : '',
            'last_at' => $latest ? interagency_manila_iso((string)$latest['created_at']) : null,
            'last_sender_name' => $latest ? (string)$latest['sender_name'] : null,
            'last_sender_role' => $latest ? strtolower((string)$latest['sender_role']) : null,
            'total_messages' => $totalMessages,
            'unread' => $unreadCount
        ];
        $totalUnread += $unreadCount;
    }

    $groupStmt = $pdo->prepare(
        "SELECT g.id, g.name, g.created_at, COUNT(gm_all.user_id) AS member_count
         FROM interagency_group_threads g
         INNER JOIN interagency_group_members gm_self
                 ON gm_self.group_id = g.id
                AND gm_self.user_id = ?
                AND gm_self.is_active = 1
         LEFT JOIN interagency_group_members gm_all
                ON gm_all.group_id = g.id
               AND gm_all.is_active = 1
         WHERE g.is_active = 1
         GROUP BY g.id, g.name, g.created_at
         ORDER BY g.updated_at DESC, g.id DESC"
    );
    $groupStmt->execute([$currentUserId]);
    $groupRows = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

    $groupIds = [];
    foreach ($groupRows as $row) {
        $groupId = (int)($row['id'] ?? 0);
        if ($groupId > 0) {
            $groupIds[] = $groupId;
        }
    }

    $groupLatestById = [];
    $groupTotalsById = [];
    $groupReadsById = [];
    if (count($groupIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

        $groupLatestStmt = $pdo->prepare(
            "SELECT a.entity_id, a.id, a.details, a.created_at, a.user_id,
                    COALESCE(NULLIF(u.name, ''), 'System') AS sender_name,
                    COALESCE(NULLIF(u.role, ''), 'system') AS sender_role
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id
             INNER JOIN (
                 SELECT a2.entity_id, MAX(a2.id) AS max_id
                 FROM activity_log a2
                 WHERE a2.entity_id IN ($placeholders)
                   AND (
                       a2.entity_type='agency_group_chat'
                       OR EXISTS (
                           SELECT 1
                           FROM interagency_groups_threads_read legacy
                           WHERE legacy.activity_log_id = a2.id
                             AND legacy.group_id = a2.entity_id
                       )
                   )
                 GROUP BY a2.entity_id
             ) latest ON latest.max_id = a.id"
        );
        $groupLatestStmt->execute($groupIds);
        foreach ($groupLatestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupLatestById[(int)$row['entity_id']] = $row;
        }

        $groupTotalStmt = $pdo->prepare(
            "SELECT entity_id, COUNT(*) AS total_messages
             FROM activity_log
             WHERE entity_id IN ($placeholders)
               AND (
                   entity_type='agency_group_chat'
                   OR EXISTS (
                       SELECT 1
                       FROM interagency_groups_threads_read legacy
                       WHERE legacy.activity_log_id = activity_log.id
                         AND legacy.group_id = activity_log.entity_id
                   )
               )
             GROUP BY entity_id"
        );
        $groupTotalStmt->execute($groupIds);
        foreach ($groupTotalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupTotalsById[(int)$row['entity_id']] = (int)$row['total_messages'];
        }

        $groupReadStmt = $pdo->prepare(
            "SELECT group_id, last_read_id
             FROM interagency_group_thread_reads
             WHERE user_id = ? AND group_id IN ($placeholders)"
        );
        $groupReadStmt->execute(array_merge([$currentUserId], $groupIds));
        foreach ($groupReadStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groupReadsById[(int)$row['group_id']] = (int)$row['last_read_id'];
        }
    }

    $groupUnreadStmt = $pdo->prepare(
        "SELECT COUNT(*) AS unread_count
         FROM activity_log
         WHERE entity_id = ?
           AND (
               entity_type='agency_group_chat'
               OR EXISTS (
                   SELECT 1
                   FROM interagency_groups_threads_read legacy
                   WHERE legacy.activity_log_id = activity_log.id
                     AND legacy.group_id = activity_log.entity_id
               )
           )
           AND user_id <> ?
           AND id > ?"
    );

    foreach ($groupRows as $row) {
        $groupId = (int)($row['id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $latest = $groupLatestById[$groupId] ?? null;
        $totalMessages = $groupTotalsById[$groupId] ?? 0;
        $lastReadId = $groupReadsById[$groupId] ?? 0;
        $groupUnreadStmt->execute([$groupId, $currentUserId, $lastReadId]);
        $unreadCount = (int)($groupUnreadStmt->fetchColumn() ?: 0);

        $threads[] = [
            'id' => 'group-' . $groupId,
            'department' => 'group',
            'thread_kind' => 'group',
            'group_id' => $groupId,
            'entity_id' => $groupId,
            'title' => (string)($row['name'] ?? ('Group #' . $groupId)),
            'kind' => 'group',
            'role' => 'group',
            'status' => 'active',
            'icon' => 'fa-users',
            'tone' => 'group',
            'member_count' => (int)($row['member_count'] ?? 0),
            'last_message_id' => $latest ? (int)$latest['id'] : 0,
            'last_text' => $latest ? preview_text_from_details((string)$latest['details']) : '',
            'last_at' => $latest ? interagency_manila_iso((string)$latest['created_at']) : interagency_manila_iso((string)($row['created_at'] ?? '')),
            'last_sender_name' => $latest ? (string)$latest['sender_name'] : null,
            'last_sender_role' => $latest ? strtolower((string)$latest['sender_role']) : null,
            'total_messages' => $totalMessages,
            'unread' => $unreadCount
        ];
        $totalUnread += $unreadCount;
    }

    usort($threads, static function (array $a, array $b): int {
        $at = strtotime((string)($a['last_at'] ?? '1970-01-01 00:00:00'));
        $bt = strtotime((string)($b['last_at'] ?? '1970-01-01 00:00:00'));
        if ($at === $bt) return 0;
        return ($at > $bt) ? -1 : 1;
    });

    $activeResponders = 0;
    foreach ($threads as $thread) {
        if (($thread['thread_kind'] ?? '') !== 'user') {
            continue;
        }
        if (($thread['status'] ?? '') === 'active' && strtolower((string)($thread['role'] ?? '')) !== 'admin') {
            $activeResponders++;
        }
    }

    echo json_encode([
        'ok' => true,
        'threads' => array_values($threads),
        'stats' => [
            'total_threads' => count($threads),
            'active_responders' => $activeResponders,
            'unread_messages' => $totalUnread
        ],
        'current_user' => [
            'id' => $currentUserId,
            'name' => (string)($user['name'] ?? 'User'),
            'role' => strtolower((string)($user['role'] ?? 'unknown'))
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load threads']);
}
