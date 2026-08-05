<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/dispatch_attempt_log.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

function recovery_json_error(string $message, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function recovery_table_exists(PDO $pdo, string $tableName): bool
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

function recovery_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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

function recovery_index_exists(PDO $pdo, string $tableName, string $indexName): bool
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

function recovery_ensure_dispatches_assignment_schema(PDO $pdo): void
{
    if (!recovery_table_exists($pdo, 'dispatches')) {
        return;
    }

    if (!recovery_column_exists($pdo, 'dispatches', 'reference_no')) {
        $pdo->exec("ALTER TABLE `dispatches` ADD COLUMN `reference_no` VARCHAR(50) DEFAULT NULL AFTER `id`");
    }
    if (!recovery_column_exists($pdo, 'dispatches', 'incident_id')) {
        $afterColumn = recovery_column_exists($pdo, 'dispatches', 'reference_no') ? 'reference_no' : 'id';
        $pdo->exec("ALTER TABLE `dispatches` ADD COLUMN `incident_id` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `{$afterColumn}`");
    }
    if (!recovery_index_exists($pdo, 'dispatches', 'idx_dispatches_reference_no')) {
        $pdo->exec("ALTER TABLE `dispatches` ADD KEY `idx_dispatches_reference_no` (`reference_no`)");
    }
    if (!recovery_index_exists($pdo, 'dispatches', 'idx_dispatches_incident_id')) {
        $pdo->exec("ALTER TABLE `dispatches` ADD KEY `idx_dispatches_incident_id` (`incident_id`)");
    }
}

function recovery_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
}

function recovery_vehicle_label(string $unitType): string
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

    return $type !== '' ? ucwords(str_replace(['_', '-'], ' ', $type)) : 'Vehicle';
}

function recovery_current_user_id(): ?int
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function recovery_ensure_dispatch_operator_records_table(PDO $pdo): void
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
        if (!recovery_column_exists($pdo, 'dispatch_operator_records', $columnName)) {
            $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD COLUMN {$definition}");
        }
    }
}

function recovery_responder_is_available(PDO $pdo, int $responderId): bool
{
    if ($responderId <= 0 || !recovery_table_exists($pdo, 'users')) {
        return false;
    }

    $select = ['u.id'];
    $select[] = recovery_column_exists($pdo, 'users', 'status') ? 'u.status AS account_status' : "'active' AS account_status";
    $select[] = recovery_column_exists($pdo, 'users', 'unit_status') ? 'u.unit_status' : "'available' AS unit_status";

    $join = '';
    $hasPresence = recovery_table_exists($pdo, 'user_presence')
        && recovery_column_exists($pdo, 'user_presence', 'user_id')
        && recovery_column_exists($pdo, 'user_presence', 'is_online')
        && recovery_column_exists($pdo, 'user_presence', 'last_seen_at');
    if ($hasPresence) {
        $select[] = 'up.is_online';
        $select[] = 'up.last_seen_at';
        $join = ' LEFT JOIN user_presence up ON up.user_id = u.id';
    }

    $roleWhere = recovery_column_exists($pdo, 'users', 'role')
        ? " AND LOWER(COALESCE(u.role, '')) = 'responder'"
        : '';
    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . "
         FROM users u
         {$join}
         WHERE u.id = ?{$roleWhere}
         LIMIT 1"
    );
    $stmt->execute([$responderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $accountStatus = strtolower(trim((string)($row['account_status'] ?? 'active')));
    if ($accountStatus !== '' && $accountStatus !== 'active') {
        return false;
    }

    $unitStatus = strtolower(trim((string)($row['unit_status'] ?? 'available')));
    if (!in_array($unitStatus, ['', 'available', 'ready', 'on_duty'], true)) {
        return false;
    }

    if (!$hasPresence) {
        return true;
    }

    $lastSeen = strtotime((string)($row['last_seen_at'] ?? ''));
    return (int)($row['is_online'] ?? 0) === 1
        && $lastSeen !== false
        && $lastSeen >= time() - 180;
}

function recovery_responder_subquery(string $field, string $orderSql): string
{
    if ($field === 'id') {
        return "(SELECT usr.id
                 FROM users usr
                 WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                   AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                 ORDER BY {$orderSql}
                 LIMIT 1)";
    }

    return "(SELECT usr.name
             FROM users usr
             WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
               AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
             ORDER BY {$orderSql}
             LIMIT 1)";
}

