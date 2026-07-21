<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

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

function table_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$tableName, $columnName]);
    return (bool)$stmt->fetchColumn();
}

function map_unit_status(string $status): string {
    $status = strtolower($status);
    if (in_array($status, ['assigned', 'acknowledged', 'enroute', 'en_route', 'on_scene', 'busy', 'active', 'in_progress'], true)) {
        return 'in_use';
    }
    if ($status === 'maintenance') {
        return 'maintenance';
    }
    if (in_array($status, ['unavailable', 'offline'], true)) {
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
    if (in_array($status, ['deployed', 'in_use', 'assigned'], true)) {
        return 'in_use';
    }
    if ($status === 'maintenance') {
        return 'maintenance';
    }
    if (in_array($status, ['out_of_service', 'offline'], true)) {
        return 'offline';
    }
    return 'available';
}

function map_admin_resource_status(string $status): string {
    $status = strtolower($status);
    if ($status === 'in_use') {
        return 'in_use';
    }
    if ($status === 'maintenance') {
        return 'maintenance';
    }
    if ($status === 'offline') {
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
    $quantity = max(1, (int)($row['quantity'] ?? 1));

    if ($assignment !== '') {
        $parts[] = $assignment;
    }
    if (strtolower((string)($row['category'] ?? '')) === 'equipment') {
        $parts[] = 'Qty: ' . $quantity;
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

function build_incident_assignment_details(array $row): string {
    $incidentId = (int)($row['incident_id'] ?? 0);
    $dispatchId = (int)($row['dispatch_id'] ?? $row['id'] ?? 0);
    $referenceNo = trim((string)($row['reference_no'] ?? ''));
    $title = trim((string)($row['incident_title'] ?? $row['dispatch_name'] ?? ''));
    $type = trim((string)($row['incident_type'] ?? $row['vehicle'] ?? ''));
    $priority = trim((string)($row['incident_priority'] ?? $row['dispatch_priority'] ?? ''));
    $location = trim((string)($row['incident_location'] ?? $row['dispatch_location'] ?? ''));

    if ($referenceNo !== '') {
        $label = 'Incident ' . $referenceNo;
    } elseif ($incidentId > 0) {
        $label = 'Incident #' . $incidentId;
    } elseif ($dispatchId > 0) {
        $label = 'Dispatch #' . $dispatchId;
    } else {
        $label = 'Assigned incident';
    }

    if ($title !== '') {
        $label .= ' - ' . $title;
    }

    $parts = [$label];
    if ($type !== '') {
        $parts[] = 'Type: ' . $type;
    }
    if ($priority !== '') {
        $parts[] = 'Priority: ' . ucfirst(strtolower($priority));
    }
    if ($location !== '') {
        $parts[] = 'Location: ' . $location;
    }

    return implode(' | ', $parts);
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

function load_active_unit_incident_assignment_map(PDO $pdo): array {
    if (
        !table_exists($pdo, 'dispatch_operator_records')
        || !table_column_exists($pdo, 'dispatch_operator_records', 'status')
    ) {
        return [];
    }

    $hasUsers = table_exists($pdo, 'users');
    $usersJoin = $hasUsers && table_column_exists($pdo, 'dispatch_operator_records', 'assigned_to')
        ? 'LEFT JOIN `users` u ON u.id = d.assigned_to'
        : '';
    $responderUnitCodeSelect = $usersJoin !== '' && table_column_exists($pdo, 'users', 'unit_code')
        ? 'u.unit_code'
        : 'NULL';
    $hasIncidents = table_exists($pdo, 'incidents');
    $hasIncidentId = table_column_exists($pdo, 'dispatch_operator_records', 'incident_id');
    $incidentsJoin = $hasIncidents && $hasIncidentId
        ? 'LEFT JOIN `incidents` i ON i.id = d.incident_id'
        : '';
    $incidentIdSelect = $hasIncidentId ? 'd.incident_id' : 'NULL';
    $referenceNoSelect = $incidentsJoin !== '' ? 'i.reference_no' : 'NULL';
    $incidentTitleSelect = $incidentsJoin !== '' ? 'i.title' : 'NULL';
    $incidentTypeSelect = $incidentsJoin !== '' ? 'i.type' : 'NULL';
    $incidentPrioritySelect = $incidentsJoin !== '' ? 'i.priority' : 'NULL';
    $incidentLocationSelect = $incidentsJoin !== '' ? 'i.location_address' : 'NULL';
    $assignedUnitCodeSelect = table_column_exists($pdo, 'dispatch_operator_records', 'assigned_unit_code')
        ? 'd.assigned_unit_code'
        : 'NULL';
    $dispatchNameSelect = table_column_exists($pdo, 'dispatch_operator_records', 'name') ? 'd.name' : 'NULL';
    $vehicleSelect = table_column_exists($pdo, 'dispatch_operator_records', 'vehicle') ? 'd.vehicle' : 'NULL';
    $locationSelect = table_column_exists($pdo, 'dispatch_operator_records', 'location') ? 'd.location' : 'NULL';
    $prioritySelect = table_column_exists($pdo, 'dispatch_operator_records', 'priority') ? 'd.priority' : 'NULL';
    $assignedAtOrder = table_column_exists($pdo, 'dispatch_operator_records', 'assigned_at')
        ? 'd.assigned_at DESC, '
        : '';

    try {
        $stmt = $pdo->query(
            "SELECT
                d.id AS dispatch_id,
                {$incidentIdSelect} AS incident_id,
                {$dispatchNameSelect} AS dispatch_name,
                {$vehicleSelect} AS vehicle,
                {$locationSelect} AS dispatch_location,
                {$prioritySelect} AS dispatch_priority,
                d.status AS dispatch_status,
                {$assignedUnitCodeSelect} AS assigned_unit_code,
                {$responderUnitCodeSelect} AS responder_unit_code,
                {$referenceNoSelect} AS reference_no,
                {$incidentTitleSelect} AS incident_title,
                {$incidentTypeSelect} AS incident_type,
                {$incidentPrioritySelect} AS incident_priority,
                {$incidentLocationSelect} AS incident_location
             FROM `dispatch_operator_records` d
             {$usersJoin}
             {$incidentsJoin}
             WHERE LOWER(d.status) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
             ORDER BY {$assignedAtOrder}d.id DESC"
        );
    } catch (Throwable $e) {
        error_log('resources_combined active unit assignment map skipped: ' . $e->getMessage());
        return [];
    }

    $assignments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = build_incident_assignment_details($row);
        $unitCodes = [
            strtoupper(trim((string)($row['assigned_unit_code'] ?? ''))),
            strtoupper(trim((string)($row['responder_unit_code'] ?? ''))),
        ];

        foreach ($unitCodes as $unitCode) {
            if ($unitCode !== '' && !isset($assignments[$unitCode])) {
                $assignments[$unitCode] = [
                    'details' => $details,
                    'incident_id' => (int)($row['incident_id'] ?? 0),
                    'incident_code' => trim((string)($row['reference_no'] ?? '')),
                ];
            }
        }
    }

    return $assignments;
}

function load_user_unit_assignment_map(PDO $pdo): array {
    if (!table_exists($pdo, 'users')) {
        return [];
    }

    try {
        $stmt = $pdo->query(
            "SELECT unit_code, name
             FROM users
             WHERE LOWER(role) = 'responder'
               AND unit_code IS NOT NULL
               AND TRIM(unit_code) <> ''
             ORDER BY id DESC"
        );
    } catch (Throwable $e) {
        error_log('resources_combined user unit assignment map skipped: ' . $e->getMessage());
        return [];
    }

    $assignments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unitCode = strtoupper(trim((string)($row['unit_code'] ?? '')));
        $name = trim((string)($row['name'] ?? ''));
        if ($unitCode !== '' && $name !== '' && !isset($assignments[$unitCode])) {
            $assignments[$unitCode] = [
                'assignment' => 'Assigned to ' . $name,
                'responder_name' => $name,
            ];
        }
    }

    return $assignments;
}

function load_shared_resource_records(PDO $pdo, string $tableName): array {
    $userUnitAssignments = load_user_unit_assignment_map($pdo);
    $activeIncidentAssignments = load_active_unit_incident_assignment_map($pdo);
    $responderPresenceMap = ers_vehicle_resource_responder_presence_map($pdo);
    $quantitySelect = table_column_exists($pdo, $tableName, 'quantity') ? ', quantity' : ', 1 AS quantity';
    $stmt = $pdo->query(
        "SELECT id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, updated_at" . $quantitySelect . "
         FROM `" . $tableName . "`
         ORDER BY updated_at DESC, id DESC"
    );

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $category = strtolower((string)($row['category'] ?? 'equipment'));
        $code = (string)($row['code'] ?? '');
        $row = ers_apply_responder_presence_to_vehicle_resource_row($row, $responderPresenceMap);
        $userAssignment = $category === 'vehicles'
            ? ($userUnitAssignments[strtoupper(trim($code))] ?? null)
            : null;
        if (is_array($userAssignment)) {
            $row['assignment'] = $userAssignment['assignment'];
            $row['driver_name'] = $userAssignment['responder_name'];
        }
        $incidentAssignment = $category === 'vehicles'
            ? ($activeIncidentAssignments[strtoupper(trim($code))] ?? null)
            : null;
        $status = is_array($incidentAssignment)
            ? 'in_use'
            : map_admin_resource_status((string)($row['status'] ?? 'available'));
        $items[] = [
            'type' => $category,
            'code' => $code,
            'name' => (string)($row['name'] ?? ''),
            'identifier' => $category === 'vehicles' ? $code : '',
            'status' => $status,
            'location' => $category === 'vehicles' ? 'Responder GPS' : (string)($row['location'] ?? ''),
            'details' => build_admin_details($row),
            'notes' => (string)($row['notes'] ?? ''),
            'assignment' => (string)($row['assignment'] ?? ''),
            'assignmentDetails' => is_array($incidentAssignment) ? (string)($incidentAssignment['details'] ?? '') : '',
            'assignmentIncidentId' => is_array($incidentAssignment) ? (int)($incidentAssignment['incident_id'] ?? 0) : 0,
            'assignmentIncidentCode' => is_array($incidentAssignment) ? (string)($incidentAssignment['incident_code'] ?? '') : '',
            'quantity' => max(1, (int)($row['quantity'] ?? 1)),
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
        $assignmentDetails = '';
        if (!empty($row['current_incident_id'])) {
            $assignmentDetails = build_incident_assignment_details([
                'incident_id' => $row['current_incident_id'],
                'reference_no' => $row['incident_code'] ?? '',
                'incident_title' => $row['incident_title'] ?? '',
                'incident_location' => $row['incident_location'] ?? '',
            ]);
            $details = $assignmentDetails;
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
            'assignmentDetails' => $assignmentDetails,
            'assignmentIncidentId' => (int)($row['current_incident_id'] ?? 0),
            'assignmentIncidentCode' => (string)($row['incident_code'] ?? ''),
            'quantity' => 1,
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
            'assignmentDetails' => '',
            'assignmentIncidentId' => 0,
            'assignmentIncidentCode' => '',
            'quantity' => 1,
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
            'assignmentDetails' => '',
            'assignmentIncidentId' => 0,
            'assignmentIncidentCode' => '',
            'quantity' => 1,
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
