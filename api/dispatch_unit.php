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
try {
    $dispatchIds = [];
    $dispatchedUnits = [];
    $notificationPayload = [];
    $notificationLogged = false;

    $pdo->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
    $unitStmt = $pdo->prepare("SELECT id, identifier, unit_type, status FROM units WHERE id IN ($placeholders) FOR UPDATE");
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
    foreach ($unit_ids as $unit_id) {
        $stmtIns->execute([$incident_id, $unit_id]);
        $dispatchId = (int)$pdo->lastInsertId();
        $dispatchIds[] = $dispatchId;

        $stmtUnit->execute([$incident_id, $unit_id]);
        ers_sync_vehicle_resource_status_by_unit_id($pdo, $unit_id, 'in_use');

        $unitMeta = $availableUnits[$unit_id];
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
