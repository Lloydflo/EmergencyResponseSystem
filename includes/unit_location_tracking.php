<?php
declare(strict_types=1);

require_once __DIR__ . '/user_presence.php';
require_once __DIR__ . '/activity_log.php';

if (!function_exists('ers_unit_location_table_exists')) {
    function ers_unit_location_table_exists(PDO $pdo, string $table): bool
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
}

if (!function_exists('ers_unit_location_column_exists')) {
    function ers_unit_location_column_exists(PDO $pdo, string $table, string $column): bool
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
}

if (!function_exists('ers_unit_location_ensure_schema')) {
    function ers_unit_location_ensure_schema(PDO $pdo): void
    {
        if (ers_unit_location_table_exists($pdo, 'units')) {
            if (!ers_unit_location_column_exists($pdo, 'units', 'latitude')) {
                $pdo->exec("ALTER TABLE `units` ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL");
            }
            if (!ers_unit_location_column_exists($pdo, 'units', 'longitude')) {
                $pdo->exec("ALTER TABLE `units` ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL AFTER `latitude`");
            }
            if (!ers_unit_location_column_exists($pdo, 'units', 'last_status_at')) {
                $pdo->exec("ALTER TABLE `units` ADD COLUMN `last_status_at` TIMESTAMP NULL DEFAULT NULL");
            }
        }

        if (!ers_unit_location_table_exists($pdo, 'unit_locations')) {
            $pdo->exec(
                "CREATE TABLE `unit_locations` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `unit_id` BIGINT UNSIGNED NOT NULL,
                    `responder_id` INT UNSIGNED DEFAULT NULL,
                    `latitude` DECIMAL(10,7) NOT NULL,
                    `longitude` DECIMAL(10,7) NOT NULL,
                    `accuracy_m` DECIMAL(8,2) DEFAULT NULL,
                    `speed_kph` DECIMAL(6,2) DEFAULT NULL,
                    `heading_deg` DECIMAL(5,2) DEFAULT NULL,
                    `source` VARCHAR(50) DEFAULT NULL,
                    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_unit_locations_unit_recorded` (`unit_id`, `recorded_at`),
                    KEY `idx_unit_locations_responder_recorded` (`responder_id`, `recorded_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return;
        }

        $columns = [
            'speed_kph' => "ALTER TABLE `unit_locations` ADD COLUMN `speed_kph` DECIMAL(6,2) DEFAULT NULL AFTER `longitude`",
            'heading_deg' => "ALTER TABLE `unit_locations` ADD COLUMN `heading_deg` DECIMAL(5,2) DEFAULT NULL AFTER `speed_kph`",
            'responder_id' => "ALTER TABLE `unit_locations` ADD COLUMN `responder_id` INT UNSIGNED DEFAULT NULL AFTER `unit_id`",
            'accuracy_m' => "ALTER TABLE `unit_locations` ADD COLUMN `accuracy_m` DECIMAL(8,2) DEFAULT NULL AFTER `longitude`",
            'source' => "ALTER TABLE `unit_locations` ADD COLUMN `source` VARCHAR(50) DEFAULT NULL AFTER `heading_deg`",
            'recorded_at' => "ALTER TABLE `unit_locations` ADD COLUMN `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        ];

        foreach ($columns as $column => $sql) {
            if (!ers_unit_location_column_exists($pdo, 'unit_locations', $column)) {
                $pdo->exec($sql);
            }
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_KEY, EXTRA
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'unit_locations'
                   AND COLUMN_NAME = 'id'
                 LIMIT 1"
            );
            $stmt->execute();
            $idMeta = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$idMeta) {
                $pdo->exec("ALTER TABLE `unit_locations` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
            } elseif (stripos((string)($idMeta['EXTRA'] ?? ''), 'auto_increment') === false) {
                $hasPrimary = strtoupper((string)($idMeta['COLUMN_KEY'] ?? '')) === 'PRI';
                $alter = "ALTER TABLE `unit_locations` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT";
                if (!$hasPrimary) {
                    $alter .= ", ADD PRIMARY KEY (`id`)";
                }
                $pdo->exec($alter);
            }
        } catch (Throwable $e) {
            error_log('unit_locations id auto-increment check skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ers_unit_location_normalize_coordinate')) {
    function ers_unit_location_normalize_coordinate($value, float $min, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }

        if (!is_numeric($value)) {
            return null;
        }

        $coordinate = (float)$value;
        if (!is_finite($coordinate) || $coordinate < $min || $coordinate > $max) {
            return null;
        }

        return $coordinate;
    }
}

if (!function_exists('ers_unit_location_normalize_optional_number')) {
    function ers_unit_location_normalize_optional_number($value, float $min, float $max): ?float
    {
        $number = ers_unit_location_normalize_coordinate($value, $min, $max);
        return $number;
    }
}

if (!function_exists('ers_unit_location_distance_meters')) {
    function ers_unit_location_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
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
}

if (!function_exists('ers_unit_location_ensure_zone_schema')) {
    function ers_unit_location_ensure_zone_schema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `responder_zones` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `zone_type` VARCHAR(50) NOT NULL DEFAULT 'custom',
                `center_latitude` DECIMAL(10,7) NOT NULL,
                `center_longitude` DECIMAL(10,7) NOT NULL,
                `radius_m` DECIMAL(10,2) NOT NULL DEFAULT 250.00,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_responder_zones_active` (`is_active`),
                KEY `idx_responder_zones_type` (`zone_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `responder_zone_states` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `zone_key` VARCHAR(120) NOT NULL,
                `zone_name` VARCHAR(150) NOT NULL,
                `zone_type` VARCHAR(50) NOT NULL DEFAULT 'custom',
                `unit_id` BIGINT UNSIGNED NOT NULL,
                `responder_id` INT UNSIGNED DEFAULT NULL,
                `is_inside` TINYINT(1) NOT NULL DEFAULT 0,
                `last_transition_at` DATETIME DEFAULT NULL,
                `last_checked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `latitude` DECIMAL(10,7) DEFAULT NULL,
                `longitude` DECIMAL(10,7) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_responder_zone_state` (`zone_key`, `unit_id`),
                KEY `idx_responder_zone_states_unit` (`unit_id`),
                KEY `idx_responder_zone_states_responder` (`responder_id`),
                KEY `idx_responder_zone_states_inside` (`is_inside`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('ers_unit_location_active_zones')) {
    function ers_unit_location_active_zones(PDO $pdo): array
    {
        $zones = [];

        try {
            if (ers_unit_location_table_exists($pdo, 'responder_zones')) {
                $stmt = $pdo->query(
                    "SELECT id, name, zone_type, center_latitude, center_longitude, radius_m
                     FROM responder_zones
                     WHERE is_active = 1
                     ORDER BY id ASC"
                );
                foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                    $lat = ers_unit_location_normalize_coordinate($row['center_latitude'] ?? null, -90, 90);
                    $lng = ers_unit_location_normalize_coordinate($row['center_longitude'] ?? null, -180, 180);
                    $radius = ers_unit_location_normalize_optional_number($row['radius_m'] ?? null, 1, 100000);
                    if ($lat === null || $lng === null || $radius === null) {
                        continue;
                    }
                    $zones[] = [
                        'key' => 'zone:' . (int)$row['id'],
                        'name' => trim((string)($row['name'] ?? 'Zone #' . (int)$row['id'])),
                        'type' => trim((string)($row['zone_type'] ?? 'custom')) ?: 'custom',
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'radius_m' => $radius,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('responder_zones load skipped: ' . $e->getMessage());
        }

        try {
            if (
                ers_unit_location_table_exists($pdo, 'incidents')
                && ers_unit_location_column_exists($pdo, 'incidents', 'id')
                && ers_unit_location_column_exists($pdo, 'incidents', 'latitude')
                && ers_unit_location_column_exists($pdo, 'incidents', 'longitude')
            ) {
                $referenceExpr = ers_unit_location_column_exists($pdo, 'incidents', 'reference_no') ? 'reference_no' : "'' AS reference_no";
                $titleExpr = ers_unit_location_column_exists($pdo, 'incidents', 'title') ? 'title' : "'' AS title";
                $typeExpr = ers_unit_location_column_exists($pdo, 'incidents', 'type') ? 'type' : "'' AS type";
                $orderExpr = ers_unit_location_column_exists($pdo, 'incidents', 'updated_at')
                    ? 'updated_at DESC, id DESC'
                    : 'id DESC';
                $stmt = $pdo->query(
                    "SELECT id, {$referenceExpr}, {$titleExpr}, {$typeExpr}, latitude, longitude
                     FROM incidents
                     WHERE status IN ('pending','dispatched','active','in_progress','enroute','on_scene')
                       AND latitude IS NOT NULL
                       AND longitude IS NOT NULL
                     ORDER BY {$orderExpr}
                     LIMIT 100"
                );
                foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                    $lat = ers_unit_location_normalize_coordinate($row['latitude'] ?? null, -90, 90);
                    $lng = ers_unit_location_normalize_coordinate($row['longitude'] ?? null, -180, 180);
                    if ($lat === null || $lng === null) {
                        continue;
                    }
                    $label = trim((string)($row['reference_no'] ?? ''));
                    if ($label === '') {
                        $label = trim((string)($row['title'] ?? ''));
                    }
                    if ($label === '') {
                        $label = 'Incident #' . (int)$row['id'];
                    }
                    $zones[] = [
                        'key' => 'incident:' . (int)$row['id'],
                        'name' => $label,
                        'type' => 'incident',
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'radius_m' => 250.0,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('incident zones load skipped: ' . $e->getMessage());
        }

        return $zones;
    }
}

if (!function_exists('ers_unit_location_log_zone_transition')) {
    function ers_unit_location_log_zone_transition(
        PDO $pdo,
        array $zone,
        array $unit,
        int $unitId,
        int $responderId,
        bool $entered,
        float $latitude,
        float $longitude,
        float $distanceMeters
    ): void {
        $unitCode = trim((string)($unit['identifier'] ?? 'Unit #' . $unitId));
        $zoneName = trim((string)($zone['name'] ?? 'Zone'));
        $action = $entered ? 'responder_zone_entered' : 'responder_zone_left';
        $verb = $entered ? 'entered' : 'left';
        $details = [
            'message' => $unitCode . ' ' . $verb . ' ' . $zoneName . '.',
            'unit_id' => $unitId,
            'unit_code' => $unitCode,
            'unit_type' => (string)($unit['unit_type'] ?? ''),
            'responder_id' => $responderId > 0 ? $responderId : null,
            'zone_key' => (string)($zone['key'] ?? ''),
            'zone_name' => $zoneName,
            'zone_type' => (string)($zone['type'] ?? 'custom'),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'distance_m' => round($distanceMeters, 1),
        ];
        log_activity_event(
            $responderId > 0 ? $responderId : null,
            $action,
            'responder_zone',
            $unitId,
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $details['message']
        );
    }
}

if (!function_exists('ers_unit_location_process_zone_transitions')) {
    function ers_unit_location_process_zone_transitions(PDO $pdo, int $unitId, int $responderId, float $latitude, float $longitude, array $unit): array
    {
        if ($unitId <= 0) {
            return [];
        }

        try {
            ers_unit_location_ensure_zone_schema($pdo);
            $zones = ers_unit_location_active_zones($pdo);
            if ($zones === []) {
                return [];
            }

            $lookup = $pdo->prepare(
                "SELECT id, is_inside
                 FROM responder_zone_states
                 WHERE zone_key = ? AND unit_id = ?
                 LIMIT 1"
            );
            $insert = $pdo->prepare(
                "INSERT INTO responder_zone_states
                    (zone_key, zone_name, zone_type, unit_id, responder_id, is_inside,
                     last_transition_at, last_checked_at, latitude, longitude)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)"
            );
            $update = $pdo->prepare(
                "UPDATE responder_zone_states
                 SET zone_name = ?,
                     zone_type = ?,
                     responder_id = ?,
                     is_inside = ?,
                     last_transition_at = ?,
                     last_checked_at = CURRENT_TIMESTAMP,
                     latitude = ?,
                     longitude = ?
                 WHERE id = ?"
            );

            $transitions = [];
            foreach ($zones as $zone) {
                $distance = ers_unit_location_distance_meters(
                    $latitude,
                    $longitude,
                    (float)$zone['latitude'],
                    (float)$zone['longitude']
                );
                $inside = $distance <= (float)$zone['radius_m'];
                $lookup->execute([(string)$zone['key'], $unitId]);
                $state = $lookup->fetch(PDO::FETCH_ASSOC);
                $wasInside = $state ? ((int)($state['is_inside'] ?? 0) === 1) : false;
                $changed = !$state || $wasInside !== $inside;
                $transitionAt = $changed ? date('Y-m-d H:i:s') : null;

                if ($state) {
                    $update->execute([
                        (string)$zone['name'],
                        (string)$zone['type'],
                        $responderId > 0 ? $responderId : null,
                        $inside ? 1 : 0,
                        $transitionAt,
                        $latitude,
                        $longitude,
                        (int)$state['id'],
                    ]);
                } else {
                    $insert->execute([
                        (string)$zone['key'],
                        (string)$zone['name'],
                        (string)$zone['type'],
                        $unitId,
                        $responderId > 0 ? $responderId : null,
                        $inside ? 1 : 0,
                        $transitionAt,
                        $latitude,
                        $longitude,
                    ]);
                }

                if ($changed && ($inside || $wasInside)) {
                    ers_unit_location_log_zone_transition($pdo, $zone, $unit, $unitId, $responderId, $inside, $latitude, $longitude, $distance);
                    $transitions[] = [
                        'zone_key' => (string)$zone['key'],
                        'zone_name' => (string)$zone['name'],
                        'zone_type' => (string)$zone['type'],
                        'transition' => $inside ? 'entered' : 'left',
                    ];
                }
            }

            return $transitions;
        } catch (Throwable $e) {
            error_log('Zone transition processing skipped: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('ers_unit_location_lookup_unit_by_id')) {
    function ers_unit_location_lookup_unit_by_id(PDO $pdo, int $unitId): ?array
    {
        if ($unitId <= 0 || !ers_unit_location_table_exists($pdo, 'units')) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT id, identifier, unit_type, status FROM units WHERE id = ? LIMIT 1");
        $stmt->execute([$unitId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        return $unit ?: null;
    }
}

if (!function_exists('ers_unit_location_lookup_unit_by_code')) {
    function ers_unit_location_lookup_unit_by_code(PDO $pdo, string $unitCode): ?array
    {
        $unitCode = trim($unitCode);
        if ($unitCode === '' || !ers_unit_location_table_exists($pdo, 'units')) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT id, identifier, unit_type, status
            FROM units
            WHERE UPPER(TRIM(identifier)) = UPPER(TRIM(?))
            LIMIT 1
        ");
        $stmt->execute([$unitCode]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        return $unit ?: null;
    }
}

if (!function_exists('ers_unit_location_responder_unit_code')) {
    function ers_unit_location_responder_unit_code(PDO $pdo, int $responderId): string
    {
        if ($responderId <= 0 || !ers_unit_location_table_exists($pdo, 'users')) {
            return '';
        }

        if (ers_unit_location_column_exists($pdo, 'users', 'unit_code')) {
            $stmt = $pdo->prepare("
                SELECT unit_code
                FROM users
                WHERE id = ?
                  AND LOWER(COALESCE(role, '')) = 'responder'
                LIMIT 1
            ");
            $stmt->execute([$responderId]);
            $unitCode = trim((string)$stmt->fetchColumn());
            if ($unitCode !== '') {
                return $unitCode;
            }
        }

        if (
            ers_unit_location_table_exists($pdo, 'dispatch_operator_records') &&
            ers_unit_location_column_exists($pdo, 'dispatch_operator_records', 'assigned_unit_code')
        ) {
            $stmt = $pdo->prepare("
                SELECT assigned_unit_code
                FROM dispatch_operator_records
                WHERE assigned_to = ?
                  AND TRIM(COALESCE(assigned_unit_code, '')) <> ''
                  AND status IN ('pending','assigned','received','accepted','en_route','enroute','on_scene')
                ORDER BY assigned_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$responderId]);
            return trim((string)$stmt->fetchColumn());
        }

        return '';
    }
}

if (!function_exists('ers_unit_location_resolve_unit')) {
    function ers_unit_location_resolve_unit(PDO $pdo, array $input): ?array
    {
        $unitId = (int)($input['unit_id'] ?? $input['unitId'] ?? 0);
        if ($unitId > 0) {
            $unit = ers_unit_location_lookup_unit_by_id($pdo, $unitId);
            if ($unit) {
                return $unit;
            }
        }

        $unitCode = trim((string)($input['unit_code'] ?? $input['unitCode'] ?? $input['identifier'] ?? ''));
        if ($unitCode !== '') {
            $unit = ers_unit_location_lookup_unit_by_code($pdo, $unitCode);
            if ($unit) {
                return $unit;
            }
        }

        $responderId = (int)($input['responder_id'] ?? $input['responderId'] ?? $input['user_id'] ?? $input['userId'] ?? 0);
        if ($responderId > 0) {
            $resolvedCode = ers_unit_location_responder_unit_code($pdo, $responderId);
            if ($resolvedCode !== '') {
                return ers_unit_location_lookup_unit_by_code($pdo, $resolvedCode);
            }
        }

        return null;
    }
}

if (!function_exists('ers_unit_location_update')) {
    function ers_unit_location_update(PDO $pdo, array $input): array
    {
        $responderId = (int)($input['responder_id'] ?? $input['responderId'] ?? $input['user_id'] ?? $input['userId'] ?? 0);
        if ($responderId > 0 && ers_unit_location_table_exists($pdo, 'user_presence')) {
            $presenceStmt = $pdo->prepare(
                "SELECT is_online
                 FROM user_presence
                 WHERE user_id = ?
                 LIMIT 1"
            );
            $presenceStmt->execute([$responderId]);
            $isOnline = $presenceStmt->fetchColumn();
            if ($isOnline !== false && (int)$isOnline !== 1) {
                return ['ok' => false, 'error' => 'Responder is offline'];
            }
        }
        if ($responderId > 0 && ers_unit_location_table_exists($pdo, 'users')) {
            $unitStatusSelect = ers_unit_location_column_exists($pdo, 'users', 'unit_status')
                ? 'COALESCE(unit_status, \'\')'
                : "''";
            $accountStatusSelect = ers_unit_location_column_exists($pdo, 'users', 'status')
                ? 'COALESCE(status, \'\')'
                : "'active'";
            $statusStmt = $pdo->prepare(
                "SELECT {$accountStatusSelect} AS account_status,
                        {$unitStatusSelect} AS unit_status
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );
            $statusStmt->execute([$responderId]);
            $statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($statusRow)) {
                $accountStatus = strtolower(trim((string)($statusRow['account_status'] ?? '')));
                $unitStatus = strtolower(trim((string)($statusRow['unit_status'] ?? '')));
                if ($accountStatus !== '' && $accountStatus !== 'active') {
                    return ['ok' => false, 'error' => 'Responder account is inactive'];
                }
                if (in_array($unitStatus, ['offline', 'unavailable', 'out_of_service', 'off_duty', 'leave'], true)) {
                    return ['ok' => false, 'error' => 'Responder is offline'];
                }
            }
        }

        $latitude = ers_unit_location_normalize_coordinate($input['latitude'] ?? $input['lat'] ?? null, -90, 90);
        $longitude = ers_unit_location_normalize_coordinate(
            $input['longitude'] ?? $input['lng'] ?? $input['lon'] ?? null,
            -180,
            180
        );

        if ($latitude === null || $longitude === null) {
            return ['ok' => false, 'error' => 'Valid latitude and longitude are required'];
        }

        if (abs($latitude) < 0.000001 && abs($longitude) < 0.000001) {
            return ['ok' => false, 'error' => 'GPS coordinates cannot be 0,0'];
        }

        $unit = ers_unit_location_resolve_unit($pdo, $input);
        if (!$unit) {
            return ['ok' => false, 'error' => 'Assigned unit not found'];
        }

        ers_unit_location_ensure_schema($pdo);

        $unitId = (int)$unit['id'];
        if ($responderId > 0) {
            touch_user_presence($pdo, $responderId);
        }

        $speedKph = ers_unit_location_normalize_optional_number($input['speed_kph'] ?? $input['speedKph'] ?? null, 0, 300);
        if ($speedKph === null && isset($input['speed'])) {
            $speed = ers_unit_location_normalize_optional_number($input['speed'], 0, 100);
            $speedKph = $speed !== null ? $speed * 3.6 : null;
        }
        $headingDeg = ers_unit_location_normalize_optional_number($input['heading_deg'] ?? $input['heading'] ?? null, 0, 360);
        $accuracyM = ers_unit_location_normalize_optional_number($input['accuracy_m'] ?? $input['accuracy'] ?? null, 0, 10000);
        $source = substr(trim((string)($input['source'] ?? 'responder_gps')), 0, 50);

        $hasResponderId = ers_unit_location_column_exists($pdo, 'unit_locations', 'responder_id');
        $hasAccuracy = ers_unit_location_column_exists($pdo, 'unit_locations', 'accuracy_m');
        $hasSource = ers_unit_location_column_exists($pdo, 'unit_locations', 'source');

        $columns = ['unit_id', 'latitude', 'longitude', 'speed_kph', 'heading_deg'];
        $values = [$unitId, $latitude, $longitude, $speedKph, $headingDeg];

        if ($hasResponderId) {
            $columns[] = 'responder_id';
            $values[] = $responderId > 0 ? $responderId : null;
        }
        if ($hasAccuracy) {
            $columns[] = 'accuracy_m';
            $values[] = $accuracyM;
        }
        if ($hasSource) {
            $columns[] = 'source';
            $values[] = $source;
        }

        $columnSql = '`' . implode('`, `', $columns) . '`';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare("INSERT INTO `unit_locations` ({$columnSql}) VALUES ({$placeholders})");
        $stmt->execute($values);

        $sets = ['latitude = ?', 'longitude = ?'];
        $params = [$latitude, $longitude];
        if (ers_unit_location_column_exists($pdo, 'units', 'last_status_at')) {
            $sets[] = 'last_status_at = CURRENT_TIMESTAMP';
        }
        if (ers_unit_location_column_exists($pdo, 'units', 'updated_at')) {
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        }
        $params[] = $unitId;
        $unitUpdate = $pdo->prepare('UPDATE units SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $unitUpdate->execute($params);
        $zoneTransitions = ers_unit_location_process_zone_transitions($pdo, $unitId, $responderId, $latitude, $longitude, $unit);

        return [
            'ok' => true,
            'unit_id' => $unitId,
            'unit_code' => (string)($unit['identifier'] ?? ''),
            'unit_type' => (string)($unit['unit_type'] ?? ''),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => $accuracyM,
            'zone_transitions' => $zoneTransitions,
        ];
    }
}
