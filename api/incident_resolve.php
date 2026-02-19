<?php
// Resolve an incident and release any assigned units
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
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
$hasIncidentId = is_array($input)
    && array_key_exists('incident_id', $input)
    && $input['incident_id'] !== ''
    && is_numeric((string)$input['incident_id']);
$incidentId = $hasIncidentId ? (int)$input['incident_id'] : null;
$note = isset($input['note']) ? trim((string)$input['note']) : '';

if ($incidentId === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing incident_id']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Update all dispatches for this incident to 'cleared' (will trigger unit availability via DB trigger)
    $stmt = $pdo->prepare("UPDATE dispatches SET status='cleared', cleared_at = CURRENT_TIMESTAMP WHERE incident_id = :iid AND status IN ('assigned','acknowledged','enroute','on_scene')");
    $stmt->execute([':iid' => $incidentId]);

    // Explicitly set units available for any units linked directly (safety net)
    $stmt2 = $pdo->prepare("UPDATE units SET status='available', current_incident_id=NULL, last_status_at=CURRENT_TIMESTAMP WHERE current_incident_id = :iid");
    $stmt2->execute([':iid' => $incidentId]);

    // Mark incident resolved
    $stmt3 = $pdo->prepare("UPDATE incidents SET status='resolved', resolved_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id = :iid");
    $stmt3->execute([':iid' => $incidentId]);

    // Optional: add note to incident_notes
    if ($note !== '') {
        $stmt4 = $pdo->prepare("INSERT INTO incident_notes (incident_id, author_name, note) VALUES (:iid, 'System', :note)");
        $stmt4->execute([':iid' => $incidentId, ':note' => $note]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Resolve failed']);
}
