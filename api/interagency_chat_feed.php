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

$user = get_logged_in_user();
$currentUserId = (int)($user['id'] ?? 0);
$dept = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
$markRead = isset($_GET['mark_read']) && (string)$_GET['mark_read'] === '1';

try {
    $params = [];
    $sqlBase = "SELECT a.id, a.entity_id, a.details, a.created_at, a.user_id,
                       COALESCE(NULLIF(u.name, ''), 'System') AS sender_name,
                       COALESCE(NULLIF(u.role, ''), 'system') AS sender_role
                FROM activity_log a
                LEFT JOIN users u ON u.id = a.user_id
                WHERE a.entity_type='agency_chat'";

    $eid = null;
    if ($dept !== '' && strtolower($dept) !== 'all') {
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

    $items = array_map(function ($row) use ($currentUserId) {
        $entityId = (int)$row['entity_id'];
        $senderUserId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        return [
            'id' => (int)$row['id'],
            'department' => entity_id_to_dept($entityId),
            'text' => (string)$row['details'],
            'created_at' => (string)$row['created_at'],
            'sender_user_id' => $senderUserId > 0 ? $senderUserId : null,
            'sender_name' => (string)$row['sender_name'],
            'sender_role' => strtolower((string)$row['sender_role']),
            'is_self' => ($senderUserId > 0 && $senderUserId === $currentUserId)
        ];
    }, $rows);

    if ($markRead && $eid !== null && $currentUserId > 0) {
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
