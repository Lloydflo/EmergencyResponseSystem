<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');
$pdo = db();

$tipId = op_query_int('id');
$requesterUserId = op_query_int('requester_user_id');
op_require_positive($tipId, 'id');
op_require_positive($requesterUserId, 'requester_user_id');
op_require_active_responder($pdo, $requesterUserId);

$statement = $pdo->prepare(
    'SELECT at.*, '
    . 'UNIX_TIMESTAMP(COALESCE(at.tip_datetime, at.received_at)) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(COALESCE(at.updated_at, at.received_at)) * 1000 AS updated_at_ms '
    . 'FROM anonymous_tips at WHERE at.id = ? LIMIT 1'
);
$statement->execute([$tipId]);
$tip = op_fetch_one($statement);
if ($tip === null) {
    op_error('Incident tip was not found.', 404);
}

$payload = op_decode_object((string)($tip['raw_payload'] ?? ''));
if (($payload['schema'] ?? '') !== 'ers_coordination_tip_v1') {
    op_error('This record is not a responder coordination tip.', 404);
}

$senderUserId = (int)($payload['sender_user_id'] ?? 0);
$recipientType = (string)($payload['recipient_type'] ?? '');
$recipientId = (int)($payload['recipient_id'] ?? 0);

$canView = $requesterUserId === $senderUserId;
if (!$canView && $recipientType === 'private') {
    $canView = $requesterUserId === $recipientId;
}
if (!$canView && $recipientType === 'group') {
    $canView = op_is_group_member($pdo, $recipientId, $requesterUserId);
}
if (!$canView) {
    op_error('You do not have access to this incident tip.', 403);
}

op_success(['tip' => op_tip_response($tip)]);
