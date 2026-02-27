<?php
// Updates a unit's latitude/longitude
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
$latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
$longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;
$speed_kph = isset($input['speed_kph']) ? (float)$input['speed_kph'] : null;
$heading_deg = isset($input['heading_deg']) ? (float)$input['heading_deg'] : null;

if (!$unit_id || $latitude === null || $longitude === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing unit_id or coordinates']);
    exit;
}

try {
    // Insert into unit_locations to track speed/heading; trigger will update units lat/lng
    $stmt = $pdo->prepare('INSERT INTO unit_locations (unit_id, latitude, longitude, speed_kph, heading_deg) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$unit_id, $latitude, $longitude, $speed_kph, $heading_deg]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
