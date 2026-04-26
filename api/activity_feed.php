<?php
// API endpoint: /api/activity_feed.php
// Returns recent auth activity for admin and operations accounts
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
$limit = isset($_GET['all']) ? 200 : 20;
// Get recent login/logout activity for admin and operations users
$stmt = $pdo->prepare(
    "SELECT a.*, u.name AS username
     FROM activity_log a
     LEFT JOIN users u ON a.user_id = u.id
     WHERE a.entity_type = 'auth'
       AND a.action IN ('login', 'logout')
       AND LOWER(COALESCE(u.role, '')) IN ('admin', 'dispatcher', 'responder', 'operator')
     ORDER BY a.created_at DESC
     LIMIT " . (int)$limit
);
$stmt->execute();
$activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['ok' => true, 'data' => $activity]);
