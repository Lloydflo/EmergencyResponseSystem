<?php
// API endpoint: /api/unit_status.php
// Updates the status of a unit (e.g., available, busy, enroute, etc.)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$unit_id = isset($input['unit_id']) ? (int)$input['unit_id'] : 0;
$status = isset($input['status']) ? trim($input['status']) : '';

if (!$unit_id || !$status) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing unit_id or status']);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE units SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $unit_id]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
