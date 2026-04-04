<?php
// Dispatch a unit to an incident
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
$hasIncidentId = is_array($data)
    && array_key_exists('incident_id', $data)
    && $data['incident_id'] !== ''
    && is_numeric((string)$data['incident_id']);
$incident_id = $hasIncidentId ? (int)$data['incident_id'] : null;
$unit_id = isset($data['unit_id']) ? (int)$data['unit_id'] : 0;
if ($incident_id === null || !$unit_id) {
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
    $dispatchId = null;
    $notificationPayload = null;
    $notificationLogged = false;

    $pdo->beginTransaction();
    // Create dispatch record (triggers will set statuses accordingly)
    $stmtIns = $pdo->prepare("INSERT INTO dispatches (incident_id, unit_id, status, assigned_at) VALUES (?, ?, 'assigned', CURRENT_TIMESTAMP)");
    $stmtIns->execute([$incident_id, $unit_id]);
    $dispatchId = (int)$pdo->lastInsertId();

    // Safety: ensure unit links to incident
    $stmtUnit = $pdo->prepare("UPDATE units SET status='assigned', current_incident_id=?, last_status_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmtUnit->execute([$incident_id, $unit_id]);

    ers_sync_vehicle_resource_status_by_unit_id($pdo, $unit_id, 'in_use');

    // Safety: mark incident dispatched
    $stmtInc = $pdo->prepare("UPDATE incidents SET status='dispatched', updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmtInc->execute([$incident_id]);

    $pdo->commit();

    // Build payload for app notification feed (best-effort; does not block dispatch success).
    try {
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
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmtMeta->execute([$dispatchId]);
        $notificationPayload = $stmtMeta->fetch(PDO::FETCH_ASSOC);

        if (!is_array($notificationPayload)) {
            $notificationPayload = [
                'dispatch_id' => $dispatchId,
                'incident_id' => $incident_id,
                'unit_id' => $unit_id,
                'dispatch_status' => 'assigned',
                'assigned_at' => date('Y-m-d H:i:s'),
                'reference_no' => null,
                'incident_type' => null,
                'priority' => null,
                'location_address' => null,
                'unit_identifier' => null,
                'unit_type' => null
            ];
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $notificationText = 'Dispatch confirmed for incident #' . (string)$incident_id . ' with unit #' . (string)$unit_id;
        $notificationDetails = [
            'message' => $notificationText,
            'dispatch' => $notificationPayload
        ];

        $stmtLog = $pdo->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (?, 'dispatch_confirmed', 'dispatch', ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmtLog->execute([$userId, $dispatchId, json_encode($notificationDetails, JSON_UNESCAPED_UNICODE)]);
        $notificationLogged = true;
    } catch (Throwable $logError) {
        // Dispatch already committed; keep success response even if logging fails.
    }

    echo json_encode([
        'ok' => true,
        'dispatch_id' => $dispatchId,
        'notification_logged' => $notificationLogged,
        'notification' => $notificationPayload
    ]);
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    echo json_encode(['ok'=>false,'error'=>'Dispatch failed: ' . $e->getMessage()]);
}
