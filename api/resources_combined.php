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

function map_unit_status(string $s): string {
    $s = strtolower($s);
    if (in_array($s, ['assigned','enroute','on_scene'], true)) return 'inuse';
    if (in_array($s, ['unavailable','maintenance'], true)) return 'offline';
    return 'available';
}
function map_staff_status(string $s): string {
    $s = strtolower($s);
    if (in_array($s, ['off_duty','leave'], true)) return 'offline';
    // on_duty and available are considered available in this table context
    return 'available';
}
function map_equipment_status(string $s): string {
    $s = strtolower($s);
    if (in_array($s, ['deployed'], true)) return 'inuse';
    if (in_array($s, ['maintenance','out_of_service'], true)) return 'offline';
    return 'available';
}

try {
    $items = [];
    // Vehicles (Units)
    $sqlU = 'SELECT u.id, u.identifier, u.unit_type, u.status, u.latitude, u.longitude, u.current_incident_id,
                    i.reference_no AS incident_code, i.title AS incident_title, i.location_address AS incident_location
             FROM units u
             LEFT JOIN incidents i ON i.id = u.current_incident_id
             ORDER BY u.unit_type, u.identifier';
    foreach ($pdo->query($sqlU)->fetchAll() as $r) {
        $statusLabel = map_unit_status((string)$r['status']);
        $details = '';
        if (!empty($r['current_incident_id'])) {
            $details = 'Assigned to ' . ((string)$r['incident_code'] ?: 'incident') .
                       (isset($r['incident_title']) && $r['incident_title'] ? ' — ' . (string)$r['incident_title'] : '') .
                       (isset($r['incident_location']) && $r['incident_location'] ? '<br>Loc: ' . (string)$r['incident_location'] : '');
        } else {
            $details = 'Idle';
        }
        $items[] = [
            'type' => 'vehicles',
            'name' => (string)$r['identifier'],
            'identifier' => (string)$r['identifier'],
            'status' => $statusLabel,
            'location' => ($r['incident_location'] ?? '') ?: ((isset($r['latitude']) && isset($r['longitude']) && $r['latitude'] !== null && $r['longitude'] !== null) ? (string)$r['latitude'] . ',' . (string)$r['longitude'] : ''),
            'details' => $details,
            'role' => ucfirst((string)$r['unit_type']),
            'id' => (int)$r['id'],
            'actions' => ['deploy','track','details']
        ];
    }

    // Personnel (Staff)
    $sqlS = 'SELECT s.id, s.name, s.role, s.status, s.assigned_resource_id, r.name AS assigned_resource_name, r.location AS assigned_resource_location
             FROM staff s
             LEFT JOIN resources r ON r.id = s.assigned_resource_id
             ORDER BY s.name';
    foreach ($pdo->query($sqlS)->fetchAll() as $r) {
        $statusLabel = map_staff_status((string)$r['status']);
        $details = '';
        if (!empty($r['assigned_resource_id'])) {
            $details = 'Assigned to ' . ((string)$r['assigned_resource_name'] ?: 'resource') .
                       (isset($r['assigned_resource_location']) && $r['assigned_resource_location'] ? '<br>Loc: ' . (string)$r['assigned_resource_location'] : '');
        } else {
            $details = 'Not assigned';
        }
        $items[] = [
            'type' => 'personnel',
            'name' => (string)$r['name'],
            'status' => $statusLabel,
            'location' => (string)($r['assigned_resource_location'] ?? ''),
            'details' => $details,
            'role' => (string)($r['role'] ?? ''),
            'id' => (int)$r['id'],
            'actions' => ['contact','schedule','details']
        ];
    }

    // Equipment (Resources table)
    $sqlE = "SELECT id, name, status, location, notes FROM resources WHERE type = 'equipment' ORDER BY name";
    foreach ($pdo->query($sqlE)->fetchAll() as $r) {
        $statusLabel = map_equipment_status((string)$r['status']);
        $details = (string)($r['notes'] ?? '');
        $items[] = [
            'type' => 'equipment',
            'name' => (string)$r['name'],
            'status' => $statusLabel,
            'location' => (string)($r['location'] ?? ''),
            'details' => $details ?: '—',
            'role' => 'Medical Equipment',
            'id' => (int)$r['id'],
            'actions' => ['assign','check','calibrate','details']
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
