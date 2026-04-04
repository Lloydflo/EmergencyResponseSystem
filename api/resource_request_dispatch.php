<?php
require_once '../includes/db.php';
require_once '../includes/vehicle_resource_units.php';
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
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing request, incident, or units to dispatch']);
    exit;
}

try {
    $pdo->beginTransaction();

    $requestStmt = $pdo->prepare('SELECT id, status, resource_name, details FROM resource_requests WHERE id = ? FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $requestRow = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$requestRow) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Backup request not found']);
        exit;
    }

    $currentStatus = (string)($requestRow['status'] ?? 'pending');
    if (in_array($currentStatus, ['rejected', 'cancelled'], true)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This request can no longer be dispatched']);
        exit;
    }
    if ($currentStatus === 'fulfilled') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This request was already sent to responders']);
        exit;
    }

    $details = json_decode((string)($requestRow['details'] ?? '{}'), true);
    if (!is_array($details)) {
        $details = [];
    }

    $requestIncidentId = isset($details['incident_id']) ? (int)$details['incident_id'] : 0;
    if ($requestIncidentId > 0 && $requestIncidentId !== $incidentId) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Incident mismatch for selected backup request']);
        exit;
    }

    $incidentStmt = $pdo->prepare('SELECT id FROM incidents WHERE id = ? LIMIT 1');
    $incidentStmt->execute([$incidentId]);
    if (!$incidentStmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Incident not found']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $unitStmt = $pdo->prepare("SELECT id, identifier, unit_type, status FROM units WHERE id IN ($placeholders) FOR UPDATE");
    $unitStmt->execute($unitIds);
    $availableUnits = [];
    foreach ($unitStmt->fetchAll(PDO::FETCH_ASSOC) as $unitRow) {
        if ((string)($unitRow['status'] ?? '') !== 'available') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Unit ' . (string)($unitRow['identifier'] ?? $unitRow['id']) . ' is no longer available'
            ]);
            exit;
        }
        $availableUnits[(int)$unitRow['id']] = $unitRow;
    }

    foreach ($unitIds as $unitId) {
        if (!isset($availableUnits[$unitId])) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'One or more selected units were not found']);
            exit;
        }
    }

    $dispatchInsert = $pdo->prepare("INSERT INTO dispatches (incident_id, unit_id, status, assigned_at) VALUES (?, ?, 'assigned', CURRENT_TIMESTAMP)");
    $unitUpdate = $pdo->prepare("UPDATE units SET status = 'assigned', current_incident_id = ?, last_status_at = CURRENT_TIMESTAMP WHERE id = ?");

    $dispatchedUnits = [];
    foreach ($unitIds as $unitId) {
        $dispatchInsert->execute([$incidentId, $unitId]);
        $unitUpdate->execute([$incidentId, $unitId]);
        ers_sync_vehicle_resource_status_by_unit_id($pdo, $unitId, 'in_use');

        $unitMeta = $availableUnits[$unitId];
        $dispatchedUnits[] = [
            'id' => (int)$unitMeta['id'],
            'identifier' => (string)($unitMeta['identifier'] ?? ''),
            'unit_type' => (string)($unitMeta['unit_type'] ?? ''),
        ];
    }

    $incidentUpdate = $pdo->prepare("UPDATE incidents SET status = 'dispatched', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $incidentUpdate->execute([$incidentId]);

    $details['dispatcher_name'] = $dispatcherName !== '' ? $dispatcherName : 'Dispatcher';
    $details['dispatch_notes'] = $notes;
    $details['dispatched_at'] = date('Y-m-d H:i:s');
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
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
