<?php
// Returns detailed history for a unit by identifier.
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$identifier = isset($_GET['identifier']) ? trim((string)$_GET['identifier']) : '';
if ($identifier === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing identifier']);
    exit;
}

try {
    $stmtUnit = $pdo->prepare(
        "SELECT u.id, u.identifier, u.unit_type, u.status, u.latitude, u.longitude, u.last_status_at, u.current_incident_id,
                i.reference_no AS incident_code, i.title AS incident_title, i.type AS incident_type, i.priority AS incident_priority,
                i.location_address AS incident_location, i.latitude AS incident_latitude, i.longitude AS incident_longitude
         FROM units u
         LEFT JOIN incidents i ON i.id = u.current_incident_id
         WHERE u.identifier = ?
         LIMIT 1"
    );
    $stmtUnit->execute([$identifier]);
    $unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

    if (!$unit) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Unit not found']);
        exit;
    }

    $unitId = (int)$unit['id'];

    $stmtLatestLocation = $pdo->prepare(
        "SELECT latitude, longitude, speed_kph, heading_deg, recorded_at
         FROM unit_locations
         WHERE unit_id = ?
         ORDER BY recorded_at DESC
         LIMIT 1"
    );
    $stmtLatestLocation->execute([$unitId]);
    $latestLocation = $stmtLatestLocation->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtStats = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_dispatches,
            SUM(CASE WHEN DATE(assigned_at) = CURRENT_DATE THEN 1 ELSE 0 END) AS dispatches_today
         FROM dispatches
         WHERE unit_id = ?"
    );
    $stmtStats->execute([$unitId]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: ['total_dispatches' => 0, 'dispatches_today' => 0];

    $stmtGpsToday = $pdo->prepare(
        "SELECT COUNT(*) AS location_points_today
         FROM unit_locations
         WHERE unit_id = ? AND DATE(recorded_at) = CURRENT_DATE"
    );
    $stmtGpsToday->execute([$unitId]);
    $gpsToday = $stmtGpsToday->fetch(PDO::FETCH_ASSOC);
    $stats['location_points_today'] = isset($gpsToday['location_points_today']) ? (int)$gpsToday['location_points_today'] : 0;

    $stmtRecent = $pdo->prepare(
        "SELECT d.id, d.incident_id, d.status, d.assigned_at, d.acknowledged_at, d.enroute_at, d.on_scene_at, d.cleared_at, d.notes,
                i.reference_no AS incident_code, i.title AS incident_title, i.type AS incident_type, i.location_address AS incident_location
         FROM dispatches d
         LEFT JOIN incidents i ON i.id = d.incident_id
         WHERE d.unit_id = ?
         ORDER BY d.assigned_at DESC
         LIMIT 5"
    );
    $stmtRecent->execute([$unitId]);
    $recentDispatches = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'unit' => $unit,
        'latest_location' => $latestLocation,
        'stats' => $stats,
        'recent_dispatches' => $recentDispatches
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}

