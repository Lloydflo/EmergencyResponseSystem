<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/activity_log.php';

if (!is_logged_in() || !in_array(current_session_role(), ['dispatcher', 'admin'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authenticated dispatcher or admin session required']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$milestone = strtolower(trim((string)($input['event'] ?? $input['milestone'] ?? '')));
$allowedMilestones = ['received', 'accepted', 'rejected', 'ended'];
if (!in_array($milestone, $allowedMilestones, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid call audit milestone']);
    exit;
}

$auditSessionId = trim((string)($input['audit_session_id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9.:-]{8,96}$/', $auditSessionId)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid audit_session_id']);
    exit;
}

$occurredAt = ers_audit_normalize_operational_datetime($input['occurred_at'] ?? null, true);
$referenceNo = substr(trim((string)($input['reference_no'] ?? '')), 0, 64);
$sourceSystem = substr(trim((string)($input['source_system'] ?? '')), 0, 120);
$reason = substr(trim((string)($input['reason'] ?? '')), 0, 120);
$isTransfer = filter_var($input['is_transfer'] ?? false, FILTER_VALIDATE_BOOL);

$actionByMilestone = [
    'received' => 'call_received',
    'accepted' => 'call_accepted',
    'rejected' => 'call_rejected',
    'ended' => 'call_ended',
];
$detailByMilestone = [
    'received' => 'Incoming emergency call was presented to the dispatcher queue.',
    'accepted' => 'Dispatcher accepted the incoming emergency call.',
    'rejected' => 'Dispatcher rejected the incoming emergency call.',
    'ended' => 'The active emergency call session ended.',
];
$outcomeByMilestone = [
    'received' => 'success',
    'accepted' => 'success',
    'rejected' => 'warning',
    'ended' => 'info',
];

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection unavailable']);
    exit;
}

$user = get_logged_in_user() ?? [];
$userId = isset($user['id']) && (int)$user['id'] > 0 ? (int)$user['id'] : null;
$actorRole = current_session_role();
$sourceChannel = $actorRole === 'admin' ? 'admin_web' : 'dispatcher_web';
$action = $actionByMilestone[$milestone];

$auditId = record_operational_audit_event(
    $pdo,
    $userId,
    $action,
    'call_session',
    null,
    $detailByMilestone[$milestone],
    [
        'actor_name' => (string)($user['name'] ?? ''),
        'actor_email' => (string)($user['email'] ?? ''),
        'actor_role' => $actorRole,
        'source_channel' => $sourceChannel,
        'event_category' => 'call_intake',
        'event_outcome' => $outcomeByMilestone[$milestone],
        'reference_no' => $referenceNo,
        'occurred_at' => $occurredAt,
        'event_key' => 'call_session:' . $auditSessionId . ':' . $milestone,
        'metadata' => [
            'audit_session_id' => $auditSessionId,
            'call_milestone' => $milestone,
            'is_transfer' => $isTransfer,
            'source_system' => $sourceSystem,
            'reason' => $reason,
        ],
    ]
);

if ($auditId === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to record call audit milestone']);
    exit;
}

echo json_encode([
    'ok' => true,
    'audit_log_id' => $auditId,
    'event' => $action,
    'occurred_at' => $occurredAt,
]);
