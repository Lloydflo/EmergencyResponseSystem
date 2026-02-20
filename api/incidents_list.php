<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

// Optional filters (client-side filtering also supported)

$priority = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$day = isset($_GET['day']) ? trim((string)$_GET['day']) : ''; // YYYY-MM-DD
$month = isset($_GET['month']) ? trim((string)$_GET['month']) : ''; // YYYY-MM

$sql = 'SELECT i.id, i.reference_no, i.type, i.priority, i.status, i.location_address, i.description, i.created_at,
        COALESCE(i.latitude, c.latitude) AS latitude, COALESCE(i.longitude, c.longitude) AS longitude,
        u.identifier AS unit_identifier, u.unit_type AS unit_type,
        c.caller_name AS caller_name, c.caller_phone AS caller_phone, i.title AS title,
        CASE WHEN ld.assigned_at IS NOT NULL AND ld.on_scene_at IS NOT NULL 
             THEN TIMESTAMPDIFF(MINUTE, ld.assigned_at, ld.on_scene_at) ELSE NULL END AS response_time_min
        FROM incidents i
        LEFT JOIN (
            SELECT d1.incident_id, d1.unit_id, d1.assigned_at, d1.on_scene_at
            FROM dispatches d1
            INNER JOIN (
                SELECT incident_id, MAX(assigned_at) AS max_assigned_at
                FROM dispatches
                GROUP BY incident_id
            ) t ON t.incident_id = d1.incident_id AND t.max_assigned_at = d1.assigned_at
        ) ld ON ld.incident_id = i.id
        LEFT JOIN units u ON u.id = ld.unit_id
        LEFT JOIN calls c ON c.id = i.reported_by_call_id';
$where = [];
$params = [];


if ($priority !== '') {
    $where[] = 'i.priority = :priority';
    $params[':priority'] = $priority;
}
if ($status !== '') {
    // Map status filter to DB values
    if ($status === 'active') {
        $where[] = "(i.status = 'pending' OR i.status = 'dispatched')";
    } elseif ($status === 'dispatched') {
        $where[] = "i.status = 'dispatched'";
    } elseif ($status === 'resolved') {
        $where[] = "(i.status = 'resolved' OR i.status = 'cancelled')";
    }
}
if ($type !== '') {
    $where[] = 'i.type = :type';
    $params[':type'] = $type;
}
if ($search !== '') {
    $where[] = "(
        i.reference_no LIKE :search OR
        i.type LIKE :search OR
        i.location_address LIKE :search OR
        i.description LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}
if ($day !== '') {
    $where[] = 'DATE(i.created_at) = :day';
    $params[':day'] = $day;
}
if ($month !== '') {
    $where[] = 'DATE_FORMAT(i.created_at, "%Y-%m") = :month';
    $params[':month'] = $month;
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY i.created_at DESC LIMIT 200';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Transform to client structure
    $items = array_map(function ($r) {
        return [
            'id' => $r['id'],
            'incident_code' => $r['reference_no'],
            'type' => $r['type'],
            'title' => $r['title'],
            'location' => $r['location_address'],
            'description' => $r['description'],
            'priority' => $r['priority'],
            'status' => $r['status'],
            'created_at' => $r['created_at'],
            'latitude' => isset($r['latitude']) && $r['latitude'] !== null ? (float)$r['latitude'] : null,
            'longitude' => isset($r['longitude']) && $r['longitude'] !== null ? (float)$r['longitude'] : null,
            'assigned_unit' => $r['unit_identifier'] ?? null,
            'assigned_unit_type' => $r['unit_type'] ?? null,
            'caller_name' => $r['caller_name'] ?? null,
            'caller_phone' => $r['caller_phone'] ?? null,
            'response_time_min' => isset($r['response_time_min']) ? (int)$r['response_time_min'] : null,
        ];
    }, $rows);
    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
