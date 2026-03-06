<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

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

function ensure_interagency_attachments_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_message_attachments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_url` VARCHAR(500) NOT NULL,
            `file_path` VARCHAR(500) DEFAULT NULL,
            `mime_type` VARCHAR(150) DEFAULT NULL,
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `file_blob` LONGBLOB DEFAULT NULL,
            `is_image` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_interagency_msg_attach_message` (`message_id`),
            KEY `idx_interagency_msg_attach_image` (`is_image`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function app_base_path(): string {
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        return '';
    }
    $dir = rtrim($dir, '/');
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    return rtrim($dir, '/');
}

function normalize_attachment_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url) || strpos($url, '/') === 0) {
        return $url;
    }
    $base = app_base_path();
    return ($base !== '' ? $base : '') . '/' . ltrim($url, '/');
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
                    $url = normalize_attachment_url((string)($a['url'] ?? ''));
                    if ($url === '') continue;
                    $attachments[] = [
                        'name' => trim((string)($a['name'] ?? basename($url))),
                        'url' => $url,
                        'mime_type' => trim((string)($a['mime_type'] ?? '')),
                        'size' => (int)($a['size'] ?? 0),
                        'is_image' => !empty($a['is_image'])
                    ];
                }
            }
        }
    }

    return ['text' => $text, 'attachments' => $attachments];
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
            "SELECT message_id, file_name, file_url, mime_type, file_size, is_image
             FROM interagency_message_attachments
             WHERE message_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $mid = (int)($row['message_id'] ?? 0);
            if ($mid <= 0) continue;
            if (!isset($result[$mid])) {
                $result[$mid] = [];
            }
            $url = normalize_attachment_url((string)($row['file_url'] ?? ''));
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

$user = get_logged_in_user();
$currentUserId = (int)($user['id'] ?? 0);
$dept = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$threadKind = isset($_GET['thread_kind']) ? strtolower(trim((string)$_GET['thread_kind'])) : 'department';
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
$markRead = isset($_GET['mark_read']) && (string)$_GET['mark_read'] === '1';

try {
    $params = [];
    $entityType = 'agency_chat';

    if ($threadKind === 'user') {
        if ($targetUserId <= 0 || $currentUserId <= 0 || $targetUserId === $currentUserId) {
            echo json_encode(['ok' => true, 'items' => [], 'current_user_id' => $currentUserId]);
            exit;
        }
        $entityType = 'agency_user_chat';
    }

    $sqlBase = "SELECT a.id, a.entity_id, a.details, a.created_at, a.user_id,
                       COALESCE(NULLIF(u.name, ''), 'System') AS sender_name,
                       COALESCE(NULLIF(u.role, ''), 'system') AS sender_role
                FROM activity_log a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE a.entity_type=?";
    $params[] = $entityType;

    $eid = null;
    if ($threadKind === 'user') {
        $sqlBase .= " AND ((a.user_id = ? AND a.entity_id = ?) OR (a.user_id = ? AND a.entity_id = ?))";
        $params[] = $currentUserId;
        $params[] = $targetUserId;
        $params[] = $targetUserId;
        $params[] = $currentUserId;
    } elseif ($dept !== '' && strtolower($dept) !== 'all') {
        $eid = dept_to_entity_id($dept);
        if ($eid === null) {
            echo json_encode(['ok' => true, 'items' => [], 'current_user_id' => $currentUserId]);
            exit;
        }
        $sqlBase .= " AND a.entity_id = ?";
        $params[] = $eid;
    }

    if ($sinceId > 0) {
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

    $items = array_map(function ($row) use ($currentUserId, $threadKind, $targetUserId, $attachmentsByMessage) {
        $entityId = (int)$row['entity_id'];
        $messageId = (int)$row['id'];
        $senderUserId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        $parsed = parse_message_details((string)$row['details']);
        $dbAttachments = $attachmentsByMessage[$messageId] ?? [];
        $attachments = count($dbAttachments) > 0 ? $dbAttachments : $parsed['attachments'];
        return [
            'id' => $messageId,
            'department' => $threadKind === 'user' ? 'user' : entity_id_to_dept($entityId),
            'thread_kind' => $threadKind,
            'user_id' => $threadKind === 'user' ? $targetUserId : null,
            'text' => (string)$parsed['text'],
            'attachments' => $attachments,
            'created_at' => (string)$row['created_at'],
            'sender_user_id' => $senderUserId > 0 ? $senderUserId : null,
            'sender_name' => (string)$row['sender_name'],
            'sender_role' => strtolower((string)$row['sender_role']),
            'is_self' => ($senderUserId > 0 && $senderUserId === $currentUserId)
        ];
    }, $rows);

    if ($markRead && $currentUserId > 0 && $threadKind === 'user' && $targetUserId > 0) {
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
    } elseif ($markRead && $eid !== null && $currentUserId > 0) {
        ensure_interagency_reads_table($pdo);
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(id), 0) AS max_id FROM activity_log WHERE entity_type='agency_chat' AND entity_id=?");
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
