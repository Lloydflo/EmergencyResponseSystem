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

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function interagency_typing_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_typing_status` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `thread_key` VARCHAR(160) NOT NULL,
            `thread_kind` VARCHAR(32) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `user_name` VARCHAR(150) NOT NULL,
            `is_typing` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_typing_user_thread` (`user_id`, `thread_key`),
            KEY `idx_interagency_typing_thread` (`thread_key`, `is_typing`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function interagency_typing_group_access(PDO $pdo, int $groupId, int $userId): bool
{
    if ($groupId <= 0 || $userId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM interagency_group_threads g
             INNER JOIN interagency_group_members gm
                     ON gm.group_id = g.id
                    AND gm.user_id = ?
                    AND gm.is_active = 1
             WHERE g.id = ?
               AND g.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$userId, $groupId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function interagency_typing_thread_key(PDO $pdo, array $input, int $currentUserId): ?array
{
    $kind = strtolower(trim((string)($input['thread_kind'] ?? $_GET['thread_kind'] ?? 'department')));
    if ($kind === '') {
        $kind = 'department';
    }

    if ($kind === 'group') {
        $groupId = (int)($input['group_id'] ?? $_GET['group_id'] ?? 0);
        if (!interagency_typing_group_access($pdo, $groupId, $currentUserId)) {
            return null;
        }
        return ['kind' => 'group', 'key' => 'group:' . $groupId];
    }

    if ($kind === 'user') {
        $targetUserId = (int)($input['user_id'] ?? $_GET['user_id'] ?? 0);
        if ($targetUserId <= 0 || $targetUserId === $currentUserId) {
            return null;
        }
        $pair = [$currentUserId, $targetUserId];
        sort($pair, SORT_NUMERIC);
        return ['kind' => 'user', 'key' => 'user:' . $pair[0] . ':' . $pair[1]];
    }

    if ($kind === 'external') {
        return null;
    }

    $department = strtolower(trim((string)($input['department'] ?? $_GET['department'] ?? '')));
    $department = preg_replace('/[^a-z0-9_-]+/', '', $department ?? '');
    if ($department === '') {
        return null;
    }
    return ['kind' => 'department', 'key' => 'department:' . $department];
}

try {
    interagency_typing_table($pdo);

    $user = get_logged_in_user();
    $currentUserId = (int)($user['id'] ?? 0);
    $currentUserName = trim((string)($user['name'] ?? 'User'));
    if ($currentUserId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $input = [];
    if ($method === 'POST') {
        $decoded = json_decode(file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : [];
    }

    $thread = interagency_typing_thread_key($pdo, $input, $currentUserId);
    if (!$thread) {
        echo json_encode(['ok' => true, 'typing' => []]);
        exit;
    }

    if ($method === 'POST') {
        $isTyping = !empty($input['is_typing']) ? 1 : 0;
        $stmt = $pdo->prepare(
            "INSERT INTO interagency_typing_status
                (thread_key, thread_kind, user_id, user_name, is_typing, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                user_name = VALUES(user_name),
                is_typing = VALUES(is_typing),
                updated_at = NOW()"
        );
        $stmt->execute([$thread['key'], $thread['kind'], $currentUserId, $currentUserName !== '' ? $currentUserName : 'User', $isTyping]);
        echo json_encode(['ok' => true]);
        exit;
    }

    $cleanup = $pdo->prepare(
        "UPDATE interagency_typing_status
         SET is_typing = 0
         WHERE is_typing = 1
           AND updated_at < (NOW() - INTERVAL 8 SECOND)"
    );
    $cleanup->execute();

    $stmt = $pdo->prepare(
        "SELECT user_id, user_name, updated_at
         FROM interagency_typing_status
         WHERE thread_key = ?
           AND is_typing = 1
           AND user_id <> ?
           AND updated_at >= (NOW() - INTERVAL 8 SECOND)
         ORDER BY updated_at DESC
         LIMIT 5"
    );
    $stmt->execute([$thread['key'], $currentUserId]);
    $typing = array_map(static function (array $row): array {
        return [
            'user_id' => (int)$row['user_id'],
            'name' => (string)$row['user_name'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['ok' => true, 'typing' => $typing]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update typing status']);
}
