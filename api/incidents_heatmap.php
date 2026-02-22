<?php
// Returns heatmap points for incidents with coordinates
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$days = isset($_GET['days']) ? max(1, min(365, (int)$_GET['days'])) : 90; // default 90 days
$hours = isset($_GET['hours']) ? max(1, min(720, (int)$_GET['hours'])) : 0; // optional hours window
$priority = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

$where = [
    'i.latitude IS NOT NULL',
    'i.longitude IS NOT NULL'
];
$params = [];
if ($hours > 0) {
    $where[] = 'i.created_at >= (CURRENT_TIMESTAMP - INTERVAL :hours HOUR)';
    $params[':hours'] = $hours;
} else {
    $where[] = 'i.created_at >= (CURRENT_TIMESTAMP - INTERVAL :days DAY)';
    $params[':days'] = $days;
}

if ($type !== '') {
    // Match either exact type or keywords in type/title/description
    $where[] = '(
        i.type = :type OR
        i.type LIKE :typekw OR i.title LIKE :typekw OR i.description LIKE :typekw
    )';
    $params[':type'] = $type;
    $params[':typekw'] = '%' . $type . '%';
}
if ($priority !== '') {
    $where[] = 'i.priority = :priority';
    $params[':priority'] = $priority;
}
if ($status !== '') {
    if (strcasecmp($status, 'active') === 0) {
        $where[] = "i.status IN ('pending','dispatched')";
    } else {
        $where[] = 'i.status = :status';
        $params[':status'] = $status;
    }
}

$sql = 'SELECT i.latitude, i.longitude, i.priority FROM incidents i';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY i.created_at DESC LIMIT 1000';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Map to Leaflet.heat points [lat, lng, intensity]
    $points = [];
    foreach ($rows as $r) {
        $lat = isset($r['latitude']) ? (float)$r['latitude'] : null;
        $lng = isset($r['longitude']) ? (float)$r['longitude'] : null;
        if ($lat === null || $lng === null) { continue; }
        $prio = strtolower((string)($r['priority'] ?? 'medium'));
        $w = $prio === 'high' ? 1.0 : ($prio === 'medium' ? 0.7 : 0.4);
        $points[] = [$lat, $lng, $w];
    }
    echo json_encode(['ok' => true, 'points' => $points]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
