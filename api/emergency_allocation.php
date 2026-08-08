<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';
require_once __DIR__ . '/../includes/emergency_com_status_sync.php';
require_once __DIR__ . '/../includes/anonymous_tip_status_sync.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!is_logged_in() || current_session_role() !== 'dispatcher') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'Dispatcher login required']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'success' => false, 'error' => 'DB unavailable']);
    exit;
}

function emergency_table_exists(PDO $pdo, string $tableName): bool
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
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function emergency_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function emergency_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName, $indexName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function emergency_ensure_dispatches_assignment_schema(PDO $pdo): void
{
    if (!emergency_table_exists($pdo, 'dispatches')) {
        return;
    }

    if (!emergency_column_exists($pdo, 'dispatches', 'reference_no')) {
        $pdo->exec("ALTER TABLE `dispatches` ADD COLUMN `reference_no` VARCHAR(50) DEFAULT NULL AFTER `id`");
    }
    if (!emergency_column_exists($pdo, 'dispatches', 'incident_id')) {
        $afterColumn = emergency_column_exists($pdo, 'dispatches', 'reference_no') ? 'reference_no' : 'id';
        $pdo->exec("ALTER TABLE `dispatches` ADD COLUMN `incident_id` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `{$afterColumn}`");
    }
    if (!emergency_index_exists($pdo, 'dispatches', 'idx_dispatches_reference_no')) {
        $pdo->exec("ALTER TABLE `dispatches` ADD KEY `idx_dispatches_reference_no` (`reference_no`)");
    }
    if (!emergency_index_exists($pdo, 'dispatches', 'idx_dispatches_incident_id')) {
        $pdo->exec("ALTER TABLE `dispatches` ADD KEY `idx_dispatches_incident_id` (`incident_id`)");
    }
}

function emergency_ensure_operator_records_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `dispatch_operator_records` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `incident_id` bigint(20) UNSIGNED DEFAULT NULL,
          `name` varchar(150) NOT NULL,
          `vehicle` varchar(100) NOT NULL,
          `location` varchar(255) DEFAULT NULL,
          `latitude` decimal(10,7) DEFAULT NULL,
          `longitude` decimal(10,7) DEFAULT NULL,
          `priority` varchar(20) DEFAULT NULL,
          `description` text DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `status` varchar(50) DEFAULT 'pending',
          `assigned_to` int(11) DEFAULT NULL,
          `assigned_responder_name` varchar(150) DEFAULT NULL,
          `assigned_unit_code` varchar(50) DEFAULT NULL,
          `assigned_unit_type` varchar(50) DEFAULT NULL,
          `assigned_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_dispatch_operator_records_incident_id` (`incident_id`),
          KEY `idx_dispatch_operator_records_priority` (`priority`),
          KEY `idx_dispatch_operator_records_created_at` (`created_at`),
          KEY `idx_dispatch_operator_records_status` (`status`),
          KEY `idx_dispatch_operator_records_assigned_to` (`assigned_to`),
          KEY `idx_dispatch_operator_records_assigned_at` (`assigned_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'incident_id' => "`incident_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `id`",
        'status' => "`status` varchar(50) DEFAULT 'pending' AFTER `created_at`",
        'assigned_to' => "`assigned_to` int(11) DEFAULT NULL AFTER `status`",
        'assigned_responder_name' => "`assigned_responder_name` varchar(150) DEFAULT NULL AFTER `assigned_to`",
        'assigned_unit_code' => "`assigned_unit_code` varchar(50) DEFAULT NULL AFTER `assigned_responder_name`",
        'assigned_unit_type' => "`assigned_unit_type` varchar(50) DEFAULT NULL AFTER `assigned_unit_code`",
        'assigned_at' => "`assigned_at` datetime DEFAULT NULL AFTER `assigned_unit_type`",
    ];

    foreach ($columns as $columnName => $definition) {
        if (!emergency_column_exists($pdo, 'dispatch_operator_records', $columnName)) {
            $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD COLUMN {$definition}");
        }
    }

    $indexes = [
        'idx_dispatch_operator_records_incident_id' => '(`incident_id`)',
        'idx_dispatch_operator_records_status' => '(`status`)',
        'idx_dispatch_operator_records_assigned_to' => '(`assigned_to`)',
        'idx_dispatch_operator_records_assigned_at' => '(`assigned_at`)',
    ];

    foreach ($indexes as $indexName => $indexColumns) {
        if (!emergency_index_exists($pdo, 'dispatch_operator_records', $indexName)) {
            $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD KEY `{$indexName}` {$indexColumns}");
        }
    }
}

