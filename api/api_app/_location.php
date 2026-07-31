<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/../../includes/unit_location_tracking.php';

/** @return string */
function app_location_unit_select(PDO $pdo): string
{
    $parts = [];
    foreach (['id', 'identifier', 'unit_type', 'status'] as $column) {
        $parts[] = op_column_exists($pdo, 'units', $column)
            ? '`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }
    return implode(', ', $parts);
}

/** @return array<string,mixed>|null */
function app_location_resolve_unit(PDO $pdo, array $input): ?array
{
    if (
        !op_table_exists($pdo, 'units')
        || !op_column_exists($pdo, 'units', 'id')
        || !op_column_exists($pdo, 'units', 'identifier')
    ) {
        return null;
    }

    $responderId = (int)(
        $input['responder_id']
        ?? $input['responderId']
        ?? $input['user_id']
        ?? $input['userId']
        ?? 0
    );
    $requestedUnitId = (int)($input['unit_id'] ?? $input['unitId'] ?? 0);
    $requestedCode = trim((string)(
        $input['unit_code']
        ?? $input['unitCode']
        ?? $input['identifier']
        ?? ''
    ));

    $authorizedCode = '';
    $authorizedUnitId = 0;
    $responderEmail = '';

    if (
        $responderId > 0
        && op_table_exists($pdo, 'users')
        && op_column_exists($pdo, 'users', 'id')
    ) {
        $select = [];
        $select[] = op_column_exists($pdo, 'users', 'unit_code')
            ? 'unit_code'
            : 'NULL AS unit_code';
        $select[] = op_column_exists($pdo, 'users', 'email')
            ? 'email'
            : 'NULL AS email';
        $statement = $pdo->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$responderId]);
        $user = op_fetch_one($statement);
        $authorizedCode = trim((string)($user['unit_code'] ?? ''));
        $responderEmail = trim((string)($user['email'] ?? ''));
    }

    // Legacy deployments may link responders through responders.assigned_unit_id
    // instead of users.unit_code.
    if (
        $authorizedCode === ''
        && $responderEmail !== ''
        && op_table_exists($pdo, 'responders')
        && op_column_exists($pdo, 'responders', 'email')
        && op_column_exists($pdo, 'responders', 'assigned_unit_id')
    ) {
        $activeClause = op_column_exists($pdo, 'responders', 'is_active')
            ? ' AND COALESCE(is_active, 1) = 1'
            : '';
        $statement = $pdo->prepare(
            'SELECT assigned_unit_id FROM responders '
            . 'WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))'
            . $activeClause . ' ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$responderEmail]);
        $authorizedUnitId = (int)$statement->fetchColumn();
    }

    if (
        $authorizedCode === ''
        && $authorizedUnitId <= 0
        && $responderId > 0
        && op_table_exists($pdo, 'dispatch_operator_records')
        && op_column_exists($pdo, 'dispatch_operator_records', 'assigned_to')
        && op_column_exists($pdo, 'dispatch_operator_records', 'assigned_unit_code')
    ) {
        $where = ['assigned_to = ?', "TRIM(COALESCE(assigned_unit_code, '')) <> ''"];
        if (op_column_exists($pdo, 'dispatch_operator_records', 'status')) {
            $where[] = "LOWER(COALESCE(status, '')) IN "
                . "('pending','assigned','received','accepted','acknowledged',"
                . "'busy','in_use','enroute','en_route','on_scene')";
        }
        $order = [];
        if (op_column_exists($pdo, 'dispatch_operator_records', 'assigned_at')) {
            $order[] = 'assigned_at DESC';
        } elseif (op_column_exists($pdo, 'dispatch_operator_records', 'created_at')) {
            $order[] = 'created_at DESC';
        }
        if (op_column_exists($pdo, 'dispatch_operator_records', 'id')) {
            $order[] = 'id DESC';
        }
        $statement = $pdo->prepare(
            'SELECT assigned_unit_code FROM dispatch_operator_records WHERE '
            . implode(' AND ', $where)
            . ($order !== [] ? ' ORDER BY ' . implode(', ', $order) : '')
            . ' LIMIT 1'
        );
        $statement->execute([$responderId]);
        $authorizedCode = trim((string)$statement->fetchColumn());
    }

    // A responder without an assigned unit must not be able to update an
    // arbitrary unit by supplying its ID or identifier.
    if ($responderId > 0 && $authorizedCode === '' && $authorizedUnitId <= 0) {
        return null;
    }

    $select = app_location_unit_select($pdo);
    $unit = null;
    if ($requestedUnitId > 0) {
        $statement = $pdo->prepare('SELECT ' . $select . ' FROM units WHERE id = ? LIMIT 1');
        $statement->execute([$requestedUnitId]);
        $unit = op_fetch_one($statement);
    } elseif ($requestedCode !== '') {
        $statement = $pdo->prepare(
            'SELECT ' . $select . ' FROM units '
            . 'WHERE UPPER(TRIM(identifier)) = UPPER(TRIM(?)) LIMIT 1'
        );
        $statement->execute([$requestedCode]);
        $unit = op_fetch_one($statement);
    } elseif ($authorizedUnitId > 0) {
        $statement = $pdo->prepare('SELECT ' . $select . ' FROM units WHERE id = ? LIMIT 1');
        $statement->execute([$authorizedUnitId]);
        $unit = op_fetch_one($statement);
    } elseif ($authorizedCode !== '') {
        $statement = $pdo->prepare(
            'SELECT ' . $select . ' FROM units '
            . 'WHERE UPPER(TRIM(identifier)) = UPPER(TRIM(?)) LIMIT 1'
        );
        $statement->execute([$authorizedCode]);
        $unit = op_fetch_one($statement);
    }

    if ($unit === null) {
        return null;
    }
    if ($responderId > 0) {
        if ($authorizedUnitId > 0 && (int)($unit['id'] ?? 0) !== $authorizedUnitId) {
            return null;
        }
        if (
            $authorizedCode !== ''
            && strcasecmp(trim((string)($unit['identifier'] ?? '')), $authorizedCode) !== 0
        ) {
            return null;
        }
    }

    return $unit;
}

