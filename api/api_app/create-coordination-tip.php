<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');
$pdo = db();

$senderUserId = op_post_int('sender_user_id');
$senderNameFromClient = op_post_string('sender_name', '', 150);
$recipientType = strtolower(op_post_string('recipient_type', '', 16));
$recipientIdRaw = op_post_string('recipient_id', '', 32);
$clientReference = strtoupper(op_post_string('client_reference', '', 120));
$incidentType = strtolower(op_post_string('incident_type', 'general', 40));
$priority = strtolower(op_post_string('priority', 'medium', 16));
$location = op_post_string('location', '', 2000);
$latitude = op_post_nullable_float('latitude', -90.0, 90.0);
$longitude = op_post_nullable_float('longitude', -180.0, 180.0);
$contactNumber = op_post_string('contact_number', '', 40);
$description = op_post_string('description', '', 10000);
$policeBackupReason = op_post_string('police_backup_reason', '', 5000);

op_require_positive($senderUserId, 'sender_user_id');
op_require_text($recipientIdRaw, 'recipient_id');
op_require_text($clientReference, 'client_reference');
op_require_text($location, 'location');
op_require_text($description, 'description');

if (!in_array($recipientType, ['private', 'group'], true)) {
    op_error('recipient_type must be private or group.', 422);
}
if (!ctype_digit($recipientIdRaw) || (int)$recipientIdRaw <= 0) {
    op_error('recipient_id must be a valid numeric ID.', 422);
}
if (!preg_match('/^TIP-[A-Z0-9_-]{8,116}$/', $clientReference)) {
    op_error('client_reference format is invalid.', 422);
}
if (!in_array($incidentType, ['police', 'medical', 'fire', 'general'], true)) {
    op_error('Unsupported incident_type.', 422);
}
if (!in_array($priority, ['high', 'medium', 'low'], true)) {
    op_error('Unsupported priority.', 422);
}
if (($latitude === null) xor ($longitude === null)) {
    op_error('latitude and longitude must be supplied together.', 422);
}

$sender = op_require_active_responder($pdo, $senderUserId);
$senderName = trim((string)($sender['name'] ?? ''));
if ($senderName === '') {
    $senderName = $senderNameFromClient !== '' ? $senderNameFromClient : 'Responder';
}

$recipientId = (int)$recipientIdRaw;
if ($recipientType === 'private') {
    if ($recipientId === $senderUserId) {
        op_error('A coordination tip cannot be sent to the same responder account.', 422);
    }
    if (op_active_responder($pdo, $recipientId) === null) {
        op_error('The recipient responder account was not found or is inactive.', 404);
    }
} else {
    if (!op_active_group_exists($pdo, $recipientId)) {
        op_error('The inter-agency group was not found or is inactive.', 404);
    }
    if (!op_is_group_member($pdo, $recipientId, $senderUserId)) {
        op_error('The sender is not an active member of this inter-agency group.', 403);
    }
}

$select = $pdo->prepare(
    'SELECT at.*, '
    . 'UNIX_TIMESTAMP(COALESCE(at.tip_datetime, at.received_at)) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(COALESCE(at.updated_at, at.received_at)) * 1000 AS updated_at_ms '
    . 'FROM anonymous_tips at WHERE at.tip_id = ? LIMIT 1'
);
$select->execute([$clientReference]);
$existing = op_fetch_one($select);
if ($existing !== null) {
    $existingPayload = op_decode_object((string)($existing['raw_payload'] ?? ''));
    if ((int)($existingPayload['sender_user_id'] ?? 0) !== $senderUserId) {
        op_error('The incident-tip reference is already in use.', 409);
    }
    op_success([
        'message' => 'Existing incident tip returned.',
        'tip' => op_tip_response($existing),
        'idempotent' => true,
    ]);
}

$payload = [
    'schema' => 'ers_coordination_tip_v1',
    'client_reference' => $clientReference,
    'sender_user_id' => $senderUserId,
    'sender_name' => $senderName,
    'recipient_type' => $recipientType,
    'recipient_id' => (string)$recipientId,
    'incident_type' => $incidentType,
    'priority' => $priority,
    'location' => $location,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'contact_number' => $contactNumber,
    'description' => $description,
    'police_backup_reason' => $policeBackupReason,
    'status' => 'pending',
    'source' => 'responder_app',
];
$rawPayload = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
if (!is_string($rawPayload)) {
    op_error('The incident tip could not be encoded.', 500);
}

try {
    $insert = $pdo->prepare(
        'INSERT INTO anonymous_tips '
        . '(tip_id, tip_datetime, location, tip_description, status, source_system, raw_payload, received_at, updated_at) '
        . "VALUES (?, CURRENT_TIMESTAMP, ?, ?, 'pending', 'Responder App Coordination', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
    );
    $insert->execute([
        $clientReference,
        substr($location, 0, 255),
        $description,
        $rawPayload,
    ]);
    $tipRowId = (int)$pdo->lastInsertId();
} catch (PDOException $error) {
    if ((string)$error->getCode() !== '23000') {
        throw $error;
    }
    $select->execute([$clientReference]);
    $existing = op_fetch_one($select);
    if ($existing === null) {
        throw $error;
    }
    op_success([
        'message' => 'Existing incident tip returned.',
        'tip' => op_tip_response($existing),
        'idempotent' => true,
    ]);
}

$get = $pdo->prepare(
    'SELECT at.*, '
    . 'UNIX_TIMESTAMP(COALESCE(at.tip_datetime, at.received_at)) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(COALESCE(at.updated_at, at.received_at)) * 1000 AS updated_at_ms '
    . 'FROM anonymous_tips at WHERE at.id = ? LIMIT 1'
);
$get->execute([$tipRowId]);
$tip = op_fetch_one($get);
if ($tip === null) {
    op_error('Incident tip was created but could not be reloaded.', 500);
}

op_success([
    'message' => 'Incident tip saved.',
    'tip' => op_tip_response($tip),
    'idempotent' => false,
], 201);
