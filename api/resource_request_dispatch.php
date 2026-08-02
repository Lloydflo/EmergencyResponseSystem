<?php
require_once '../includes/db.php';
require_once '../includes/vehicle_resource_units.php';
require_once '../includes/dispatch_attempt_log.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

function backup_dispatch_fail_response(PDO $pdo, int $incidentId, array $unitIds, string $message, array $context = [], int $statusCode = 400): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    ers_dispatch_attempt_log_failed($pdo, $incidentId, $unitIds, $message, 'resource_request_dispatch', $context);
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function backup_dispatch_philippine_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
}

function backup_dispatch_table_exists(PDO $pdo, string $tableName): bool
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

function backup_dispatch_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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

function backup_dispatch_index_exists(PDO $pdo, string $tableName, string $indexName): bool
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

function backup_dispatch_ensure_operator_records_table(PDO $pdo): void
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
        if (!backup_dispatch_column_exists($pdo, 'dispatch_operator_records', $columnName)) {
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
        if (!backup_dispatch_index_exists($pdo, 'dispatch_operator_records', $indexName)) {
            $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD KEY `{$indexName}` {$indexColumns}");
        }
    }
}

function backup_dispatch_vehicle_label(string $unitType, string $vehicleName = ''): string
{
    $type = strtolower(trim($unitType));
    if ($type === 'ambulance') {
        return 'Ambulance';
    }
    if ($type === 'fire') {
        return 'Fire Truck';
    }
    if ($type === 'police') {
        return 'Police Vehicle';
    }
    if ($type === 'rescue') {
        return 'Rescue Vehicle';
    }
    if ($type !== '') {
        return ucwords(str_replace(['_', '-'], ' ', $type));
    }

    $vehicleName = trim($vehicleName);
    return $vehicleName !== '' ? $vehicleName : 'Vehicle';
}

$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$incidentId = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
$dispatcherName = trim((string)($_POST['dispatcher_name'] ?? 'Dispatcher'));
$notes = trim((string)($_POST['notes'] ?? ''));
$unitIds = $_POST['unit_ids'] ?? ($_POST['unit_ids[]'] ?? []);

if (!is_array($unitIds)) {
    $unitIds = [$unitIds];
}

$unitIds = array_values(array_unique(array_filter(array_map(static function ($value): int {
    return (int)$value;
}, $unitIds), static function ($value): bool {
    return $value > 0;
})));

if ($requestId <= 0 || $incidentId <= 0 || $unitIds === []) {
    backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'Missing request, incident, or units to dispatch', [
        'request_id' => $requestId,
        'incident_id' => $incidentId,
        'unit_ids' => $unitIds,
    ]);
}