function app_location_distance_meters(
    float $lat1,
    float $lng1,
    float $lat2,
    float $lng2
): float {
    $radius = 6371000.0;
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLng = deg2rad($lng2 - $lng1);
    $a = sin($deltaLat / 2) ** 2
        + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;
    $a = max(0.0, min(1.0, $a));
    return $radius * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
}

/** @return array<string,mixed> */
function app_location_update(PDO $pdo, array $input): array
{
    if (!op_table_exists($pdo, 'units')) {
        return ['ok' => false, 'error' => 'Location tracking is not installed on the database.'];
    }

    try {
        ers_unit_location_ensure_schema($pdo);
    } catch (Throwable $schemaError) {
        error_log('[api_app location] schema ensure skipped: ' . $schemaError->getMessage());
    }

    if (!op_table_exists($pdo, 'unit_locations')) {
        return ['ok' => false, 'error' => 'Location tracking is not installed on the database.'];
    }

    foreach (['unit_id', 'latitude', 'longitude'] as $column) {
        if (!op_column_exists($pdo, 'unit_locations', $column)) {
            return ['ok' => false, 'error' => 'Location tracking requires a database update.'];
        }
    }

    $responderId = (int)(
        $input['responder_id']
        ?? $input['responderId']
        ?? $input['user_id']
        ?? $input['userId']
        ?? 0
    );
    if ($responderId > 0 && op_active_responder($pdo, $responderId) === null) {
        return ['ok' => false, 'error' => 'Responder account is inactive.'];
    }

    $latitude = filter_var(
        $input['latitude'] ?? $input['lat'] ?? null,
        FILTER_VALIDATE_FLOAT
    );
    $longitude = filter_var(
        $input['longitude'] ?? $input['lng'] ?? $input['lon'] ?? null,
        FILTER_VALIDATE_FLOAT
    );
    if (
        $latitude === false
        || $longitude === false
        || (float)$latitude < -90
        || (float)$latitude > 90
        || (float)$longitude < -180
        || (float)$longitude > 180
    ) {
        return ['ok' => false, 'error' => 'Valid latitude and longitude are required.'];
    }
    $latitude = (float)$latitude;
    $longitude = (float)$longitude;
    if (abs($latitude) < 0.000001 && abs($longitude) < 0.000001) {
        return ['ok' => false, 'error' => 'GPS coordinates cannot be 0,0.'];
    }

    $unit = app_location_resolve_unit($pdo, $input);
    if ($unit === null) {
        return [
            'ok' => false,
            'error' => 'Assigned unit not found or does not belong to this responder.',
        ];
    }

    $optionalFloat = static function (mixed $value, float $min, float $max): ?float {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || (float)$number < $min || (float)$number > $max) {
            return null;
        }
        return (float)$number;
    };

    $speedKph = $optionalFloat(
        $input['speed_kph'] ?? $input['speedKph'] ?? null,
        0,
        300
    );
    if ($speedKph === null && array_key_exists('speed', $input)) {
        $speedMps = $optionalFloat($input['speed'], 0, 100);
        $speedKph = $speedMps === null ? null : round($speedMps * 3.6, 2);
    }
    $heading = $optionalFloat($input['heading_deg'] ?? $input['heading'] ?? null, 0, 360);
    $accuracy = $optionalFloat($input['accuracy_m'] ?? $input['accuracy'] ?? null, 0, 10000);
    $source = substr(trim((string)($input['source'] ?? 'responder_gps')), 0, 50);
    if ($source === '') {
        $source = 'responder_gps';
    }

    $unitId = (int)($unit['id'] ?? 0);
    if ($unitId <= 0) {
        return ['ok' => false, 'error' => 'Assigned unit is invalid.'];
    }

    $coalesced = false;
    $locationId = 0;
    $canCoalesce = op_column_exists($pdo, 'unit_locations', 'id')
        && op_column_exists($pdo, 'unit_locations', 'recorded_at');
    if ($canCoalesce) {
        $latest = $pdo->prepare(
            'SELECT id, latitude, longitude, UNIX_TIMESTAMP(recorded_at) AS recorded_ts '
            . 'FROM unit_locations WHERE unit_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1'
        );
        $latest->execute([$unitId]);
        $last = op_fetch_one($latest);
        if ($last !== null) {
            $age = max(0, time() - (int)($last['recorded_ts'] ?? 0));
            $distance = app_location_distance_meters(
                (float)$last['latitude'],
                (float)$last['longitude'],
                $latitude,
                $longitude
            );
            // Collapse near-identical rapid retries into the latest sample.
            if ($age <= 5 && $distance <= 5.0) {
                $sets = ['latitude = ?', 'longitude = ?', 'recorded_at = NOW()'];
                $values = [$latitude, $longitude];
                foreach ([
                    'responder_id' => $responderId > 0 ? $responderId : null,
                    'accuracy_m' => $accuracy,
                    'speed_kph' => $speedKph,
                    'heading_deg' => $heading,
                    'source' => $source,
                ] as $column => $value) {
                    if (op_column_exists($pdo, 'unit_locations', $column)) {
                        $sets[] = '`' . $column . '` = ?';
                        $values[] = $value;
                    }
                }
                $locationId = (int)$last['id'];
                $values[] = $locationId;
                $statement = $pdo->prepare(
                    'UPDATE unit_locations SET ' . implode(', ', $sets) . ' WHERE id = ?'
                );
                $statement->execute($values);
                $coalesced = true;
            }
        }
    }

    if (!$coalesced) {
        $columns = ['unit_id', 'latitude', 'longitude'];
        $values = [$unitId, $latitude, $longitude];
        foreach ([
            'responder_id' => $responderId > 0 ? $responderId : null,
            'accuracy_m' => $accuracy,
            'speed_kph' => $speedKph,
            'heading_deg' => $heading,
            'source' => $source,
        ] as $column => $value) {
            if (op_column_exists($pdo, 'unit_locations', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }
        $statement = $pdo->prepare(
            'INSERT INTO unit_locations (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute($values);
        $locationId = (int)$pdo->lastInsertId();
    }

    $sets = [];
    $updateValues = [];
    if (op_column_exists($pdo, 'units', 'latitude')) {
        $sets[] = 'latitude = ?';
        $updateValues[] = $latitude;
    }
    if (op_column_exists($pdo, 'units', 'longitude')) {
        $sets[] = 'longitude = ?';
        $updateValues[] = $longitude;
    }
    if (op_column_exists($pdo, 'units', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }
    if ($sets !== []) {
        $updateValues[] = $unitId;
        $statement = $pdo->prepare(
            'UPDATE units SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $statement->execute($updateValues);
    }

    if ($responderId > 0) {
        op_touch_presence($pdo, $responderId);
    }

    return [
        'ok' => true,
        'unit_id' => $unitId,
        'unit_code' => (string)($unit['identifier'] ?? ''),
        'unit_type' => (string)($unit['unit_type'] ?? ''),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy_m' => $accuracy,
        'location_id' => $locationId,
        'coalesced' => $coalesced,
        'recorded_at' => date('Y-m-d H:i:s'),
    ];
}
