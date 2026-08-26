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

if (!function_exists('ers_vehicle_resource_has_assigned_responder')) {
    function ers_vehicle_resource_has_assigned_responder(PDO $pdo, string $unitCode): bool
    {
        $unitCode = strtoupper(trim($unitCode));
        if ($unitCode === '') {
            return false;
        }

        if (
            ers_vehicle_resource_table_exists($pdo, 'users') &&
            ers_vehicle_resource_column_exists($pdo, 'users', 'role') &&
            ers_vehicle_resource_column_exists($pdo, 'users', 'unit_code')
        ) {
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM `users`
                 WHERE LOWER(COALESCE(`role`, '')) = 'responder'
                   AND `unit_code` IS NOT NULL
                   AND TRIM(`unit_code`) <> ''
                   AND UPPER(TRIM(`unit_code`)) = ?
                 LIMIT 1"
            );
            $stmt->execute([$unitCode]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }

        if (
            ers_vehicle_resource_table_exists($pdo, 'responders') &&
            ers_vehicle_resource_column_exists($pdo, 'responders', 'assigned_unit_id') &&
            ers_units_table_available($pdo)
        ) {
            $stmt = $pdo->prepare(
                "SELECT 1
                 FROM `responders` r
                 INNER JOIN `units` u ON u.id = r.assigned_unit_id
                 WHERE UPPER(TRIM(u.identifier)) = ?
                 LIMIT 1"
            );
            $stmt->execute([$unitCode]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }

        return false;
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
             WHERE UPPER(TRIM(code)) = UPPER(TRIM(?))
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

        $status = ers_normalize_responder_unit_status_for_schema($pdo, $status);
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

if (!function_exists('ers_column_enum_values')) {
    function ers_column_enum_values(PDO $pdo, string $tableName, string $columnName): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_TYPE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1"
            );
            $stmt->execute([$tableName, $columnName]);
            $columnType = (string) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return [];
        }

        if (!preg_match("/^enum\\((.*)\\)$/i", $columnType, $matches)) {
            return [];
        }

        $values = str_getcsv($matches[1], ',', "'");
        return array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string) $value)),
            $values
        )));
    }
}

if (!function_exists('ers_normalize_responder_unit_status_for_schema')) {
    function ers_normalize_responder_unit_status_for_schema(PDO $pdo, string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            $status = 'available';
        }

        $allowed = ers_column_enum_values($pdo, 'users', 'unit_status');
        if ($allowed === [] || in_array($status, $allowed, true)) {
            return $status;
        }

        $fallbacks = [
            'offline' => ['offline', 'out_of_service', 'unavailable', 'maintenance'],
            'unavailable' => ['unavailable', 'out_of_service', 'offline', 'maintenance'],
            'out_of_service' => ['out_of_service', 'offline', 'unavailable', 'maintenance'],
            'in_use' => ['busy', 'assigned', 'in_use'],
            'assigned' => ['busy', 'assigned', 'in_use'],
            'en_route' => ['en_route', 'enroute', 'busy'],
            'enroute' => ['enroute', 'en_route', 'busy'],
            'on_scene' => ['on_scene', 'busy'],
            'available' => ['available'],
            'busy' => ['busy'],
            'maintenance' => ['maintenance'],
        ];

        foreach ($fallbacks[$status] ?? [$status, 'available'] as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        return $allowed[0] ?? 'available';
    }
}

