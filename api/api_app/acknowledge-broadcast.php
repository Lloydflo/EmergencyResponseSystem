<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/../../includes/activity_log.php';

op_require_method('POST');
$responderId = op_post_int('responder_id');
$broadcastId = op_post_int('broadcast_id');
op_require_positive($responderId, 'responder_id');
op_require_positive($broadcastId, 'broadcast_id');

try {
    $pdo = db();
    $responder = op_require_active_responder($pdo, $responderId);

    $broadcastStmt = $pdo->prepare('SELECT id, incident_id, message FROM interagency_incident_broadcasts WHERE id = ? LIMIT 1');
    $broadcastStmt->execute([$broadcastId]);
    $broadcast = $broadcastStmt->fetch(PDO::FETCH_ASSOC);
    if (!$broadcast) {
        op_error('Broadcast was not found.', 404);
    }

    $statement = $pdo->prepare(
        'INSERT INTO interagency_incident_broadcast_acks '
        . '(broadcast_id, user_id, acknowledged_at) VALUES (?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE acknowledged_at = VALUES(acknowledged_at)'
    );
    $statement->execute([$broadcastId, $responderId]);

    // Notify admin/dispatcher: recorded in activity_log, surfaced by
    // broadcast_ack_notifications.php in the header notification bell.
    $responderLabel = trim((string)($responder['name'] ?? '')) ?: ('Responder #' . $responderId);
    $unitCode = trim((string)($responder['unit_code'] ?? ''));
    if ($unitCode !== '') {
        $responderLabel .= ' (' . $unitCode . ')';
    }
    log_activity_event(
        $responderId,
        'broadcast_acknowledged',
        'interagency_broadcast',
        $broadcastId,
        json_encode([
            'broadcast_id' => $broadcastId,
            'incident_id' => $broadcast['incident_id'] ?? null,
            'responder_id' => $responderId,
            'responder_name' => $responderLabel,
        ], JSON_UNESCAPED_SLASHES)
    );

    op_success(['message' => 'Broadcast acknowledged.']);
} catch (Throwable $error) {
    error_log('acknowledge-broadcast: ' . $error->getMessage());
    op_error('Unable to acknowledge this broadcast.', 500);
}
