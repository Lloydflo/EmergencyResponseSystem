<?php
// Returns units, optional status filters, linked incident data, latest GPS,
// responder presence, and vehicle-resource metadata.
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

    // Read-only AJAX endpoints must not keep the PHP session lock while
    // querying MySQL. Releasing it lets requests from the same tab run safely.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $user['canonical_role'] = $role;
    return $user;
};
$requireApiRoles(['admin', 'dispatcher']);
unset($requireApiRoles);

require_once __DIR__ . '/../includes/db.php';

/**
 * Load the relevant database schema with one INFORMATION_SCHEMA query.
 *
 * @return array<string,array<string,true>>
 */
function ers_units_schema(PDO $pdo): array
{
    $tables = [
        'units',
        'unit_locations',
        'incidents',
        'calls',
        'users',
        'user_presence',
        'responders',
        'resource_records',
        'admin_resources',
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
        error_log('units_list schema lookup failed: ' . $e->getMessage());
        return [];
    }

    $schema = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $schema[$table][$column] = true;
        }
    }

    return $schema;
}

/** @param array<string,array<string,true>> $schema */
function ers_units_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]);
}

/** @param array<string,array<string,true>> $schema */
function ers_units_has_column(array $schema, string $table, string $column): bool
{
    return isset($schema[$table][$column]);
}

function ers_units_query_bool(string $name): bool
{
    if (!isset($_GET[$name])) {
        return false;
    }

    return in_array(strtolower(trim((string)$_GET[$name])), ['1', 'true', 'yes', 'on'], true);
}

/** @param list<string> $expressions */
function ers_units_coalesce(array $expressions): string
{
    $expressions = array_values(array_filter(
        $expressions,
        static fn (string $expression): bool => $expression !== 'NULL'
    ));

    if ($expressions === []) {
        return 'NULL';
    }
    if (count($expressions) === 1) {
        return $expressions[0];
    }

    return 'COALESCE(' . implode(', ', $expressions) . ')';
}

function ers_units_fallback_coordinate_condition(string $alias): string
{
    $safeAlias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'u';

    return "(
        (ABS({$safeAlias}.latitude - 14.7338) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0368) < 0.000001)
        OR (ABS({$safeAlias}.latitude - 14.7295) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0342) < 0.000001)
        OR (ABS({$safeAlias}.latitude - 14.7351) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0380) < 0.000001)
        OR (ABS({$safeAlias}.latitude - 14.7320) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0351) < 0.000001)
    )";
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$schema = ers_units_schema($pdo);
if (!ers_units_has_table($schema, 'units')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Units table unavailable']);
    exit;
}

$status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
$includeUnassigned = ers_units_query_bool('include_unassigned');
$onlyUnassignedResponders = ers_units_query_bool('only_unassigned_responders');

// Dispatcher-facing aliases mapped to persisted unit statuses.
$statuses = [];
if ($status === 'dispatched') {
    $statuses = ['assigned', 'enroute', 'en_route', 'on_scene'];
} elseif ($status === 'available' && $includeUnassigned && $onlyUnassignedResponders) {
    $statuses = ['available', 'unavailable'];
} elseif ($status !== '') {
    $statuses = [$status];
}

// Select the shared resource table without running any synchronization writes.
$vehicleResourceTable = null;
foreach (['resource_records', 'admin_resources'] as $candidate) {
    if (
        ers_units_has_table($schema, $candidate)
        && ers_units_has_column($schema, $candidate, 'code')
        && ers_units_has_column($schema, $candidate, 'category')
    ) {
        $vehicleResourceTable = $candidate;
        break;
    }
}

$hasUnitLatitude = ers_units_has_column($schema, 'units', 'latitude');
$hasUnitLongitude = ers_units_has_column($schema, 'units', 'longitude');
$hasCurrentIncidentId = ers_units_has_column($schema, 'units', 'current_incident_id');

$storedUnitLatRawExpr = $hasUnitLatitude ? 'u.latitude' : 'NULL';
$storedUnitLngRawExpr = $hasUnitLongitude ? 'u.longitude' : 'NULL';
$storedUnitLatExpr = $storedUnitLatRawExpr;
$storedUnitLngExpr = $storedUnitLngRawExpr;
if ($hasUnitLatitude && $hasUnitLongitude) {
    $storedFallback = ers_units_fallback_coordinate_condition('u');
    $storedUnitLatExpr = "CASE WHEN {$storedFallback} THEN NULL ELSE u.latitude END";
    $storedUnitLngExpr = "CASE WHEN {$storedFallback} THEN NULL ELSE u.longitude END";
}

