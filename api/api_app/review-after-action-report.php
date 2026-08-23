<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_after_action_schema.php';
require_once __DIR__ . '/../../includes/activity_log.php';

op_require_method('POST');
$pdo = db();
op_require_after_action_schema($pdo);

$reviewerId = op_post_int('reviewer_id');
$reportId = op_post_int('report_id');

// When this endpoint is called from the authenticated admin website, bind the
// requested reviewer to the active session. API clients without a PHP session
// retain the existing reviewer-id validation below.
if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) {
    @session_start();
}
$sessionReviewerId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($sessionReviewerId > 0 && $sessionReviewerId !== $reviewerId) {
    op_error('The reviewer does not match the authenticated session.', 403);
}

$action = strtolower(op_post_string('action', '', 16));
$notes = op_post_string('notes', '', 10000);

$reviewer = op_require_active_reviewer($pdo, $reviewerId);
op_require_positive($reportId, 'report_id');
if (!in_array($action, ['approve', 'verify', 'return', 'reject'], true)) {
    op_error('action must be approve or return (verify and reject remain supported aliases).', 422);
}
if (in_array($action, ['return', 'reject'], true)) {
    op_require_text($notes, 'notes');
}

try {
    $pdo->beginTransaction();
    $select = $pdo->prepare(
        'SELECT * FROM responder_after_action_reports WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $select->execute([$reportId]);
    $report = op_fetch_one($select);
    if ($report === null) {
        $pdo->rollBack();
        op_error('After-action report was not found.', 404);
    }
    if ((string)$report['status'] !== 'submitted') {
        $pdo->rollBack();
        op_error('Only submitted reports can be reviewed.', 409);
    }

    $isApproval = in_array($action, ['approve', 'verify'], true);
    $newStatus = $isApproval ? 'approved' : 'returned';
    $update = $pdo->prepare(
        'UPDATE responder_after_action_reports SET status = ?, reviewer_user_id = ?, '
        . 'reviewer_notes = ?, reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
        . 'WHERE id = ?'
    );
    $update->execute([$newStatus, $reviewerId, $notes, $reportId]);

    $reload = $pdo->prepare(
        'SELECT aar.*, UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
        . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms '
        . 'FROM responder_after_action_reports aar WHERE aar.id = ? LIMIT 1'
    );
    $reload->execute([$reportId]);
    $updated = op_fetch_one($reload);

    $incidentId = (int)($report['incident_id'] ?? 0);
    $referenceNo = ers_audit_reference_no($pdo, 'after_action_report', $reportId, [
        'incident_id' => $incidentId,
        'report_id' => $reportId,
    ]);
    $reviewerRole = strtolower(trim((string)($reviewer['role'] ?? 'admin')));
    if ($reviewerRole === 'operator') {
        $reviewerRole = 'dispatcher';
    }
    $auditAction = $isApproval
        ? 'after_action_report_approved'
        : 'after_action_report_returned';
    $auditDetails = $isApproval
        ? 'Admin approved the after-action report for incident '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId)) . '.'
        : 'Reviewer returned the after-action report for incident '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId))
            . ' for revision.';
    $auditContext = [
        'actor_name' => (string)($reviewer['name'] ?? ''),
        'actor_email' => (string)($reviewer['email'] ?? ''),
        'actor_role' => $reviewerRole,
        'source_channel' => $reviewerRole === 'admin' ? 'admin_web' : 'dispatcher_web',
        'event_category' => 'report_review',
        'event_outcome' => $isApproval ? 'success' : 'warning',
        'reference_no' => $referenceNo,
        'incident_id' => $incidentId,
        'report_id' => $reportId,
        'metadata' => [
            'previous_status' => 'submitted',
            'report_status' => $newStatus,
            'review_notes_recorded' => trim($notes) !== '',
        ],
    ];
    if ($isApproval) {
        $auditContext['event_key'] = 'after_action_report:' . $reportId . ':approved';
    }
    record_operational_audit_event(
        $pdo,
        $reviewerId,
        $auditAction,
        'after_action_report',
        $reportId,
        $auditDetails,
        $auditContext
    );

    $pdo->commit();

    op_success([
        'message' => $isApproval
            ? 'After-action report approved.'
            : 'After-action report returned for revision.',
        'review_action' => $isApproval ? 'approved' : 'returned',
        'report' => op_after_action_response($updated ?? $report),
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
