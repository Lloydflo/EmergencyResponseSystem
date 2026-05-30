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

function ensure_interagency_thread_titles_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_thread_titles` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_user_id` INT UNSIGNED NOT NULL,
            `thread_key` VARCHAR(64) NOT NULL,
            `title` VARCHAR(120) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_thread_title_owner_key` (`owner_user_id`, `thread_key`),
            KEY `idx_interagency_thread_title_owner` (`owner_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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

function normalize_title(string $title): string {
    return trim(preg_replace('/\s+/', ' ', $title));
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

$threadKind = strtolower(trim((string)($payload['thread_kind'] ?? '')));
$title = normalize_title((string)($payload['title'] ?? ''));

if ($title === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Thread name cannot be empty']);
    exit;
}

if (text_len($title) > 120) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Thread name is too long']);
    exit;
}

$actor = get_logged_in_user();
$ownerUserId = (int)($actor['id'] ?? 0);
if ($ownerUserId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    ensure_interagency_thread_titles_table($pdo);
    ensure_interagency_group_tables($pdo);

    $threadKey = '';
    $department = '';
    $targetUserId = 0;
    $groupId = 0;

    if ($threadKind === 'department') {
        $department = strtolower(trim((string)($payload['department'] ?? '')));
        $allowedDepartments = ['police', 'fire', 'medical', 'coordinator'];
        if (!in_array($department, $allowedDepartments, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid department thread']);
            exit;
        }
        $threadKey = 'dept:' . $department;
    } elseif ($threadKind === 'user') {
        $targetUserId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
        if ($targetUserId <= 0 || $targetUserId === $ownerUserId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid user thread']);
            exit;
        }

        $userCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $userCheck->execute([$targetUserId]);
        $exists = $userCheck->fetchColumn();
        if (!$exists) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'User not found']);
            exit;
        }

        $threadKey = 'user:' . $targetUserId;
    } elseif ($threadKind === 'group') {
        $groupId = isset($payload['group_id']) ? (int)$payload['group_id'] : 0;
        if ($groupId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid group thread']);
            exit;
        }

        $memberCheck = $pdo->prepare(
            "SELECT 1
             FROM interagency_group_threads g
             INNER JOIN interagency_group_members gm ON gm.group_id = g.id
             WHERE g.id = ?
               AND g.is_active = 1
               AND gm.user_id = ?
               AND gm.is_active = 1
             LIMIT 1"
        );
        $memberCheck->execute([$groupId, $ownerUserId]);
        if (!$memberCheck->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Group not found']);
            exit;
        }

        $updateGroup = $pdo->prepare("UPDATE interagency_group_threads SET name = ?, updated_at = NOW() WHERE id = ?");
        $updateGroup->execute([$title, $groupId]);
        $threadKey = 'group:' . $groupId;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid thread kind']);
        exit;
    }

    $upsert = $pdo->prepare(
        "INSERT INTO interagency_thread_titles (owner_user_id, thread_key, title, created_at, updated_at)
         VALUES (?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            updated_at = NOW()"
    );
    $upsert->execute([$ownerUserId, $threadKey, $title]);

    echo json_encode([
        'ok' => true,
        'thread_kind' => $threadKind,
        'thread_key' => $threadKey,
        'department' => $threadKind === 'department' ? $department : null,
        'user_id' => $threadKind === 'user' ? $targetUserId : null,
        'group_id' => $threadKind === 'group' ? $groupId : null,
        'title' => $title
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update thread name']);
}
