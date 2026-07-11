<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/media_storage.php';
require_once __DIR__ . '/../includes/interagency_time.php';

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

function dept_to_entity_id(string $dept): ?int {
    switch (strtolower(trim($dept))) {
        case 'police': return 1;
        case 'fire': return 2;
        case 'medical': return 3;
        case 'coordinator': return 4;
        default: return null;
    }
}

function entity_id_to_dept(int $entityId): string {
    switch ($entityId) {
        case 1: return 'police';
        case 2: return 'fire';
        case 3: return 'medical';
        case 4: return 'coordinator';
        default: return 'system';
    }
}

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

function ensure_interagency_solo_chat_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_solo_chat` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activity_log_id` INT NOT NULL,
            `sender_user_id` VARCHAR(255) NOT NULL,
            `recipient_user_id` INT UNSIGNED NOT NULL,
            `message_details` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_solo_chat_activity_log` (`activity_log_id`),
            KEY `idx_interagency_solo_chat_participants` (`sender_user_id`, `recipient_user_id`),
            KEY `idx_interagency_solo_chat_recipient_created` (`recipient_user_id`, `created_at`),
            KEY `idx_interagency_solo_chat_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_external_reads_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_external_thread_reads` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `activity_log_id` INT NOT NULL,
            `last_read_id` INT NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_external_read` (`user_id`, `activity_log_id`),
            KEY `idx_interagency_external_reads_user` (`user_id`),
            KEY `idx_interagency_external_reads_activity` (`activity_log_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_interagency_incident_cards_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_incident_cards` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT NOT NULL,
            `incident_id` INT NOT NULL,
            `reference_no` VARCHAR(120) NOT NULL DEFAULT '',
            `status` ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
            `decided_by` INT UNSIGNED DEFAULT NULL,
            `decided_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_incident_card_message` (`message_id`),
            KEY `idx_interagency_incident_card_incident` (`incident_id`),
            KEY `idx_interagency_incident_card_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function current_user_can_access_group(PDO $pdo, int $groupId, int $userId): bool {
    if ($groupId <= 0 || $userId <= 0) {
        return false;
    }
    ensure_interagency_group_tables($pdo);
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM interagency_group_threads g
         INNER JOIN interagency_group_members gm ON gm.group_id = g.id
         WHERE g.id = ?
           AND g.is_active = 1
           AND gm.user_id = ?
           AND gm.is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$groupId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function parse_message_details(string $raw): array {
    $text = trim($raw);
    $attachments = [];
    $replyTo = null;

    if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
        $decoded = json_decode($text, true);
        if (is_array($decoded) && (isset($decoded['text']) || isset($decoded['attachments']) || isset($decoded['reply_to']) || isset($decoded['incident_card']))) {
            $text = isset($decoded['text']) ? trim((string)$decoded['text']) : '';
            if (isset($decoded['attachments']) && is_array($decoded['attachments'])) {
                foreach ($decoded['attachments'] as $a) {
                    if (!is_array($a)) continue;
                    $url = trim((string)($a['url'] ?? $a['file_url'] ?? ''));
                    if ($url === '') continue;
                    $attachments[] = [
                        'name' => trim((string)($a['name'] ?? $a['file_name'] ?? basename($url))),
                        'url' => preg_match('#^https?://#i', $url) || strpos($url, '/') === 0 ? $url : media_endpoint_url($url),
                        'mime_type' => trim((string)($a['mime_type'] ?? '')),
                        'size' => (int)($a['size'] ?? $a['file_size'] ?? 0),
                        'is_image' => !empty($a['is_image'])
                    ];
                }
            }
            if (isset($decoded['reply_to']) && is_array($decoded['reply_to'])) {
                $replyText = trim((string)($decoded['reply_to']['text'] ?? ''));
                $replyAttachments = (int)($decoded['reply_to']['attachment_count'] ?? 0);
                $replySender = trim((string)($decoded['reply_to']['sender_name'] ?? ''));
                if ($replyText !== '' || $replyAttachments > 0 || $replySender !== '') {
                    $replyTo = [
                        'message_id' => (int)($decoded['reply_to']['message_id'] ?? 0),
                        'sender_name' => $replySender,
                        'text' => $replyText,
                        'attachment_count' => max(0, $replyAttachments),
                    ];
                }
            }
            $incidentCard = null;
            if (isset($decoded['incident_card']) && is_array($decoded['incident_card'])) {
                $card = $decoded['incident_card'];
                $cardIncidentId = (int)($card['incident_id'] ?? 0);
                if ($cardIncidentId > 0 || trim((string)($card['reference_no'] ?? '')) !== '') {
                    $incidentCard = [
                        'incident_id' => $cardIncidentId,
                        'reference_no' => trim((string)($card['reference_no'] ?? '')),
                        'title' => trim((string)($card['title'] ?? '')),
                        'type' => trim((string)($card['type'] ?? '')),
                        'location' => trim((string)($card['location'] ?? '')),
                        'priority' => trim((string)($card['priority'] ?? '')),
                    ];
                }
            }
        }
    }

    return ['text' => $text, 'attachments' => $attachments, 'reply_to' => $replyTo, 'incident_card' => $incidentCard];
}

function load_attachments_by_message(PDO $pdo, array $messageIds): array {
    $result = [];
    if (count($messageIds) === 0) {
        return $result;
    }

    try {
        ensure_interagency_attachments_table($pdo);

        $ids = [];
        foreach ($messageIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if (count($ids) === 0) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, message_id, file_name, file_url, mime_type, file_size, is_image, file_blob
             FROM interagency_message_attachments
             WHERE message_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $attachmentId = (int)($row['id'] ?? 0);
            $mid = (int)($row['message_id'] ?? 0);
            if ($mid <= 0) continue;
            if (!isset($result[$mid])) {
                $result[$mid] = [];
            }
            $hasBlob = !empty($row['file_blob']);
            $url = $hasBlob && $attachmentId > 0
                ? interagency_attachment_url($attachmentId)
                : trim((string)($row['file_url'] ?? ''));
            if ($url !== '' && !preg_match('#^https?://#i', $url) && strpos($url, '/') !== 0) {
                $url = media_endpoint_url($url);
            }
            if ($url === '') continue;
            $result[$mid][] = [
                'name' => trim((string)($row['file_name'] ?? basename($url))),
                'url' => $url,
                'mime_type' => trim((string)($row['mime_type'] ?? '')),
                'size' => (int)($row['file_size'] ?? 0),
                'is_image' => ((int)($row['is_image'] ?? 0) === 1)
            ];
        }
    } catch (Throwable $e) {
        return [];
    }

    return $result;
}

function external_interagency_intake_enabled(): bool {
    if (!function_exists('ers_env')) {
        return false;
    }
    $value = strtolower((string)ers_env('ERS_EXTERNAL_INTAKE_ENABLED', ''));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

$user = get_logged_in_user();
$currentUserId = (int)($user['id'] ?? 0);
$dept = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$threadKind = isset($_GET['thread_kind']) ? strtolower(trim((string)$_GET['thread_kind'])) : 'department';
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$externalMessageId = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
$markRead = isset($_GET['mark_read']) && (string)$_GET['mark_read'] === '1';

try {
    ensure_interagency_group_messages_table($pdo);
    ensure_interagency_solo_chat_table($pdo);
    ensure_interagency_external_reads_table($pdo);
    $params = [];
    $entityType = 'agency_chat';

    if ($threadKind === 'user') {
        if ($targetUserId <= 0 || $currentUserId <= 0 || $targetUserId === $currentUserId) {
            echo json_encode(['ok' => true, 'items' => [], 'current_user_id' => $currentUserId]);
            exit;
        }
        $entityType = 'agency_user_chat';
    } elseif ($threadKind === 'group') {
        if (!current_user_can_access_group($pdo, $groupId, $currentUserId)) {
            echo json_encode(['ok' => true, 'items' => [], 'current_user_id' => $currentUserId]);
            exit;
        }
        $entityType = 'agency_group_chat';
    } elseif ($threadKind === 'external') {
        if (!external_interagency_intake_enabled()) {
            echo json_encode(['ok' => true, 'items' => [], 'current_user_id' => $currentUserId]);
            exit;
        }
        if ($externalMessageId <= 0 || $currentUserId <= 0) {
            echo json_encode(['ok' => true, 'items' => [], 'current_user_id' => $currentUserId]);
            exit;
        }
    }

    if ($threadKind === 'external') {
        $sqlBase = "SELECT a.id, a.entity_id, COALESCE(a.details, s.message_details) AS details,
                           COALESCE(a.created_at, s.created_at) AS created_at,
                           a.user_id,
                           COALESCE(NULLIF(s.sender_user_id, ''), 'External System') AS sender_name,
                           'external' AS sender_role
                    FROM interagency_solo_chat s
                    LEFT JOIN activity_log a ON a.id = s.activity_log_id
                    WHERE s.activity_log_id = ?
                      AND s.recipient_user_id = ?
                      AND COALESCE(a.user_id, 0) = 0";
        $params[] = $externalMessageId;
        $params[] = $currentUserId;
    } else {
        $sqlBase = "SELECT a.id, a.entity_id, a.details, a.created_at, a.user_id,
                           COALESCE(NULLIF(u.name, ''), 'System') AS sender_name,
                           COALESCE(NULLIF(u.role, ''), 'system') AS sender_role
                    FROM activity_log a
                    LEFT JOIN users u ON u.id = a.user_id
                    WHERE ";
    }

    $eid = null;
    if ($threadKind === 'user') {
        $sqlBase .= "a.entity_type = ?";
        $params[] = $entityType;
        $sqlBase .= " AND ((a.user_id = ? AND a.entity_id = ?) OR (a.user_id = ? AND a.entity_id = ?))";
        $params[] = $currentUserId;
        $params[] = $targetUserId;
        $params[] = $targetUserId;
        $params[] = $currentUserId;
    } elseif ($threadKind === 'group') {
        $sqlBase .= "(a.entity_type = ? OR EXISTS (
                         SELECT 1
                         FROM interagency_groups_threads_read legacy
                         WHERE legacy.activity_log_id = a.id
                           AND legacy.group_id = ?
                     ))";
        $params[] = $entityType;
        $params[] = $groupId;
        $sqlBase .= " AND a.entity_id = ?";
        $params[] = $groupId;
    } elseif ($threadKind !== 'external') {
        $sqlBase .= "a.entity_type = ?";
        $params[] = $entityType;
        if ($dept !== '' && strtolower($dept) !== 'all') {
        $eid = dept_to_entity_id($dept);
        if ($eid === null) {
            echo json_encode(['ok' => true, 'items' => [], 'current_user_id' => $currentUserId]);
            exit;
        }
        $sqlBase .= " AND a.entity_id = ?";
        $params[] = $eid;
        }
        $sqlBase .= " AND NOT EXISTS (
                         SELECT 1
                         FROM interagency_groups_threads_read legacy
                         WHERE legacy.activity_log_id = a.id
                     )";
    }

    if ($threadKind === 'external') {
        $sqlBase .= " ORDER BY s.activity_log_id DESC LIMIT 1";
    } elseif ($sinceId > 0) {
        $sqlBase .= " AND a.id > ?";
        $params[] = $sinceId;
        $sqlBase .= " ORDER BY a.id ASC LIMIT ?";
        $params[] = $limit;
    } else {
        $sqlBase .= " ORDER BY a.id DESC LIMIT ?";
        $params[] = $limit;
    }

    $stmt = $pdo->prepare($sqlBase);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $attachmentsByMessage = load_attachments_by_message($pdo, $ids);

    $incidentStatusMap = [];
    try {
        ensure_interagency_incident_cards_table($pdo);
        if (count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT message_id, status, decided_by, decided_at
                 FROM interagency_incident_cards
                 WHERE message_id IN ($ph)"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $incidentStatusMap[(int)$row['message_id']] = [
                    'status' => strtolower(trim((string)($row['status'] ?? 'pending'))),
                    'decided_by' => isset($row['decided_by']) ? (int)$row['decided_by'] : null,
                    'decided_at' => $row['decided_at'] ?? null,
                ];
            }
        }
    } catch (Throwable $e) {
        $incidentStatusMap = [];
    }

    $items = array_map(function ($row) use ($currentUserId, $threadKind, $targetUserId, $groupId, $attachmentsByMessage, $incidentStatusMap) {
        $entityId = (int)$row['entity_id'];
        $messageId = (int)$row['id'];
        $senderUserId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        $parsed = parse_message_details((string)$row['details']);
        $dbAttachments = $attachmentsByMessage[$messageId] ?? [];
        $attachments = count($dbAttachments) > 0 ? $dbAttachments : $parsed['attachments'];
        $incMeta = $incidentStatusMap[$messageId] ?? ['status' => 'pending', 'decided_by' => null, 'decided_at' => null];
        return [
            'id' => $messageId,
            'department' => $threadKind === 'external' ? 'external' : ($threadKind === 'user' ? 'user' : ($threadKind === 'group' ? 'group' : entity_id_to_dept($entityId))),
            'thread_kind' => $threadKind,
            'user_id' => $threadKind === 'user' ? $targetUserId : null,
            'group_id' => $threadKind === 'group' ? $groupId : null,
            'external_message_id' => $threadKind === 'external' ? $messageId : null,
            'text' => (string)$parsed['text'],
            'attachments' => $attachments,
            'reply_to' => $parsed['reply_to'],
            'incident_card' => $parsed['incident_card'],
            'incident_status' => $incMeta['status'],
            'incident_decided_by' => $incMeta['decided_by'],
            'incident_decided_at' => $incMeta['decided_at'],
            'created_at' => interagency_manila_iso((string)$row['created_at']),
            'sender_user_id' => $senderUserId > 0 ? $senderUserId : null,
            'sender_name' => (string)$row['sender_name'],
            'sender_role' => strtolower((string)$row['sender_role']),
            'is_self' => ($senderUserId > 0 && $senderUserId === $currentUserId)
        ];
    }, $rows);

    if ($markRead && $currentUserId > 0 && $threadKind === 'group' && $groupId > 0) {
        ensure_interagency_group_reads_table($pdo);
        $maxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(id), 0) AS max_id
             FROM activity_log
             WHERE entity_id=?
               AND (
                   entity_type='agency_group_chat'
                   OR EXISTS (
                       SELECT 1
                       FROM interagency_groups_threads_read legacy
                       WHERE legacy.activity_log_id = activity_log.id
                         AND legacy.group_id = activity_log.entity_id
                   )
               )"
        );
        $maxStmt->execute([$groupId]);
        $maxId = (int)($maxStmt->fetchColumn() ?: 0);
        if ($maxId > 0) {
            $upsert = $pdo->prepare(
                "INSERT INTO interagency_group_thread_reads (user_id, group_id, last_read_id, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
                    updated_at = NOW()"
            );
            $upsert->execute([$currentUserId, $groupId, $maxId]);
        }
    } elseif ($markRead && $currentUserId > 0 && $threadKind === 'user' && $targetUserId > 0) {
        ensure_interagency_user_reads_table($pdo);
        $maxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(id), 0) AS max_id
             FROM activity_log
             WHERE entity_type='agency_user_chat'
               AND user_id=?
               AND entity_id=?"
        );
        $maxStmt->execute([$targetUserId, $currentUserId]);
        $maxId = (int)($maxStmt->fetchColumn() ?: 0);
        if ($maxId > 0) {
            $upsert = $pdo->prepare(
                "INSERT INTO interagency_user_thread_reads (user_id, target_user_id, last_read_id, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
                    updated_at = NOW()"
            );
            $upsert->execute([$currentUserId, $targetUserId, $maxId]);
        }
    } elseif ($markRead && $currentUserId > 0 && $threadKind === 'external' && $externalMessageId > 0) {
        ensure_interagency_external_reads_table($pdo);
        $upsert = $pdo->prepare(
            "INSERT INTO interagency_external_thread_reads (user_id, activity_log_id, last_read_id, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
                updated_at = NOW()"
        );
        $upsert->execute([$currentUserId, $externalMessageId, $externalMessageId]);
    } elseif ($markRead && $eid !== null && $currentUserId > 0) {
        ensure_interagency_reads_table($pdo);
        $maxStmt = $pdo->prepare(
            "SELECT COALESCE(MAX(id), 0) AS max_id
             FROM activity_log
             WHERE entity_type='agency_chat'
               AND entity_id=?
               AND NOT EXISTS (
                   SELECT 1
                   FROM interagency_groups_threads_read legacy
                   WHERE legacy.activity_log_id = activity_log.id
               )"
        );
        $maxStmt->execute([$eid]);
        $maxId = (int)($maxStmt->fetchColumn() ?: 0);
        if ($maxId > 0) {
            $upsert = $pdo->prepare(
                "INSERT INTO interagency_thread_reads (user_id, entity_id, last_read_id, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
                    updated_at = NOW()"
            );
            $upsert->execute([$currentUserId, $eid, $maxId]);
        }
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'current_user_id' => $currentUserId
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