function emergency_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
}

function emergency_vehicle_label(string $unitType, string $vehicleName = ''): string
{
    $type = strtolower(trim($unitType));
    if ($type === 'ambulance') return 'Ambulance';
    if ($type === 'fire') return 'Fire Truck';
    if ($type === 'police') return 'Police Vehicle';
    if ($type === 'rescue') return 'Rescue Vehicle';
    if ($type !== '') return ucwords(str_replace(['_', '-'], ' ', $type));

    $vehicleName = trim($vehicleName);
    return $vehicleName !== '' ? $vehicleName : 'Vehicle';
}

function emergency_priority_weight(string $priority): int
{
    $priority = strtolower(trim($priority));
    if ($priority === 'critical') return 5;
    if ($priority === 'high' || $priority === 'urgent') return 4;
    if ($priority === 'moderate' || $priority === 'medium') return 2;
    return 1;
}

function emergency_desired_units(array $incident): int
{
    return emergency_priority_weight((string)($incident['priority'] ?? '')) >= 3 ? 2 : 1;
}

function emergency_max_units(array $incident): int
{
    $weight = emergency_priority_weight((string)($incident['priority'] ?? ''));
    if ($weight >= 3) return 3;
    if ($weight === 2) return 2;
    return 1;
}

function emergency_preferred_unit_type(array $incident): string
{
    $haystack = strtolower(trim(implode(' ', [
        (string)($incident['type'] ?? ''),
        (string)($incident['title'] ?? ''),
        (string)($incident['description'] ?? ''),
    ])));

    if (preg_match('/medical|ambulance|health|injur|patient|hospital|clinic/', $haystack)) return 'ambulance';
    if (preg_match('/fire|smoke|burn|blaze|explosion/', $haystack)) return 'fire';
    if (preg_match('/police|crime|assault|robbery|theft|violence/', $haystack)) return 'police';
    if (preg_match('/rescue|trapped|collapse|flood|search|retrieval/', $haystack)) return 'rescue';

    return '';
}

function emergency_take_unit(array &$units, string $preferredType = ''): ?array
{
    if ($units === []) {
        return null;
    }

    $preferredType = strtolower(trim($preferredType));
    if ($preferredType !== '') {
        foreach ($units as $index => $unit) {
            if (strtolower((string)($unit['unit_type'] ?? '')) === $preferredType) {
                array_splice($units, $index, 1);
                return $unit;
            }
        }
    }

    foreach (['rescue', 'ambulance', 'fire', 'police', 'other'] as $fallbackType) {
        foreach ($units as $index => $unit) {
            if (strtolower((string)($unit['unit_type'] ?? '')) === $fallbackType) {
                array_splice($units, $index, 1);
                return $unit;
            }
        }
    }

    return array_shift($units);
}

