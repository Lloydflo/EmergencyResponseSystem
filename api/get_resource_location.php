<?php
// Get resource location; currently supports vehicles (units)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }
try {
    $stmt = $pdo->prepare('SELECT latitude, longitude FROM units WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
    $lng = isset($row['longitude']) ? (float)$row['longitude'] : null;
    echo json_encode(['ok'=>true,'latitude'=>$lat,'longitude'=>$lng]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Query failed']);
}
