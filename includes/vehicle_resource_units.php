<?php
declare(strict_types=1);

require_once __DIR__ . '/geocode_helper.php';

if (!function_exists('ers_vehicle_resource_table_exists')) {
    function ers_vehicle_resource_table_exists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1"
            );
            $stmt->execute([$tableName]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ers_vehicle_resource_units_table')) {
    function ers_vehicle_resource_units_table(PDO $pdo): ?string
    {
        if (ers_vehicle_resource_table_exists($pdo, 'resource_records')) {
            return 'resource_records';
        }
        if (ers_vehicle_resource_table_exists($pdo, 'admin_resources')) {
            return 'admin_resources';
        }

        return null;
    }
}

if (!function_exists('ers_vehicle_resource_column_exists')) {
    function ers_vehicle_resource_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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
            $stmt->execute([$tableName, $columnName]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ers_units_table_available')) {
    function ers_units_table_available(PDO $pdo): bool
    {
        return ers_vehicle_resource_table_exists($pdo, 'units');
    }
}

if (!function_exists('ers_infer_vehicle_unit_type')) {
    function ers_infer_vehicle_unit_type(array $resource): string
    {
        $haystack = strtolower(trim(implode(' ', [
            (string) ($resource['code'] ?? ''),
            (string) ($resource['name'] ?? ''),
            (string) ($resource['assignment'] ?? ''),
            (string) ($resource['notes'] ?? ''),
            (string) ($resource['driver_name'] ?? ''),
            (string) ($resource['plate_number'] ?? '')
        ])));

        if ($haystack !== '') {
            if (preg_match('/ambulance|medical|emt|medic|clinic|hospital/', $haystack)) {
                return 'ambulance';
            }
            if (preg_match('/fire|truck|blaze|engine/', $haystack)) {
                return 'fire';
            }
            if (preg_match('/police|patrol|crime|law/', $haystack)) {
                return 'police';
            }
            if (preg_match('/rescue|search|retrieval|sar/', $haystack)) {
                return 'rescue';
            }
        }

        return 'other';
    }
}

if (!function_exists('ers_map_vehicle_resource_status_to_unit_status')) {
    function ers_map_vehicle_resource_status_to_unit_status(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'in_use') {
            return 'assigned';
        }
        if ($status === 'maintenance') {
            return 'maintenance';
        }
        if ($status === 'offline') {
            return 'unavailable';
        }

        return 'available';
    }
}

if (!function_exists('ers_vehicle_resource_default_coordinates')) {
    function ers_vehicle_resource_default_coordinates(string $unitType): array
    {
        $defaults = [
            'police' => [14.6500, 121.0300],
            'fire' => [14.6700, 121.0450],
            'ambulance' => [14.6900, 121.0600],
            'rescue' => [14.6760, 121.0437],
            'other' => [14.6760, 121.0437]
        ];

        return $defaults[strtolower(trim($unitType))] ?? $defaults['other'];
    }
}

if (!function_exists('ers_vehicle_resource_coordinates')) {
    function ers_vehicle_resource_coordinates(array $resource, string $unitType): array
    {
        $storedLat = $resource['latitude'] ?? null;
        $storedLng = $resource['longitude'] ?? null;
        if ($storedLat !== null && $storedLng !== null && $storedLat !== '' && $storedLng !== '') {
            $lat = (float) $storedLat;
            $lng = (float) $storedLng;
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return [$lat, $lng, 'explicit'];
            }
        }

        $location = trim((string) ($resource['location'] ?? ''));
        if ($location !== '' && preg_match('/(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)/', $location, $matches)) {
            $lat = (float) $matches[1];
            $lng = (float) $matches[2];
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return [$lat, $lng, 'explicit'];
            }
        }

        $geocoded = ers_geocode_location_to_coordinates($location);
        if ($geocoded !== null) {
            return [$geocoded[0], $geocoded[1], 'geocoded'];
        }

        [$lat, $lng] = ers_vehicle_resource_default_coordinates($unitType);
        return [$lat, $lng, 'default'];
    }
}

