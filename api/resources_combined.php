<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';

$requireApiRoles = static function (array $allowedRoles): array {
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $user = get_logged_in_user() ?? [];
    $role = canonical_role((string)($user['role'] ?? ''));
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $user['canonical_role'] = $role;
    return $user;
};
$requireApiRoles(['admin', 'dispatcher']);
unset($requireApiRoles);

require_once __DIR__ . '/../includes/db.php';

const ERS_RESOURCE_RECORDS_TABLE = 'resource_records';
const ERS_LEGACY_ADMIN_RESOURCES_TABLE = 'admin_resources';

/** @return array<string,array<string,true>> */
function ers_resources_schema(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tables = [
        'resource_records',
        'admin_resources',
        'dispatch_operator_records',
        'users',
        'user_presence',
        'incidents',
        'units',
        'staff',
        'resources',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));

    try {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute($tables);
    } catch (Throwable $e) {
        error_log('resources_combined schema lookup failed: ' . $e->getMessage());
        return $cache[$key] = [];
    }

    $schema = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $schema[$table][$column] = true;
        }
    }

    return $cache[$key] = $schema;
}

function ers_resources_table_exists(PDO $pdo, string $tableName): bool
{
    return isset(ers_resources_schema($pdo)[$tableName]);
}

function ers_resources_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    return isset(ers_resources_schema($pdo)[$tableName][$columnName]);
}

function ers_resources_map_unit_status(string $status): string
{
    $status = strtolower(trim($status));
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

function ers_resources_map_staff_status(string $status): string
{
    return in_array(strtolower(trim($status)), ['off_duty', 'leave'], true) ? 'offline' : 'available';
}

function ers_resources_map_equipment_status(string $status): string
{
    $status = strtolower(trim($status));
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

function ers_resources_map_admin_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['in_use', 'maintenance', 'offline'], true) ? $status : 'available';
}

function ers_resources_build_admin_role(array $row): string
{
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
        return $parts !== [] ? implode(' | ', $parts) : 'Vehicle Unit';
    }

    if ($category === 'personnel') {
        $positionTitle = trim((string)($row['position_title'] ?? ''));
        return $positionTitle !== '' ? $positionTitle : 'Personnel';
    }

    $assignment = trim((string)($row['assignment'] ?? ''));
    return $assignment !== '' ? $assignment : 'Equipment';
}

function ers_resources_build_admin_details(array $row): string
{
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

    return $parts !== [] ? implode(' | ', $parts) : 'No details provided';
}

