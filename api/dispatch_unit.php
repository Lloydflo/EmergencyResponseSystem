<?php
// Dispatch a unit to an incident
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$hasIncidentId = is_array($data)
    && array_key_exists('incident_id', $data)
    && $data['incident_id'] !== ''
    && is_numeric((string)$data['incident_id']);
$incident_id = $hasIncidentId ? (int)$data['incident_id'] : null;
$rawUnitIds = [];
if (is_array($data) && isset($data['unit_ids']) && is_array($data['unit_ids'])) {
    $rawUnitIds = $data['unit_ids'];
} elseif (is_array($data) && isset($data['unit_id'])) {
    $rawUnitIds = [$data['unit_id']];
}
$unit_ids = array_values(array_unique(array_filter(array_map(static function ($value): int {
    return (int)$value;
}, $rawUnitIds), static function (int $value): bool {
    return $value > 0;
})));

if ($incident_id === null || $unit_ids === []) {
    echo json_encode(['ok'=>false,'error'=>'Missing data']);
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';
$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(['ok'=>false,'error'=>'DB error']);
    exit;
}

function ensure_dispatch_operator_records_table(PDO $pdo): void
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
        if (!dispatch_column_exists($pdo, 'dispatch_operator_records', $columnName)) {
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
        if (!dispatch_index_exists($pdo, 'dispatch_operator_records', $indexName)) {
            $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD KEY `{$indexName}` {$indexColumns}");
        }
    }
}

function dispatch_vehicle_label(string $unitType, string $vehicleName = ''): string
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

function dispatch_philippine_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
}

function dispatch_table_exists(PDO $pdo, string $tableName): bool
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

function dispatch_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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

function dispatch_index_exists(PDO $pdo, string $tableName, string $indexName): bool
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

