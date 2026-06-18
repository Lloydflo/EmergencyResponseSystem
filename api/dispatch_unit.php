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
          `name` varchar(150) NOT NULL,
          `vehicle` varchar(100) NOT NULL,
          `location` varchar(255) DEFAULT NULL,
          `latitude` decimal(10,7) DEFAULT NULL,
          `longitude` decimal(10,7) DEFAULT NULL,
          `priority` varchar(20) DEFAULT NULL,
          `description` text DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_dispatch_operator_records_priority` (`priority`),
          KEY `idx_dispatch_operator_records_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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
    $responderOperatorExpr = 'NULL';
    if (
        dispatch_table_exists($pdo, 'users') &&
        dispatch_column_exists($pdo, 'users', 'unit_code') &&
        dispatch_column_exists($pdo, 'users', 'name') &&
        dispatch_column_exists($pdo, 'users', 'role')
    ) {
        $responderOperatorExpr = "(SELECT usr.name
                                  FROM users usr
                                  WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                    AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                                    AND TRIM(COALESCE(usr.name, '')) <> ''
                                  ORDER BY usr.id DESC
                                  LIMIT 1)";
    }
    $resourceJoin = '';
    $resourceSelect = $responderOperatorExpr . ' AS operator_name, NULL AS vehicle_name';
    if ($resourceTable !== null) {
        $resourceJoin = " LEFT JOIN `" . $resourceTable . "` rr
                          ON rr.code = u.identifier
                         AND LOWER(rr.category) = 'vehicles'";
        $resourceSelect = 'COALESCE(NULLIF(TRIM(rr.driver_name), \'\'), ' . $responderOperatorExpr . ') AS operator_name, rr.name AS vehicle_name';
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
        $availableUnits[(int)$unitRow['id']] = $unitRow;
    }

    foreach ($unit_ids as $unit_id) {
        if (!isset($availableUnits[$unit_id])) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'One or more selected units were not found']);
            exit;
        }
    }

    $stmtIns = $pdo->prepare("INSERT INTO dispatches (incident_id, unit_id, status, assigned_at) VALUES (?, ?, 'assigned', CURRENT_TIMESTAMP)");
    $stmtUnit = $pdo->prepare("UPDATE units SET status='assigned', current_incident_id=?, last_status_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmtOperatorRecord = $pdo->prepare("
        INSERT INTO dispatch_operator_records
            (`name`, `vehicle`, `location`, `latitude`, `longitude`, `priority`, `description`)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($unit_ids as $unit_id) {
        $stmtIns->execute([$incident_id, $unit_id]);
        $dispatchId = (int)$pdo->lastInsertId();
        $dispatchIds[] = $dispatchId;

        $stmtUnit->execute([$incident_id, $unit_id]);
        ers_sync_vehicle_resource_status_by_unit_id($pdo, $unit_id, 'in_use');

        $unitMeta = $availableUnits[$unit_id];
        $operatorName = trim((string)($unitMeta['operator_name'] ?? ''));
        if ($operatorName === '') {
            $operatorName = trim((string)($unitMeta['identifier'] ?? 'Operator'));
        }
        $stmtOperatorRecord->execute([
            $operatorName,
            dispatch_vehicle_label((string)($unitMeta['unit_type'] ?? ''), (string)($unitMeta['vehicle_name'] ?? '')),
            $incidentRow['location_address'] ?? null,
            $incidentRow['latitude'] ?? null,
            $incidentRow['longitude'] ?? null,
            $incidentRow['priority'] ?? null,
            $incidentRow['description'] ?? null,
        ]);

        $dispatchedUnits[] = [
            'dispatch_id' => $dispatchId,
            'id' => (int)$unitMeta['id'],
            'identifier' => (string)($unitMeta['identifier'] ?? ''),
            'unit_type' => (string)($unitMeta['unit_type'] ?? ''),
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
            $notificationPayload = array_map(static function (array $unit) use ($incident_id): array {
                return [
                    'dispatch_id' => $unit['dispatch_id'],
                    'incident_id' => $incident_id,
                    'unit_id' => $unit['id'],
                    'dispatch_status' => 'assigned',
                    'assigned_at' => date('Y-m-d H:i:s'),
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
            VALUES (?, 'dispatch_confirmed', 'dispatch', ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmtLog->execute([$userId, $dispatchIds[0] ?? null, json_encode($notificationDetails, JSON_UNESCAPED_UNICODE)]);
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
