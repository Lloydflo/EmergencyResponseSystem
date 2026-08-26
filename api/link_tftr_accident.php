<?php
// Links an ERS incident to a TFTR public_accident_id so status updates
// (Dispatched / On Scene / Cleared) sync back to the TFTR system.
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$incidentId = is_array($input) && isset($input['incident_id']) && is_numeric((string)$input['incident_id'])
    ? (int)$input['incident_id']
    : null;
$accidentId = is_array($input) ? trim((string)($input['tftr_accident_id'] ?? '')) : '';

if ($incidentId === null || $incidentId <= 0 || $accidentId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing incident_id or tftr_accident_id']);
    exit;
}

try {
    // Confirm the accident actually exists on the TFTR side before linking.
    $check = $pdo->prepare('SELECT 1 FROM `lgu-traffic`.`accident_cases` WHERE public_accident_id = ? LIMIT 1');
    $check->execute([$accidentId]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No TFTR accident found with that ID']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE incidents SET tftr_accident_id = ? WHERE id = ?');
    $stmt->execute([$accidentId, $incidentId]);

    // Push the incident's current status immediately so the two systems
    // aren't out of sync until the next transition happens.
    require_once __DIR__ . '/../includes/tftr_status_sync.php';
    $currentStatusStmt = $pdo->prepare('SELECT status FROM incidents WHERE id = ? LIMIT 1');
    $currentStatusStmt->execute([$incidentId]);
    $currentStatus = strtolower(trim((string)$currentStatusStmt->fetchColumn()));
    $tftrStatus = match ($currentStatus) {
        'dispatched' => 'Dispatched',
        'resolved' => 'Cleared',
        default => 'Reported',
    };
    ers_tftr_sync_accident_status($pdo, $incidentId, $tftrStatus);

    echo json_encode(['ok' => true, 'incident_id' => $incidentId, 'tftr_accident_id' => $accidentId]);
} catch (Throwable $e) {
    error_log('[link_tftr_accident] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to link accident']);
}