function ers_resources_build_incident_assignment_details(array $row): string
{
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

/** @return list<string> */
function ers_resources_build_admin_actions(string $category): array
{
    if ($category === 'vehicles') {
        return ['deploy', 'track', 'details'];
    }
    if ($category === 'personnel') {
        return ['contact', 'schedule', 'details'];
    }
    return ['assign', 'check', 'details'];
}

/**
 * Read responder presence without calling ensure_user_presence_table(). A GET
 * request must not execute CREATE TABLE or synchronize resource rows.
 *
 * @return array<string,array<string,mixed>>
 */
function ers_resources_load_responder_presence_map(PDO $pdo): array
{
    if (
        !ers_resources_table_exists($pdo, 'users')
        || !ers_resources_column_exists($pdo, 'users', 'id')
        || !ers_resources_column_exists($pdo, 'users', 'role')
        || !ers_resources_column_exists($pdo, 'users', 'unit_code')
    ) {
        return [];
    }

    $nameExpr = ers_resources_column_exists($pdo, 'users', 'name') ? 'u.name' : "''";
    $accountStatusExpr = ers_resources_column_exists($pdo, 'users', 'status') ? 'u.status' : "'active'";
    $unitStatusExpr = ers_resources_column_exists($pdo, 'users', 'unit_status') ? 'u.unit_status' : "''";
    $presenceJoin = '';
    $presenceStatusExpr = "'offline'";

    if (
        ers_resources_table_exists($pdo, 'user_presence')
        && ers_resources_column_exists($pdo, 'user_presence', 'user_id')
        && ers_resources_column_exists($pdo, 'user_presence', 'is_online')
        && ers_resources_column_exists($pdo, 'user_presence', 'last_seen_at')
    ) {
        $presenceJoin = ' LEFT JOIN user_presence up ON up.user_id = u.id';
        $presenceStatusExpr = "CASE
            WHEN up.is_online = 1
             AND up.last_seen_at >= DATE_SUB(NOW(), INTERVAL 180 SECOND)
            THEN 'online'
            ELSE 'offline'
        END";
    }

    try {
        $stmt = $pdo->query(
            "SELECT
                UPPER(TRIM(u.unit_code)) AS unit_code,
                u.id AS responder_id,
                {$nameExpr} AS responder_name,
                COALESCE({$accountStatusExpr}, '') AS account_status,
                COALESCE({$unitStatusExpr}, '') AS unit_status,
                {$presenceStatusExpr} AS presence_status
             FROM users u
             {$presenceJoin}
             WHERE u.role = 'responder'
               AND u.unit_code IS NOT NULL
               AND TRIM(u.unit_code) <> ''
             ORDER BY u.id DESC"
        );
    } catch (Throwable $e) {
        error_log('resources_combined responder presence skipped: ' . $e->getMessage());
        return [];
    }

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unitCode = strtoupper(trim((string)($row['unit_code'] ?? '')));
        if ($unitCode === '' || isset($map[$unitCode])) {
            continue;
        }
        $map[$unitCode] = [
            'responder_id' => (int)($row['responder_id'] ?? 0),
            'account_status' => strtolower(trim((string)($row['account_status'] ?? ''))),
            'unit_status' => strtolower(trim((string)($row['unit_status'] ?? ''))),
            'presence_status' => strtolower(trim((string)($row['presence_status'] ?? 'offline'))) ?: 'offline',
            'responder_name' => trim((string)($row['responder_name'] ?? '')),
        ];
    }

    return $map;
}

function ers_resources_status_from_responder_state(array $state): string
{
    if (strtolower(trim((string)($state['account_status'] ?? ''))) === 'inactive') {
        return 'offline';
    }
    if (strtolower(trim((string)($state['presence_status'] ?? 'offline'))) === 'offline') {
        return 'offline';
    }

    $unitStatus = strtolower(trim((string)($state['unit_status'] ?? '')));
    if (in_array($unitStatus, ['available', 'ready', 'on_duty'], true)) {
        return 'available';
    }
    if (in_array($unitStatus, ['busy', 'in_use', 'assigned', 'accepted', 'acknowledged', 'enroute', 'en_route', 'on_scene'], true)) {
        return 'in_use';
    }
    if ($unitStatus === 'maintenance') {
        return 'maintenance';
    }
    if (in_array($unitStatus, ['offline', 'unavailable', 'out_of_service', 'off_duty', 'leave'], true)) {
        return 'offline';
    }

    return 'available';
}

function ers_resources_apply_presence(array $row, array $presenceMap): array
{
    $category = strtolower(trim((string)($row['category'] ?? '')));
    $code = strtoupper(trim((string)($row['code'] ?? '')));
    if ($category === 'vehicles' && $code !== '' && isset($presenceMap[$code])) {
        $row['status'] = ers_resources_status_from_responder_state($presenceMap[$code]);
    }

    return $row;
}

