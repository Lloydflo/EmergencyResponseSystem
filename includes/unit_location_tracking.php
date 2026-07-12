<?php
declare(strict_types=1);

require_once __DIR__ . '/user_presence.php';

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
        $responderId = (int)($input['responder_id'] ?? $input['responderId'] ?? $input['user_id'] ?? $input['userId'] ?? 0);
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

        return [
            'ok' => true,
            'unit_id' => $unitId,
            'unit_code' => (string)($unit['identifier'] ?? ''),
            'unit_type' => (string)($unit['unit_type'] ?? ''),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => $accuracyM,
        ];
    }
}
