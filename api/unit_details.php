<?php
// Returns details for a single unit
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

function unit_details_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function unit_details_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    try {
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
    } catch (Throwable $e) {
        return false;
    }
}

$pdo = get_db_connection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$out = ["unit" => null];
if ($pdo && $id) {
    $resourceTable = null;
    if (unit_details_table_exists($pdo, 'resource_records')) {
        $resourceTable = 'resource_records';
    } elseif (unit_details_table_exists($pdo, 'admin_resources')) {
        $resourceTable = 'admin_resources';
    }

    $hasUnitLocations = unit_details_table_exists($pdo, 'unit_locations');
    $hasLocationLatitude = $hasUnitLocations && unit_details_column_exists($pdo, 'unit_locations', 'latitude');
    $hasLocationLongitude = $hasUnitLocations && unit_details_column_exists($pdo, 'unit_locations', 'longitude');
    $hasLocationRecordedAt = $hasUnitLocations && unit_details_column_exists($pdo, 'unit_locations', 'recorded_at');
    $hasLocationId = $hasUnitLocations && unit_details_column_exists($pdo, 'unit_locations', 'id');
    $hasLocationAccuracy = $hasUnitLocations && unit_details_column_exists($pdo, 'unit_locations', 'accuracy_m');
    $hasLocationSource = $hasUnitLocations && unit_details_column_exists($pdo, 'unit_locations', 'source');
    $hasUnitLatitude = unit_details_column_exists($pdo, 'units', 'latitude');
    $hasUnitLongitude = unit_details_column_exists($pdo, 'units', 'longitude');
    $latestLocationOrderExpr = $hasLocationId ? 'ul.recorded_at DESC, ul.id DESC' : 'ul.recorded_at DESC';
    $ignoredFallbackGpsWhere = ($hasLocationLatitude && $hasLocationLongitude) ? " AND NOT (
        (ABS(ul.latitude) < 0.000001 AND ABS(ul.longitude) < 0.000001)
        OR (ABS(ul.latitude - 14.7338) < 0.000001 AND ABS(ul.longitude - 121.0368) < 0.000001)
        OR (ABS(ul.latitude - 14.7295) < 0.000001 AND ABS(ul.longitude - 121.0342) < 0.000001)
        OR (ABS(ul.latitude - 14.7351) < 0.000001 AND ABS(ul.longitude - 121.0380) < 0.000001)
        OR (ABS(ul.latitude - 14.7320) < 0.000001 AND ABS(ul.longitude - 121.0351) < 0.000001)
    )" : '';

    $storedUnitLatRawExpr = $hasUnitLatitude ? 'u.latitude' : 'NULL';
    $storedUnitLngRawExpr = $hasUnitLongitude ? 'u.longitude' : 'NULL';
    $storedFallbackCoordinateSql = ($hasUnitLatitude && $hasUnitLongitude) ? "(
        (ABS(u.latitude) < 0.000001 AND ABS(u.longitude) < 0.000001)
        OR (ABS(u.latitude - 14.7338) < 0.000001 AND ABS(u.longitude - 121.0368) < 0.000001)
        OR (ABS(u.latitude - 14.7295) < 0.000001 AND ABS(u.longitude - 121.0342) < 0.000001)
        OR (ABS(u.latitude - 14.7351) < 0.000001 AND ABS(u.longitude - 121.0380) < 0.000001)
        OR (ABS(u.latitude - 14.7320) < 0.000001 AND ABS(u.longitude - 121.0351) < 0.000001)
    )" : '0 = 1';
    $storedUnitLatExpr = ($hasUnitLatitude && $hasUnitLongitude) ? "CASE WHEN {$storedFallbackCoordinateSql} THEN NULL ELSE u.latitude END" : $storedUnitLatRawExpr;
    $storedUnitLngExpr = ($hasUnitLatitude && $hasUnitLongitude) ? "CASE WHEN {$storedFallbackCoordinateSql} THEN NULL ELSE u.longitude END" : $storedUnitLngRawExpr;
    $latestLocationLatExpr = 'NULL';
    $latestLocationLngExpr = 'NULL';
    $latestLocationRecordedExpr = 'NULL';
    $latestLocationAccuracyExpr = 'NULL';
    $latestLocationSourceExpr = 'NULL';
    if ($hasLocationLatitude && $hasLocationRecordedAt) {
        $latestLocationLatExpr = "(SELECT ul.latitude FROM unit_locations ul WHERE ul.unit_id = u.id {$ignoredFallbackGpsWhere} ORDER BY {$latestLocationOrderExpr} LIMIT 1)";
    }
    if ($hasLocationLongitude && $hasLocationRecordedAt) {
        $latestLocationLngExpr = "(SELECT ul.longitude FROM unit_locations ul WHERE ul.unit_id = u.id {$ignoredFallbackGpsWhere} ORDER BY {$latestLocationOrderExpr} LIMIT 1)";
    }
    if ($hasLocationRecordedAt) {
        $latestLocationRecordedExpr = "(SELECT ul.recorded_at FROM unit_locations ul WHERE ul.unit_id = u.id {$ignoredFallbackGpsWhere} ORDER BY {$latestLocationOrderExpr} LIMIT 1)";
    }
    if ($hasLocationAccuracy && $hasLocationRecordedAt) {
        $latestLocationAccuracyExpr = "(SELECT ul.accuracy_m FROM unit_locations ul WHERE ul.unit_id = u.id {$ignoredFallbackGpsWhere} ORDER BY {$latestLocationOrderExpr} LIMIT 1)";
    }
    if ($hasLocationSource && $hasLocationRecordedAt) {
        $latestLocationSourceExpr = "(SELECT ul.source FROM unit_locations ul WHERE ul.unit_id = u.id {$ignoredFallbackGpsWhere} ORDER BY {$latestLocationOrderExpr} LIMIT 1)";
    }

    $responderDriverExpr = 'NULL';
    if (
        unit_details_table_exists($pdo, 'users') &&
        unit_details_column_exists($pdo, 'users', 'unit_code') &&
        unit_details_column_exists($pdo, 'users', 'name') &&
        unit_details_column_exists($pdo, 'users', 'role')
    ) {
        $responderDriverExpr = "(SELECT usr.name
                                FROM users usr
                                WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                  AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                                  AND TRIM(COALESCE(usr.name, '')) <> ''
                                ORDER BY usr.id DESC
                                LIMIT 1)";
    }

    $resourceJoin = '';
    $resourceSelect = $responderDriverExpr . ' AS driver_name, NULL AS plate_number, NULL AS resource_name, NULL AS assignment, NULL AS resource_notes';
    if ($resourceTable !== null) {
        $resourceJoin = ' LEFT JOIN `' . $resourceTable . '` rr ON rr.code = u.identifier ';
        $resourceSelect = 'COALESCE(NULLIF(TRIM(rr.driver_name), \'\'), ' . $responderDriverExpr . ') AS driver_name, rr.plate_number AS plate_number, rr.name AS resource_name, rr.assignment AS assignment, rr.notes AS resource_notes';
    }

    $stmt = $pdo->prepare(
        "SELECT u.*,
                {$latestLocationLatExpr} AS latitude,
                {$latestLocationLngExpr} AS longitude,
                {$latestLocationLatExpr} AS latest_latitude,
                {$latestLocationLngExpr} AS latest_longitude,
                {$storedUnitLatExpr} AS stored_latitude,
                {$storedUnitLngExpr} AS stored_longitude,
                {$latestLocationRecordedExpr} AS last_recorded_at,
                {$latestLocationAccuracyExpr} AS accuracy_m,
                {$latestLocationSourceExpr} AS location_source,
                {$resourceSelect}
         FROM units u
         {$resourceJoin}
         WHERE u.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $out['unit'] = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($out['unit']) {
        if (($out['unit']['latest_latitude'] ?? null) !== null && ($out['unit']['latest_latitude'] ?? '') !== '') {
            $out['unit']['latitude'] = $out['unit']['latest_latitude'];
        }
        if (($out['unit']['latest_longitude'] ?? null) !== null && ($out['unit']['latest_longitude'] ?? '') !== '') {
            $out['unit']['longitude'] = $out['unit']['latest_longitude'];
        }
    }
}
echo json_encode($out);