// One latest-location lookup per unit replaces the repeated correlated
// subqueries previously used for every GPS field.
$locationJoin = '';
$latestLocationLatExpr = 'NULL';
$latestLocationLngExpr = 'NULL';
$speedExpr = 'NULL';
$headingExpr = 'NULL';
$accuracyExpr = 'NULL';
$locationSourceExpr = 'NULL';
$lastRecordedExpr = 'NULL';
$canJoinLatestLocation = ers_units_has_table($schema, 'unit_locations')
    && ers_units_has_column($schema, 'unit_locations', 'id')
    && ers_units_has_column($schema, 'unit_locations', 'unit_id')
    && ers_units_has_column($schema, 'unit_locations', 'recorded_at');

if ($canJoinLatestLocation) {
    $validLocationWhere = '';
    if (
        ers_units_has_column($schema, 'unit_locations', 'latitude')
        && ers_units_has_column($schema, 'unit_locations', 'longitude')
    ) {
        $validLocationWhere = ' AND NOT ' . ers_units_fallback_coordinate_condition('ul2');
    }

    $locationJoin = " LEFT JOIN unit_locations ul
        ON ul.id = (
            SELECT ul2.id
            FROM unit_locations ul2
            WHERE ul2.unit_id = u.id
            {$validLocationWhere}
            ORDER BY ul2.recorded_at DESC, ul2.id DESC
            LIMIT 1
        )";

    $latestLocationLatExpr = ers_units_has_column($schema, 'unit_locations', 'latitude') ? 'ul.latitude' : 'NULL';
    $latestLocationLngExpr = ers_units_has_column($schema, 'unit_locations', 'longitude') ? 'ul.longitude' : 'NULL';
    $speedExpr = ers_units_has_column($schema, 'unit_locations', 'speed_kph') ? 'ul.speed_kph' : 'NULL';
    $headingExpr = ers_units_has_column($schema, 'unit_locations', 'heading_deg') ? 'ul.heading_deg' : 'NULL';
    $accuracyExpr = ers_units_has_column($schema, 'unit_locations', 'accuracy_m') ? 'ul.accuracy_m' : 'NULL';
    $locationSourceExpr = ers_units_has_column($schema, 'unit_locations', 'source') ? 'ul.source' : 'NULL';
    $lastRecordedExpr = 'ul.recorded_at';
}

// Build one responder row per normalized unit code, then join presence once.
$responderJoin = '';
$presenceJoin = '';
$responderDriverExpr = 'NULL';
$responderPresenceStatusExpr = "'offline'";
$responderLastSeenExpr = 'NULL';
$responderLoggedInExpr = 'NULL';
$responderUserIdExpr = 'NULL';
$responderUnitStatusExpr = 'NULL';
$hasResponderUserJoin = ers_units_has_table($schema, 'users')
    && ers_units_has_column($schema, 'users', 'id')
    && ers_units_has_column($schema, 'users', 'role')
    && ers_units_has_column($schema, 'users', 'unit_code');

