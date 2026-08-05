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

function normalize_group_name(string $name): string {
    return trim((string)preg_replace('/\s+/', ' ', $name));
}

function text_len(string $value): int {
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$payload = is_array($payload) ? $payload : $_POST;

$groupName = normalize_group_name((string)($payload['name'] ?? ''));
$rawUserIds = $payload['user_ids'] ?? [];
if (!is_array($rawUserIds)) {
    $rawUserIds = [];
}

if ($groupName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Group name is required']);
    exit;
}

if (text_len($groupName) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Group name is too long']);
    exit;
}

$actor = get_logged_in_user();
$actorId = (int)($actor['id'] ?? 0);
if ($actorId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$memberIds = [$actorId => true];
foreach ($rawUserIds as $rawId) {
    $id = (int)$rawId;
    if ($id > 0) {
        $memberIds[$id] = true;
    }
}
$memberIds = array_keys($memberIds);

if (count($memberIds) < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Select at least two users for a group chat']);
    exit;
}

try {
    ensure_interagency_group_tables($pdo);

    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $userStmt = $pdo->prepare(
        "SELECT id
         FROM users
         WHERE id IN ($placeholders)
           AND LOWER(status) = 'active'"
    );
    $userStmt->execute($memberIds);
    $validIds = [];
    foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $validIds[(int)$row['id']] = true;
    }

    if (!isset($validIds[$actorId])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your account is not active']);
        exit;
    }

    if (count($validIds) < 3) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Select at least two active users for a group chat']);
        exit;
    }

    $pdo->beginTransaction();

    $groupStmt = $pdo->prepare(
        "INSERT INTO interagency_group_threads (name, created_by, is_active, created_at, updated_at)
         VALUES (?, ?, 1, NOW(), NOW())"
    );
    $groupStmt->execute([$groupName, $actorId]);
    $groupId = (int)$pdo->lastInsertId();

    $memberStmt = $pdo->prepare(
        "INSERT INTO interagency_group_members (group_id, user_id, added_by, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            is_active = 1,
            updated_at = NOW()"
    );
    foreach (array_keys($validIds) as $memberId) {
        $memberStmt->execute([$groupId, (int)$memberId, $actorId]);
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'thread' => [
            'id' => 'group-' . $groupId,
            'thread_kind' => 'group',
            'group_id' => $groupId,
            'entity_id' => $groupId,
            'title' => $groupName,
            'member_count' => count($validIds)
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to create group chat']);
}
