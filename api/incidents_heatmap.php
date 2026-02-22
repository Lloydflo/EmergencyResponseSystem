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
$days = isset($_GET['days']) ? max(1, min(3650, (int)$_GET['days'])) : 90; // default 90 days
$hours = isset($_GET['hours']) ? max(1, min(720, (int)$_GET['hours'])) : 0; // optional hours window
$priority = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$all = isset($_GET['all']) ? strtolower(trim((string)$_GET['all'])) : '';
$includeAll = in_array($all, ['1', 'true', 'yes', 'all'], true);

$params = [];
$incWhere = [
    'COALESCE(i.latitude, c.latitude) IS NOT NULL',
    'COALESCE(i.longitude, c.longitude) IS NOT NULL'
];
$callsWhere = [
    'c.latitude IS NOT NULL',
    'c.longitude IS NOT NULL',
    'i2.id IS NULL'
];

if (!$includeAll) {
    if ($hours > 0) {
        $incWhere[] = 'i.created_at >= (CURRENT_TIMESTAMP - INTERVAL :hours HOUR)';
        $callsWhere[] = 'c.created_at >= (CURRENT_TIMESTAMP - INTERVAL :hours HOUR)';
        $params[':hours'] = $hours;
    } else {
        $incWhere[] = 'i.created_at >= (CURRENT_TIMESTAMP - INTERVAL :days DAY)';
        $callsWhere[] = 'c.created_at >= (CURRENT_TIMESTAMP - INTERVAL :days DAY)';
        $params[':days'] = $days;
    }
}

if ($type !== '') {
    // Match either exact type or keywords for both incidents and calls.
    $incWhere[] = '(
        i.type = :type OR
        i.type LIKE :typekw OR i.title LIKE :typekw OR i.description LIKE :typekw
    )';
    $callsWhere[] = '(
        c.incident_type = :type OR
        c.incident_type LIKE :typekw OR c.description LIKE :typekw OR c.location_address LIKE :typekw
    )';
    $params[':type'] = $type;
    $params[':typekw'] = '%' . $type . '%';
}
if ($priority !== '') {
    $incWhere[] = 'COALESCE(i.priority, c.priority) = :priority';
    $callsWhere[] = 'c.priority = :priority';
    $params[':priority'] = $priority;
}

$includeCallsFallback = true;
if ($status !== '') {
    if (strcasecmp($status, 'active') === 0) {
        $incWhere[] = "i.status IN ('pending','dispatched')";
        // Calls table uses different status domain; treat new/triaged as active.
        $callsWhere[] = "c.status IN ('new','triaged')";
    } else {
        $incWhere[] = 'i.status = :status';
        $params[':status'] = $status;
        // Calls fallback does not map 1:1 with incidents status.
        $includeCallsFallback = false;
    }
}

$incSql = 'SELECT
    COALESCE(i.latitude, c.latitude) AS latitude,
    COALESCE(i.longitude, c.longitude) AS longitude,
    COALESCE(i.priority, c.priority, \'medium\') AS priority,
    i.created_at AS created_at
FROM incidents i
LEFT JOIN calls c ON c.id = i.reported_by_call_id';
if ($incWhere) {
    $incSql .= ' WHERE ' . implode(' AND ', $incWhere);
}

$sql = $incSql;
if ($includeCallsFallback) {
    $callsSql = 'SELECT
        c.latitude AS latitude,
        c.longitude AS longitude,
        COALESCE(c.priority, \'medium\') AS priority,
        c.created_at AS created_at
    FROM calls c
    LEFT JOIN incidents i2 ON i2.reported_by_call_id = c.id';
    if ($callsWhere) {
        $callsSql .= ' WHERE ' . implode(' AND ', $callsWhere);
    }
    $sql = $incSql . ' UNION ALL ' . $callsSql;
}

$sql = 'SELECT latitude, longitude, priority, created_at
        FROM (' . $sql . ') heat_points
        ORDER BY created_at DESC
        LIMIT 5000';

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
    echo json_encode(['ok' => true, 'points' => $points, 'count' => count($points)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
