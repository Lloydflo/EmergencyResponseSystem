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

$threadDefs = [
    1 => [
        'id' => 'police',
        'department' => 'police',
        'title' => 'Police Command Center',
        'kind' => 'department',
        'status' => 'online',
        'icon' => 'fa-shield-halved',
        'tone' => 'police'
    ],
    2 => [
        'id' => 'fire',
        'department' => 'fire',
        'title' => 'Fire Department HQ',
        'kind' => 'department',
        'status' => 'online',
        'icon' => 'fa-fire-extinguisher',
        'tone' => 'fire'
    ],
    3 => [
        'id' => 'medical',
        'department' => 'medical',
        'title' => 'EMS Coordination',
        'kind' => 'department',
        'status' => 'online',
        'icon' => 'fa-truck-medical',
        'tone' => 'medical'
    ],
    4 => [
        'id' => 'coordinator',
        'department' => 'coordinator',
        'title' => 'Operations Coordinator',
        'kind' => 'department',
        'status' => 'online',
        'icon' => 'fa-user-tie',
        'tone' => 'coordinator'
    ],
];

try {
    ensure_interagency_reads_table($pdo);

    $user = get_logged_in_user();
    $currentUserId = (int)($user['id'] ?? 0);

    $threads = [];
    foreach ($threadDefs as $entityId => $def) {
        $threads[$entityId] = array_merge($def, [
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
             SELECT entity_id, MAX(id) AS max_id
             FROM activity_log
             WHERE entity_type='agency_chat' AND entity_id IN (1,2,3,4)
             GROUP BY entity_id
         ) latest ON latest.max_id = a.id"
    );
    $latestRows = $latestStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($latestRows as $row) {
        $eid = (int)$row['entity_id'];
        if (!isset($threads[$eid])) {
            continue;
        }
        $threads[$eid]['last_message_id'] = (int)$row['id'];
        $threads[$eid]['last_text'] = (string)$row['details'];
        $threads[$eid]['last_at'] = (string)$row['created_at'];
        $threads[$eid]['last_sender_name'] = (string)$row['sender_name'];
        $threads[$eid]['last_sender_role'] = strtolower((string)$row['sender_role']);
    }

    $totalStmt = $pdo->query(
        "SELECT entity_id, COUNT(*) AS total_messages
         FROM activity_log
         WHERE entity_type='agency_chat' AND entity_id IN (1,2,3,4)
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
         WHERE user_id = ? AND entity_id IN (1,2,3,4)"
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
         WHERE entity_type='agency_chat' AND entity_id = ? AND id > ?"
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

    $responderStmt = $pdo->query(
        "SELECT COUNT(DISTINCT a.user_id) AS active_responders
         FROM activity_log a
         INNER JOIN users u ON u.id = a.user_id
         WHERE a.entity_type='agency_chat'
           AND a.user_id IS NOT NULL
           AND a.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
           AND LOWER(u.role) <> 'admin'"
    );
    $activeResponders = (int)($responderStmt->fetchColumn() ?: 0);

    echo json_encode([
        'ok' => true,
        'threads' => array_values($threads),
        'stats' => [
            'total_threads' => count($threadDefs),
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
