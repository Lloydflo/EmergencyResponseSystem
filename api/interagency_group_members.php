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

try {
    ensure_interagency_group_tables($pdo);

    $user = get_logged_in_user();
    $currentUserId = (int)($user['id'] ?? 0);
    $groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;

    if ($currentUserId <= 0 || $groupId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid group']);
        exit;
    }

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

    $membersStmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.role, u.status,
                gm.created_at AS joined_at,
                gm.is_active AS member_active
         FROM interagency_group_members gm
         INNER JOIN users u ON u.id = gm.user_id
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
        return [
            'id' => $memberId,
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'role' => strtolower((string)$row['role']),
            'status' => strtolower((string)$row['status']),
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
