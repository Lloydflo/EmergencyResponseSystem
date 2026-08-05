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

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$payload = is_array($payload) ? $payload : $_POST;

$targetUserId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing user_id']);
    exit;
}

try {
    ensure_interagency_user_threads_table($pdo);

    $actor = get_logged_in_user();
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if ($targetUserId === $actorId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Cannot create thread with yourself']);
        exit;
    }

    $userCheck = $pdo->prepare("SELECT id, name, role, status FROM users WHERE id = ? LIMIT 1");
    $userCheck->execute([$targetUserId]);
    $target = $userCheck->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        exit;
    }

    $upsert = $pdo->prepare(
        "INSERT INTO interagency_user_thread_pairs (owner_user_id, target_user_id, created_by, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            is_active = 1,
            updated_at = NOW()"
    );
    $upsert->execute([$actorId, $targetUserId, $actorId]);

    echo json_encode([
        'ok' => true,
        'thread' => [
            'id' => 'user-' . $targetUserId,
            'thread_kind' => 'user',
            'user_id' => $targetUserId,
            'title' => (string)$target['name']
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to add user thread']);
}
