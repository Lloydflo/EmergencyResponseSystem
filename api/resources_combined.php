<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

if (!defined('RESOURCE_RECORDS_TABLE')) {
    define('RESOURCE_RECORDS_TABLE', 'resource_records');
}
if (!defined('LEGACY_ADMIN_RESOURCES_TABLE')) {
    define('LEGACY_ADMIN_RESOURCES_TABLE', 'admin_resources');
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function map_unit_status(string $status): string {
    $status = strtolower($status);
    if (in_array($status, ['assigned', 'enroute', 'on_scene'], true)) {
        return 'inuse';
    }
    if (in_array($status, ['unavailable', 'maintenance'], true)) {
        return 'offline';
    }
    return 'available';
}

function map_staff_status(string $status): string {
    $status = strtolower($status);
    if (in_array($status, ['off_duty', 'leave'], true)) {
        return 'offline';
    }
    return 'available';
}

function map_equipment_status(string $status): string {
    $status = strtolower($status);
    if (in_array($status, ['deployed'], true)) {
        return 'inuse';
    }
    if (in_array($status, ['maintenance', 'out_of_service'], true)) {
        return 'offline';
    }
    return 'available';
}

function map_admin_resource_status(string $status): string {
    $status = strtolower($status);
    if ($status === 'in_use') {
        return 'inuse';
    }
    if (in_array($status, ['maintenance', 'offline'], true)) {
        return 'offline';
    }
    return 'available';
}

function build_admin_role(array $row): string {
    $category = strtolower((string)($row['category'] ?? ''));

    if ($category === 'vehicles') {
        $parts = [];
        $plateNumber = trim((string)($row['plate_number'] ?? ''));
        $driverName = trim((string)($row['driver_name'] ?? ''));
        if ($plateNumber !== '') {
            $parts[] = 'Plate: ' . $plateNumber;
        }
        if ($driverName !== '') {
            $parts[] = 'Driver: ' . $driverName;
        }
        return $parts ? implode(' | ', $parts) : 'Vehicle Unit';
    }

    if ($category === 'personnel') {
        $positionTitle = trim((string)($row['position_title'] ?? ''));
        return $positionTitle !== '' ? $positionTitle : 'Personnel';
    }

    $assignment = trim((string)($row['assignment'] ?? ''));
    return $assignment !== '' ? $assignment : 'Equipment';
}

function build_admin_details(array $row): string {
    $parts = [];

    $assignment = trim((string)($row['assignment'] ?? ''));
    $notes = trim((string)($row['notes'] ?? ''));
    $driverName = trim((string)($row['driver_name'] ?? ''));
    $plateNumber = trim((string)($row['plate_number'] ?? ''));
    $positionTitle = trim((string)($row['position_title'] ?? ''));

    if ($assignment !== '') {
        $parts[] = $assignment;
    }
    if ($driverName !== '') {
        $parts[] = 'Driver: ' . $driverName;
    }
    if ($plateNumber !== '') {
        $parts[] = 'Plate: ' . $plateNumber;
    }
    if ($positionTitle !== '') {
        $parts[] = 'Position: ' . $positionTitle;
    }
    if ($notes !== '') {
        $parts[] = $notes;
    }

    return $parts ? implode(' | ', $parts) : 'No details provided';
}

function build_admin_actions(string $category): array {
    if ($category === 'vehicles') {
        return ['deploy', 'track', 'details'];
    }
    if ($category === 'personnel') {
        return ['contact', 'schedule', 'details'];
    }
    return ['assign', 'check', 'details'];
}

function load_shared_resource_records(PDO $pdo, string $tableName): array {
    $stmt = $pdo->query(
        "SELECT id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, updated_at
         FROM `" . $tableName . "`
         ORDER BY updated_at DESC, id DESC"
    );

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $category = strtolower((string)($row['category'] ?? 'equipment'));
        $items[] = [
            'type' => $category,
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'identifier' => $category === 'vehicles' ? (string)($row['code'] ?? '') : '',
            'status' => map_admin_resource_status((string)($row['status'] ?? 'available')),
            'location' => (string)($row['location'] ?? ''),
            'details' => build_admin_details($row),
            'notes' => (string)($row['notes'] ?? ''),
            'assignment' => (string)($row['assignment'] ?? ''),
            'driverName' => (string)($row['driver_name'] ?? ''),
            'plateNumber' => (string)($row['plate_number'] ?? ''),
            'positionTitle' => (string)($row['position_title'] ?? ''),
            'role' => build_admin_role($row),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'id' => (int)($row['id'] ?? 0),
            'source' => $tableName,
            'actions' => build_admin_actions($category)
        ];
    }

    return $items;
}

