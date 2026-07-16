<?php
// Returns list of units, optionally filtered by status; includes linked incident info.
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';
require_once __DIR__ . '/../includes/user_presence.php';

function ers_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$vehicleResourceTable = ers_vehicle_resource_units_table($pdo);
if ($vehicleResourceTable !== null) {
    ers_sync_responder_vehicle_resources($pdo);
    ers_sync_all_vehicle_resource_units($pdo, $vehicleResourceTable);
}

$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$includeUnassigned = isset($_GET['include_unassigned'])
    && in_array(strtolower(trim((string) $_GET['include_unassigned'])), ['1', 'true', 'yes'], true);

// Map dispatcher-facing filter names to actual unit statuses.
$statuses = [];
if ($status === 'dispatched') {
    $statuses = ['assigned', 'enroute', 'en_route', 'on_scene'];
} elseif ($status !== '') {
    $statuses = [$status];
}

$hasUnitsTable = ers_table_exists($pdo, 'units');
if (!$hasUnitsTable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Units table unavailable']);
    exit;
}

$hasIncidentsTable = ers_table_exists($pdo, 'incidents');
$hasCallsTable = ers_table_exists($pdo, 'calls');
$hasUnitLocationsTable = ers_table_exists($pdo, 'unit_locations');
if (ers_table_exists($pdo, 'users')) {
    ensure_user_presence_table($pdo);
}

$hasCurrentIncidentId = ers_column_exists($pdo, 'units', 'current_incident_id');
$hasUnitLatitude = ers_column_exists($pdo, 'units', 'latitude');
$hasUnitLongitude = ers_column_exists($pdo, 'units', 'longitude');
$hasIncidentReportedByCall = $hasIncidentsTable && ers_column_exists($pdo, 'incidents', 'reported_by_call_id');
$hasCallLocationAddress = $hasCallsTable && ers_column_exists($pdo, 'calls', 'location_address');
$hasCallLatitude = $hasCallsTable && ers_column_exists($pdo, 'calls', 'latitude');
$hasCallLongitude = $hasCallsTable && ers_column_exists($pdo, 'calls', 'longitude');
$hasSpeed = $hasUnitLocationsTable && ers_column_exists($pdo, 'unit_locations', 'speed_kph');
$hasHeading = $hasUnitLocationsTable && ers_column_exists($pdo, 'unit_locations', 'heading_deg');
$hasAccuracy = $hasUnitLocationsTable && ers_column_exists($pdo, 'unit_locations', 'accuracy_m');
$hasSource = $hasUnitLocationsTable && ers_column_exists($pdo, 'unit_locations', 'source');
$hasRecordedAt = $hasUnitLocationsTable && ers_column_exists($pdo, 'unit_locations', 'recorded_at');

$unitLatExpr = $hasUnitLatitude ? 'u.latitude' : 'NULL';
$unitLngExpr = $hasUnitLongitude ? 'u.longitude' : 'NULL';
$incidentJoin = ($hasIncidentsTable && $hasCurrentIncidentId)
    ? ' LEFT JOIN incidents i ON i.id = u.current_incident_id'
    : ' LEFT JOIN incidents i ON 1 = 0';
$callJoin = ($hasCallsTable && $hasIncidentReportedByCall)
    ? ' LEFT JOIN calls c ON c.id = i.reported_by_call_id'
    : ' LEFT JOIN calls c ON 1 = 0';
$currentIncidentExpr = $hasCurrentIncidentId ? 'u.current_incident_id' : 'NULL';
$callLocationExpr = $hasCallLocationAddress ? 'c.location_address' : 'NULL';
$callLatExpr = $hasCallLatitude ? 'c.latitude' : 'NULL';
$callLngExpr = $hasCallLongitude ? 'c.longitude' : 'NULL';

$speedExpr = 'NULL';
if ($hasSpeed && $hasRecordedAt) {
    $speedExpr = "(SELECT ul.speed_kph
                   FROM unit_locations ul
                   WHERE ul.unit_id = u.id
                   ORDER BY ul.recorded_at DESC
                   LIMIT 1)";
}

$headingExpr = 'NULL';
if ($hasHeading && $hasRecordedAt) {
    $headingExpr = "(SELECT ul.heading_deg
                     FROM unit_locations ul
                     WHERE ul.unit_id = u.id
                     ORDER BY ul.recorded_at DESC
                     LIMIT 1)";
}

$lastRecordedExpr = 'NULL';
if ($hasRecordedAt) {
    $lastRecordedExpr = "(SELECT ul.recorded_at
                         FROM unit_locations ul
                         WHERE ul.unit_id = u.id
                         ORDER BY ul.recorded_at DESC
                         LIMIT 1)";
}

$accuracyExpr = 'NULL';
if ($hasAccuracy && $hasRecordedAt) {
    $accuracyExpr = "(SELECT ul.accuracy_m
                      FROM unit_locations ul
                      WHERE ul.unit_id = u.id
                      ORDER BY ul.recorded_at DESC
                      LIMIT 1)";
}

