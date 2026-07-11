<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/interagency_time.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized', 'notifications' => []]);
    exit;
}

if (current_session_role() !== 'admin') {
    echo json_encode(['ok' => true, 'notifications' => [], 'latest_id' => 0]);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable', 'notifications' => []]);
    exit;
}

interagency_apply_database_timezone($pdo);

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1) {
    $limit = 10;
}
if ($limit > 50) {
    $limit = 50;
}

function interagency_notification_incident_card(string $details): ?array {
    $details = trim($details);
    if ($details === '' || ($details[0] !== '{' && $details[0] !== '[')) {
        return null;
    }

    $decoded = json_decode($details, true);
    if (!is_array($decoded) || !isset($decoded['incident_card']) || !is_array($decoded['incident_card'])) {
        return null;
    }

    $card = $decoded['incident_card'];
    $incidentId = (int)($card['incident_id'] ?? 0);
    $referenceNo = trim((string)($card['reference_no'] ?? ''));
    if ($incidentId <= 0 && $referenceNo === '') {
        return null;
    }

    return [
        'incident_id' => $incidentId,
        'reference_no' => $referenceNo,
        'title' => trim((string)($card['title'] ?? '')),
        'type' => trim((string)($card['type'] ?? '')),
        'location' => trim((string)($card['location'] ?? '')),
        'priority' => trim((string)($card['priority'] ?? '')),
    ];
}

function interagency_notification_external_sender(string $details): string {
    $decoded = json_decode(trim($details), true);
    if (!is_array($decoded)) {
        return '';
    }
    $sender = trim((string)($decoded['external_sender_name'] ?? ''));
    if ($sender !== '') {
        return $sender;
    }
    if (isset($decoded['incident_card']) && is_array($decoded['incident_card'])) {
        return trim((string)($decoded['incident_card']['source_system'] ?? ''));
    }
    return '';
}

function interagency_notification_external_intake_enabled(): bool {
    if (!function_exists('ers_env')) {
        return false;
    }
    $value = strtolower((string)ers_env('ERS_EXTERNAL_INTAKE_ENABLED', ''));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function ensure_interagency_notification_group_tables(PDO $pdo): void {
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

try {
    ensure_interagency_notification_group_tables($pdo);
    $externalIntakeEnabled = interagency_notification_external_intake_enabled();

    $stmt = $pdo->prepare(
        "SELECT
            a.id AS notification_id,
            a.user_id AS sender_id,
            a.entity_type,
            a.entity_id,
            a.details,
            a.created_at AS notified_at,
            COALESCE(NULLIF(sender.name, ''), CONCAT('User #', a.user_id), 'System') AS sender_name,
            COALESCE(NULLIF(target.name, ''), '') AS target_name,
            COALESCE(NULLIF(g.name, ''), '') AS group_name
         FROM activity_log a
         LEFT JOIN users sender ON sender.id = a.user_id
         LEFT JOIN users target
                ON a.entity_type = 'agency_user_chat'
               AND target.id = a.entity_id
         LEFT JOIN interagency_group_threads g
                ON a.entity_type = 'agency_group_chat'
               AND g.id = a.entity_id
         LEFT JOIN interagency_group_members gm
                ON a.entity_type = 'agency_group_chat'
               AND gm.group_id = a.entity_id
               AND gm.user_id = ?
               AND gm.is_active = 1
         WHERE a.action = 'chat'
           AND a.entity_type IN ('agency_chat', 'agency_user_chat', 'agency_group_chat')
           AND a.details LIKE '%\"incident_card\"%'
           AND COALESCE(a.user_id, 0) <> ?
           AND (
                a.entity_type = 'agency_chat'
                OR (a.entity_type = 'agency_user_chat' AND a.entity_id = ?)
                OR (a.entity_type = 'agency_group_chat' AND gm.user_id IS NOT NULL)
           )
         ORDER BY a.id DESC
         LIMIT " . (int)$limit
    );
    $stmt->execute([$currentUserId, $currentUserId, $currentUserId]);

    $notifications = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$externalIntakeEnabled && (int)($row['sender_id'] ?? 0) <= 0) {
            continue;
        }
        $details = (string)($row['details'] ?? '');
        $card = interagency_notification_incident_card($details);
        if ($card === null) {
            continue;
        }
        $senderName = (string)($row['sender_name'] ?? 'System');
        $externalSender = interagency_notification_external_sender($details);
        if ($externalSender !== '') {
            $senderName = $externalSender;
        }

        $threadTitle = 'Interagency';
        if ((string)($row['entity_type'] ?? '') === 'agency_group_chat' && trim((string)($row['group_name'] ?? '')) !== '') {
            $threadTitle = (string)$row['group_name'];
        } elseif ((string)($row['entity_type'] ?? '') === 'agency_user_chat' && trim($senderName) !== '') {
            $threadTitle = $senderName;
        } elseif ((string)($row['entity_type'] ?? '') === 'agency_chat') {
            $threadTitle = 'Department conversation';
        }

        $notifications[] = [
            'notification_id' => (int)($row['notification_id'] ?? 0),
            'notified_at' => interagency_manila_iso((string)($row['notified_at'] ?? '')),
            'sender_id' => isset($row['sender_id']) ? (int)$row['sender_id'] : null,
            'sender_name' => $senderName,
            'thread_title' => $threadTitle,
            'entity_type' => (string)($row['entity_type'] ?? ''),
            'entity_id' => isset($row['entity_id']) ? (int)$row['entity_id'] : null,
            'incident_card' => $card,
        ];
    }

    echo json_encode([
        'ok' => true,
        'notifications' => $notifications,
        'latest_id' => $notifications[0]['notification_id'] ?? 0,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed', 'notifications' => []]);
}
