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

try {
    ensure_interagency_user_threads_table($pdo);

    $user = get_logged_in_user();
    $currentUserId = (int)($user['id'] ?? 0);
    $includeInactive = isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1';
    $includeSelf = isset($_GET['include_self']) && (string)$_GET['include_self'] === '1';

    $statusFilter = $includeInactive ? '' : " AND u.status = 'active'";
    $selfFilter = $includeSelf ? '' : ' AND u.id <> ?';
    $params = [$currentUserId];
    if (!$includeSelf) {
        $params[] = $currentUserId;
    }

    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.role, u.status,
                CASE WHEN t.target_user_id IS NULL THEN 0 ELSE 1 END AS has_thread
         FROM users u
         LEFT JOIN interagency_user_thread_pairs t
                ON t.owner_user_id = ? AND t.target_user_id = u.id AND t.is_active = 1
         WHERE 1=1{$selfFilter}{$statusFilter}
         ORDER BY u.name ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'role' => strtolower((string)$row['role']),
            'status' => strtolower((string)$row['status']),
            'has_thread' => ((int)$row['has_thread']) === 1
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load users']);
}