function emergency_load_active_incidents(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            i.id,
            i.reference_no,
            i.type,
            i.priority,
            i.status,
            i.title,
            i.description,
            i.location_address,
            i.latitude,
            i.longitude,
            i.created_at,
            (
                SELECT COUNT(*)
                FROM dispatches d
                WHERE d.reference_no = i.reference_no
                  AND d.status IN ('assigned','acknowledged','enroute','on_scene')
            ) AS active_dispatch_count
        FROM incidents i
        WHERE i.status IN ('pending','dispatched','active','in_progress')
        ORDER BY
            CASE LOWER(COALESCE(i.priority, ''))
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'urgent' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'moderate' THEN 3
                WHEN 'low' THEN 4
                ELSE 6
            END,
            i.created_at ASC,
            i.id ASC
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function emergency_load_available_units(PDO $pdo): array
{
    $resourceTable = ers_vehicle_resource_units_table($pdo);
    $responderIdExpr = 'NULL';
    $responderNameExpr = 'NULL';

    if (
        emergency_table_exists($pdo, 'users') &&
        emergency_column_exists($pdo, 'users', 'id') &&
        emergency_column_exists($pdo, 'users', 'unit_code') &&
        emergency_column_exists($pdo, 'users', 'name') &&
        emergency_column_exists($pdo, 'users', 'role')
    ) {
        $responderOrder = emergency_column_exists($pdo, 'users', 'status')
            ? "CASE WHEN LOWER(COALESCE(usr.status, '')) = 'active' THEN 0 ELSE 1 END, usr.id DESC"
            : 'usr.id DESC';
        $responderWhere = "LOWER(COALESCE(usr.role, '')) = 'responder'
                           AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))";
        $responderIdExpr = "(SELECT usr.id FROM users usr WHERE {$responderWhere} ORDER BY {$responderOrder} LIMIT 1)";
        $responderNameExpr = "(SELECT usr.name FROM users usr WHERE {$responderWhere} AND TRIM(COALESCE(usr.name, '')) <> '' ORDER BY {$responderOrder} LIMIT 1)";
    }

    $resourceJoin = '';
    $resourceSelect = "{$responderIdExpr} AS assigned_user_id, {$responderNameExpr} AS responder_name, {$responderNameExpr} AS operator_name, NULL AS vehicle_name";
    if ($resourceTable !== null) {
        $resourceJoin = " LEFT JOIN `" . $resourceTable . "` rr
                          ON rr.code = u.identifier
                         AND LOWER(rr.category) = 'vehicles'";
        $resourceSelect = "{$responderIdExpr} AS assigned_user_id,
                           {$responderNameExpr} AS responder_name,
                           COALESCE(NULLIF(TRIM(rr.driver_name), ''), {$responderNameExpr}, u.identifier) AS operator_name,
                           rr.name AS vehicle_name";
    }

    $stmt = $pdo->query("
        SELECT u.id, u.identifier, u.unit_type, u.status, {$resourceSelect}
        FROM units u
        {$resourceJoin}
        WHERE u.status = 'available'
        ORDER BY
            FIELD(u.unit_type, 'rescue', 'ambulance', 'fire', 'police', 'other'),
            u.identifier ASC
        FOR UPDATE
    ");

    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return array_values(array_filter($rows, static function (array $unit): bool {
        return (int)($unit['assigned_user_id'] ?? 0) > 0;
    }));
}

function emergency_log_dispatch_notification(?int $userId, int $dispatchId, array $payload): void
{
    $details = [
        'message' => 'Emergency allocation dispatched unit ' . (string)($payload['unit_identifier'] ?? '') .
            ' to incident ' . (string)($payload['reference_no'] ?? ('#' . (int)($payload['incident_id'] ?? 0))),
        'emergency_allocation' => true,
        'dispatch' => [$payload],
    ];

    log_activity_event(
        $userId,
        'dispatch_confirmed',
        'dispatch',
        $dispatchId,
        json_encode($details, JSON_UNESCAPED_UNICODE)
    );
}