function recovery_load_unit_for_retry(PDO $pdo, int $unitId): ?array
{
    if (
        !recovery_table_exists($pdo, 'units') ||
        !recovery_table_exists($pdo, 'users') ||
        !recovery_column_exists($pdo, 'users', 'role') ||
        !recovery_column_exists($pdo, 'users', 'unit_code') ||
        !recovery_column_exists($pdo, 'users', 'name')
    ) {
        return null;
    }

    $responderOrder = recovery_column_exists($pdo, 'users', 'status')
        ? "CASE WHEN COALESCE(usr.status, 'active') = 'active' THEN 0 ELSE 1 END, usr.id DESC"
        : 'usr.id DESC';
    $responderIdExpr = recovery_responder_subquery('id', $responderOrder);
    $responderNameExpr = recovery_responder_subquery('name', $responderOrder);
    $stmt = $pdo->prepare("
        SELECT u.id, u.identifier, u.unit_type, u.status,
               {$responderIdExpr} AS assigned_user_id,
               {$responderNameExpr} AS responder_name,
               {$responderNameExpr} AS operator_name
        FROM units u
        WHERE u.id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function recovery_set_unit_assignment(PDO $pdo, int $unitId, int $incidentId): void
{
    $fields = ['status = ?'];
    $params = ['assigned'];
    if (recovery_column_exists($pdo, 'units', 'current_incident_id')) {
        $fields[] = 'current_incident_id = ?';
        $params[] = $incidentId;
    }
    if (recovery_column_exists($pdo, 'units', 'last_status_at')) {
        $fields[] = 'last_status_at = CURRENT_TIMESTAMP';
    }
    $params[] = $unitId;

    $stmt = $pdo->prepare('UPDATE units SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
}

function recovery_free_unit(PDO $pdo, int $unitId): void
{
    if ($unitId <= 0) {
        return;
    }

    $fields = ['status = ?'];
    $params = ['available'];
    if (recovery_column_exists($pdo, 'units', 'current_incident_id')) {
        $fields[] = 'current_incident_id = NULL';
    }
    if (recovery_column_exists($pdo, 'units', 'last_status_at')) {
        $fields[] = 'last_status_at = CURRENT_TIMESTAMP';
    }
    $params[] = $unitId;

    $stmt = $pdo->prepare('UPDATE units SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
    ers_sync_vehicle_resource_status_by_unit_id($pdo, $unitId, 'available');
}

function recovery_log_activity(PDO $pdo, string $action, string $entityType, ?int $entityId, array $details): void
{
    if (!recovery_table_exists($pdo, 'activity_log')) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            recovery_current_user_id(),
            $action,
            $entityType,
            $entityId,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            recovery_now(),
        ]);
    } catch (Throwable $e) {
        error_log('Dispatch recovery activity log skipped: ' . $e->getMessage());
    }
}

function recovery_first_attempt_unit_id(array $attempt): int
{
    $unitId = (int)($attempt['unit_id'] ?? 0);
    if ($unitId > 0) {
        return $unitId;
    }

    $decoded = json_decode((string)($attempt['attempted_unit_ids'] ?? '[]'), true);
    if (!is_array($decoded)) {
        return 0;
    }

    foreach ($decoded as $value) {
        $candidate = (int)$value;
        if ($candidate > 0) {
            return $candidate;
        }
    }

    return 0;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = strtolower(trim((string)($payload['action'] ?? '')));
$attemptId = (int)($payload['attempt_id'] ?? 0);
$incidentId = (int)($payload['incident_id'] ?? 0);
$unitId = (int)($payload['unit_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    recovery_json_error('POST required', 405);
}
if (!in_array($action, ['retry_same_unit', 'cancel_dispatch', 'close_failure'], true)) {
    recovery_json_error('Unknown recovery action');
}
if ($attemptId <= 0) {
    recovery_json_error('Missing failed dispatch reference');
}

try {
    ers_dispatch_attempt_ensure_table($pdo);
    recovery_ensure_dispatch_operator_records_table($pdo);
    recovery_ensure_dispatches_assignment_schema($pdo);

    if ($action === 'close_failure') {
        $stmt = $pdo->prepare("SELECT id FROM dispatch_attempt_logs WHERE id = ? LIMIT 1");
        $stmt->execute([$attemptId]);
        if (!$stmt->fetchColumn()) {
            recovery_json_error('Failed dispatch attempt not found');
        }
        ers_dispatch_attempt_mark_recovered($pdo, $attemptId, 'close_failure', null, 'closed', 'Closed by admin from dispatch recovery panel.');
        recovery_log_activity($pdo, 'dispatch_failure_closed', 'dispatch_attempt', $attemptId, [
            'attempt_id' => $attemptId,
            'action' => $action,
        ]);
        echo json_encode(['ok' => true, 'message' => 'Failed dispatch attempt closed']);
        exit;
    }

    if ($action === 'cancel_dispatch') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT d.id, i.id AS incident_id, d.unit_id, d.status, u.identifier
            FROM dispatches d
            LEFT JOIN incidents i ON i.reference_no = d.reference_no
            LEFT JOIN units u ON u.id = d.unit_id
            WHERE d.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$attemptId]);
        $dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dispatch) {
            $pdo->rollBack();
            recovery_json_error('Dispatch assignment not found');
        }

        $status = strtolower(trim((string)($dispatch['status'] ?? '')));
        if (in_array($status, ['cleared', 'completed', 'cancelled'], true)) {
            $pdo->rollBack();
            recovery_json_error('Dispatch is already closed');
        }

        $updateFields = ['status = ?'];
        $updateParams = ['cancelled'];
        if (recovery_column_exists($pdo, 'dispatches', 'cancelled_at')) {
            $updateFields[] = 'cancelled_at = CURRENT_TIMESTAMP';
        }
        $updateParams[] = (int)$dispatch['id'];
        $stmt = $pdo->prepare('UPDATE dispatches SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
        $stmt->execute($updateParams);

        recovery_free_unit($pdo, (int)($dispatch['unit_id'] ?? 0));

        if (recovery_table_exists($pdo, 'dispatch_operator_records')) {
            $stmt = $pdo->prepare("
                UPDATE dispatch_operator_records
                SET status = 'cancelled'
                WHERE incident_id = ?
                  AND assigned_unit_code = ?
                  AND status IN ('pending','assigned','received','accepted','acknowledged')
            ");
            $stmt->execute([(int)$dispatch['incident_id'], (string)($dispatch['identifier'] ?? '')]);
        }

        $pdo->commit();
        recovery_log_activity($pdo, 'dispatch_recovery_cancelled', 'dispatch', (int)$dispatch['id'], [
            'dispatch_id' => (int)$dispatch['id'],
            'incident_id' => (int)$dispatch['incident_id'],
            'unit_id' => (int)$dispatch['unit_id'],
        ]);
        echo json_encode(['ok' => true, 'message' => 'Stale dispatch assignment cancelled']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, incident_id, unit_id, unit_identifier, attempted_unit_ids, recovery_status
        FROM dispatch_attempt_logs
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) {
        recovery_json_error('Failed dispatch attempt not found');
    }
    if (in_array(strtolower(trim((string)($attempt['recovery_status'] ?? 'open'))), ['recovered', 'closed'], true)) {
        recovery_json_error('Failed dispatch attempt has already been handled');
    }

    $incidentId = $incidentId > 0 ? $incidentId : (int)($attempt['incident_id'] ?? 0);
    $unitId = $unitId > 0 ? $unitId : recovery_first_attempt_unit_id($attempt);
    if ($incidentId <= 0 || $unitId <= 0) {
        recovery_json_error('Retry needs both an incident and a unit');
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, reference_no, priority, description, location_address, latitude, longitude
        FROM incidents
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$incident) {
        $pdo->rollBack();
        recovery_json_error('Incident not found');
    }

    $unit = recovery_load_unit_for_retry($pdo, $unitId);
    if (!$unit) {
        $pdo->rollBack();
        recovery_json_error('Unit not found');
    }
    if (strtolower(trim((string)($unit['status'] ?? ''))) !== 'available') {
        $pdo->rollBack();
        recovery_json_error('Unit is no longer available for retry');
    }

    $responderId = (int)($unit['assigned_user_id'] ?? 0);
    if ($responderId <= 0) {
        $pdo->rollBack();
        recovery_json_error('Unit has no assigned responder');
    }
    if (!recovery_responder_is_available($pdo, $responderId)) {
        $pdo->rollBack();
        recovery_json_error('Unit responder is not online and available');
    }

    $dispatchTime = recovery_now();
    $incidentReferenceNo = trim((string)($incident['reference_no'] ?? ''));
    if ($incidentReferenceNo === '') {
        $pdo->rollBack();
        recovery_json_error('Incident reference number is missing');
    }

    $stmt = $pdo->prepare("INSERT INTO dispatches (incident_id, reference_no, unit_id, status, assigned_at) VALUES (?, ?, ?, 'assigned', ?)");
    $stmt->execute([$incidentId, $incidentReferenceNo, $unitId, $dispatchTime]);
    $dispatchId = (int)$pdo->lastInsertId();

    recovery_set_unit_assignment($pdo, $unitId, $incidentId);
    ers_sync_vehicle_resource_status_by_unit_id($pdo, $unitId, 'in_use');

    $operatorName = trim((string)($unit['operator_name'] ?? ''));
    if ($operatorName === '') {
        $operatorName = trim((string)($unit['identifier'] ?? 'Operator'));
    }
    $responderName = trim((string)($unit['responder_name'] ?? ''));
    if ($responderName === '') {
        $responderName = $operatorName;
    }

    $stmt = $pdo->prepare("
        INSERT INTO dispatch_operator_records
            (`incident_id`, `name`, `vehicle`, `location`, `latitude`, `longitude`, `priority`, `description`, `created_at`, `status`, `assigned_to`, `assigned_responder_name`, `assigned_unit_code`, `assigned_unit_type`, `assigned_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $incidentId,
        $operatorName,
        recovery_vehicle_label((string)($unit['unit_type'] ?? '')),
        $incident['location_address'] ?? null,
        $incident['latitude'] ?? null,
        $incident['longitude'] ?? null,
        $incident['priority'] ?? null,
        $incident['description'] ?? null,
        $dispatchTime,
        $responderId,
        $responderName,
        (string)($unit['identifier'] ?? ''),
        (string)($unit['unit_type'] ?? ''),
        $dispatchTime,
    ]);
    ers_update_responder_unit_status($pdo, $responderId, 'busy');

    if (recovery_column_exists($pdo, 'incidents', 'updated_at')) {
        $stmt = $pdo->prepare("UPDATE incidents SET status = 'dispatched', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE incidents SET status = 'dispatched' WHERE id = ?");
    }
    $stmt->execute([$incidentId]);

    ers_dispatch_attempt_mark_recovered(
        $pdo,
        $attemptId,
        'retry_same_unit',
        $dispatchId,
        'recovered',
        'Retried from admin failed dispatch recovery panel.'
    );

    $pdo->commit();

    recovery_log_activity($pdo, 'dispatch_recovery_retried', 'dispatch', $dispatchId, [
        'attempt_id' => $attemptId,
        'dispatch_id' => $dispatchId,
        'incident_id' => $incidentId,
        'unit_id' => $unitId,
        'unit_identifier' => (string)($unit['identifier'] ?? ''),
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Dispatch retry created',
        'dispatch_id' => $dispatchId,
        'incident_id' => $incidentId,
        'unit_id' => $unitId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Dispatch recovery failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to recover dispatch']);
}
?>