$locationSourceExpr = 'NULL';
if ($hasSource && $hasRecordedAt) {
    $locationSourceExpr = "(SELECT ul.source
                           FROM unit_locations ul
                           WHERE ul.unit_id = u.id
                           ORDER BY ul.recorded_at DESC
                           LIMIT 1)";
}

$responderDriverExpr = 'NULL';
$responderPresenceStatusExpr = "'offline'";
$responderLastSeenExpr = 'NULL';
$responderLoggedInExpr = 'NULL';
$responderUserIdExpr = 'NULL';
if (
    ers_table_exists($pdo, 'users') &&
    ers_column_exists($pdo, 'users', 'unit_code') &&
    ers_column_exists($pdo, 'users', 'name') &&
    ers_column_exists($pdo, 'users', 'role')
) {
    $presenceStatusSql = user_presence_status_sql('up');
    $responderDriverExpr = "(SELECT usr.name
                             FROM users usr
                             WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                               AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                               AND TRIM(COALESCE(usr.name, '')) <> ''
                             ORDER BY usr.id DESC
                             LIMIT 1)";
    $responderPresenceStatusExpr = "COALESCE((SELECT {$presenceStatusSql}
                                     FROM users usr
                                     LEFT JOIN user_presence up ON up.user_id = usr.id
                                     WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                       AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                                     ORDER BY usr.id DESC
                                     LIMIT 1), 'offline')";
    $responderLastSeenExpr = "(SELECT up.last_seen_at
                              FROM users usr
                              LEFT JOIN user_presence up ON up.user_id = usr.id
                              WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                              ORDER BY usr.id DESC
                              LIMIT 1)";
    $responderLoggedInExpr = "(SELECT up.logged_in_at
                              FROM users usr
                              LEFT JOIN user_presence up ON up.user_id = usr.id
                              WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                              ORDER BY usr.id DESC
                              LIMIT 1)";
    $responderUserIdExpr = "(SELECT usr.id
                            FROM users usr
                            WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                              AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                            ORDER BY usr.id DESC
                            LIMIT 1)";
}

$locationCurrentExpr = '0';
if ($hasRecordedAt) {
    $locationCurrentExpr = "CASE
        WHEN {$lastRecordedExpr} IS NOT NULL
         AND ({$responderLoggedInExpr} IS NULL OR {$lastRecordedExpr} >= {$responderLoggedInExpr})
        THEN 1
        ELSE 0
    END";
}

$resourceJoin = '';
$driverNameExpr = $responderDriverExpr;
$resourceSelect = 'NULL AS resource_name,
            NULL AS resource_location,
            ' . $driverNameExpr . ' AS driver_name,
            NULL AS plate_number,
            NULL AS assignment';
if ($vehicleResourceTable !== null) {
    $resourceJoin = " INNER JOIN `" . $vehicleResourceTable . "` rr
                      ON rr.code = u.identifier
                     AND LOWER(rr.category) = 'vehicles'";
    $driverNameExpr = 'COALESCE(NULLIF(TRIM(rr.driver_name), \'\'), ' . $responderDriverExpr . ')';
    $resourceSelect = 'rr.name AS resource_name,
            rr.location AS resource_location,
            ' . $driverNameExpr . ' AS driver_name,
            rr.plate_number AS plate_number,
            rr.assignment AS assignment';
}

$sql = "SELECT
            u.id,
            u.identifier,
            u.unit_type,
            u.status,
            {$resourceSelect},
            {$unitLatExpr} AS latitude,
            {$unitLngExpr} AS longitude,
            {$currentIncidentExpr} AS current_incident_id,
            i.reference_no AS incident_code,
            i.title AS incident_title,
            i.type AS incident_type,
            COALESCE(i.location_address, {$callLocationExpr}) AS incident_location,
            COALESCE(i.latitude, {$callLatExpr}) AS incident_latitude,
            COALESCE(i.longitude, {$callLngExpr}) AS incident_longitude,
            {$speedExpr} AS speed_kph,
            {$headingExpr} AS heading_deg,
            {$accuracyExpr} AS accuracy_m,
            {$locationSourceExpr} AS location_source,
            {$responderPresenceStatusExpr} AS presence_status,
            {$responderLastSeenExpr} AS responder_last_seen_at,
            {$responderLoggedInExpr} AS responder_logged_in_at,
            {$responderUserIdExpr} AS responder_user_id,
            {$locationCurrentExpr} AS location_current,
            {$lastRecordedExpr} AS last_recorded_at
        FROM units u
        {$resourceJoin}
        {$incidentJoin}
        {$callJoin}";

$params = [];
if (!empty($statuses)) {
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $sql .= " WHERE u.status IN ($in)";
    $params = $statuses;
}

if (in_array('available', $statuses, true) && !$includeUnassigned) {
    $sql .= ($params === [] ? ' WHERE ' : ' AND ')
        . "TRIM(COALESCE(" . $driverNameExpr . ", '')) <> ''";
}

$sql .= ' ORDER BY u.unit_type, u.identifier';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'items' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Query failed',
        'details' => $e->getMessage()
    ]);
}