function dispatch_responder_is_available(PDO $pdo, int $responderId): bool
{
    if ($responderId <= 0 || !dispatch_table_exists($pdo, 'users')) {
        return false;
    }

    $select = ['u.id'];
    $join = '';
    if (dispatch_column_exists($pdo, 'users', 'status')) {
        $select[] = 'u.status AS account_status';
    } else {
        $select[] = "'active' AS account_status";
    }
    if (dispatch_column_exists($pdo, 'users', 'unit_status')) {
        $select[] = 'u.unit_status';
    } else {
        $select[] = "'available' AS unit_status";
    }

    $hasPresence = dispatch_table_exists($pdo, 'user_presence')
        && dispatch_column_exists($pdo, 'user_presence', 'user_id')
        && dispatch_column_exists($pdo, 'user_presence', 'is_online')
        && dispatch_column_exists($pdo, 'user_presence', 'last_seen_at');
    if ($hasPresence) {
        $select[] = 'up.is_online';
        $select[] = 'up.last_seen_at';
        $join = ' LEFT JOIN user_presence up ON up.user_id = u.id';
    } else {
        $select[] = '1 AS is_online';
        $select[] = 'NOW() AS last_seen_at';
    }

    $roleWhere = dispatch_column_exists($pdo, 'users', 'role')
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

try {
    $dispatchIds = [];
    $dispatchedUnits = [];
    $notificationPayload = [];
    $notificationLogged = false;

    ensure_dispatch_operator_records_table($pdo);

    $pdo->beginTransaction();

    $incidentStmt = $pdo->prepare("
        SELECT id, priority, description, location_address, latitude, longitude
        FROM incidents
        WHERE id = ?
        LIMIT 1
    ");
    $incidentStmt->execute([$incident_id]);
    $incidentRow = $incidentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$incidentRow) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Incident not found']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
    $resourceTable = ers_vehicle_resource_units_table($pdo);
    $responderIdExpr = 'NULL';
    $responderNameExpr = 'NULL';
    if (
        dispatch_table_exists($pdo, 'users') &&
        dispatch_column_exists($pdo, 'users', 'id') &&
        dispatch_column_exists($pdo, 'users', 'unit_code') &&
        dispatch_column_exists($pdo, 'users', 'name') &&
        dispatch_column_exists($pdo, 'users', 'role')
    ) {
        $responderOrder = dispatch_column_exists($pdo, 'users', 'status')
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
    $unitStmt->execute($unit_ids);
    $availableUnits = [];
    foreach ($unitStmt->fetchAll(PDO::FETCH_ASSOC) as $unitRow) {
        if ((string)($unitRow['status'] ?? '') !== 'available') {
            $pdo->rollBack();
            echo json_encode([
                'ok' => false,
                'error' => 'Unit ' . (string)($unitRow['identifier'] ?? $unitRow['id']) . ' is no longer available'
            ]);
            exit;
        }
        if ((int)($unitRow['assigned_user_id'] ?? 0) <= 0) {
            $pdo->rollBack();
            echo json_encode([
                'ok' => false,
                'error' => 'Unit ' . (string)($unitRow['identifier'] ?? $unitRow['id']) . ' has no assigned responder'
            ]);
            exit;
        }
        if (!dispatch_responder_is_available($pdo, (int)$unitRow['assigned_user_id'])) {
            $pdo->rollBack();
            echo json_encode([
                'ok' => false,
                'error' => 'Unit ' . (string)($unitRow['identifier'] ?? $unitRow['id']) . ' responder is not online and available'
            ]);
            exit;
        }
        $availableUnits[(int)$unitRow['id']] = $unitRow;
    }

    foreach ($unit_ids as $unit_id) {
        if (!isset($availableUnits[$unit_id])) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'One or more selected units were not found']);
            exit;
        }
    }

    $dispatchTime = dispatch_philippine_timestamp();
    $stmtIns = $pdo->prepare("INSERT INTO dispatches (incident_id, unit_id, status, assigned_at) VALUES (?, ?, 'assigned', ?)");
    $stmtUnit = $pdo->prepare("UPDATE units SET status='assigned', current_incident_id=?, last_status_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmtOperatorRecord = $pdo->prepare("
        INSERT INTO dispatch_operator_records
            (`incident_id`, `name`, `vehicle`, `location`, `latitude`, `longitude`, `priority`, `description`, `created_at`, `status`, `assigned_to`, `assigned_responder_name`, `assigned_unit_code`, `assigned_unit_type`, `assigned_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
    ");
    foreach ($unit_ids as $unit_id) {
        $stmtIns->execute([$incident_id, $unit_id, $dispatchTime]);
        $dispatchId = (int)$pdo->lastInsertId();
        $dispatchIds[] = $dispatchId;

        $stmtUnit->execute([$incident_id, $unit_id]);
        ers_sync_vehicle_resource_status_by_unit_id($pdo, $unit_id, 'in_use');

        $unitMeta = $availableUnits[$unit_id];
        $operatorName = trim((string)($unitMeta['operator_name'] ?? ''));
        if ($operatorName === '') {
            $operatorName = trim((string)($unitMeta['identifier'] ?? 'Operator'));
        }
        $responderName = trim((string)($unitMeta['responder_name'] ?? ''));
        if ($responderName === '') {
            $responderName = $operatorName;
        }
        $assignedResponderId = (int)($unitMeta['assigned_user_id'] ?? 0);
        $stmtOperatorRecord->execute([
            $incident_id,
            $operatorName,
            dispatch_vehicle_label((string)($unitMeta['unit_type'] ?? ''), (string)($unitMeta['vehicle_name'] ?? '')),
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
            'dispatch_id' => $dispatchId,
            'id' => (int)$unitMeta['id'],
            'identifier' => (string)($unitMeta['identifier'] ?? ''),
            'unit_type' => (string)($unitMeta['unit_type'] ?? ''),
            'responder_id' => $assignedResponderId,
        ];
    }

    // Safety: mark incident dispatched
    $stmtInc = $pdo->prepare("UPDATE incidents SET status='dispatched', updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmtInc->execute([$incident_id]);

    $pdo->commit();

    // Build payload for app notification feed (best-effort; does not block dispatch success).
    try {
        $metaPlaceholders = implode(',', array_fill(0, count($dispatchIds), '?'));
        $stmtMeta = $pdo->prepare("
            SELECT
                d.id AS dispatch_id,
                d.incident_id,
                d.unit_id,
                d.status AS dispatch_status,
                d.assigned_at,
                i.reference_no,
                i.type AS incident_type,
                i.priority,
                i.location_address,
                u.identifier AS unit_identifier,
                u.unit_type
            FROM dispatches d
            LEFT JOIN incidents i ON i.id = d.incident_id
            LEFT JOIN units u ON u.id = d.unit_id
            WHERE d.id IN ($metaPlaceholders)
            ORDER BY d.id ASC
        ");
        $stmtMeta->execute($dispatchIds);
        $notificationPayload = $stmtMeta->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($notificationPayload) || $notificationPayload === []) {
            $notificationPayload = array_map(static function (array $unit) use ($incident_id, $dispatchTime): array {
                return [
                    'dispatch_id' => $unit['dispatch_id'],
                    'incident_id' => $incident_id,
                    'unit_id' => $unit['id'],
                    'dispatch_status' => 'assigned',
                    'assigned_at' => $dispatchTime,
                    'reference_no' => null,
                    'incident_type' => null,
                    'priority' => null,
                    'location_address' => null,
                    'unit_identifier' => $unit['identifier'],
                    'unit_type' => $unit['unit_type']
                ];
            }, $dispatchedUnits);
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $notificationText = 'Dispatch confirmed for incident #' . (string)$incident_id . ' with ' . count($dispatchIds) . ' unit' . (count($dispatchIds) === 1 ? '' : 's');
        $notificationDetails = [
            'message' => $notificationText,
            'dispatch' => $notificationPayload
        ];

        $stmtLog = $pdo->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (?, 'dispatch_confirmed', 'dispatch', ?, ?, ?)
        ");
        $stmtLog->execute([$userId, $dispatchIds[0] ?? null, json_encode($notificationDetails, JSON_UNESCAPED_UNICODE), $dispatchTime]);
        $notificationLogged = true;
    } catch (Throwable $logError) {
        // Dispatch already committed; keep success response even if logging fails.
    }

    echo json_encode([
        'ok' => true,
        'dispatch_id' => $dispatchIds[0] ?? null,
        'dispatch_ids' => $dispatchIds,
        'dispatched_count' => count($dispatchIds),
        'dispatched_units' => $dispatchedUnits,
        'notification_logged' => $notificationLogged,
        'notification' => $notificationPayload
    ]);
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    echo json_encode(['ok'=>false,'error'=>'Dispatch failed: ' . $e->getMessage()]);
}