try {
    backup_dispatch_ensure_operator_records_table($pdo);

    $pdo->beginTransaction();

    $requestStmt = $pdo->prepare('SELECT id, status, resource_name, details FROM resource_requests WHERE id = ? FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $requestRow = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$requestRow) {
        backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'Backup request not found', [
            'request_id' => $requestId,
            'incident_id' => $incidentId,
            'unit_ids' => $unitIds,
        ], 404);
    }

    $currentStatus = (string)($requestRow['status'] ?? 'pending');
    if (in_array($currentStatus, ['rejected', 'cancelled'], true)) {
        backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'This request can no longer be dispatched', [
            'request_id' => $requestId,
            'incident_id' => $incidentId,
            'request_status' => $currentStatus,
            'unit_ids' => $unitIds,
        ]);
    }
    if ($currentStatus === 'fulfilled') {
        backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'This request was already sent to responders', [
            'request_id' => $requestId,
            'incident_id' => $incidentId,
            'request_status' => $currentStatus,
            'unit_ids' => $unitIds,
        ]);
    }

    $details = json_decode((string)($requestRow['details'] ?? '{}'), true);
    if (!is_array($details)) {
        $details = [];
    }

    $requestIncidentId = isset($details['incident_id']) ? (int)$details['incident_id'] : 0;
    if ($requestIncidentId > 0 && $requestIncidentId !== $incidentId) {
        backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'Incident mismatch for selected backup request', [
            'request_id' => $requestId,
            'incident_id' => $incidentId,
            'request_incident_id' => $requestIncidentId,
            'unit_ids' => $unitIds,
        ]);
    }

    $incidentStmt = $pdo->prepare('
        SELECT id, priority, description, location_address, latitude, longitude
        FROM incidents
        WHERE id = ?
        LIMIT 1
    ');
    $incidentStmt->execute([$incidentId]);
    $incidentRow = $incidentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$incidentRow) {
        backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'Incident not found', [
            'request_id' => $requestId,
            'incident_id' => $incidentId,
            'unit_ids' => $unitIds,
        ], 404);
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $resourceTable = ers_vehicle_resource_units_table($pdo);
    $responderIdExpr = 'NULL';
    $responderNameExpr = 'NULL';
    if (
        backup_dispatch_table_exists($pdo, 'users') &&
        backup_dispatch_column_exists($pdo, 'users', 'id') &&
        backup_dispatch_column_exists($pdo, 'users', 'unit_code') &&
        backup_dispatch_column_exists($pdo, 'users', 'name') &&
        backup_dispatch_column_exists($pdo, 'users', 'role')
    ) {
        $responderOrder = backup_dispatch_column_exists($pdo, 'users', 'status')
            ? "CASE WHEN LOWER(COALESCE(usr.status, '')) = 'active' THEN 0 ELSE 1 END, usr.id DESC"
            : 'usr.id DESC';
        $responderWhere = "LOWER(COALESCE(usr.role, '')) = 'responder'
                           AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))";
        $responderIdExpr = "(SELECT usr.id
                             FROM users usr
                             WHERE {$responderWhere}
                             ORDER BY {$responderOrder}
                             LIMIT 1)";
        $responderNameExpr = "(SELECT usr.name
                               FROM users usr
                               WHERE {$responderWhere}
                                 AND TRIM(COALESCE(usr.name, '')) <> ''
                               ORDER BY {$responderOrder}
                               LIMIT 1)";
    }

    $resourceJoin = '';
    $resourceSelect = "{$responderIdExpr} AS assigned_user_id, {$responderNameExpr} AS responder_name, {$responderNameExpr} AS operator_name, NULL AS vehicle_name";
    if ($resourceTable !== null) {
        $resourceJoin = " LEFT JOIN `" . $resourceTable . "` rr
                          ON rr.code = u.identifier
                         AND LOWER(rr.category) = 'vehicles'";
        $resourceSelect = "{$responderIdExpr} AS assigned_user_id,
                           {$responderNameExpr} AS responder_name,
                           COALESCE(NULLIF(TRIM(rr.driver_name), ''), {$responderNameExpr}) AS operator_name,
                           rr.name AS vehicle_name";
    }

    $unitStmt = $pdo->prepare("
        SELECT u.id, u.identifier, u.unit_type, u.status, {$resourceSelect}
        FROM units u
        {$resourceJoin}
        WHERE u.id IN ($placeholders)
        FOR UPDATE
    ");
    $unitStmt->execute($unitIds);
    $availableUnits = [];
    foreach ($unitStmt->fetchAll(PDO::FETCH_ASSOC) as $unitRow) {
        if ((string)($unitRow['status'] ?? '') !== 'available') {
            $unitLabel = (string)($unitRow['identifier'] ?? $unitRow['id']);
            backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'Unit ' . $unitLabel . ' is no longer available', [
                'request_id' => $requestId,
                'incident_id' => $incidentId,
                'unit_ids' => $unitIds,
                'unit_identifier' => $unitLabel,
                'unit_status' => (string)($unitRow['status'] ?? ''),
            ]);
        }
        if ((int)($unitRow['assigned_user_id'] ?? 0) <= 0) {
            $unitLabel = (string)($unitRow['identifier'] ?? $unitRow['id']);
            backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'Unit ' . $unitLabel . ' has no assigned responder', [
                'request_id' => $requestId,
                'incident_id' => $incidentId,
                'unit_ids' => $unitIds,
                'unit_identifier' => $unitLabel,
            ]);
        }
        $availableUnits[(int)$unitRow['id']] = $unitRow;
    }

    foreach ($unitIds as $unitId) {
        if (!isset($availableUnits[$unitId])) {
            backup_dispatch_fail_response($pdo, $incidentId, $unitIds, 'One or more selected units were not found', [
                'request_id' => $requestId,
                'incident_id' => $incidentId,
                'unit_ids' => $unitIds,
                'missing_unit_id' => $unitId,
            ], 404);
        }
    }

    $dispatchTime = backup_dispatch_philippine_timestamp();
    $dispatchInsert = $pdo->prepare("INSERT INTO dispatches (incident_id, unit_id, status, assigned_at) VALUES (?, ?, 'assigned', ?)");
    $unitUpdate = $pdo->prepare("UPDATE units SET status = 'assigned', current_incident_id = ?, last_status_at = CURRENT_TIMESTAMP WHERE id = ?");
    $operatorRecordInsert = $pdo->prepare("
        INSERT INTO dispatch_operator_records
            (`incident_id`, `name`, `vehicle`, `location`, `latitude`, `longitude`, `priority`, `description`, `created_at`, `status`, `assigned_to`, `assigned_responder_name`, `assigned_unit_code`, `assigned_unit_type`, `assigned_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
    ");

    $dispatchedUnits = [];
    foreach ($unitIds as $unitId) {
        $dispatchInsert->execute([$incidentId, $unitId, $dispatchTime]);
        $unitUpdate->execute([$incidentId, $unitId]);
        ers_sync_vehicle_resource_status_by_unit_id($pdo, $unitId, 'in_use');

        $unitMeta = $availableUnits[$unitId];
        $operatorName = trim((string)($unitMeta['operator_name'] ?? ''));
        $responderName = trim((string)($unitMeta['responder_name'] ?? ''));
        if ($responderName === '') {
            $responderName = $operatorName;
        }
        $assignedResponderId = (int)($unitMeta['assigned_user_id'] ?? 0);

        $operatorRecordInsert->execute([
            $incidentId,
            $operatorName,
            backup_dispatch_vehicle_label((string)($unitMeta['unit_type'] ?? ''), (string)($unitMeta['vehicle_name'] ?? '')),
            $incidentRow['location_address'] ?? null,
            $incidentRow['latitude'] ?? null,
            $incidentRow['longitude'] ?? null,
            $incidentRow['priority'] ?? null,
            $incidentRow['description'] ?? null,
            $dispatchTime,
            $assignedResponderId,
            $responderName,
            (string)($unitMeta['identifier'] ?? ''),
            (string)($unitMeta['unit_type'] ?? ''),
            $dispatchTime,
        ]);
        ers_update_responder_unit_status($pdo, $assignedResponderId, 'busy');

        $dispatchedUnits[] = [
            'id' => (int)$unitMeta['id'],
            'identifier' => (string)($unitMeta['identifier'] ?? ''),
            'unit_type' => (string)($unitMeta['unit_type'] ?? ''),
            'responder_id' => $assignedResponderId,
        ];
    }

    $incidentUpdate = $pdo->prepare("UPDATE incidents SET status = 'dispatched', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $incidentUpdate->execute([$incidentId]);

    $details['dispatcher_name'] = $dispatcherName !== '' ? $dispatcherName : 'Dispatcher';
    $details['dispatch_notes'] = $notes;
    $details['dispatched_at'] = $dispatchTime;
    $details['dispatched_units'] = $dispatchedUnits;
    $details['dispatched_unit_ids'] = $unitIds;

    $updateRequest = $pdo->prepare('UPDATE resource_requests SET status = ?, details = ? WHERE id = ?');
    $updateRequest->execute([
        'fulfilled',
        json_encode($details, JSON_UNESCAPED_UNICODE),
        $requestId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'ok' => true,
        'request_id' => $requestId,
        'incident_id' => $incidentId,
        'dispatched_count' => count($dispatchedUnits),
        'dispatched_units' => $dispatchedUnits,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ers_dispatch_attempt_log_failed($pdo, $incidentId, $unitIds, 'Backup dispatch failed: ' . $e->getMessage(), 'resource_request_dispatch', [
        'request_id' => $requestId,
        'incident_id' => $incidentId,
        'unit_ids' => $unitIds,
        'exception' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