if ($hasResponderUserJoin) {
    $responderSelectColumns = ['usr_base.id', 'usr_base.unit_code'];
    foreach (['name', 'status', 'unit_status'] as $column) {
        if (ers_units_has_column($schema, 'users', $column)) {
            $responderSelectColumns[] = "usr_base.`{$column}`";
        }
    }
    $responderJoin = " LEFT JOIN (
        SELECT " . implode(', ', $responderSelectColumns) . "
        FROM users usr_base
        INNER JOIN (
            SELECT UPPER(TRIM(unit_code)) AS unit_code_key, MAX(id) AS max_id
            FROM users
            WHERE role = 'responder'
              AND unit_code IS NOT NULL
              AND TRIM(unit_code) <> ''
            GROUP BY UPPER(TRIM(unit_code))
        ) latest_usr ON latest_usr.max_id = usr_base.id
    ) usr ON UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))";

    $responderDriverExpr = ers_units_has_column($schema, 'users', 'name')
        ? "NULLIF(TRIM(usr.name), '')"
        : 'NULL';
    $responderUserIdExpr = 'usr.id';
    $responderUnitStatusExpr = ers_units_has_column($schema, 'users', 'unit_status')
        ? 'usr.unit_status'
        : 'NULL';

    $hasPresenceJoin = ers_units_has_table($schema, 'user_presence')
        && ers_units_has_column($schema, 'user_presence', 'user_id');
    if ($hasPresenceJoin) {
        $presenceJoin = ' LEFT JOIN user_presence up ON up.user_id = usr.id';
        $responderLastSeenExpr = ers_units_has_column($schema, 'user_presence', 'last_seen_at')
            ? 'up.last_seen_at'
            : 'NULL';
        $responderLoggedInExpr = ers_units_has_column($schema, 'user_presence', 'logged_in_at')
            ? 'up.logged_in_at'
            : 'NULL';

        $accountInactiveSql = ers_units_has_column($schema, 'users', 'status')
            ? "LOWER(COALESCE(usr.status, '')) <> 'active'"
            : '0 = 1';
        $unitOfflineSql = ers_units_has_column($schema, 'users', 'unit_status')
            ? "LOWER(COALESCE(usr.unit_status, '')) IN ('offline', 'unavailable', 'out_of_service', 'off_duty', 'leave')"
            : '0 = 1';
        $onlineSql = ers_units_has_column($schema, 'user_presence', 'is_online')
            && ers_units_has_column($schema, 'user_presence', 'last_seen_at')
            ? "up.is_online = 1 AND up.last_seen_at >= DATE_SUB(NOW(), INTERVAL 180 SECOND)"
            : '0 = 1';

        $responderPresenceStatusExpr = "CASE
            WHEN usr.id IS NULL THEN 'offline'
            WHEN {$accountInactiveSql} OR {$unitOfflineSql} THEN 'offline'
            WHEN {$onlineSql} THEN 'online'
            ELSE 'offline'
        END";
    }
}

$locationCurrentExpr = '0';
if ($lastRecordedExpr !== 'NULL') {
    $locationCurrentExpr = "CASE
        WHEN {$lastRecordedExpr} IS NOT NULL
         AND ({$responderLoggedInExpr} IS NULL OR {$lastRecordedExpr} >= {$responderLoggedInExpr})
        THEN 1
        ELSE 0
    END";
}

// Resource fields remain compatible with the previous JSON response. The join
// stays INNER when a valid shared vehicle table exists, matching old behavior.
$resourceJoin = '';
$resourceNameExpr = 'NULL';
$resourceLocationExpr = 'NULL';
$plateNumberExpr = 'NULL';
$assignmentExpr = 'NULL';
$driverNameExpr = $responderDriverExpr;
if ($vehicleResourceTable !== null) {
    $resourceJoin = " INNER JOIN `{$vehicleResourceTable}` rr
        ON rr.code = u.identifier
       AND rr.category = 'vehicles'";
    $resourceNameExpr = ers_units_has_column($schema, $vehicleResourceTable, 'name') ? 'rr.name' : 'NULL';
    $resourceLocationExpr = ers_units_has_column($schema, $vehicleResourceTable, 'location') ? 'rr.location' : 'NULL';
    $plateNumberExpr = ers_units_has_column($schema, $vehicleResourceTable, 'plate_number') ? 'rr.plate_number' : 'NULL';
    $assignmentExpr = ers_units_has_column($schema, $vehicleResourceTable, 'assignment') ? 'rr.assignment' : 'NULL';
    if (ers_units_has_column($schema, $vehicleResourceTable, 'driver_name')) {
        $driverNameExpr = "COALESCE(NULLIF(TRIM(rr.driver_name), ''), {$responderDriverExpr})";
    }
}

// Incident/call joins are included only when the required tables and keys exist.
$incidentJoin = '';
$callJoin = '';
$currentIncidentExpr = $hasCurrentIncidentId ? 'u.current_incident_id' : 'NULL';
$incidentCodeExpr = 'NULL';
$incidentTitleExpr = 'NULL';
$incidentTypeExpr = 'NULL';
$incidentLocationExpr = 'NULL';
$incidentLatExpr = 'NULL';
$incidentLngExpr = 'NULL';

$hasIncidentJoin = $hasCurrentIncidentId
    && ers_units_has_table($schema, 'incidents')
    && ers_units_has_column($schema, 'incidents', 'id');
