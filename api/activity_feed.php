<?php
// API endpoint: /api/activity_feed.php
// Returns the 20 most recent activities (all entities)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
$limit = isset($_GET['all']) ? 200 : 20;
// Get recent activity log
$activity = $pdo->query("SELECT a.*, u.name AS username FROM activity_log a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['ok' => true, 'data' => $activity]);
