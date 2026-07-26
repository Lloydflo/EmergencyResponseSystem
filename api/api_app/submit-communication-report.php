<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');
$pdo = db();

$reporterId = op_post_int('reporter_id');
$reportedResponderId = op_post_int('reported_responder_id');
$reason = op_post_string('reason', '', 5000);

op_require_positive($reporterId, 'reporter_id');
op_require_positive($reportedResponderId, 'reported_responder_id');
op_require_text($reason, 'reason');
if ($reporterId === $reportedResponderId) {
    op_error('A responder cannot report their own communication account.', 422);
}

$reporter = op_require_active_responder($pdo, $reporterId);
$reported = op_active_responder($pdo, $reportedResponderId);
if ($reported === null) {
    op_error('The reported responder account was not found or is inactive.', 404);
}

$details = json_encode([
    'schema' => 'ers_communication_report_v1',
    'reporter_id' => $reporterId,
    'reporter_name' => (string)($reporter['name'] ?? ''),
    'reported_responder_id' => $reportedResponderId,
    'reported_responder_name' => (string)($reported['name'] ?? ''),
    'reported_responder_role' => (string)($reported['department'] ?? ''),
    'reason' => $reason,
    'status' => 'pending',
    'source' => 'responder_app',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

$statement = $pdo->prepare(
    "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
     VALUES (?, 'communication_issue', 'responder', ?, ?, CURRENT_TIMESTAMP)"
);
$statement->execute([$reporterId, $reportedResponderId, $details]);

op_success([
    'message' => 'Communication issue submitted for review.',
    'report_id' => (int)$pdo->lastInsertId(),
    'status' => 'pending',
], 201);
