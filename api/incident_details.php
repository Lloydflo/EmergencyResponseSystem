<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

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
        return (bool)$stmt->fetchColumn();
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
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

$pdo = get_db_connection();
$hasId = array_key_exists('id', $_GET) && $_GET['id'] !== '' && is_numeric((string)$_GET['id']);
$id = $hasId ? (int)$_GET['id'] : null;
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';

$out = ['ok' => false, 'incident' => null, 'units' => []];
if (!$pdo) {
    echo json_encode($out);
    exit;
}
$isAnonymousTipIncident = false;

$resourceRecordsTable = ers_vehicle_resource_units_table($pdo);
if ($resourceRecordsTable !== null) {
    ers_sync_all_vehicle_resource_units($pdo, $resourceRecordsTable);
}
$responderPresenceMap = ers_vehicle_resource_responder_presence_map($pdo);

try {
    if ($hasId) {
        $stmt = $pdo->prepare(
            "SELECT i.*,
                    c.caller_name AS call_caller_name,
                    c.caller_phone AS call_caller_phone,
                    c.location_address AS call_location_address,
                    c.incident_type AS call_incident_type,
                    c.priority AS call_priority,
                    c.description AS call_description,
                    c.latitude AS call_latitude,
                    c.longitude AS call_longitude
             FROM incidents i
             LEFT JOIN calls c ON c.id = i.reported_by_call_id
             WHERE i.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
    } elseif ($code !== '') {
        $stmt = $pdo->prepare(
            "SELECT i.*,
                    c.caller_name AS call_caller_name,
                    c.caller_phone AS call_caller_phone,
                    c.location_address AS call_location_address,
                    c.incident_type AS call_incident_type,
                    c.priority AS call_priority,
                    c.description AS call_description,
                    c.latitude AS call_latitude,
                    c.longitude AS call_longitude
             FROM incidents i
             LEFT JOIN calls c ON c.id = i.reported_by_call_id
             WHERE i.reference_no = ?
             LIMIT 1"
        );
        $stmt->execute([$code]);
    } else {
        $stmt = null;
    }

    if ($stmt) {
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($incident) {
            if ((!isset($incident['type']) || trim((string)$incident['type']) === '' || strtolower(trim((string)$incident['type'])) === 'other') && isset($incident['call_incident_type']) && trim((string)$incident['call_incident_type']) !== '') {
                $incident['type'] = $incident['call_incident_type'];
            }
            if ((!isset($incident['priority']) || trim((string)$incident['priority']) === '') && isset($incident['call_priority']) && trim((string)$incident['call_priority']) !== '') {
                $incident['priority'] = $incident['call_priority'];
            }
            if ((!isset($incident['description']) || trim((string)$incident['description']) === '') && isset($incident['call_description']) && trim((string)$incident['call_description']) !== '') {
                $incident['description'] = $incident['call_description'];
            }
            if (
                (!isset($incident['location_address']) || trim((string)$incident['location_address']) === '' || stripos((string)$incident['location_address'], 'Location pending') !== false)
                && isset($incident['call_location_address'])
                && trim((string)$incident['call_location_address']) !== ''
            ) {
                $incident['location_address'] = $incident['call_location_address'];
            }
            $incident['caller_name'] = trim((string)($incident['caller_name'] ?? '')) !== ''
                ? $incident['caller_name']
                : ($incident['call_caller_name'] ?? null);
            $incident['caller_phone'] = trim((string)($incident['caller_phone'] ?? '')) !== ''
                ? $incident['caller_phone']
                : ($incident['call_caller_phone'] ?? null);
            if ((!isset($incident['latitude']) || $incident['latitude'] === null || $incident['latitude'] === '') && isset($incident['call_latitude']) && $incident['call_latitude'] !== null && $incident['call_latitude'] !== '') {
                $incident['latitude'] = $incident['call_latitude'];
            }
            if ((!isset($incident['longitude']) || $incident['longitude'] === null || $incident['longitude'] === '') && isset($incident['call_longitude']) && $incident['call_longitude'] !== null && $incident['call_longitude'] !== '') {
                $incident['longitude'] = $incident['call_longitude'];
            }
            unset(
                $incident['call_caller_name'],
                $incident['call_caller_phone'],
                $incident['call_location_address'],
                $incident['call_incident_type'],
                $incident['call_priority'],
                $incident['call_description'],
                $incident['call_latitude'],
                $incident['call_longitude']
            );

            $incidentId = (int)$incident['id'];
            if (
                ers_table_exists($pdo, 'external_incident_links')
                && ers_column_exists($pdo, 'external_incident_links', 'incident_id')
                && ers_column_exists($pdo, 'external_incident_links', 'source_system')
            ) {
                $sourceStmt = $pdo->prepare(
                    "SELECT 1
                     FROM external_incident_links
                     WHERE incident_id = ?
                       AND source_system = 'Anonymous Tip Inbox'
                     LIMIT 1"
                );
                $sourceStmt->execute([$incidentId]);
                $isAnonymousTipIncident = (bool)$sourceStmt->fetchColumn();
                if ($isAnonymousTipIncident) {
                    $incident['type'] = 'medical, police, fire';
                }
            }
            $hasIncidentNotes = ers_table_exists($pdo, 'incident_notes');
            $hasRatingColumn = $hasIncidentNotes && ers_column_exists($pdo, 'incident_notes', 'rating');
            $hasIncidentSurveys = ers_table_exists($pdo, 'incident_surveys')
                && ers_column_exists($pdo, 'incident_surveys', 'incident_id')
                && ers_column_exists($pdo, 'incident_surveys', 'response_rating');

            $dispatchSelect = 'NULL AS vehicle_name, NULL AS driver_name, NULL AS plate_number';
            $dispatchJoin = '';
            $responderDriverExpr = 'NULL';
            if (
                ers_table_exists($pdo, 'users') &&
                ers_column_exists($pdo, 'users', 'unit_code') &&
                ers_column_exists($pdo, 'users', 'name') &&
                ers_column_exists($pdo, 'users', 'role')
            ) {
                $responderDriverExpr = "(SELECT usr.name
                                        FROM users usr
                                        WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                          AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                                          AND TRIM(COALESCE(usr.name, '')) <> ''
                                        ORDER BY usr.id DESC
                                        LIMIT 1)";
            }
            if ($resourceRecordsTable !== null) {
                $dispatchSelect = 'ar.name AS vehicle_name, COALESCE(NULLIF(TRIM(ar.driver_name), \'\'), ' . $responderDriverExpr . ') AS driver_name, ar.plate_number AS plate_number';
                $dispatchJoin = ' LEFT JOIN `' . $resourceRecordsTable . '` ar ON ar.code = u.identifier ';
            } else {
                $dispatchSelect = 'NULL AS vehicle_name, ' . $responderDriverExpr . ' AS driver_name, NULL AS plate_number';
            }

            $latestDispatch = null;
            $hasDispatchesTable = ers_table_exists($pdo, 'dispatches');
            $hasDispatchIncidentId = $hasDispatchesTable && ers_column_exists($pdo, 'dispatches', 'incident_id');
            $hasDispatchReferenceNo = $hasDispatchesTable && ers_column_exists($pdo, 'dispatches', 'reference_no');

            if ($hasDispatchesTable && ($hasDispatchIncidentId || $hasDispatchReferenceNo)) {
                $dispatchWhere = [];
                $dispatchParams = [];
                if ($hasDispatchIncidentId) {
                    $dispatchWhere[] = 'd.incident_id = ?';
                    $dispatchParams[] = $incidentId;
                }
                if ($hasDispatchReferenceNo && !empty($incident['reference_no'])) {
                    $dispatchWhere[] = 'd.reference_no = ?';
                    $dispatchParams[] = (string)$incident['reference_no'];
                }

                $dispatchWhereClause = implode(' OR ', $dispatchWhere);
                $dispatchStmt = $pdo->prepare(
                    "SELECT
                        d.id,
                        d.status,
                        d.assigned_at,
                        d.acknowledged_at,
                        d.enroute_at,
                        d.on_scene_at,
                        d.cleared_at,
                        u.id AS unit_id,
                        u.identifier AS unit_identifier,
                        u.unit_type,
                        {$dispatchSelect}
                     FROM dispatches d
                     LEFT JOIN units u ON u.id = d.unit_id
                     {$dispatchJoin}
                     WHERE ({$dispatchWhereClause})
                     ORDER BY d.id DESC
                     LIMIT 1"
                );
                $dispatchStmt->execute($dispatchParams);
                $latestDispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $incident['assigned_unit_identifier'] = null;
            $incident['assigned_unit_type'] = null;
            $incident['vehicle_name'] = null;
            $incident['driver_name'] = null;
            $incident['plate_number'] = null;
            $incident['dispatch_status'] = null;
            $incident['dispatch_assigned_at'] = null;
            $incident['acknowledged_at'] = null;
            $incident['enroute_at'] = null;
            $incident['on_scene_at'] = null;
            $incident['cleared_at'] = null;
            $incident['response_time_min'] = null;
            $incident['resolution_time_min'] = null;
            $incident['feedback_count'] = 0;
            $incident['rating_count'] = 0;
            $incident['avg_rating'] = null;

            if ($latestDispatch) {
                $incident['assigned_unit_identifier'] = $latestDispatch['unit_identifier'] ?? null;
                $incident['assigned_unit_type'] = $latestDispatch['unit_type'] ?? null;
                $incident['vehicle_name'] = $latestDispatch['vehicle_name'] ?? null;
                $incident['driver_name'] = $latestDispatch['driver_name'] ?? null;
                $incident['plate_number'] = $latestDispatch['plate_number'] ?? null;
                $incident['dispatch_status'] = $latestDispatch['status'] ?? null;
                $incident['dispatch_assigned_at'] = $latestDispatch['assigned_at'] ?? null;
                $incident['acknowledged_at'] = $latestDispatch['acknowledged_at'] ?? null;
                $incident['enroute_at'] = $latestDispatch['enroute_at'] ?? null;
                $incident['on_scene_at'] = $latestDispatch['on_scene_at'] ?? null;
                $incident['cleared_at'] = $latestDispatch['cleared_at'] ?? null;

                if (!empty($latestDispatch['assigned_at']) && !empty($latestDispatch['on_scene_at'])) {
                    $assigned = new DateTime($latestDispatch['assigned_at']);
                    $onScene = new DateTime($latestDispatch['on_scene_at']);
                    $diff = $assigned->diff($onScene);
                    $incident['response_time_min'] = (int)($diff->days * 24 * 60 + $diff->h * 60 + $diff->i);
                }

                $closedAt = $incident['resolved_at'] ?? ($latestDispatch['cleared_at'] ?? null);
                if (!empty($latestDispatch['assigned_at']) && !empty($closedAt)) {
                    $assigned = new DateTime($latestDispatch['assigned_at']);
                    $closed = new DateTime($closedAt);
                    $diff = $assigned->diff($closed);
                    $incident['resolution_time_min'] = (int)($diff->days * 24 * 60 + $diff->h * 60 + $diff->i);
                }
            }

            $noteCount = 0;
            $ratingCount = 0;
            $ratingSum = 0.0;
            if ($hasIncidentNotes) {
                $noteSql = $hasRatingColumn
                    ? "SELECT COUNT(*) AS feedback_count,
                              COUNT(rating) AS rating_count,
                              COALESCE(SUM(rating), 0) AS rating_sum
                       FROM incident_notes
                       WHERE incident_id = ?
                         AND note NOT LIKE 'Resolution proof uploaded:%'"
                    : "SELECT COUNT(*) AS feedback_count,
                              0 AS rating_count,
                              0 AS rating_sum
                       FROM incident_notes
                       WHERE incident_id = ?
                         AND note NOT LIKE 'Resolution proof uploaded:%'";
                $feedbackStmt = $pdo->prepare($noteSql);
                $feedbackStmt->execute([$incidentId]);
                $feedback = $feedbackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($feedback) {
                    $noteCount = isset($feedback['feedback_count']) ? (int)$feedback['feedback_count'] : 0;
                    $ratingCount += isset($feedback['rating_count']) ? (int)$feedback['rating_count'] : 0;
                    $ratingSum += isset($feedback['rating_sum']) ? (float)$feedback['rating_sum'] : 0.0;
                }
            }

            $surveyCount = 0;
            if ($hasIncidentSurveys) {
                $surveyStmt = $pdo->prepare(
                    "SELECT COUNT(*) AS feedback_count,
                            COUNT(response_rating) AS rating_count,
                            COALESCE(SUM(response_rating), 0) AS rating_sum
                     FROM incident_surveys
                     WHERE incident_id = ?"
                );
                $surveyStmt->execute([$incidentId]);
                $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($survey) {
                    $surveyCount = isset($survey['feedback_count']) ? (int)$survey['feedback_count'] : 0;
                    $ratingCount += isset($survey['rating_count']) ? (int)$survey['rating_count'] : 0;
                    $ratingSum += isset($survey['rating_sum']) ? (float)$survey['rating_sum'] : 0.0;
                }
            }

            $incident['feedback_count'] = $noteCount + $surveyCount;
            $incident['rating_count'] = $ratingCount;
            $incident['avg_rating'] = $ratingCount > 0 ? round($ratingSum / $ratingCount, 1) : null;

            $out['incident'] = $incident;
            $out['ok'] = true;
        }
    }

    $desiredTypes = [];
    if ($isAnonymousTipIncident) {
        $desiredTypes = [];
    } elseif (!empty($out['incident']) && !empty($out['incident']['type'])) {
        $typeValue = strtolower(trim((string)$out['incident']['type']));
        $typeParts = preg_split('/[,|]+/', $typeValue) ?: [$typeValue];
        foreach ($typeParts as $typePart) {
            $typePart = strtolower(trim((string)$typePart));
            if ($typePart === '') {
                continue;
            }
            if (preg_match('/fire|smoke|blaze|burn/i', $typePart)) {
                $desiredTypes[] = 'fire';
            }
            if (preg_match('/medical|injur|cardiac|stroke|ambulance|unconscious|pregnan|health/i', $typePart)) {
                $desiredTypes[] = 'ambulance';
            }
            if (preg_match('/crime|robbery|assault|police|theft|violence|shoot|armed/i', $typePart)) {
                $desiredTypes[] = 'police';
            }
            if (preg_match('/traffic|accident|collision|crash|vehicle/i', $typePart)) {
                $desiredTypes[] = 'ambulance';
                $desiredTypes[] = 'rescue';
                $desiredTypes[] = 'police';
            }
            if (preg_match('/rescue|collapse|trapped|flood|earthquake|landslide|water|drowning/i', $typePart)) {
                $desiredTypes[] = 'rescue';
            }
            if (in_array($typePart, ['fire', 'ambulance', 'police', 'rescue', 'other'], true)) {
                $desiredTypes[] = $typePart;
            }
        }
        $desiredTypes = array_values(array_unique($desiredTypes));
    }

    $responderDriverExpr = 'NULL';
    if (
        ers_table_exists($pdo, 'users') &&
        ers_column_exists($pdo, 'users', 'unit_code') &&
        ers_column_exists($pdo, 'users', 'name') &&
        ers_column_exists($pdo, 'users', 'role')
    ) {
        $responderDriverExpr = "(SELECT usr.name
                                FROM users usr
                                WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                  AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                                  AND TRIM(COALESCE(usr.name, '')) <> ''
                                ORDER BY usr.id DESC
                                LIMIT 1)";
    }

    $hasUnitLocations = ers_table_exists($pdo, 'unit_locations');
    $hasLocationLatitude = $hasUnitLocations && ers_column_exists($pdo, 'unit_locations', 'latitude');
    $hasLocationLongitude = $hasUnitLocations && ers_column_exists($pdo, 'unit_locations', 'longitude');
    $hasLocationRecordedAt = $hasUnitLocations && ers_column_exists($pdo, 'unit_locations', 'recorded_at');
    $hasLocationId = $hasUnitLocations && ers_column_exists($pdo, 'unit_locations', 'id');
    $hasLocationAccuracy = $hasUnitLocations && ers_column_exists($pdo, 'unit_locations', 'accuracy_m');
    $hasLocationSource = $hasUnitLocations && ers_column_exists($pdo, 'unit_locations', 'source');
    $hasUnitLatitude = ers_column_exists($pdo, 'units', 'latitude');
    $hasUnitLongitude = ers_column_exists($pdo, 'units', 'longitude');
    $latestLocationOrderExpr = $hasLocationId ? 'ul.recorded_at DESC, ul.id DESC' : 'ul.recorded_at DESC';
    $ignoredFallbackGpsWhere = ($hasLocationLatitude && $hasLocationLongitude) ? " AND NOT (
        (ABS(ul.latitude - 14.7338) < 0.000001 AND ABS(ul.longitude - 121.0368) < 0.000001)
        OR (ABS(ul.latitude - 14.7295) < 0.000001 AND ABS(ul.longitude - 121.0342) < 0.000001)
        OR (ABS(ul.latitude - 14.7351) < 0.000001 AND ABS(ul.longitude - 121.0380) < 0.000001)
        OR (ABS(ul.latitude - 14.7320) < 0.000001 AND ABS(ul.longitude - 121.0351) < 0.000001)
    )" : '';
    $storedUnitLatRawExpr = $hasUnitLatitude ? 'u.latitude' : 'NULL';
    $storedUnitLngRawExpr = $hasUnitLongitude ? 'u.longitude' : 'NULL';
    $storedFallbackCoordinateSql = ($hasUnitLatitude && $hasUnitLongitude) ? "(
        (ABS(u.latitude - 14.7338) < 0.000001 AND ABS(u.longitude - 121.0368) < 0.000001)
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
    $unitLocationSelect = "{$latestLocationLatExpr} AS latitude,
        {$latestLocationLngExpr} AS longitude,
        {$latestLocationLatExpr} AS latest_latitude,
        {$latestLocationLngExpr} AS latest_longitude,
        {$storedUnitLatExpr} AS stored_latitude,
        {$storedUnitLngExpr} AS stored_longitude,
        {$latestLocationRecordedExpr} AS last_recorded_at,
        {$latestLocationAccuracyExpr} AS accuracy_m,
        {$latestLocationSourceExpr} AS location_source";

    $unitSelect = 'u.*, ' . $unitLocationSelect . ', NULL AS vehicle_name, ' . $responderDriverExpr . ' AS driver_name, NULL AS plate_number';
    $unitFrom = 'units u';
    $unitAlias = 'u.';
    $unitJoin = '';
    $driverNameExpr = $responderDriverExpr;
    if ($resourceRecordsTable !== null) {
        $driverNameExpr = 'COALESCE(NULLIF(TRIM(rr.driver_name), \'\'), ' . $responderDriverExpr . ')';
        $unitSelect = 'u.*, ' . $unitLocationSelect . ', rr.name AS vehicle_name, ' . $driverNameExpr . ' AS driver_name, rr.plate_number';
        $unitFrom = 'units u';
        $unitAlias = 'u.';
        $unitJoin = " INNER JOIN `" . $resourceRecordsTable . "` rr
                      ON rr.code = u.identifier
                     AND LOWER(rr.category) = 'vehicles'";
    }
    $assignedDriverWhere = " AND TRIM(COALESCE(" . $driverNameExpr . ", '')) <> ''";

    if (!empty($desiredTypes)) {
        if (!$isAnonymousTipIncident && !in_array('other', $desiredTypes, true)) {
            $desiredTypes[] = 'other';
        }
        $placeholders = implode(',', array_fill(0, count($desiredTypes), '?'));
        $unitStmt = $pdo->prepare(
            "SELECT {$unitSelect}
             FROM {$unitFrom}
             {$unitJoin}
             WHERE {$unitAlias}status = 'available'
               AND {$unitAlias}unit_type IN ({$placeholders})
               {$assignedDriverWhere}"
        );
        $unitStmt->execute($desiredTypes);
        $units = $unitStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $units = $pdo->query(
            "SELECT {$unitSelect}
             FROM {$unitFrom}
             {$unitJoin}
             WHERE {$unitAlias}status = 'available'
             {$assignedDriverWhere}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($responderPresenceMap !== []) {
        $units = array_values(array_filter($units, static function (array $unit) use ($responderPresenceMap): bool {
            $unitCode = strtoupper(trim((string)($unit['identifier'] ?? '')));
            if ($unitCode === '' || !isset($responderPresenceMap[$unitCode])) {
                return false;
            }
            return ers_vehicle_resource_status_from_responder_state($responderPresenceMap[$unitCode]) === 'available';
        }));

        foreach ($units as &$unit) {
            $unitCode = strtoupper(trim((string)($unit['identifier'] ?? '')));
            $presence = $responderPresenceMap[$unitCode] ?? [];
            $unit['responder_user_id'] = (int)($presence['responder_id'] ?? 0);
            $unit['presence_status'] = (string)($presence['presence_status'] ?? '');
            $unit['responder_unit_status'] = (string)($presence['unit_status'] ?? '');
            if (trim((string)($unit['driver_name'] ?? '')) === '' && trim((string)($presence['responder_name'] ?? '')) !== '') {
                $unit['driver_name'] = trim((string)$presence['responder_name']);
            }
        }
        unset($unit);
    }

    foreach ($units as &$unit) {
        if (($unit['latest_latitude'] ?? null) !== null && ($unit['latest_latitude'] ?? '') !== '') {
            $unit['latitude'] = $unit['latest_latitude'];
        }
        if (($unit['latest_longitude'] ?? null) !== null && ($unit['latest_longitude'] ?? '') !== '') {
            $unit['longitude'] = $unit['latest_longitude'];
        }
    }
    unset($unit);

    $incidentLat = isset($out['incident']['latitude']) ? (float)$out['incident']['latitude'] : null;
    $incidentLng = isset($out['incident']['longitude']) ? (float)$out['incident']['longitude'] : null;
    $hasCoords = ($incidentLat !== null && $incidentLng !== null);

    if ($hasCoords && !empty($units)) {
        $earthRadiusKm = 6371.0;
        $toRad = static function (float $degrees): float {
            return $degrees * M_PI / 180.0;
        };

        foreach ($units as &$unit) {
            $unitLatRaw = $unit['latest_latitude'] ?? $unit['latitude'] ?? null;
            $unitLngRaw = $unit['latest_longitude'] ?? $unit['longitude'] ?? null;
            $unitLat = ($unitLatRaw !== null && $unitLatRaw !== '') ? (float)$unitLatRaw : null;
            $unitLng = ($unitLngRaw !== null && $unitLngRaw !== '') ? (float)$unitLngRaw : null;
            if ($unitLat !== null && $unitLng !== null) {
                $unit['latitude'] = $unitLat;
                $unit['longitude'] = $unitLng;
                $dLat = $toRad($incidentLat - $unitLat);
                $dLon = $toRad($incidentLng - $unitLng);
                $a = sin($dLat / 2) * sin($dLat / 2)
                    + cos($toRad($unitLat)) * cos($toRad($incidentLat))
                    * sin($dLon / 2) * sin($dLon / 2);
                $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                $unit['distance_km'] = round($earthRadiusKm * $c, 2);
            } else {
                $unit['distance_km'] = null;
            }
        }
        unset($unit);

        usort($units, static function (array $a, array $b): int {
            $distanceA = $a['distance_km'];
            $distanceB = $b['distance_km'];
            if ($distanceA === null && $distanceB === null) {
                return 0;
            }
            if ($distanceA === null) {
                return 1;
            }
            if ($distanceB === null) {
                return -1;
            }
            if ($distanceA == $distanceB) {
                return 0;
            }
            return ($distanceA < $distanceB) ? -1 : 1;
        });
    } else {
        usort($units, static function (array $a, array $b): int {
            $typeA = $a['unit_type'] ?? '';
            $typeB = $b['unit_type'] ?? '';
            if ($typeA === $typeB) {
                return strcmp($a['identifier'] ?? '', $b['identifier'] ?? '');
            }
            return strcmp($typeA, $typeB);
        });
    }

    $out['units'] = $units;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
    exit;
}

echo json_encode($out);
