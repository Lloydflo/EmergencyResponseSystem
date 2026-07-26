<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

$auth = ers_external_authenticate();
$pdo = ers_external_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ers_external_json(405, [
        'success' => false,
        'error' => 'POST method required',
    ]);
}

$input = ers_external_input();

try {
    ers_tracking_ensure_tables($pdo);

    $items = ers_tracking_normalize_items($input);
    if ($items === []) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'No officer assignment data found',
            'hint' => 'Send officer_id, availability_status, assigned_district or an officers array.',
        ]);
    }

    $saved = [];
    foreach ($items as $item) {
        $saved[] = ers_tracking_save_assignment($pdo, $item);
    }

    ers_external_json(201, [
        'success' => true,
        'message' => 'Officer tracking data received.',
        'count' => count($saved),
        'items' => $saved,
    ]);
} catch (Throwable $e) {
    error_log('tracking_data.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to receive tracking data',
    ]);
}

function ers_tracking_normalize_items(array $input): array
{
    $rawItems = [];
    if (isset($input['officers']) && is_array($input['officers'])) {
        $rawItems = $input['officers'];
    } elseif (isset($input['assignments']) && is_array($input['assignments'])) {
        $rawItems = $input['assignments'];
    } elseif (isset($input['data']) && is_array($input['data']) && array_is_list($input['data'])) {
        $rawItems = $input['data'];
    } else {
        $rawItems = [$input];
    }

    $items = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $officerId = ers_external_clean($raw['officer_id'] ?? $raw['officerId'] ?? $raw['id'] ?? '', 120);
        if ($officerId === '') {
            continue;
        }

        $availabilityStatus = ers_external_clean(
            $raw['availability_status']
                ?? $raw['availabilityStatus']
                ?? $raw['status']
                ?? '',
            50
        );
        $assignedDistrict = ers_external_clean(
            $raw['assigned_district']
                ?? $raw['assignedDistrict']
                ?? $raw['district']
                ?? '',
            150
        );
        $officerName = ers_external_clean(
            $raw['officer_name']
                ?? $raw['officerName']
                ?? $raw['name']
                ?? '',
            150
        );
        $sourceSystem = ers_external_clean(
            $raw['source_system']
                ?? $raw['sourceSystem']
                ?? $input['source_system']
                ?? $input['sourceSystem']
                ?? 'Group 1',
            120
        );

        $items[] = [
            'officer_id' => $officerId,
            'officer_name' => $officerName,
            'availability_status' => $availabilityStatus,
            'assigned_district' => $assignedDistrict,
            'source_system' => $sourceSystem !== '' ? $sourceSystem : 'Group 1',
            'raw' => $raw,
        ];
    }

    return $items;
}

function ers_tracking_save_assignment(PDO $pdo, array $item): array
{
    $rawPayload = json_encode($item['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        $rawPayload = '{}';
    }

    $hasOfficerName = ers_external_column_exists($pdo, 'external_officer_assignments', 'officer_name');
    if ($hasOfficerName) {
        $stmt = $pdo->prepare(
            "INSERT INTO external_officer_assignments
                (officer_id, officer_name, availability_status, assigned_district, source_system, received_at, raw_payload)
             VALUES
                (?, ?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->execute([
            $item['officer_id'],
            $item['officer_name'] !== '' ? $item['officer_name'] : null,
            $item['availability_status'] !== '' ? $item['availability_status'] : null,
            $item['assigned_district'] !== '' ? $item['assigned_district'] : null,
            $item['source_system'],
            $rawPayload,
        ]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO external_officer_assignments
                (officer_id, availability_status, assigned_district, source_system, received_at, raw_payload)
             VALUES
                (?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->execute([
            $item['officer_id'],
            $item['availability_status'] !== '' ? $item['availability_status'] : null,
            $item['assigned_district'] !== '' ? $item['assigned_district'] : null,
            $item['source_system'],
            $rawPayload,
        ]);
    }

    $id = (int)$pdo->lastInsertId();
    ers_tracking_insert_sync_log($pdo, $id, $item);

    return [
        'id' => $id,
        'officer_id' => $item['officer_id'],
        'officer_name' => $item['officer_name'],
        'availability_status' => $item['availability_status'],
        'assigned_district' => $item['assigned_district'],
        'source_system' => $item['source_system'],
    ];
}

function ers_tracking_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `external_officer_assignments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `officer_id` VARCHAR(120) NOT NULL,
            `officer_name` VARCHAR(150) DEFAULT NULL,
            `availability_status` VARCHAR(50) DEFAULT NULL,
            `assigned_district` VARCHAR(150) DEFAULT NULL,
            `source_system` VARCHAR(120) DEFAULT 'Group 1',
            `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `raw_payload` LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ext_officer_id` (`officer_id`),
            KEY `idx_ext_officer_status` (`availability_status`),
            KEY `idx_ext_officer_district` (`assigned_district`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'officer_name' => "ALTER TABLE `external_officer_assignments` ADD COLUMN `officer_name` VARCHAR(150) DEFAULT NULL AFTER `officer_id`",
        'availability_status' => "ALTER TABLE `external_officer_assignments` ADD COLUMN `availability_status` VARCHAR(50) DEFAULT NULL AFTER `officer_name`",
        'assigned_district' => "ALTER TABLE `external_officer_assignments` ADD COLUMN `assigned_district` VARCHAR(150) DEFAULT NULL AFTER `availability_status`",
        'source_system' => "ALTER TABLE `external_officer_assignments` ADD COLUMN `source_system` VARCHAR(120) DEFAULT 'Group 1' AFTER `assigned_district`",
        'received_at' => "ALTER TABLE `external_officer_assignments` ADD COLUMN `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `source_system`",
        'raw_payload' => "ALTER TABLE `external_officer_assignments` ADD COLUMN `raw_payload` LONGTEXT DEFAULT NULL AFTER `received_at`",
    ];

    foreach ($columns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'external_officer_assignments', $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `api_sync_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `direction` ENUM('incoming','outgoing') NOT NULL,
            `target_group` VARCHAR(100) DEFAULT NULL,
            `endpoint_name` VARCHAR(150) DEFAULT NULL,
            `entity_type` VARCHAR(100) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `status` ENUM('pending','sent','received','failed') NOT NULL DEFAULT 'pending',
            `request_payload` LONGTEXT DEFAULT NULL,
            `response_payload` LONGTEXT DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_api_sync_entity` (`entity_type`, `entity_id`),
            KEY `idx_api_sync_status` (`status`),
            KEY `idx_api_sync_group` (`target_group`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ers_tracking_insert_sync_log(PDO $pdo, int $assignmentId, array $item): void
{
    $payload = json_encode($item['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        $payload = '{}';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, created_at, updated_at)
         VALUES
            ('incoming', 'Group 1', 'tracking_data', 'external_officer_assignment', ?, 'received', ?, NOW(), NOW())"
    );
    $stmt->execute([$assignmentId, $payload]);
}
?>