if (!function_exists('ers_vehicle_resource_is_default_coordinate')) {
    function ers_vehicle_resource_is_default_coordinate($latitude, $longitude): bool
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return false;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;
        foreach (['police', 'fire', 'ambulance', 'rescue', 'other'] as $unitType) {
            [$defaultLat, $defaultLng] = ers_vehicle_resource_default_coordinates($unitType);
            if (abs($lat - $defaultLat) < 0.000001 && abs($lng - $defaultLng) < 0.000001) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ers_map_unit_status_to_vehicle_resource_status')) {
    function ers_map_unit_status_to_vehicle_resource_status(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['assigned', 'acknowledged', 'enroute', 'on_scene', 'busy', 'active', 'in_progress'], true)) {
            return 'in_use';
        }
        if (in_array($status, ['maintenance'], true)) {
            return 'maintenance';
        }
        if (in_array($status, ['offline', 'unavailable'], true)) {
            return 'offline';
        }

        return 'available';
    }
}

if (!function_exists('ers_find_unit_by_identifiers')) {
    function ers_find_unit_by_identifiers(PDO $pdo, array $identifiers): ?array
    {
        $identifiers = array_values(array_filter(array_map(
            static fn($value): string => strtoupper(trim((string) $value)),
            $identifiers
        )));

        if ($identifiers === [] || !ers_units_table_available($pdo)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
        $stmt = $pdo->prepare("SELECT * FROM `units` WHERE `identifier` IN ($placeholders) ORDER BY id ASC LIMIT 1");
        $stmt->execute($identifiers);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('ers_sync_vehicle_resource_unit')) {
    function ers_sync_vehicle_resource_unit(PDO $pdo, array $resource, ?string $previousCode = null): void
    {
        if (!ers_units_table_available($pdo)) {
            return;
        }

        $category = strtolower(trim((string) ($resource['category'] ?? '')));
        if ($category !== 'vehicles') {
            return;
        }

        $identifier = strtoupper(trim((string) ($resource['code'] ?? '')));
        if ($identifier === '') {
            return;
        }

        $existingUnit = ers_find_unit_by_identifiers($pdo, [$identifier, $previousCode]);
        $unitType = ers_infer_vehicle_unit_type($resource);
        $unitStatus = ers_map_vehicle_resource_status_to_unit_status((string) ($resource['status'] ?? 'available'));
        $hasLastStatusAt = ers_vehicle_resource_column_exists($pdo, 'units', 'last_status_at');
        $hasCurrentIncidentId = ers_vehicle_resource_column_exists($pdo, 'units', 'current_incident_id');

        if ($existingUnit) {
            $fields = ['identifier = ?', 'unit_type = ?', 'status = ?'];
            $params = [$identifier, $unitType, $unitStatus];

            if ($hasLastStatusAt) {
                $fields[] = 'last_status_at = NOW()';
            }
            if ($hasCurrentIncidentId && $unitStatus === 'available') {
                $fields[] = 'current_incident_id = NULL';
            }

            $params[] = (int) $existingUnit['id'];
            $stmt = $pdo->prepare("UPDATE `units` SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);
            return;
        }

        $columns = ['identifier', 'unit_type', 'status'];
        $values = ['?', '?', '?'];
        $params = [$identifier, $unitType, $unitStatus];

        if ($hasCurrentIncidentId) {
            $columns[] = 'current_incident_id';
            $values[] = 'NULL';
        }
        if ($hasLastStatusAt) {
            $columns[] = 'last_status_at';
            $values[] = 'NOW()';
        }
        if (ers_vehicle_resource_column_exists($pdo, 'units', 'latitude') && ers_vehicle_resource_column_exists($pdo, 'units', 'longitude')) {
            $columns[] = 'latitude';
            $columns[] = 'longitude';
            $values[] = 'NULL';
            $values[] = 'NULL';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO `units` (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $values) . ")"
        );
        $stmt->execute($params);
    }
}

if (!function_exists('ers_update_vehicle_resource_status_by_identifier')) {
    function ers_update_vehicle_resource_status_by_identifier(PDO $pdo, string $identifier, string $status): bool
    {
        $tableName = ers_vehicle_resource_units_table($pdo);
        $identifier = strtoupper(trim($identifier));
        $status = strtolower(trim($status));

        if ($tableName === null || $identifier === '') {
            return false;
        }

        $allowedStatuses = ['available', 'in_use', 'maintenance', 'offline'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'available';
        }

        $stmt = $pdo->prepare(
            "UPDATE `" . $tableName . "`
             SET status = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE code = ?
               AND LOWER(category) = 'vehicles'"
        );
        $stmt->execute([$status, $identifier]);

        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('ers_sync_vehicle_resource_status_by_unit_id')) {
    function ers_sync_vehicle_resource_status_by_unit_id(PDO $pdo, int $unitId, ?string $resourceStatus = null): bool
    {
        if ($unitId <= 0 || !ers_units_table_available($pdo)) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT identifier, status FROM `units` WHERE id = ? LIMIT 1");
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) {
            return false;
        }

        $targetStatus = $resourceStatus !== null && trim($resourceStatus) !== ''
            ? strtolower(trim($resourceStatus))
            : ers_map_unit_status_to_vehicle_resource_status((string) ($unit['status'] ?? 'available'));

        return ers_update_vehicle_resource_status_by_identifier(
            $pdo,
            (string) ($unit['identifier'] ?? ''),
            $targetStatus
        );
    }
}

if (!function_exists('ers_sync_vehicle_resource_status_by_unit_ids')) {
    function ers_sync_vehicle_resource_status_by_unit_ids(PDO $pdo, array $unitIds, ?string $resourceStatus = null): int
    {
        $count = 0;
        $unitIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => (int) $value,
            $unitIds
        ), static fn(int $value): bool => $value > 0)));

        foreach ($unitIds as $unitId) {
            if (ers_sync_vehicle_resource_status_by_unit_id($pdo, $unitId, $resourceStatus)) {
                $count++;
            }
        }

        return $count;
    }
}