if (!function_exists('ers_vehicle_resource_unit_code_for_responder')) {
    function ers_vehicle_resource_unit_code_for_responder(PDO $pdo, int $responderId): string
    {
        if ($responderId <= 0 || !ers_vehicle_resource_table_exists($pdo, 'users')) {
            return '';
        }
        if (!ers_vehicle_resource_column_exists($pdo, 'users', 'unit_code')) {
            return '';
        }

        $roleFilter = ers_vehicle_resource_column_exists($pdo, 'users', 'role')
            ? " AND LOWER(COALESCE(`role`, '')) = 'responder'"
            : '';

        try {
            $stmt = $pdo->prepare(
                "SELECT `unit_code`
                 FROM `users`
                 WHERE `id` = ?{$roleFilter}
                 LIMIT 1"
            );
            $stmt->execute([$responderId]);
            return strtoupper(trim((string) $stmt->fetchColumn()));
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('ers_reconcile_all_dispatch_and_unit_statuses')) {
    function ers_reconcile_all_dispatch_and_unit_statuses(PDO $pdo): void
    {
        try {
            $hasIncidents = ers_vehicle_resource_table_exists($pdo, 'incidents');
            $hasDispatches = ers_vehicle_resource_table_exists($pdo, 'dispatches');
            $hasOperatorRecords = ers_vehicle_resource_table_exists($pdo, 'dispatch_operator_records');
            $hasUnits = ers_units_table_available($pdo);
            $hasUsers = ers_vehicle_resource_table_exists($pdo, 'users');
            $hasResourceRecords = ers_vehicle_resource_units_table($pdo) !== null;
            $resourceTable = ers_vehicle_resource_units_table($pdo) ?? 'resource_records';

            // 1. Mark dispatch_operator_records completed if linked incident is resolved/closed/cancelled
            if ($hasOperatorRecords && $hasIncidents && ers_vehicle_resource_column_exists($pdo, 'dispatch_operator_records', 'incident_id')) {
                $pdo->exec("
                    UPDATE `dispatch_operator_records` dor
                    INNER JOIN `incidents` i ON i.id = dor.incident_id
                    SET dor.status = 'completed'
                    WHERE LOWER(COALESCE(i.status, '')) IN ('resolved', 'closed', 'cancelled', 'completed')
                      AND LOWER(COALESCE(dor.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                ");
            }

            // 1b. Mark dispatch_operator_records completed if assigned responder in users table is available
            if ($hasOperatorRecords && $hasUsers && ers_vehicle_resource_column_exists($pdo, 'users', 'unit_status')) {
                $pdo->exec("
                    UPDATE `dispatch_operator_records` dor
                    INNER JOIN `users` u ON u.id = dor.assigned_to
                    SET dor.status = 'completed'
                    WHERE LOWER(COALESCE(u.unit_status, '')) IN ('available', 'ready', 'on_duty')
                      AND LOWER(COALESCE(dor.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                ");
            }

            // 2. Mark dispatches cleared if linked incident is resolved/closed/cancelled
            if ($hasDispatches && $hasIncidents && ers_vehicle_resource_column_exists($pdo, 'dispatches', 'incident_id')) {
                $clearedAtSql = ers_vehicle_resource_column_exists($pdo, 'dispatches', 'cleared_at') ? ", d.cleared_at = COALESCE(d.cleared_at, CURRENT_TIMESTAMP)" : "";
                $pdo->exec("
                    UPDATE `dispatches` d
                    INNER JOIN `incidents` i ON i.id = d.incident_id
                    SET d.status = 'cleared'{$clearedAtSql}
                    WHERE LOWER(COALESCE(i.status, '')) IN ('resolved', 'closed', 'cancelled', 'completed')
                      AND LOWER(COALESCE(d.status, '')) IN ('assigned','acknowledged','enroute','en_route','on_scene','pending')
                ");
            }

            // 3. Clear current_incident_id on units if linked incident is resolved/closed/cancelled
            if ($hasUnits && $hasIncidents && ers_vehicle_resource_column_exists($pdo, 'units', 'current_incident_id')) {
                $lastStatusSql = ers_vehicle_resource_column_exists($pdo, 'units', 'last_status_at') ? ", u.last_status_at = CURRENT_TIMESTAMP" : "";
                $pdo->exec("
                    UPDATE `units` u
                    INNER JOIN `incidents` i ON i.id = u.current_incident_id
                    SET u.current_incident_id = NULL,
                        u.status = 'available'{$lastStatusSql}
                    WHERE LOWER(COALESCE(i.status, '')) IN ('resolved', 'closed', 'cancelled', 'completed')
                      AND LOWER(COALESCE(u.status, '')) IN ('assigned','acknowledged','enroute','en_route','on_scene','busy')
                ");
            }

            // 4. Release units that have status busy/assigned/enroute but NO live unresolved dispatch
            if ($hasUnits) {
                $lastStatusSql = ers_vehicle_resource_column_exists($pdo, 'units', 'last_status_at') ? ", u.last_status_at = CURRENT_TIMESTAMP" : "";
                $currIncSql = ers_vehicle_resource_column_exists($pdo, 'units', 'current_incident_id') ? ", u.current_incident_id = NULL" : "";

                $dispatchCheck = $hasDispatches && $hasIncidents && ers_vehicle_resource_column_exists($pdo, 'dispatches', 'incident_id')
                    ? "AND NOT EXISTS (
                        SELECT 1 FROM `dispatches` d
                        INNER JOIN `incidents` i ON i.id = d.incident_id
                        WHERE d.unit_id = u.id
                          AND LOWER(COALESCE(i.status, '')) NOT IN ('resolved','closed','cancelled','completed')
                          AND LOWER(COALESCE(d.status, '')) IN ('assigned','acknowledged','enroute','en_route','on_scene','pending')
                    )" : "";

                $operatorCheck = $hasOperatorRecords && $hasIncidents && ers_vehicle_resource_column_exists($pdo, 'dispatch_operator_records', 'incident_id') && ers_vehicle_resource_column_exists($pdo, 'dispatch_operator_records', 'assigned_unit_code')
                    ? "AND NOT EXISTS (
                        SELECT 1 FROM `dispatch_operator_records` dor
                        INNER JOIN `incidents` i ON i.id = dor.incident_id
                        WHERE UPPER(TRIM(dor.assigned_unit_code)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(u.identifier)) COLLATE utf8mb4_unicode_ci
                          AND LOWER(COALESCE(i.status, '')) NOT IN ('resolved','closed','cancelled','completed')
                          AND LOWER(COALESCE(dor.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                    )" : "";

                $eventCheck = ers_vehicle_resource_table_exists($pdo, 'event_unit_dispatches')
                    ? "AND NOT EXISTS (
                        SELECT 1 FROM `event_unit_dispatches` ed
                        WHERE ed.unit_id = u.id
                          AND LOWER(COALESCE(ed.status, '')) = 'assigned'
                    )" : "";

                $pdo->exec("
                    UPDATE `units` u
                    SET u.status = 'available'{$currIncSql}{$lastStatusSql}
                    WHERE LOWER(COALESCE(u.status, '')) IN ('assigned','acknowledged','enroute','en_route','on_scene','busy')
                       {$dispatchCheck}
                       {$operatorCheck}
                       {$eventCheck}
                ");
            }

            // 5. Reset responder unit_status in users table to 'available' if no active unresolved dispatch exists
            if ($hasUsers && ers_vehicle_resource_column_exists($pdo, 'users', 'unit_status')) {
                $roleFilter = ers_vehicle_resource_column_exists($pdo, 'users', 'role')
                    ? " AND LOWER(COALESCE(usr.role, '')) = 'responder'"
                    : "";

                $operatorCheck = $hasOperatorRecords
                    ? ($hasIncidents && ers_vehicle_resource_column_exists($pdo, 'dispatch_operator_records', 'incident_id')
                        ? "AND NOT EXISTS (
                            SELECT 1 FROM `dispatch_operator_records` dor
                            LEFT JOIN `incidents` i ON i.id = dor.incident_id
                            WHERE dor.assigned_to = usr.id
                              AND LOWER(COALESCE(dor.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                              AND (dor.incident_id IS NULL OR dor.incident_id = 0 OR i.id IS NULL OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved','closed','cancelled','completed'))
                        )"
                        : "AND NOT EXISTS (
                            SELECT 1 FROM `dispatch_operator_records` dor
                            WHERE dor.assigned_to = usr.id
                              AND LOWER(COALESCE(dor.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                        )")
                    : "";

                $eventCheck = ers_vehicle_resource_table_exists($pdo, 'event_unit_dispatches') && $hasUnits && ers_vehicle_resource_column_exists($pdo, 'users', 'unit_code')
                    ? "AND NOT EXISTS (
                        SELECT 1 FROM `event_unit_dispatches` ed
                        INNER JOIN `units` un ON un.id = ed.unit_id
                        WHERE UPPER(TRIM(un.identifier)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(usr.unit_code)) COLLATE utf8mb4_unicode_ci
                          AND LOWER(COALESCE(ed.status, '')) = 'assigned'
                    )" : "";

                $pdo->exec("
                    UPDATE `users` usr
                    SET usr.unit_status = 'available'
                    WHERE LOWER(COALESCE(usr.unit_status, '')) IN ('busy','in_use','assigned','accepted','acknowledged','enroute','en_route','on_scene')
                      {$roleFilter}
                      {$operatorCheck}
                      {$eventCheck}
                ");
            }

            // 6. Reconcile vehicle resource_records status to 'available' if vehicle is currently 'in_use' with no active dispatch
            if ($hasResourceRecords) {
                $codeCol = 'code';
                $catCol = 'category';
                $statusCol = 'status';

                $operatorCheck = $hasOperatorRecords
                    ? ($hasIncidents && ers_vehicle_resource_column_exists($pdo, 'dispatch_operator_records', 'incident_id')
                        ? "AND NOT EXISTS (
                            SELECT 1 FROM `dispatch_operator_records` dor
                            LEFT JOIN `incidents` i ON i.id = dor.incident_id
                            WHERE UPPER(TRIM(dor.assigned_unit_code)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(rr.`{$codeCol}`)) COLLATE utf8mb4_unicode_ci
                              AND LOWER(COALESCE(dor.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                              AND (dor.incident_id IS NULL OR dor.incident_id = 0 OR i.id IS NULL OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved','closed','cancelled','completed'))
                        )"
                        : "AND NOT EXISTS (
                            SELECT 1 FROM `dispatch_operator_records` dor
                            WHERE UPPER(TRIM(dor.assigned_unit_code)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(rr.`{$codeCol}`)) COLLATE utf8mb4_unicode_ci
                              AND LOWER(COALESCE(dor.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                        )")
                    : "";

                $eventCheck = ers_vehicle_resource_table_exists($pdo, 'event_unit_dispatches') && $hasUnits
                    ? "AND NOT EXISTS (
                        SELECT 1 FROM `event_unit_dispatches` ed
                        INNER JOIN `units` un ON un.id = ed.unit_id
                        WHERE UPPER(TRIM(un.identifier)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(rr.`{$codeCol}`)) COLLATE utf8mb4_unicode_ci
                          AND LOWER(COALESCE(ed.status, '')) = 'assigned'
                    )" : "";

                $dispatchCheck = $hasDispatches && $hasIncidents && $hasUnits && ers_vehicle_resource_column_exists($pdo, 'dispatches', 'incident_id')
                    ? "AND NOT EXISTS (
                        SELECT 1 FROM `dispatches` d
                        INNER JOIN `units` un ON un.id = d.unit_id
                        INNER JOIN `incidents` i ON i.id = d.incident_id
                        WHERE UPPER(TRIM(un.identifier)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(rr.`{$codeCol}`)) COLLATE utf8mb4_unicode_ci
                          AND LOWER(COALESCE(i.status, '')) NOT IN ('resolved','closed','cancelled','completed')
                          AND LOWER(COALESCE(d.status, '')) IN ('assigned','acknowledged','enroute','en_route','on_scene','pending')
                    )" : "";

                $pdo->exec("
                    UPDATE `{$resourceTable}` rr
                    SET rr.`{$statusCol}` = 'available',
                        rr.updated_at = CURRENT_TIMESTAMP
                    WHERE LOWER(COALESCE(rr.`{$catCol}`, '')) = 'vehicles'
                      AND LOWER(COALESCE(rr.`{$statusCol}`, '')) = 'in_use'
                      {$operatorCheck}
                      {$eventCheck}
                      {$dispatchCheck}
                ");
            }

            // 7. Mark responder_backup_requests completed if linked incident is resolved/closed/cancelled
            if (ers_vehicle_resource_table_exists($pdo, 'responder_backup_requests') && $hasIncidents) {
                $pdo->exec("
                    UPDATE `responder_backup_requests` rbr
                    INNER JOIN `incidents` i ON (
                        (rbr.incident_id REGEXP '^[0-9]+$' AND i.id = CAST(rbr.incident_id AS UNSIGNED))
                        OR (i.reference_no COLLATE utf8mb4_unicode_ci = rbr.incident_id COLLATE utf8mb4_unicode_ci)
                    )
                    SET rbr.status = 'completed',
                        rbr.updated_at = CURRENT_TIMESTAMP
                    WHERE LOWER(COALESCE(i.status, '')) IN ('resolved', 'closed', 'cancelled', 'completed')
                      AND LOWER(COALESCE(rbr.status, '')) = 'pending'
                ");
            }

            // 8. Mark responder_resource_requests completed if linked incident is resolved/closed/cancelled
            if (ers_vehicle_resource_table_exists($pdo, 'responder_resource_requests') && $hasIncidents) {
                $pdo->exec("
                    UPDATE `responder_resource_requests` rrr
                    INNER JOIN `incidents` i ON (
                        (rrr.incident_id REGEXP '^[0-9]+$' AND i.id = CAST(rrr.incident_id AS UNSIGNED))
                        OR (i.reference_no COLLATE utf8mb4_unicode_ci = rrr.incident_id COLLATE utf8mb4_unicode_ci)
                    )
                    SET rrr.status = 'completed',
                        rrr.updated_at = CURRENT_TIMESTAMP
                    WHERE LOWER(COALESCE(i.status, '')) IN ('resolved', 'closed', 'cancelled', 'completed')
                      AND LOWER(COALESCE(rrr.status, '')) = 'pending'
                ");
            }

            // 9. Mark resource_requests completed if linked incident is resolved/closed/cancelled
            if (ers_vehicle_resource_table_exists($pdo, 'resource_requests') && $hasIncidents) {
                $rStmt = $pdo->query("SELECT id, details FROM `resource_requests` WHERE status = 'pending'");
                if ($rStmt) {
                    while ($rRow = $rStmt->fetch(PDO::FETCH_ASSOC)) {
                        $rDetails = json_decode((string)($rRow['details'] ?? ''), true);
                        if (is_array($rDetails) && !empty($rDetails['incident_id'])) {
                            $rIncId = (int)$rDetails['incident_id'];
                            $chk = $pdo->prepare("SELECT 1 FROM `incidents` WHERE id = ? AND LOWER(COALESCE(status, '')) IN ('resolved', 'closed', 'cancelled', 'completed') LIMIT 1");
                            $chk->execute([$rIncId]);
                            if ($chk->fetchColumn()) {
                                $upd = $pdo->prepare("UPDATE `resource_requests` SET status = 'completed' WHERE id = ?");
                                $upd->execute([(int)$rRow['id']]);
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('ers_reconcile_all_dispatch_and_unit_statuses skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ers_responder_has_active_dispatch_assignment')) {
    function ers_responder_has_active_dispatch_assignment(PDO $pdo, int $responderId): bool
    {
        if ($responderId <= 0) {
            return false;
        }

        try {
            if (ers_vehicle_resource_table_exists($pdo, 'dispatch_operator_records')) {
                $hasIncidents = ers_vehicle_resource_table_exists($pdo, 'incidents') && ers_vehicle_resource_column_exists($pdo, 'dispatch_operator_records', 'incident_id');
                $incidentJoin = $hasIncidents ? "LEFT JOIN `incidents` i ON i.id = d.incident_id" : "";
                $incidentFilter = $hasIncidents ? "AND (d.incident_id IS NULL OR d.incident_id = 0 OR i.id IS NULL OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved', 'closed', 'cancelled', 'completed'))" : "";

                $stmt = $pdo->prepare(
                    "SELECT 1
                     FROM `dispatch_operator_records` d
                     {$incidentJoin}
                     WHERE d.assigned_to = ?
                       AND LOWER(COALESCE(d.status, '')) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
                       {$incidentFilter}
                     ORDER BY d.assigned_at DESC, d.id DESC
                     LIMIT 1"
                );
                $stmt->execute([$responderId]);
                if ((bool) $stmt->fetchColumn()) {
                    return true;
                }
            }

            if (!ers_vehicle_resource_table_exists($pdo, 'event_unit_dispatches')) {
                return false;
            }

            $eventStmt = $pdo->prepare(
                "SELECT 1
                 FROM event_unit_dispatches ed
                 INNER JOIN users u ON UPPER(TRIM(u.unit_code)) = UPPER(TRIM((SELECT identifier FROM units WHERE id = ed.unit_id LIMIT 1)))
                 WHERE u.id = ?
                   AND LOWER(COALESCE(ed.status, '')) = 'assigned'
                 LIMIT 1"
            );
            $eventStmt->execute([$responderId]);
            return (bool) $eventStmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('ers_update_unit_status_by_identifier')) {
    function ers_update_unit_status_by_identifier(PDO $pdo, string $identifier, string $status): bool
    {
        $identifier = strtoupper(trim($identifier));
        $status = strtolower(trim($status));
        if ($identifier === '' || $status === '' || !ers_units_table_available($pdo)) {
            return false;
        }

        $allowedStatuses = ['available', 'assigned', 'acknowledged', 'enroute', 'en_route', 'on_scene', 'maintenance', 'unavailable', 'offline'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'available';
        }

        $fields = ['status = ?'];
        $params = [$status];
        if (ers_vehicle_resource_column_exists($pdo, 'units', 'last_status_at')) {
            $fields[] = 'last_status_at = NOW()';
        }
        if (ers_vehicle_resource_column_exists($pdo, 'units', 'current_incident_id') && $status === 'available') {
            $fields[] = 'current_incident_id = NULL';
        }

        $params[] = $identifier;
        $stmt = $pdo->prepare(
            "UPDATE `units`
             SET " . implode(', ', $fields) . "
             WHERE UPPER(TRIM(`identifier`)) = ?"
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('ers_map_vehicle_resource_status_to_responder_unit_status')) {
    function ers_map_vehicle_resource_status_to_responder_unit_status(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'in_use') {
            return 'busy';
        }
        if ($status === 'offline') {
            return 'offline';
        }
        if ($status === 'maintenance') {
            return 'maintenance';
        }

        return 'available';
    }
}

if (!function_exists('ers_current_vehicle_resource_status_for_responder')) {
    function ers_current_vehicle_resource_status_for_responder(PDO $pdo, int $responderId): string
    {
        return ers_responder_has_active_dispatch_assignment($pdo, $responderId) ? 'in_use' : 'available';
    }
}

if (!function_exists('ers_sync_vehicle_resource_record_status_for_responder')) {
    function ers_sync_vehicle_resource_record_status_for_responder(PDO $pdo, int $responderId, ?string $resourceStatus = null): bool
    {
        $unitCode = ers_vehicle_resource_unit_code_for_responder($pdo, $responderId);
        if ($unitCode === '') {
            return false;
        }

        $resourceStatus = strtolower(trim((string) $resourceStatus));
        if ($resourceStatus === '') {
            $resourceStatus = ers_current_vehicle_resource_status_for_responder($pdo, $responderId);
        }

        return ers_update_vehicle_resource_status_by_identifier($pdo, $unitCode, $resourceStatus);
    }
}

if (!function_exists('ers_sync_online_vehicle_resource_status_for_responder')) {
    function ers_sync_online_vehicle_resource_status_for_responder(PDO $pdo, int $responderId): bool
    {
        if (ers_responder_has_active_dispatch_assignment($pdo, $responderId)) {
            return ers_sync_vehicle_resource_record_status_for_responder($pdo, $responderId, 'in_use');
        }

        return ers_sync_vehicle_resource_status_for_responder($pdo, $responderId, 'available');
    }
}

if (!function_exists('ers_sync_vehicle_resource_status_for_responder')) {
    function ers_sync_vehicle_resource_status_for_responder(PDO $pdo, int $responderId, ?string $resourceStatus = null): bool
    {
        $unitCode = ers_vehicle_resource_unit_code_for_responder($pdo, $responderId);
        if ($unitCode === '') {
            return false;
        }

        $resourceStatus = strtolower(trim((string) $resourceStatus));
        if ($resourceStatus === '') {
            $resourceStatus = ers_current_vehicle_resource_status_for_responder($pdo, $responderId);
        }

        if (ers_responder_has_active_dispatch_assignment($pdo, $responderId)) {
            $resourceStatus = 'in_use';
        }

        $allowedStatuses = ['available', 'in_use', 'maintenance', 'offline'];
        if (!in_array($resourceStatus, $allowedStatuses, true)) {
            $resourceStatus = 'available';
        }

        $responderStatus = ers_map_vehicle_resource_status_to_responder_unit_status($resourceStatus);
        $unitStatus = ers_map_vehicle_resource_status_to_unit_status($resourceStatus);

        $updated = false;
        $updated = ers_update_responder_unit_status($pdo, $responderId, $responderStatus) || $updated;
        $updated = ers_update_vehicle_resource_status_by_identifier($pdo, $unitCode, $resourceStatus) || $updated;
        $updated = ers_update_unit_status_by_identifier($pdo, $unitCode, $unitStatus) || $updated;

        return $updated;
    }
}

if (!function_exists('ers_vehicle_resource_responder_presence_map')) {
    function ers_vehicle_resource_responder_presence_map(PDO $pdo): array
    {
        if (!ers_vehicle_resource_table_exists($pdo, 'users')) {
            return [];
        }
        if (!ers_vehicle_resource_column_exists($pdo, 'users', 'unit_code')) {
            return [];
        }

        require_once __DIR__ . '/user_presence.php';

        try {
            ensure_user_presence_table($pdo);
            $presenceStatusSql = user_presence_status_sql('up');
            $stmt = $pdo->query(
                "SELECT
                    UPPER(TRIM(u.unit_code)) AS unit_code,
                    u.id AS responder_id,
                    u.name AS responder_name,
                    COALESCE(u.status, '') AS account_status,
                    COALESCE(u.unit_status, '') AS unit_status,
                    {$presenceStatusSql} AS presence_status
                 FROM `users` u
                 LEFT JOIN `user_presence` up ON up.user_id = u.id
                 WHERE LOWER(COALESCE(u.role, '')) = 'responder'
                   AND u.unit_code IS NOT NULL
                   AND TRIM(u.unit_code) <> ''
                 ORDER BY u.id DESC"
            );
        } catch (Throwable $e) {
            return [];
        }

        $presenceMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unitCode = strtoupper(trim((string) ($row['unit_code'] ?? '')));
            if ($unitCode === '' || isset($presenceMap[$unitCode])) {
                continue;
            }
            $presenceMap[$unitCode] = [
                'responder_id' => (int) ($row['responder_id'] ?? 0),
                'account_status' => strtolower(trim((string) ($row['account_status'] ?? ''))),
                'unit_status' => strtolower(trim((string) ($row['unit_status'] ?? ''))),
                'presence_status' => strtolower(trim((string) ($row['presence_status'] ?? 'offline'))) ?: 'offline',
                'responder_name' => trim((string) ($row['responder_name'] ?? '')),
            ];
        }

        return $presenceMap;
    }
}

if (!function_exists('ers_vehicle_resource_status_from_responder_state')) {
    function ers_vehicle_resource_status_from_responder_state(array $state): string
    {
        $accountStatus = strtolower(trim((string) ($state['account_status'] ?? '')));
        if ($accountStatus === 'inactive') {
            return 'offline';
        }

        $presenceStatus = strtolower(trim((string) ($state['presence_status'] ?? 'offline')));
        if ($presenceStatus === 'offline') {
            return 'offline';
        }

        $unitStatus = strtolower(trim((string) ($state['unit_status'] ?? '')));
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
}

if (!function_exists('ers_apply_responder_presence_to_vehicle_resource_row')) {
    function ers_apply_responder_presence_to_vehicle_resource_row(array $row, array $presenceMap): array
    {
        $category = strtolower(trim((string) ($row['category'] ?? '')));
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        if ($category !== 'vehicles' || $code === '' || !isset($presenceMap[$code])) {
            return $row;
        }

        $row['status'] = ers_vehicle_resource_status_from_responder_state($presenceMap[$code]);

        return $row;
    }
}

if (!function_exists('ers_sync_responder_vehicle_resources')) {
    function ers_sync_responder_vehicle_resources(PDO $pdo): int
    {
        ers_reconcile_all_dispatch_and_unit_statuses($pdo);
        $synced = 0;
        foreach (ers_vehicle_resource_responder_presence_map($pdo) as $presence) {
            $responderId = (int) ($presence['responder_id'] ?? 0);
            if ($responderId <= 0) {
                continue;
            }

            $resourceStatus = ers_vehicle_resource_status_from_responder_state($presence);
            if (ers_sync_vehicle_resource_status_for_responder($pdo, $responderId, $resourceStatus)) {
                $synced++;
            }
        }

        return $synced;
    }
}

if (!function_exists('ers_sync_offline_responder_vehicle_resources')) {
    function ers_sync_offline_responder_vehicle_resources(PDO $pdo): int
    {
        return ers_sync_responder_vehicle_resources($pdo);
    }
}

if (!function_exists('ers_sync_all_vehicle_resource_units')) {
    function ers_sync_all_vehicle_resource_units(PDO $pdo, ?string $tableName = null): void
    {
        ers_reconcile_all_dispatch_and_unit_statuses($pdo);
        $tableName = $tableName ?? ers_vehicle_resource_units_table($pdo);
        if ($tableName === null || !ers_units_table_available($pdo)) {
            return;
        }

        $presenceMap = ers_vehicle_resource_responder_presence_map($pdo);
        $latitudeSelect = ers_vehicle_resource_column_exists($pdo, $tableName, 'latitude') ? 'latitude' : 'NULL AS latitude';
        $longitudeSelect = ers_vehicle_resource_column_exists($pdo, $tableName, 'longitude') ? 'longitude' : 'NULL AS longitude';
        $stmt = $pdo->query(
            "SELECT code, name, category, status, location, {$latitudeSelect}, {$longitudeSelect}, assignment, notes, driver_name, plate_number
             FROM `" . $tableName . "`
             WHERE LOWER(category) = 'vehicles'"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $resource) {
            $unitCode = strtoupper(trim((string) ($resource['code'] ?? '')));
            $resourceStatus = strtolower(trim((string) ($resource['status'] ?? '')));
            if ($unitCode === '') {
                ers_sync_vehicle_resource_unit($pdo, $resource);
                continue;
            }

            if (isset($presenceMap[$unitCode])) {
                $resourceStatus = ers_vehicle_resource_status_from_responder_state($presenceMap[$unitCode]);
                $resource['status'] = $resourceStatus;
                ers_update_vehicle_resource_status_by_identifier($pdo, $unitCode, $resourceStatus);
                ers_sync_vehicle_resource_unit($pdo, $resource);
                continue;
            }

            $hasAssignedResponder = ers_vehicle_resource_has_assigned_responder($pdo, $unitCode);
            if ($resourceStatus === 'available' && !$hasAssignedResponder) {
                ers_update_vehicle_resource_status_by_identifier($pdo, $unitCode, 'offline');
                $resource['status'] = 'offline';
            }
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
                $presenceMap = ers_vehicle_resource_responder_presence_map($pdo);
                $stmt = $pdo->query(
                    "SELECT DISTINCT u.id, u.identifier
                     FROM `units` u
                     INNER JOIN `" . $tableName . "` rr
                        ON rr.code = u.identifier
                       AND LOWER(rr.category) = 'vehicles'
                     WHERE u.status = 'available'"
                );
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if ($presenceMap === []) {
                    return count($rows);
                }

                $count = 0;
                foreach ($rows as $row) {
                    $unitCode = strtoupper(trim((string) ($row['identifier'] ?? '')));
                    if ($unitCode !== '' && isset($presenceMap[$unitCode]) && ers_vehicle_resource_status_from_responder_state($presenceMap[$unitCode]) === 'available') {
                        $count++;
                    }
                }
                return $count;
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
