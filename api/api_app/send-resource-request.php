<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$responderId = (int)($_POST['responder_id'] ?? 0);
$responderName = trim((string)($_POST['responder_name'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));
$resourceName = trim((string)($_POST['resource_name'] ?? ''));
$quantity = (int)($_POST['quantity'] ?? 0);
$urgency = trim((string)($_POST['urgency'] ?? ''));
$incidentRaw = trim((string)($_POST['incident_id'] ?? ''));
$location = trim((string)($_POST['location'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$incidentValue = ($incidentRaw === '' || strtoupper($incidentRaw) === 'N/A') ? null : $incidentRaw;
$incidentId = $incidentValue !== null && ctype_digit($incidentValue) ? (int)$incidentValue : 0;

if ($responderId <= 0 || $responderName === '' || $resourceName === '' || $quantity <= 0 || $location === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $pdo = db();
    $statement = $pdo->prepare(
        'INSERT INTO responder_resource_requests '
        . '(responder_id, responder_name, category, resource_name, quantity, urgency, incident_id, location, notes, status) '
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
    );
    $statement->execute([
        $responderId,
        $responderName,
        $category,
        $resourceName,
        $quantity,
        $urgency,
        $incidentValue,
        $location,
        $notes,
    ]);
    $requestId = (int)$pdo->lastInsertId();
    $referenceNo = $incidentId > 0
        ? ers_audit_reference_no($pdo, 'incident', $incidentId, ['incident_id' => $incidentId])
        : '';

    record_operational_audit_event(
        $pdo,
        $responderId,
        'resource_requested',
        'resource_request',
        $requestId,
        'Responder requested ' . $quantity . ' × ' . $resourceName
            . ($referenceNo !== '' ? ' for incident ' . $referenceNo : '') . '.',
        [
            'actor_name' => $responderName,
            'actor_role' => 'responder',
            'source_channel' => 'responder_app',
            'event_category' => 'resource',
            'event_outcome' => 'success',
            'reference_no' => $referenceNo,
            'incident_id' => $incidentId,
            'event_key' => 'resource_request:' . $requestId . ':created',
            'metadata' => [
                'request_id' => $requestId,
                'category' => $category,
                'resource_name' => $resourceName,
                'quantity' => $quantity,
                'urgency' => $urgency,
                'request_notes_recorded' => $notes !== '',
            ],
        ]
    );

    echo json_encode(['success' => true, 'message' => 'Resource request sent', 'id' => $requestId]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('[send-resource-request] ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send resource request']);
}
