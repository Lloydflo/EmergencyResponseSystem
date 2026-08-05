<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');
$pdo = db();

$responderId = op_query_int('responder_id');
$limit = max(1, min(200, op_query_int('limit', 50)));
op_require_positive($responderId, 'responder_id');
op_require_active_responder($pdo, $responderId);

$summaryStatement = $pdo->prepare(
    'SELECT COUNT(*) AS total_routes, '
    . 'COALESCE(SUM(total_distance_meters), 0) AS total_distance_meters, '
    . 'COALESCE(AVG(duration_seconds), 0) AS avg_duration_seconds, '
    . 'COALESCE(AVG(total_distance_meters), 0) AS avg_distance_meters, '
    . 'COALESCE(AVG(average_speed_kmh), 0) AS avg_speed_kmh, '
    . 'COALESCE(MAX(max_speed_kmh), 0) AS max_speed_kmh '
    . 'FROM responder_route_summary WHERE responder_id = ?'
);
$summaryStatement->execute([$responderId]);
$summary = op_fetch_one($summaryStatement) ?? [];

$sql =
    'SELECT rs.id, '
    . 'CASE WHEN d.incident_id IS NOT NULL THEN d.incident_id ELSE i.id END AS real_incident_id, '
    . 'COALESCE(di.reference_no, i.reference_no, CONCAT(\'Route-\', rs.incident_id)) AS reference_no, '
    . 'COALESCE(di.type, i.type, d.name, \'general\') AS incident_type, '
    . 'COALESCE(di.location_address, i.location_address, d.location, \'\') AS location_address, '
    . 'rs.started_at, rs.arrived_at, rs.duration_seconds, rs.total_points, '
    . 'rs.total_distance_meters, rs.average_speed_kmh, rs.max_speed_kmh '
    . 'FROM responder_route_summary rs '
    . 'LEFT JOIN dispatch_operator_records d '
    . '  ON d.id = rs.incident_id AND d.assigned_to = rs.responder_id '
    . 'LEFT JOIN incidents di ON di.id = d.incident_id '
    . 'LEFT JOIN incidents i ON i.id = rs.incident_id '
    . 'WHERE rs.responder_id = ? '
    . 'ORDER BY COALESCE(rs.arrived_at, rs.completed_at, rs.started_at, rs.created_at) DESC, rs.id DESC '
    . 'LIMIT ' . $limit;
$statement = $pdo->prepare($sql);
$statement->execute([$responderId]);
$rows = op_fetch_all($statement);

$routes = array_map(static function (array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'incident_id' => (int)($row['real_incident_id'] ?? 0),
        'reference_no' => (string)($row['reference_no'] ?? ''),
        'incident_type' => (string)($row['incident_type'] ?? 'general'),
        'location_address' => (string)($row['location_address'] ?? ''),
        'started_at' => (string)($row['started_at'] ?? ''),
        'arrived_at' => (string)($row['arrived_at'] ?? ''),
        'duration_seconds' => (int)($row['duration_seconds'] ?? 0),
        'total_points' => (int)($row['total_points'] ?? 0),
        'total_distance_meters' => (float)($row['total_distance_meters'] ?? 0),
        'average_speed_kmh' => (float)($row['average_speed_kmh'] ?? 0),
        'max_speed_kmh' => (float)($row['max_speed_kmh'] ?? 0),
    ];
}, $rows);

op_success([
    'summary' => [
        'total_routes' => (int)($summary['total_routes'] ?? 0),
        'total_distance_meters' => (float)($summary['total_distance_meters'] ?? 0),
        'avg_duration_seconds' => (float)($summary['avg_duration_seconds'] ?? 0),
        'avg_distance_meters' => (float)($summary['avg_distance_meters'] ?? 0),
        'avg_speed_kmh' => (float)($summary['avg_speed_kmh'] ?? 0),
        'max_speed_kmh' => (float)($summary['max_speed_kmh'] ?? 0),
    ],
    'routes' => $routes,
    'count' => count($routes),
]);
