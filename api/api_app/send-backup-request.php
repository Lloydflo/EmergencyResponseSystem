<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$responderId = (int)($_POST['responder_id'] ?? 0);
$responderName = trim((string)($_POST['responder_name'] ?? ''));
$department = trim((string)($_POST['department'] ?? ''));
$requestedDepartment = trim((string)($_POST['requested_department'] ?? ''));
$resources = trim((string)($_POST['resources'] ?? ''));
$isFullBackup = (int)($_POST['is_full_backup'] ?? 0) === 1;
$incidentRaw = trim((string)($_POST['incident_id'] ?? ''));
$incidentId = ctype_digit($incidentRaw) ? (int)$incidentRaw : 0;

if ($responderId <= 0 || $responderName === '' || $requestedDepartment === '' || $resources === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $pdo = db();
    $statement = $pdo->prepare(
        'INSERT INTO responder_backup_requests '
        . '(responder_id, responder_name, department, requested_department, resources, is_full_backup, incident_id) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $responderId,
        $responderName,
        $department,
        $requestedDepartment,
        $resources,
        $isFullBackup ? 1 : 0,
        $incidentRaw,
    ]);
    $requestId = (int)$pdo->lastInsertId();
    $referenceNo = $incidentId > 0
        ? ers_audit_reference_no($pdo, 'incident', $incidentId, ['incident_id' => $incidentId])
        : '';

    record_operational_audit_event(
        $pdo,
        $responderId,
        'backup_requested',
        'backup_request',
        $requestId,
        'Responder requested ' . ($isFullBackup ? 'full backup' : 'additional backup')
            . ' from ' . $requestedDepartment
            . ($referenceNo !== '' ? ' for incident ' . $referenceNo : '') . '.',
        [
            'actor_name' => $responderName,
            'actor_role' => 'responder',
            'source_channel' => 'responder_app',
            'event_category' => 'resource',
            'event_outcome' => 'success',
            'reference_no' => $referenceNo,
            'incident_id' => $incidentId,
            'event_key' => 'backup_request:' . $requestId . ':created',
            'metadata' => [
                'request_id' => $requestId,
                'department' => $department,
                'requested_department' => $requestedDepartment,
                'full_backup' => $isFullBackup,
                'resources_requested' => $resources,
            ],
        ]
    );

    echo json_encode(['success' => true, 'message' => 'Backup request sent', 'id' => $requestId]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('[send-backup-request] ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send backup request']);
}
