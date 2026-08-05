<?php
// API endpoint: /api/activity_feed.php
// Returns recent login/logout activity for admin and operations accounts.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';

$requireApiRoles = static function (array $allowedRoles): array {
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $user = get_logged_in_user() ?? [];
    $role = canonical_role((string)($user['role'] ?? ''));
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $user['canonical_role'] = $role;
    return $user;
};
$requireApiRoles(['admin']);
unset($requireApiRoles);

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$limit = isset($_GET['all']) ? 200 : 20;

try {
    // Explicit columns preserve the previous payload while avoiding SELECT *.
    // The role comparison can use the users.role index on the deployed schema.
    $stmt = $pdo->query(
        "SELECT
            a.id,
            a.user_id,
            a.action,
            a.entity_type,
            a.entity_id,
            a.details,
            a.created_at,
            u.name AS username
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity_type = 'auth'
           AND a.action IN ('login', 'logout')
           AND u.role IN ('admin', 'dispatcher', 'responder', 'operator')
         ORDER BY a.created_at DESC
         LIMIT {$limit}"
    );

    $activity = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    echo json_encode(
        ['ok' => true, 'data' => $activity],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('activity_feed query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