try {
    emergency_ensure_operator_records_table($pdo);
    emergency_ensure_dispatches_assignment_schema($pdo);

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $dispatchTime = emergency_timestamp();
    $allocations = [];

    $pdo->beginTransaction();

    $incidents = emergency_load_active_incidents($pdo);
    $availableUnits = emergency_load_available_units($pdo);
    $initialAvailableUnits = count($availableUnits);

    if ($incidents !== [] && $availableUnits !== []) {
        $dispatchInsert = $pdo->prepare("INSERT INTO dispatches (incident_id, reference_no, unit_id, status, assigned_at) VALUES (?, ?, ?, 'assigned', ?)");
        $unitUpdate = $pdo->prepare("UPDATE units SET status = 'assigned', current_incident_id = ?, last_status_at = CURRENT_TIMESTAMP WHERE id = ?");
        $incidentUpdate = $pdo->prepare("UPDATE incidents SET status = 'dispatched', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('pending','dispatched','active','in_progress')");
        $operatorInsert = $pdo->prepare("
            INSERT INTO dispatch_operator_records
                (`incident_id`, `name`, `vehicle`, `location`, `latitude`, `longitude`, `priority`, `description`, `created_at`, `status`, `assigned_to`, `assigned_responder_name`, `assigned_unit_code`, `assigned_unit_type`, `assigned_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
        ");

        foreach ($incidents as &$incident) {
            $incident['allocated_now'] = 0;
            $needed = max(0, emergency_desired_units($incident) - (int)($incident['active_dispatch_count'] ?? 0));
            $preferredType = emergency_preferred_unit_type($incident);

            while ($needed > 0 && $availableUnits !== []) {
                $unit = emergency_take_unit($availableUnits, $preferredType);
                if ($unit === null) break;

                $dispatchInsert->execute([(int)$incident['id'], (string)$incident['reference_no'], (int)$unit['id'], $dispatchTime]);
                $dispatchId = (int)$pdo->lastInsertId();
                $unitUpdate->execute([(int)$incident['id'], (int)$unit['id']]);
                ers_sync_vehicle_resource_status_by_unit_id($pdo, (int)$unit['id'], 'in_use');
                $incidentUpdate->execute([(int)$incident['id']]);

                $operatorName = trim((string)($unit['operator_name'] ?? ''));
                if ($operatorName === '') {
                    $operatorName = (string)($unit['identifier'] ?? 'Emergency Unit');
                }
                $responderName = trim((string)($unit['responder_name'] ?? ''));
                if ($responderName === '') {
                    $responderName = $operatorName;
                }
                $assignedResponderId = (int)($unit['assigned_user_id'] ?? 0);

                $operatorInsert->execute([
                    (int)$incident['id'],
                    $operatorName,
                    emergency_vehicle_label((string)($unit['unit_type'] ?? ''), (string)($unit['vehicle_name'] ?? '')),
                    $incident['location_address'] ?? null,
                    $incident['latitude'] ?? null,
                    $incident['longitude'] ?? null,
                    $incident['priority'] ?? null,
                    $incident['description'] ?? null,
                    $dispatchTime,
                    $assignedResponderId,
                    $responderName,
                    (string)($unit['identifier'] ?? ''),
                    (string)($unit['unit_type'] ?? ''),
                    $dispatchTime,
                ]);
                ers_update_responder_unit_status($pdo, $assignedResponderId, 'busy');

                $payload = [
                    'dispatch_id' => $dispatchId,
                    'incident_id' => (int)$incident['id'],
                    'unit_id' => (int)$unit['id'],
                    'dispatch_status' => 'assigned',
                    'assigned_at' => $dispatchTime,
                    'reference_no' => $incident['reference_no'] ?? null,
                    'incident_type' => $incident['type'] ?? null,
                    'priority' => $incident['priority'] ?? null,
                    'location_address' => $incident['location_address'] ?? null,
                    'unit_identifier' => $unit['identifier'] ?? null,
                    'unit_type' => $unit['unit_type'] ?? null,
                    'responder_id' => $assignedResponderId,
                ];

                $allocations[] = $payload;
                $incident['allocated_now'] = (int)$incident['allocated_now'] + 1;
                $needed--;
            }
        }
        unset($incident);

        foreach ($incidents as &$incident) {
            if ($availableUnits === []) break;

            $currentCoverage = (int)($incident['active_dispatch_count'] ?? 0) + (int)($incident['allocated_now'] ?? 0);
            $extraSlots = max(0, emergency_max_units($incident) - $currentCoverage);
            $preferredType = emergency_preferred_unit_type($incident);

            while ($extraSlots > 0 && $availableUnits !== []) {
                $unit = emergency_take_unit($availableUnits, $preferredType);
                if ($unit === null) break;

                $dispatchInsert->execute([(int)$incident['id'], (string)$incident['reference_no'], (int)$unit['id'], $dispatchTime]);
                $dispatchId = (int)$pdo->lastInsertId();
                $unitUpdate->execute([(int)$incident['id'], (int)$unit['id']]);
                ers_sync_vehicle_resource_status_by_unit_id($pdo, (int)$unit['id'], 'in_use');
                $incidentUpdate->execute([(int)$incident['id']]);

                $operatorName = trim((string)($unit['operator_name'] ?? ''));
                if ($operatorName === '') {
                    $operatorName = (string)($unit['identifier'] ?? 'Emergency Unit');
                }
                $responderName = trim((string)($unit['responder_name'] ?? ''));
                if ($responderName === '') {
                    $responderName = $operatorName;
                }
                $assignedResponderId = (int)($unit['assigned_user_id'] ?? 0);

                $operatorInsert->execute([
                    (int)$incident['id'],
                    $operatorName,
                    emergency_vehicle_label((string)($unit['unit_type'] ?? ''), (string)($unit['vehicle_name'] ?? '')),
                    $incident['location_address'] ?? null,
                    $incident['latitude'] ?? null,
                    $incident['longitude'] ?? null,
                    $incident['priority'] ?? null,
                    $incident['description'] ?? null,
                    $dispatchTime,
                    $assignedResponderId,
                    $responderName,
                    (string)($unit['identifier'] ?? ''),
                    (string)($unit['unit_type'] ?? ''),
                    $dispatchTime,
                ]);
                ers_update_responder_unit_status($pdo, $assignedResponderId, 'busy');

                $allocations[] = [
                    'dispatch_id' => $dispatchId,
                    'incident_id' => (int)$incident['id'],
                    'unit_id' => (int)$unit['id'],
                    'dispatch_status' => 'assigned',
                    'assigned_at' => $dispatchTime,
                    'reference_no' => $incident['reference_no'] ?? null,
                    'incident_type' => $incident['type'] ?? null,
                    'priority' => $incident['priority'] ?? null,
                    'location_address' => $incident['location_address'] ?? null,
                    'unit_identifier' => $unit['identifier'] ?? null,
                    'unit_type' => $unit['unit_type'] ?? null,
                    'responder_id' => $assignedResponderId,
                ];

                $extraSlots--;
            }
        }
        unset($incident);
    }

    $pdo->commit();

    $notifiedIncidentIds = [];
    $anonymousTipStatusSync = [];
    foreach ($allocations as $allocation) {
        $incidentId = (int)($allocation['incident_id'] ?? 0);
        if ($incidentId <= 0 || isset($notifiedIncidentIds[$incidentId])) {
            continue;
        }
        $notifiedIncidentIds[$incidentId] = true;
        ers_notify_emergency_com_status(
            $pdo,
            $incidentId,
            'Response units are being dispatched.'
        );
        $anonymousTipStatusSync[] = ers_notify_anonymous_tip_status_result(
            $pdo,
            $incidentId,
            'dispatched',
            'Response units are being dispatched.'
        );
    }

    foreach ($allocations as $allocation) {
        emergency_log_dispatch_notification($userId, (int)$allocation['dispatch_id'], $allocation);
    }

    log_activity_event(
        $userId,
        'emergency_allocation',
        'system',
        null,
        json_encode([
            'message' => 'Emergency allocation protocol activated',
            'allocated_count' => count($allocations),
            'active_incidents' => count($incidents),
            'available_units_before' => $initialAvailableUnits,
            'available_units_after' => count($availableUnits),
        ], JSON_UNESCAPED_UNICODE)
    );

    echo json_encode([
        'ok' => true,
        'success' => true,
        'allocated_count' => count($allocations),
        'active_incidents' => count($incidents),
        'available_units_before' => $initialAvailableUnits,
        'available_units_after' => count($availableUnits),
        'allocations' => $allocations,
        'anonymous_tip_status_sync' => $anonymousTipStatusSync,
        'summary' => [
            'units_allocated' => count($allocations),
            'active_incidents' => count($incidents),
            'units_available_before' => $initialAvailableUnits,
            'units_available_after' => count($availableUnits),
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'success' => false,
        'error' => 'Emergency allocation failed: ' . $e->getMessage(),
    ]);
}