/** @return array<string,array<string,mixed>> */
function ers_resources_load_active_unit_incident_assignment_map(PDO $pdo): array
{
    if (
        !ers_resources_table_exists($pdo, 'dispatch_operator_records')
        || !ers_resources_column_exists($pdo, 'dispatch_operator_records', 'id')
        || !ers_resources_column_exists($pdo, 'dispatch_operator_records', 'status')
    ) {
        return [];
    }

    $hasUsers = ers_resources_table_exists($pdo, 'users');
    $usersJoin = $hasUsers
        && ers_resources_column_exists($pdo, 'dispatch_operator_records', 'assigned_to')
        && ers_resources_column_exists($pdo, 'users', 'id')
        ? 'LEFT JOIN users u ON u.id = d.assigned_to'
        : '';
    $responderUnitCodeSelect = $usersJoin !== '' && ers_resources_column_exists($pdo, 'users', 'unit_code')
        ? 'u.unit_code'
        : 'NULL';

    $hasIncidentId = ers_resources_column_exists($pdo, 'dispatch_operator_records', 'incident_id');
    $incidentsJoin = $hasIncidentId
        && ers_resources_table_exists($pdo, 'incidents')
        && ers_resources_column_exists($pdo, 'incidents', 'id')
        ? 'LEFT JOIN incidents i ON i.id = d.incident_id'
        : '';

    $incidentIdSelect = $hasIncidentId ? 'd.incident_id' : 'NULL';
    $referenceNoSelect = $incidentsJoin !== '' && ers_resources_column_exists($pdo, 'incidents', 'reference_no') ? 'i.reference_no' : 'NULL';
    $incidentTitleSelect = $incidentsJoin !== '' && ers_resources_column_exists($pdo, 'incidents', 'title') ? 'i.title' : 'NULL';
    $incidentTypeSelect = $incidentsJoin !== '' && ers_resources_column_exists($pdo, 'incidents', 'type') ? 'i.type' : 'NULL';
    $incidentPrioritySelect = $incidentsJoin !== '' && ers_resources_column_exists($pdo, 'incidents', 'priority') ? 'i.priority' : 'NULL';
    $incidentLocationSelect = $incidentsJoin !== '' && ers_resources_column_exists($pdo, 'incidents', 'location_address') ? 'i.location_address' : 'NULL';
    $assignedUnitCodeSelect = ers_resources_column_exists($pdo, 'dispatch_operator_records', 'assigned_unit_code') ? 'd.assigned_unit_code' : 'NULL';
    $dispatchNameSelect = ers_resources_column_exists($pdo, 'dispatch_operator_records', 'name') ? 'd.name' : 'NULL';
    $vehicleSelect = ers_resources_column_exists($pdo, 'dispatch_operator_records', 'vehicle') ? 'd.vehicle' : 'NULL';
    $locationSelect = ers_resources_column_exists($pdo, 'dispatch_operator_records', 'location') ? 'd.location' : 'NULL';
    $prioritySelect = ers_resources_column_exists($pdo, 'dispatch_operator_records', 'priority') ? 'd.priority' : 'NULL';
    $assignedAtOrder = ers_resources_column_exists($pdo, 'dispatch_operator_records', 'assigned_at') ? 'd.assigned_at DESC, ' : '';
    $incidentActiveFilter = $incidentsJoin !== ''
        ? "AND (d.incident_id IS NULL OR d.incident_id = 0 OR i.id IS NULL OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved', 'closed', 'cancelled', 'completed'))"
        : "";

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
             FROM dispatch_operator_records d
             {$usersJoin}
             {$incidentsJoin}
             WHERE LOWER(d.status) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
               {$incidentActiveFilter}
             ORDER BY {$assignedAtOrder}d.id DESC"
        );
    } catch (Throwable $e) {
        error_log('resources_combined active assignment map skipped: ' . $e->getMessage());
        return [];
    }

    $assignments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = ers_resources_build_incident_assignment_details($row);
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

