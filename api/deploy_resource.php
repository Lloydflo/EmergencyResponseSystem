<?php
// Deploy resource: vehicles -> units.status=inuse (assigned), personnel -> staff.status=on_duty, equipment -> resources.status=deployed
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }
$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : 0;
$type = isset($input['type']) ? strtolower(trim((string)$input['type'])) : '';
if (!$id || $type==='') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing id/type']); exit; }
try {
    if ($type === 'vehicles') {
        $stmt = $pdo->prepare("UPDATE units SET status = 'assigned', last_status_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($type === 'personnel') {
        $stmt = $pdo->prepare("UPDATE staff SET status = 'on_duty', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($type === 'equipment') {
        $stmt = $pdo->prepare("UPDATE resources SET status = 'deployed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Unsupported type']);
        exit;
    }
    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Update failed']);
}