if (!function_exists('ers_update_responder_unit_status')) {
    function ers_update_responder_unit_status(PDO $pdo, int $responderId, string $status): bool
    {
        $status = strtolower(trim($status));
        if ($responderId <= 0 || $status === '' || !ers_vehicle_resource_table_exists($pdo, 'users')) {
            return false;
        }
        if (!ers_vehicle_resource_column_exists($pdo, 'users', 'unit_status')) {
            return false;
        }

        $roleFilter = ers_vehicle_resource_column_exists($pdo, 'users', 'role')
            ? " AND LOWER(COALESCE(`role`, '')) = 'responder'"
            : '';

        $stmt = $pdo->prepare(
            "UPDATE `users`
             SET `unit_status` = ?
             WHERE `id` = ?{$roleFilter}"
        );
        $stmt->execute([$status, $responderId]);

        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('ers_sync_all_vehicle_resource_units')) {
    function ers_sync_all_vehicle_resource_units(PDO $pdo, ?string $tableName = null): void
    {
        $tableName = $tableName ?? ers_vehicle_resource_units_table($pdo);
        if ($tableName === null || !ers_units_table_available($pdo)) {
            return;
        }

        $latitudeSelect = ers_vehicle_resource_column_exists($pdo, $tableName, 'latitude') ? 'latitude' : 'NULL AS latitude';
        $longitudeSelect = ers_vehicle_resource_column_exists($pdo, $tableName, 'longitude') ? 'longitude' : 'NULL AS longitude';
        $stmt = $pdo->query(
            "SELECT code, name, category, status, location, {$latitudeSelect}, {$longitudeSelect}, assignment, notes, driver_name, plate_number
             FROM `" . $tableName . "`
             WHERE LOWER(category) = 'vehicles'"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $resource) {
            ers_sync_vehicle_resource_unit($pdo, $resource);
        }
    }
}

if (!function_exists('ers_count_available_vehicle_resource_units')) {
    function ers_count_available_vehicle_resource_units(PDO $pdo, ?string $tableName = null): int
    {
        $tableName = $tableName ?? ers_vehicle_resource_units_table($pdo);
        if ($tableName !== null) {
            ers_sync_all_vehicle_resource_units($pdo, $tableName);

            if (ers_units_table_available($pdo)) {
                $stmt = $pdo->query(
                    "SELECT COUNT(DISTINCT u.id) AS c
                     FROM `units` u
                     INNER JOIN `" . $tableName . "` rr
                        ON rr.code = u.identifier
                       AND LOWER(rr.category) = 'vehicles'
                     WHERE u.status = 'available'"
                );
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return (int) ($row['c'] ?? 0);
            }

            $stmt = $pdo->query(
                "SELECT COUNT(*) AS c
                 FROM `" . $tableName . "`
                 WHERE LOWER(category) = 'vehicles'
                   AND LOWER(status) = 'available'"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int) ($row['c'] ?? 0);
        }

        if (!ers_units_table_available($pdo)) {
            return 0;
        }

        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `units` WHERE status = 'available'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['c'] ?? 0);
    }
}