/** @return array<string,array<string,string>> */
function ers_resources_load_user_unit_assignment_map(PDO $pdo): array
{
    if (
        !ers_resources_table_exists($pdo, 'users')
        || !ers_resources_column_exists($pdo, 'users', 'id')
        || !ers_resources_column_exists($pdo, 'users', 'role')
        || !ers_resources_column_exists($pdo, 'users', 'unit_code')
        || !ers_resources_column_exists($pdo, 'users', 'name')
    ) {
        return [];
    }

    try {
        $stmt = $pdo->query(
            "SELECT unit_code, name
             FROM users
             WHERE role = 'responder'
               AND unit_code IS NOT NULL
               AND TRIM(unit_code) <> ''
             ORDER BY id DESC"
        );
    } catch (Throwable $e) {
        error_log('resources_combined user assignment map skipped: ' . $e->getMessage());
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

/** @return list<array<string,mixed>> */
function ers_resources_load_shared_records(PDO $pdo, string $tableName): array
{
    $columnExpr = static function (string $column, string $fallback = 'NULL') use ($pdo, $tableName): string {
        return ers_resources_column_exists($pdo, $tableName, $column) ? "rr.`{$column}`" : $fallback;
    };

    $idExpr = $columnExpr('id', '0');
    $codeExpr = $columnExpr('code', "''");
    $nameExpr = $columnExpr('name', "''");
    $categoryExpr = $columnExpr('category', "'equipment'");
    $statusExpr = $columnExpr('status', "'available'");
    $locationExpr = $columnExpr('location', "''");
    $driverExpr = $columnExpr('driver_name', "''");
    $plateExpr = $columnExpr('plate_number', "''");
    $positionExpr = $columnExpr('position_title', "''");
    $assignmentExpr = $columnExpr('assignment', "''");
    $notesExpr = $columnExpr('notes', "''");
    $updatedExpr = $columnExpr('updated_at', "''");
    $quantityExpr = $columnExpr('quantity', '1');
    $orderBy = ers_resources_column_exists($pdo, $tableName, 'updated_at')
        ? 'rr.updated_at DESC' . (ers_resources_column_exists($pdo, $tableName, 'id') ? ', rr.id DESC' : '')
        : (ers_resources_column_exists($pdo, $tableName, 'id') ? 'rr.id DESC' : '1');

    $stmt = $pdo->query(
        "SELECT
            {$idExpr} AS id,
            {$codeExpr} AS code,
            {$nameExpr} AS name,
            {$categoryExpr} AS category,
            {$statusExpr} AS status,
            {$locationExpr} AS location,
            {$driverExpr} AS driver_name,
            {$plateExpr} AS plate_number,
            {$positionExpr} AS position_title,
            {$assignmentExpr} AS assignment,
            {$notesExpr} AS notes,
            {$updatedExpr} AS updated_at,
            {$quantityExpr} AS quantity
         FROM `{$tableName}` rr
         ORDER BY {$orderBy}"
    );

    $userUnitAssignments = ers_resources_load_user_unit_assignment_map($pdo);
    $activeIncidentAssignments = ers_resources_load_active_unit_incident_assignment_map($pdo);
    $responderPresenceMap = ers_resources_load_responder_presence_map($pdo);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $category = strtolower((string)($row['category'] ?? 'equipment'));
        $code = (string)($row['code'] ?? '');
        $row = ers_resources_apply_presence($row, $responderPresenceMap);

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
            : ers_resources_map_admin_status((string)($row['status'] ?? 'available'));

        $items[] = [
            'type' => $category,
            'code' => $code,
            'name' => (string)($row['name'] ?? ''),
            'identifier' => $category === 'vehicles' ? $code : '',
            'status' => $status,
            'location' => $category === 'vehicles' ? 'Responder GPS' : (string)($row['location'] ?? ''),
            'details' => ers_resources_build_admin_details($row),
            'notes' => (string)($row['notes'] ?? ''),
            'assignment' => (string)($row['assignment'] ?? ''),
            'assignmentDetails' => is_array($incidentAssignment) ? (string)($incidentAssignment['details'] ?? '') : '',
            'assignmentIncidentId' => is_array($incidentAssignment) ? (int)($incidentAssignment['incident_id'] ?? 0) : 0,
            'assignmentIncidentCode' => is_array($incidentAssignment) ? (string)($incidentAssignment['incident_code'] ?? '') : '',
            'quantity' => max(1, (int)($row['quantity'] ?? 1)),
            'driverName' => (string)($row['driver_name'] ?? ''),
            'plateNumber' => (string)($row['plate_number'] ?? ''),
            'positionTitle' => (string)($row['position_title'] ?? ''),
            'role' => ers_resources_build_admin_role($row),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
            'id' => (int)($row['id'] ?? 0),
            'source' => $tableName,
            'actions' => ers_resources_build_admin_actions($category),
        ];
    }

    return $items;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

try {
    $sharedTable = null;
    if (ers_resources_table_exists($pdo, ERS_RESOURCE_RECORDS_TABLE)) {
        $sharedTable = ERS_RESOURCE_RECORDS_TABLE;
    } elseif (ers_resources_table_exists($pdo, ERS_LEGACY_ADMIN_RESOURCES_TABLE)) {
        $sharedTable = ERS_LEGACY_ADMIN_RESOURCES_TABLE;
    }

    if ($sharedTable !== null) {
        echo json_encode(
            ['ok' => true, 'items' => ers_resources_load_shared_records($pdo, $sharedTable)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    // Compatibility fallback for installations that do not yet have the shared
    // resource table.
    $items = [];

    if (
        ers_resources_table_exists($pdo, 'units')
        && ers_resources_column_exists($pdo, 'units', 'id')
        && ers_resources_column_exists($pdo, 'units', 'identifier')
        && ers_resources_column_exists($pdo, 'units', 'status')
    ) {
        $unitTypeExpr = ers_resources_column_exists($pdo, 'units', 'unit_type') ? 'u.unit_type' : "'other'";
        $latExpr = ers_resources_column_exists($pdo, 'units', 'latitude') ? 'u.latitude' : 'NULL';
        $lngExpr = ers_resources_column_exists($pdo, 'units', 'longitude') ? 'u.longitude' : 'NULL';
        $currentIncidentExpr = ers_resources_column_exists($pdo, 'units', 'current_incident_id') ? 'u.current_incident_id' : 'NULL';
        $incidentJoin = '';
        $incidentCodeExpr = 'NULL';
        $incidentTitleExpr = 'NULL';
        $incidentLocationExpr = 'NULL';
        if (
            $currentIncidentExpr !== 'NULL'
            && ers_resources_table_exists($pdo, 'incidents')
            && ers_resources_column_exists($pdo, 'incidents', 'id')
        ) {
            $incidentJoin = ' LEFT JOIN incidents i ON i.id = u.current_incident_id';
            $incidentCodeExpr = ers_resources_column_exists($pdo, 'incidents', 'reference_no') ? 'i.reference_no' : 'NULL';
            $incidentTitleExpr = ers_resources_column_exists($pdo, 'incidents', 'title') ? 'i.title' : 'NULL';
            $incidentLocationExpr = ers_resources_column_exists($pdo, 'incidents', 'location_address') ? 'i.location_address' : 'NULL';
        }

        $sqlUnits = "SELECT
                u.id,
                u.identifier,
                {$unitTypeExpr} AS unit_type,
                u.status,
                {$latExpr} AS latitude,
                {$lngExpr} AS longitude,
                {$currentIncidentExpr} AS current_incident_id,
                {$incidentCodeExpr} AS incident_code,
                {$incidentTitleExpr} AS incident_title,
                {$incidentLocationExpr} AS incident_location
             FROM units u
             {$incidentJoin}
             ORDER BY {$unitTypeExpr}, u.identifier";

        foreach ($pdo->query($sqlUnits)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details = 'Idle';
            $assignmentDetails = '';
            if (!empty($row['current_incident_id'])) {
                $assignmentDetails = ers_resources_build_incident_assignment_details([
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
                'status' => ers_resources_map_unit_status((string)($row['status'] ?? '')),
                'location' => ($row['incident_location'] ?? '') ?: (
                    isset($row['latitude'], $row['longitude'])
                    && $row['latitude'] !== null
                    && $row['longitude'] !== null
                        ? (string)$row['latitude'] . ',' . (string)$row['longitude']
                        : ''
                ),
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
                'actions' => ['deploy', 'track', 'details'],
            ];
        }
    }

    if (
        ers_resources_table_exists($pdo, 'staff')
        && ers_resources_column_exists($pdo, 'staff', 'id')
        && ers_resources_column_exists($pdo, 'staff', 'name')
        && ers_resources_column_exists($pdo, 'staff', 'status')
    ) {
        $staffRoleExpr = ers_resources_column_exists($pdo, 'staff', 'role') ? 's.role' : "''";
        $staffPhoneExpr = ers_resources_column_exists($pdo, 'staff', 'phone') ? 's.phone' : "''";
        $staffEmailExpr = ers_resources_column_exists($pdo, 'staff', 'email') ? 's.email' : "''";
        $assignedResourceIdExpr = ers_resources_column_exists($pdo, 'staff', 'assigned_resource_id') ? 's.assigned_resource_id' : 'NULL';
        $resourceJoin = '';
        $assignedNameExpr = 'NULL';
        $assignedLocationExpr = 'NULL';
        if (
            $assignedResourceIdExpr !== 'NULL'
            && ers_resources_table_exists($pdo, 'resources')
            && ers_resources_column_exists($pdo, 'resources', 'id')
        ) {
            $resourceJoin = ' LEFT JOIN resources r ON r.id = s.assigned_resource_id';
            $assignedNameExpr = ers_resources_column_exists($pdo, 'resources', 'name') ? 'r.name' : 'NULL';
            $assignedLocationExpr = ers_resources_column_exists($pdo, 'resources', 'location') ? 'r.location' : 'NULL';
        }

        $sqlStaff = "SELECT
                s.id,
                s.name,
                {$staffRoleExpr} AS role,
                {$staffPhoneExpr} AS phone,
                {$staffEmailExpr} AS email,
                s.status,
                {$assignedResourceIdExpr} AS assigned_resource_id,
                {$assignedNameExpr} AS assigned_resource_name,
                {$assignedLocationExpr} AS assigned_resource_location
             FROM staff s
             {$resourceJoin}
             ORDER BY s.name";

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
                'status' => ers_resources_map_staff_status((string)($row['status'] ?? '')),
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
                'phone' => (string)($row['phone'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'positionTitle' => '',
                'role' => (string)($row['role'] ?? ''),
                'updatedAt' => '',
                'id' => (int)($row['id'] ?? 0),
                'source' => 'staff',
                'actions' => ['contact', 'schedule', 'details'],
            ];
        }
    }

    if (
        ers_resources_table_exists($pdo, 'resources')
        && ers_resources_column_exists($pdo, 'resources', 'id')
        && ers_resources_column_exists($pdo, 'resources', 'name')
        && ers_resources_column_exists($pdo, 'resources', 'status')
        && ers_resources_column_exists($pdo, 'resources', 'type')
    ) {
        $locationExpr = ers_resources_column_exists($pdo, 'resources', 'location') ? 'location' : "'' AS location";
        $notesExpr = ers_resources_column_exists($pdo, 'resources', 'notes') ? 'notes' : "'' AS notes";
        $sqlEquipment = "SELECT id, name, status, {$locationExpr}, {$notesExpr}
                         FROM resources
                         WHERE type = 'equipment'
                         ORDER BY name";
        foreach ($pdo->query($sqlEquipment)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'type' => 'equipment',
                'code' => 'EQ-' . (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'status' => ers_resources_map_equipment_status((string)($row['status'] ?? '')),
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
                'phone' => '',
                'email' => '',
                'positionTitle' => '',
                'role' => 'Medical Equipment',
                'updatedAt' => '',
                'id' => (int)($row['id'] ?? 0),
                'source' => 'resources',
                'actions' => ['assign', 'check', 'calibrate', 'details'],
            ];
        }
    }

    echo json_encode(
        ['ok' => true, 'items' => $items],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('resources_combined query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
