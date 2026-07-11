<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
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

function interagency_users_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function interagency_users_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function interagency_users_active_assignment_map(PDO $pdo): array {
    if (
        !interagency_users_table_exists($pdo, 'dispatch_operator_records')
        || !interagency_users_column_exists($pdo, 'dispatch_operator_records', 'assigned_to')
        || !interagency_users_column_exists($pdo, 'dispatch_operator_records', 'status')
    ) {
        return [];
    }

    $activeStatuses = [
        'pending',
        'assigned',
        'acknowledged',
        'received',
        'accepted',
        'enroute',
        'en_route',
        'on_scene',
        'busy',
        'in_use',
    ];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $stmt = $pdo->prepare(
        "SELECT assigned_to
         FROM dispatch_operator_records
         WHERE assigned_to IS NOT NULL
           AND assigned_to > 0
           AND LOWER(status) IN ({$placeholders})
         GROUP BY assigned_to"
    );
    $stmt->execute($activeStatuses);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $map[(int)$userId] = true;
    }
    return $map;
}

function interagency_users_availability_status(array $row, array $activeAssignments): string {
    $accountStatus = strtolower(trim((string)($row['status'] ?? '')));
    if ($accountStatus !== 'active') {
        return 'offline';
    }

    $presenceStatus = strtolower(trim((string)($row['presence_status'] ?? 'offline')));
    if ($presenceStatus !== 'online') {
        return 'offline';
    }

    $userId = (int)($row['id'] ?? 0);
    if ($userId > 0 && !empty($activeAssignments[$userId])) {
        return 'responding';
    }

    $unitStatus = strtolower(trim((string)($row['unit_status'] ?? '')));
    if (in_array($unitStatus, ['busy', 'in_use', 'assigned', 'acknowledged', 'enroute', 'en_route', 'on_scene', 'active', 'in_progress', 'dispatched'], true)) {
        return 'busy';
    }

    return 'available';
}

try {
    ensure_interagency_user_threads_table($pdo);
    ensure_user_presence_table($pdo);

    $user = get_logged_in_user();
    $currentUserId = (int)($user['id'] ?? 0);
    touch_user_presence($pdo, $currentUserId);
    $includeInactive = isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1';
    $includeSelf = isset($_GET['include_self']) && (string)$_GET['include_self'] === '1';
    $presenceStatusExpr = user_presence_status_sql('up');
    $unitStatusSelect = interagency_users_column_exists($pdo, 'users', 'unit_status')
        ? 'u.unit_status'
        : 'NULL AS unit_status';
    $activeAssignments = interagency_users_active_assignment_map($pdo);

    $statusFilter = $includeInactive ? '' : " AND u.status = 'active'";
    $selfFilter = $includeSelf ? '' : ' AND u.id <> ?';
    $params = [$currentUserId];
    if (!$includeSelf) {
        $params[] = $currentUserId;
    }

    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.role, u.status, {$unitStatusSelect},
                {$presenceStatusExpr} AS presence_status,
                up.last_seen_at,
                CASE WHEN t.target_user_id IS NULL THEN 0 ELSE 1 END AS has_thread
         FROM users u
         LEFT JOIN interagency_user_thread_pairs t
                ON t.owner_user_id = ? AND t.target_user_id = u.id AND t.is_active = 1
         LEFT JOIN user_presence up
                ON up.user_id = u.id
         WHERE 1=1{$selfFilter}{$statusFilter}
         ORDER BY u.name ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(static function (array $row) use ($activeAssignments): array {
        $availabilityStatus = interagency_users_availability_status($row, $activeAssignments);
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'role' => strtolower((string)$row['role']),
            'status' => strtolower((string)$row['status']),
            'account_status' => strtolower((string)$row['status']),
            'presence_status' => strtolower((string)($row['presence_status'] ?? 'offline')),
            'availability_status' => $availabilityStatus,
            'user_status' => $availabilityStatus,
            'unit_status' => strtolower((string)($row['unit_status'] ?? '')),
            'last_seen_at' => $row['last_seen_at'] !== null ? (string)$row['last_seen_at'] : null,
            'has_thread' => ((int)$row['has_thread']) === 1
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load users']);
}
