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

function interagency_group_members_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function interagency_group_members_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function interagency_group_member_status(array $row): string {
    $accountStatus = strtolower(trim((string)($row['status'] ?? '')));
    if ($accountStatus !== 'active') {
        return 'offline';
    }

    $unitStatus = strtolower(trim((string)($row['unit_status'] ?? '')));
    if (in_array($unitStatus, ['offline', 'unavailable', 'out_of_service', 'off_duty', 'leave'], true)) {
        return 'offline';
    }

    $role = strtolower(trim((string)($row['role'] ?? '')));
    if ($role === 'responder') {
        $responderStatus = strtolower(trim((string)($row['responder_status'] ?? '')));
        if (in_array($responderStatus, ['offline', 'unavailable', 'inactive', 'out_of_service', 'off_duty', 'leave'], true)) {
            return 'offline';
        }
        if (array_key_exists('responder_is_active', $row) && $row['responder_is_active'] !== null && (int)$row['responder_is_active'] !== 1) {
            return 'offline';
        }
    }

    $presenceStatus = strtolower(trim((string)($row['presence_status'] ?? 'offline')));
    if ($presenceStatus !== 'online') {
        return 'offline';
    }

    if (in_array($unitStatus, ['busy', 'in_use', 'assigned', 'acknowledged', 'enroute', 'en_route', 'on_scene', 'active', 'in_progress', 'dispatched'], true)) {
        return 'busy';
    }

    return 'available';
}

try {
    ensure_interagency_group_tables($pdo);
    ensure_user_presence_table($pdo);

    $user = get_logged_in_user();
    $currentUserId = (int)($user['id'] ?? 0);
    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

    if ($currentUserId <= 0 || $groupId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid group']);
        exit;
    }
    touch_user_presence($pdo, $currentUserId);

    $accessStmt = $pdo->prepare(
        "SELECT g.id, g.name, g.created_by,
                creator.name AS creator_name,
                creator.email AS creator_email,
                creator.role AS creator_role,
                creator.status AS creator_status
         FROM interagency_group_threads g
         LEFT JOIN users creator ON creator.id = g.created_by
         INNER JOIN interagency_group_members gm
                 ON gm.group_id = g.id
                AND gm.user_id = ?
                AND gm.is_active = 1
         WHERE g.id = ?
           AND g.is_active = 1
         LIMIT 1"
    );
    $accessStmt->execute([$currentUserId, $groupId]);
    $group = $accessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Group not found or access denied']);
        exit;
    }

    $presenceStatusExpr = user_presence_status_sql('up');
    $responderJoin = interagency_group_members_table_exists($pdo, 'responders')
        ? 'LEFT JOIN responders r ON LOWER(TRIM(r.email)) = LOWER(TRIM(u.email))'
        : '';
    $responderStatusSelect = $responderJoin !== '' && interagency_group_members_column_exists($pdo, 'responders', 'status')
        ? 'r.status AS responder_status'
        : 'NULL AS responder_status';
    $responderActiveSelect = $responderJoin !== '' && interagency_group_members_column_exists($pdo, 'responders', 'is_active')
        ? 'r.is_active AS responder_is_active'
        : 'NULL AS responder_is_active';
    $membersStmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.role, u.status, u.unit_status,
                {$responderStatusSelect},
                {$responderActiveSelect},
                {$presenceStatusExpr} AS presence_status,
                up.last_seen_at,
                gm.created_at AS joined_at,
                gm.is_active AS member_active
         FROM interagency_group_members gm
         INNER JOIN users u ON u.id = gm.user_id
         LEFT JOIN user_presence up ON up.user_id = u.id
         {$responderJoin}
         WHERE gm.group_id = ?
           AND gm.is_active = 1
         ORDER BY u.name ASC"
    );
    $membersStmt->execute([$groupId]);
    $rows = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

    $creatorId = (int)($group['created_by'] ?? 0);
    $canManage = $creatorId > 0 && $creatorId === $currentUserId;

    $members = array_map(static function (array $row) use ($creatorId, $canManage): array {
        $memberId = (int)$row['id'];
        $isCreator = $memberId === $creatorId;
        $availabilityStatus = interagency_group_member_status($row);
        return [
            'id' => $memberId,
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'role' => strtolower((string)$row['role']),
            'status' => $availabilityStatus,
            'account_status' => strtolower((string)$row['status']),
            'presence_status' => strtolower((string)($row['presence_status'] ?? 'offline')),
            'responder_status' => strtolower((string)($row['responder_status'] ?? '')),
            'responder_is_active' => $row['responder_is_active'] !== null ? (int)$row['responder_is_active'] : null,
            'availability_status' => $availabilityStatus,
            'user_status' => $availabilityStatus,
            'unit_status' => strtolower((string)($row['unit_status'] ?? '')),
            'last_seen_at' => $row['last_seen_at'] !== null ? (string)$row['last_seen_at'] : null,
            'joined_at' => (string)$row['joined_at'],
            'member_active' => ((int)$row['member_active']) === 1,
            'is_creator' => $isCreator,
            'can_remove' => $canManage && !$isCreator
        ];
    }, $rows);

    echo json_encode([
        'ok' => true,
        'group' => [
            'id' => (int)$group['id'],
            'name' => (string)$group['name'],
            'created_by' => $creatorId,
            'creator' => [
                'id' => $creatorId,
                'name' => (string)($group['creator_name'] ?? ''),
                'email' => (string)($group['creator_email'] ?? ''),
                'role' => strtolower((string)($group['creator_role'] ?? '')),
                'status' => strtolower((string)($group['creator_status'] ?? ''))
            ],
            'can_manage' => $canManage
        ],
        'members' => $members,
        'count' => count($members)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load group members']);
}
