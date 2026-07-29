<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_fcm.php';

op_require_method('POST');
$configuredKey = trim((string)(getenv('APP_BROADCAST_PUSH_KEY') ?: ($_ENV['APP_BROADCAST_PUSH_KEY'] ?? '')));
$providedKey = trim((string)($_SERVER['HTTP_X_BROADCAST_KEY'] ?? op_post_string('broadcast_key', '', 512)));
if ($configuredKey === '' || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
    op_error('Broadcast creation authorization failed.', 403);
}

$creatorId = op_post_int('created_by');
$incidentId = op_post_int('incident_id');
$priority = strtolower(op_post_string('priority', 'routine', 20));
$message = op_post_string('message', '', 5000);
op_require_positive($creatorId, 'created_by');
op_require_positive($incidentId, 'incident_id');
op_require_text($message, 'message');
if (!in_array($priority, ['routine', 'urgent', 'critical'], true)) {
    op_error('Invalid broadcast priority.', 422);
}

try {
    $pdo = db();
    op_require_active_reviewer($pdo, $creatorId);
    $incidentStmt = $pdo->prepare('SELECT id, reference_no, type, location_address FROM incidents WHERE id = ? LIMIT 1');
    $incidentStmt->execute([$incidentId]);
    $incident = op_fetch_one($incidentStmt);
    if ($incident === null) {
        op_error('Incident was not found.', 404);
    }

    $insert = $pdo->prepare(
        'INSERT INTO interagency_incident_broadcasts '
        . '(incident_id, priority, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())'
    );
    $insert->execute([$incidentId, $priority, $message, $creatorId]);
    $broadcastId = (int)$pdo->lastInsertId();

    $result = ['attempted' => 0, 'delivered' => 0, 'failed' => 0];
    $pushErrorMessage = '';
    try {
        $result = ers_fcm_send_to_all_responders($pdo, [
            'type' => 'broadcast',
            'broadcast_id' => $broadcastId,
            'incident_id' => $incidentId,
            'priority' => $priority,
            'title' => trim((string)$incident['type'] . ' • ' . (string)$incident['reference_no']),
            // FCM data payloads have a strict size limit; the app fetches the
            // complete authoritative broadcast from MySQL after the user opens it.
            'body' => ers_notification_preview($message, 700),
            'location' => (string)($incident['location_address'] ?? ''),
        ]);
    } catch (Throwable $pushError) {
        // The authoritative broadcast record already exists. Do not report the
        // database operation as failed or invite duplicate rows on a retry.
        $pushErrorMessage = 'Push delivery is not configured or temporarily unavailable.';
        error_log('broadcast push skipped: ' . $pushError->getMessage());
    }

    op_success([
        'message' => $pushErrorMessage === ''
            ? 'Emergency broadcast created and push delivery processed.'
            : 'Emergency broadcast created. ' . $pushErrorMessage,
        'broadcast_id' => $broadcastId,
        'attempted' => (int)$result['attempted'],
        'delivered' => (int)$result['delivered'],
        'failed' => (int)$result['failed'],
        'push_warning' => $pushErrorMessage,
    ], 201);
} catch (Throwable $error) {
    error_log('create-incident-broadcast: ' . $error->getMessage());
    op_error('Unable to create the emergency broadcast.', 500);
}
