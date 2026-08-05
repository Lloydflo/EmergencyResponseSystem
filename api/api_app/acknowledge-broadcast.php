<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');
$responderId = op_post_int('responder_id');
$broadcastId = op_post_int('broadcast_id');
op_require_positive($responderId, 'responder_id');
op_require_positive($broadcastId, 'broadcast_id');

try {
    $pdo = db();
    op_require_active_responder($pdo, $responderId);

    $exists = $pdo->prepare('SELECT 1 FROM interagency_incident_broadcasts WHERE id = ? LIMIT 1');
    $exists->execute([$broadcastId]);
    if (!$exists->fetchColumn()) {
        op_error('Broadcast was not found.', 404);
    }

    $statement = $pdo->prepare(
        'INSERT INTO interagency_incident_broadcast_acks '
        . '(broadcast_id, user_id, acknowledged_at) VALUES (?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE acknowledged_at = VALUES(acknowledged_at)'
    );
    $statement->execute([$broadcastId, $responderId]);

    op_success(['message' => 'Broadcast acknowledged.']);
} catch (Throwable $error) {
    error_log('acknowledge-broadcast: ' . $error->getMessage());
    op_error('Unable to acknowledge this broadcast.', 500);
}
