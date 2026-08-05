<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$requestId = (int)($_POST['request_id'] ?? 0);
$responderId = (int)($_POST['responder_id'] ?? 0);
if ($requestId <= 0 || $responderId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing request_id or responder_id']);
    exit;
}

try {
    $pdo = db();
    $statement = $pdo->prepare(
        'SELECT status, incident_id, requested_department FROM responder_backup_requests '
        . 'WHERE id = ? AND responder_id = ? LIMIT 1'
    );
    $statement->execute([$requestId, $responderId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }
    if (strtolower((string)$row['status']) !== 'pending') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only pending requests can be cancelled']);
        exit;
    }

    $update = $pdo->prepare(
        "UPDATE responder_backup_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
    );
    $update->execute([$requestId]);

    $incidentRaw = trim((string)($row['incident_id'] ?? ''));
    $incidentId = ctype_digit($incidentRaw) ? (int)$incidentRaw : 0;
    $referenceNo = $incidentId > 0
        ? ers_audit_reference_no($pdo, 'incident', $incidentId, ['incident_id' => $incidentId])
        : '';
    record_operational_audit_event(
        $pdo,
        $responderId,
        'backup_request_cancelled',
        'backup_request',
        $requestId,
        'Responder cancelled a pending backup request'
            . ($referenceNo !== '' ? ' for incident ' . $referenceNo : '') . '.',
        [
            'actor_role' => 'responder',
            'source_channel' => 'responder_app',
            'event_category' => 'resource',
            'event_outcome' => 'warning',
            'reference_no' => $referenceNo,
            'incident_id' => $incidentId,
            'event_key' => 'backup_request:' . $requestId . ':cancelled',
            'metadata' => [
                'request_id' => $requestId,
                'requested_department' => (string)($row['requested_department'] ?? ''),
            ],
        ]
    );

    echo json_encode(['success' => true, 'message' => 'Backup request cancelled']);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('[cancel-backup-request] ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to cancel backup request']);
}
