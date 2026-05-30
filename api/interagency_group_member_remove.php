<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
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

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$payload = is_array($payload) ? $payload : $_POST;

$groupId = isset($payload['group_id']) ? (int)$payload['group_id'] : 0;
$memberId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
$actor = get_logged_in_user();
$actorId = (int)($actor['id'] ?? 0);

if ($actorId <= 0 || $groupId <= 0 || $memberId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid member removal request']);
    exit;
}

try {
    ensure_interagency_group_tables($pdo);

    $groupStmt = $pdo->prepare(
        "SELECT id, name, created_by
         FROM interagency_group_threads
         WHERE id = ?
           AND is_active = 1
         LIMIT 1"
    );
    $groupStmt->execute([$groupId]);
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Group not found']);
        exit;
    }

    $creatorId = (int)$group['created_by'];
    if ($creatorId !== $actorId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the group creator can remove members']);
        exit;
    }

    if ($memberId === $creatorId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'The group creator cannot be removed']);
        exit;
    }

    $memberStmt = $pdo->prepare(
        "SELECT gm.user_id, u.name
         FROM interagency_group_members gm
         INNER JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ?
           AND gm.user_id = ?
           AND gm.is_active = 1
         LIMIT 1"
    );
    $memberStmt->execute([$groupId, $memberId]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Member not found in this group']);
        exit;
    }

    $updateStmt = $pdo->prepare(
        "UPDATE interagency_group_members
         SET is_active = 0,
             updated_at = NOW()
         WHERE group_id = ?
           AND user_id = ?
           AND is_active = 1"
    );
    $updateStmt->execute([$groupId, $memberId]);

    echo json_encode([
        'ok' => true,
        'removed_user_id' => $memberId,
        'removed_name' => (string)$member['name']
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to remove member']);
}
