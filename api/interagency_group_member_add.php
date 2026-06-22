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
$groupId = (int)($payload['group_id'] ?? 0);
$memberId = (int)($payload['user_id'] ?? 0);
$actor = get_logged_in_user();
$actorId = (int)($actor['id'] ?? 0);

if ($groupId <= 0 || $memberId <= 0 || $actorId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid group or member']);
    exit;
}

try {
    ensure_interagency_group_tables($pdo);

    $groupStmt = $pdo->prepare(
        "SELECT id, created_by
         FROM interagency_group_threads
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );
    $groupStmt->execute([$groupId]);
    $group = $groupStmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Group not found']);
        exit;
    }

    if ((int)$group['created_by'] !== $actorId) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the group creator can add members']);
        exit;
    }

    $userStmt = $pdo->prepare(
        "SELECT id, name
         FROM users
         WHERE id = ? AND LOWER(status) = 'active'
         LIMIT 1"
    );
    $userStmt->execute([$memberId]);
    $member = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Select an active user']);
        exit;
    }

    $insertStmt = $pdo->prepare(
        "INSERT INTO interagency_group_members (group_id, user_id, added_by, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            added_by = VALUES(added_by),
            is_active = 1,
            updated_at = NOW()"
    );
    $insertStmt->execute([$groupId, $memberId, $actorId]);

    echo json_encode([
        'ok' => true,
        'group_id' => $groupId,
        'member' => [
            'id' => (int)$member['id'],
            'name' => (string)$member['name'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to add member']);
}
