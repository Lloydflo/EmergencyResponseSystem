<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_fcm.php';

op_require_method('POST');
$configuredKey = trim((string)(getenv('APP_BROADCAST_PUSH_KEY') ?: ($_ENV['APP_BROADCAST_PUSH_KEY'] ?? '')));
$providedKey = trim((string)($_SERVER['HTTP_X_BROADCAST_KEY'] ?? op_post_string('broadcast_key', '', 512)));
if ($configuredKey === '' || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
    op_error('Broadcast push authorization failed.', 403);
}

$broadcastId = op_post_int('broadcast_id');
op_require_positive($broadcastId, 'broadcast_id');

try {
    $pdo = db();
    $statement = $pdo->prepare(
        'SELECT b.id, b.incident_id, b.priority, b.message, b.created_at, '
        . 'i.reference_no, i.type, i.location_address '
        . 'FROM interagency_incident_broadcasts b '
        . 'INNER JOIN incidents i ON i.id = b.incident_id '
        . 'WHERE b.id = ? LIMIT 1'
    );
    $statement->execute([$broadcastId]);
    $row = op_fetch_one($statement);
    if ($row === null) {
        op_error('Broadcast was not found.', 404);
    }

    $reference = trim((string)($row['reference_no'] ?? ''));
    $incidentType = trim((string)($row['type'] ?? 'Incident'));
    $result = ers_fcm_send_to_all_responders($pdo, [
        'type' => 'broadcast',
        'broadcast_id' => (int)$row['id'],
        'incident_id' => (int)$row['incident_id'],
        'priority' => (string)$row['priority'],
        'title' => trim($incidentType . ($reference !== '' ? ' • ' . $reference : '')),
        // Send only a notification preview. The full message remains in MySQL.
        'body' => ers_notification_preview((string)$row['message'], 700),
        'location' => (string)($row['location_address'] ?? ''),
    ]);

    op_success([
        'message' => 'Broadcast notification processed.',
        'attempted' => $result['attempted'],
        'delivered' => $result['delivered'],
        'failed' => $result['failed'],
    ]);
} catch (Throwable $error) {
    error_log('send-broadcast-notification: ' . $error->getMessage());
    op_error('Unable to send the emergency broadcast notification.', 502);
}
