<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$actor = get_logged_in_user();
$role = canonical_role((string)($actor['role'] ?? ''));
if (!in_array($role, ['admin', 'dispatcher'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function ia_command_table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function ia_command_ensure_tables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS interagency_incident_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            agency VARCHAR(80) NOT NULL,
            task VARCHAR(255) NOT NULL,
            status ENUM('pending','in_progress','done','blocked') NOT NULL DEFAULT 'pending',
            assigned_to VARCHAR(150) DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            updated_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ia_tasks_incident (incident_id),
            KEY idx_ia_tasks_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS interagency_incident_broadcasts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            priority ENUM('routine','urgent','critical') NOT NULL DEFAULT 'routine',
            message TEXT NOT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ia_broadcasts_incident (incident_id),
            KEY idx_ia_broadcasts_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS interagency_incident_broadcast_acks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            broadcast_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_ia_broadcast_ack_user (broadcast_id, user_id),
            KEY idx_ia_broadcast_ack_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS interagency_incident_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED DEFAULT NULL,
            actor_name VARCHAR(150) DEFAULT NULL,
            action VARCHAR(80) NOT NULL,
            details TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ia_audit_incident (incident_id),
            KEY idx_ia_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ia_command_clean_text($value, int $max = 255): string {
    $text = trim((string)$value);
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    if (strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function ia_command_actor_id(array $actor): ?int {
    $id = (int)($actor['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function ia_command_actor_name(array $actor): string {
    $name = ia_command_clean_text($actor['name'] ?? 'User', 150);
    return $name !== '' ? $name : 'User';
}

function ia_command_log(PDO $pdo, int $incidentId, array $actor, string $action, string $details = ''): void {
    $stmt = $pdo->prepare(
        "INSERT INTO interagency_incident_audit (incident_id, user_id, actor_name, action, details, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $incidentId,
        ia_command_actor_id($actor),
        ia_command_actor_name($actor),
        ia_command_clean_text($action, 80),
        trim($details)
    ]);
}

function ia_command_fetch_room(PDO $pdo, int $incidentId, array $actor): array {
    $incident = null;
    $stmt = $pdo->prepare(
        "SELECT id, reference_no, type, priority, status, title, description, location_address, latitude, longitude, created_at, updated_at, resolved_at
         FROM incidents
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $incident = [
            'id' => (int)$row['id'],
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'location' => (string)($row['location_address'] ?? ''),
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'resolved_at' => (string)($row['resolved_at'] ?? ''),
        ];
    }

    $tasksStmt = $pdo->prepare(
        "SELECT id, incident_id, agency, task, status, assigned_to, created_at, updated_at
         FROM interagency_incident_tasks
         WHERE incident_id = ?
         ORDER BY FIELD(status, 'pending', 'in_progress', 'blocked', 'done'), updated_at DESC, id DESC"
    );
    $tasksStmt->execute([$incidentId]);
    $tasks = array_map(static function (array $task): array {
        return [
            'id' => (int)$task['id'],
            'incident_id' => (int)$task['incident_id'],
            'agency' => (string)$task['agency'],
            'task' => (string)$task['task'],
            'status' => (string)$task['status'],
            'assigned_to' => (string)($task['assigned_to'] ?? ''),
            'created_at' => (string)$task['created_at'],
            'updated_at' => (string)$task['updated_at'],
        ];
    }, $tasksStmt->fetchAll(PDO::FETCH_ASSOC));

    $userId = ia_command_actor_id($actor) ?? 0;
    $broadcastStmt = $pdo->prepare(
        "SELECT b.id, b.incident_id, b.priority, b.message, b.created_by, b.created_at,
                u.name AS sender_name,
                COUNT(a.id) AS ack_count,
                MAX(CASE WHEN a.user_id = ? THEN 1 ELSE 0 END) AS acknowledged_by_me
         FROM interagency_incident_broadcasts b
         LEFT JOIN users u ON u.id = b.created_by
         LEFT JOIN interagency_incident_broadcast_acks a ON a.broadcast_id = b.id
         WHERE b.incident_id = ?
         GROUP BY b.id, b.incident_id, b.priority, b.message, b.created_by, b.created_at, u.name
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT 50"
    );
    $broadcastStmt->execute([$userId, $incidentId]);
    $broadcasts = array_map(static function (array $broadcast): array {
        return [
            'id' => (int)$broadcast['id'],
            'incident_id' => (int)$broadcast['incident_id'],
            'priority' => (string)$broadcast['priority'],
            'message' => (string)$broadcast['message'],
            'sender_name' => (string)($broadcast['sender_name'] ?? 'System'),
            'created_at' => (string)$broadcast['created_at'],
            'ack_count' => (int)$broadcast['ack_count'],
            'acknowledged_by_me' => (int)$broadcast['acknowledged_by_me'] === 1,
        ];
    }, $broadcastStmt->fetchAll(PDO::FETCH_ASSOC));

    $auditStmt = $pdo->prepare(
        "SELECT id, incident_id, user_id, actor_name, action, details, created_at
         FROM interagency_incident_audit
         WHERE incident_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 80"
    );
    $auditStmt->execute([$incidentId]);
    $audit = array_map(static function (array $entry): array {
        return [
            'id' => (int)$entry['id'],
            'incident_id' => (int)$entry['incident_id'],
            'user_id' => isset($entry['user_id']) ? (int)$entry['user_id'] : null,
            'actor_name' => (string)($entry['actor_name'] ?? 'System'),
            'action' => (string)$entry['action'],
            'details' => (string)($entry['details'] ?? ''),
            'created_at' => (string)$entry['created_at'],
        ];
    }, $auditStmt->fetchAll(PDO::FETCH_ASSOC));

    $summary = [
        'task_total' => count($tasks),
        'task_open' => count(array_filter($tasks, static fn(array $task): bool => $task['status'] !== 'done')),
        'task_done' => count(array_filter($tasks, static fn(array $task): bool => $task['status'] === 'done')),
        'critical_unacked' => count(array_filter($broadcasts, static fn(array $item): bool => $item['priority'] === 'critical' && !$item['acknowledged_by_me'])),
    ];

    return [
        'incident' => $incident,
        'tasks' => $tasks,
        'broadcasts' => $broadcasts,
        'audit' => $audit,
        'summary' => $summary,
    ];
}

ia_command_ensure_tables($pdo);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $incidentId = isset($_GET['incident_id']) ? (int)$_GET['incident_id'] : 0;
        if ($incidentId <= 0) {
            echo json_encode(['ok' => true, 'room' => null]);
            exit;
        }
        echo json_encode(['ok' => true, 'room' => ia_command_fetch_room($pdo, $incidentId, $actor ?? [])]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = ia_command_clean_text($input['action'] ?? '', 80);
    $incidentId = (int)($input['incident_id'] ?? 0);
    if ($incidentId <= 0 && $action !== 'acknowledge') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing incident id']);
        exit;
    }

    if ($action === 'add_task') {
        $agency = ia_command_clean_text($input['agency'] ?? 'Dispatcher', 80);
        $task = ia_command_clean_text($input['task'] ?? '', 255);
        $assignedTo = ia_command_clean_text($input['assigned_to'] ?? '', 150);
        if ($task === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Task is required']);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO interagency_incident_tasks (incident_id, agency, task, assigned_to, created_by, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $actorId = ia_command_actor_id($actor ?? []);
        $stmt->execute([$incidentId, $agency, $task, $assignedTo !== '' ? $assignedTo : null, $actorId, $actorId]);
        ia_command_log($pdo, $incidentId, $actor ?? [], 'task_added', $agency . ': ' . $task);
    } elseif ($action === 'update_task') {
        $taskId = (int)($input['task_id'] ?? 0);
        $status = strtolower(ia_command_clean_text($input['status'] ?? '', 30));
        if ($taskId <= 0 || !in_array($status, ['pending', 'in_progress', 'done', 'blocked'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid task update']);
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE interagency_incident_tasks
             SET status = ?, updated_by = ?, updated_at = NOW()
             WHERE id = ? AND incident_id = ?"
        );
        $stmt->execute([$status, ia_command_actor_id($actor ?? []), $taskId, $incidentId]);
        ia_command_log($pdo, $incidentId, $actor ?? [], 'task_' . $status, 'Task #' . $taskId . ' marked ' . $status);
    } elseif ($action === 'broadcast') {
        $priority = strtolower(ia_command_clean_text($input['priority'] ?? 'routine', 20));
        if (!in_array($priority, ['routine', 'urgent', 'critical'], true)) {
            $priority = 'routine';
        }
        $message = trim((string)($input['message'] ?? ''));
        if ($message === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Broadcast message is required']);
            exit;
        }
        if (strlen($message) > 2000) {
            $message = substr($message, 0, 2000);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO interagency_incident_broadcasts (incident_id, priority, message, created_by, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$incidentId, $priority, $message, ia_command_actor_id($actor ?? [])]);
        ia_command_log($pdo, $incidentId, $actor ?? [], 'broadcast_' . $priority, $message);
    } elseif ($action === 'acknowledge') {
        $broadcastId = (int)($input['broadcast_id'] ?? 0);
        if ($broadcastId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing broadcast id']);
            exit;
        }
        $lookup = $pdo->prepare('SELECT incident_id FROM interagency_incident_broadcasts WHERE id = ? LIMIT 1');
        $lookup->execute([$broadcastId]);
        $incidentId = (int)$lookup->fetchColumn();
        if ($incidentId <= 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Broadcast not found']);
            exit;
        }
        $actorId = ia_command_actor_id($actor ?? []);
        if ($actorId === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing user id']);
            exit;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO interagency_incident_broadcast_acks (broadcast_id, user_id, acknowledged_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE acknowledged_at = VALUES(acknowledged_at)"
        );
        $stmt->execute([$broadcastId, $actorId]);
        ia_command_log($pdo, $incidentId, $actor ?? [], 'broadcast_acknowledged', 'Broadcast #' . $broadcastId . ' acknowledged');
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }

    echo json_encode(['ok' => true, 'room' => ia_command_fetch_room($pdo, $incidentId, $actor ?? [])]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