try {
    $sharedTable = null;
    if (table_exists($pdo, RESOURCE_RECORDS_TABLE)) {
        $sharedTable = RESOURCE_RECORDS_TABLE;
    } elseif (table_exists($pdo, LEGACY_ADMIN_RESOURCES_TABLE)) {
        $sharedTable = LEGACY_ADMIN_RESOURCES_TABLE;
    }

    if ($sharedTable !== null) {
        echo json_encode(['ok' => true, 'items' => load_shared_resource_records($pdo, $sharedTable)]);
        exit;
    }

    $items = [];

    $sqlUnits = 'SELECT u.id, u.identifier, u.unit_type, u.status, u.latitude, u.longitude, u.current_incident_id,
                        i.reference_no AS incident_code, i.title AS incident_title, i.location_address AS incident_location
                 FROM units u
                 LEFT JOIN incidents i ON i.id = u.current_incident_id
                 ORDER BY u.unit_type, u.identifier';
    foreach ($pdo->query($sqlUnits)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = 'Idle';
        if (!empty($row['current_incident_id'])) {
            $details = 'Assigned to ' . ((string)($row['incident_code'] ?? '') ?: 'incident');
            if (!empty($row['incident_title'])) {
                $details .= ' - ' . (string)$row['incident_title'];
            }
            if (!empty($row['incident_location'])) {
                $details .= ' | Loc: ' . (string)$row['incident_location'];
            }
        }

        $items[] = [
            'type' => 'vehicles',
            'code' => (string)($row['identifier'] ?? ''),
            'name' => (string)($row['identifier'] ?? ''),
            'identifier' => (string)($row['identifier'] ?? ''),
            'status' => map_unit_status((string)($row['status'] ?? '')),
            'location' => ($row['incident_location'] ?? '') ?: ((isset($row['latitude'], $row['longitude']) && $row['latitude'] !== null && $row['longitude'] !== null)
                ? (string)$row['latitude'] . ',' . (string)$row['longitude']
                : ''),
            'details' => $details,
            'notes' => '',
            'assignment' => '',
            'driverName' => '',
            'plateNumber' => '',
            'positionTitle' => '',
            'role' => ucfirst((string)($row['unit_type'] ?? '')),
            'updatedAt' => '',
            'id' => (int)($row['id'] ?? 0),
            'source' => 'units',
            'actions' => ['deploy', 'track', 'details']
        ];
    }

    $sqlStaff = 'SELECT s.id, s.name, s.role, s.status, s.assigned_resource_id, r.name AS assigned_resource_name, r.location AS assigned_resource_location
                 FROM staff s
                 LEFT JOIN resources r ON r.id = s.assigned_resource_id
                 ORDER BY s.name';
    foreach ($pdo->query($sqlStaff)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = 'Not assigned';
        if (!empty($row['assigned_resource_id'])) {
            $details = 'Assigned to ' . ((string)($row['assigned_resource_name'] ?? '') ?: 'resource');
            if (!empty($row['assigned_resource_location'])) {
                $details .= ' | Loc: ' . (string)$row['assigned_resource_location'];
            }
        }

        $items[] = [
            'type' => 'personnel',
            'code' => 'STAFF-' . (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'status' => map_staff_status((string)($row['status'] ?? '')),
            'location' => (string)($row['assigned_resource_location'] ?? ''),
            'details' => $details,
            'notes' => '',
            'assignment' => '',
            'driverName' => '',
            'plateNumber' => '',
            'positionTitle' => '',
            'role' => (string)($row['role'] ?? ''),
            'updatedAt' => '',
            'id' => (int)($row['id'] ?? 0),
            'source' => 'staff',
            'actions' => ['contact', 'schedule', 'details']
        ];
    }

    $sqlEquipment = "SELECT id, name, status, location, notes FROM resources WHERE type = 'equipment' ORDER BY name";
    foreach ($pdo->query($sqlEquipment)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'type' => 'equipment',
            'code' => 'EQ-' . (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'status' => map_equipment_status((string)($row['status'] ?? '')),
            'location' => (string)($row['location'] ?? ''),
            'details' => (string)($row['notes'] ?? '') ?: 'No details provided',
            'notes' => (string)($row['notes'] ?? ''),
            'assignment' => '',
            'driverName' => '',
            'plateNumber' => '',
            'positionTitle' => '',
            'role' => 'Medical Equipment',
            'updatedAt' => '',
            'id' => (int)($row['id'] ?? 0),
            'source' => 'resources',
            'actions' => ['assign', 'check', 'calibrate', 'details']
        ];
    }

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