if ($hasIncidentJoin) {
    $incidentJoin = ' LEFT JOIN incidents i ON i.id = u.current_incident_id';
    $incidentCodeExpr = ers_units_has_column($schema, 'incidents', 'reference_no') ? 'i.reference_no' : 'NULL';
    $incidentTitleExpr = ers_units_has_column($schema, 'incidents', 'title') ? 'i.title' : 'NULL';
    $incidentTypeExpr = ers_units_has_column($schema, 'incidents', 'type') ? 'i.type' : 'NULL';
    $incidentLocationExpr = ers_units_has_column($schema, 'incidents', 'location_address') ? 'i.location_address' : 'NULL';
    $incidentLatExpr = ers_units_has_column($schema, 'incidents', 'latitude') ? 'i.latitude' : 'NULL';
    $incidentLngExpr = ers_units_has_column($schema, 'incidents', 'longitude') ? 'i.longitude' : 'NULL';

    $hasCallJoin = ers_units_has_column($schema, 'incidents', 'reported_by_call_id')
        && ers_units_has_table($schema, 'calls')
        && ers_units_has_column($schema, 'calls', 'id');
    if ($hasCallJoin) {
        $callJoin = ' LEFT JOIN calls c ON c.id = i.reported_by_call_id';
        $callLocationExpr = ers_units_has_column($schema, 'calls', 'location_address') ? 'c.location_address' : 'NULL';
        $callLatExpr = ers_units_has_column($schema, 'calls', 'latitude') ? 'c.latitude' : 'NULL';
        $callLngExpr = ers_units_has_column($schema, 'calls', 'longitude') ? 'c.longitude' : 'NULL';
        $incidentLocationExpr = ers_units_coalesce([$incidentLocationExpr, $callLocationExpr]);
        $incidentLatExpr = ers_units_coalesce([$incidentLatExpr, $callLatExpr]);
        $incidentLngExpr = ers_units_coalesce([$incidentLngExpr, $callLngExpr]);
    }
}

$sql = "SELECT
            u.id,
            u.identifier,
            u.unit_type,
            u.status,
            {$resourceNameExpr} AS resource_name,
            {$resourceLocationExpr} AS resource_location,
            {$driverNameExpr} AS driver_name,
            {$plateNumberExpr} AS plate_number,
            {$assignmentExpr} AS assignment,
            {$latestLocationLatExpr} AS latitude,
            {$latestLocationLngExpr} AS longitude,
            {$latestLocationLatExpr} AS latest_latitude,
            {$latestLocationLngExpr} AS latest_longitude,
            {$storedUnitLatExpr} AS stored_latitude,
            {$storedUnitLngExpr} AS stored_longitude,
            {$currentIncidentExpr} AS current_incident_id,
            {$incidentCodeExpr} AS incident_code,
            {$incidentTitleExpr} AS incident_title,
            {$incidentTypeExpr} AS incident_type,
            {$incidentLocationExpr} AS incident_location,
            {$incidentLatExpr} AS incident_latitude,
            {$incidentLngExpr} AS incident_longitude,
            {$speedExpr} AS speed_kph,
            {$headingExpr} AS heading_deg,
            {$accuracyExpr} AS accuracy_m,
            {$locationSourceExpr} AS location_source,
            {$responderPresenceStatusExpr} AS presence_status,
            {$responderLastSeenExpr} AS responder_last_seen_at,
            {$responderLoggedInExpr} AS responder_logged_in_at,
            {$responderUserIdExpr} AS responder_user_id,
            {$responderUnitStatusExpr} AS responder_unit_status,
            {$locationCurrentExpr} AS location_current,
            {$lastRecordedExpr} AS last_recorded_at
        FROM units u
        {$resourceJoin}
        {$locationJoin}
        {$responderJoin}
        {$presenceJoin}
        {$incidentJoin}
        {$callJoin}";

$where = [];
$params = [];
if ($statuses !== []) {
    $where[] = 'u.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
    array_push($params, ...$statuses);
}

if ($onlyUnassignedResponders) {
    if ($hasResponderUserJoin) {
        $where[] = "NOT EXISTS (
            SELECT 1
            FROM users assigned_usr
            WHERE assigned_usr.role = 'responder'
              AND assigned_usr.unit_code IS NOT NULL
              AND TRIM(assigned_usr.unit_code) <> ''
              AND UPPER(TRIM(assigned_usr.unit_code)) = UPPER(TRIM(u.identifier))
        )";
    }

    if (
        ers_units_has_table($schema, 'responders')
        && ers_units_has_column($schema, 'responders', 'assigned_unit_id')
    ) {
        $where[] = "NOT EXISTS (
            SELECT 1
            FROM responders assigned_responder
            WHERE assigned_responder.assigned_unit_id = u.id
        )";
    }
}

if (in_array('available', $statuses, true) && !$includeUnassigned) {
    $where[] = "TRIM(COALESCE({$driverNameExpr}, '')) <> ''";
}

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.unit_type, u.identifier';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(
        ['ok' => true, 'items' => $rows],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('units_list query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
