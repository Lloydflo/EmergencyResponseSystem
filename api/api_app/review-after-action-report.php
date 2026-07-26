<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');
$pdo = db();
if (!op_table_exists($pdo, 'responder_after_action_reports')) {
    op_error('After-action reporting is not installed on the database yet.', 503);
}

$reviewerId = op_post_int('reviewer_id');
$reportId = op_post_int('report_id');
$action = strtolower(op_post_string('action', '', 16));
$notes = op_post_string('notes', '', 10000);

op_require_active_reviewer($pdo, $reviewerId);
op_require_positive($reportId, 'report_id');
if (!in_array($action, ['verify', 'return'], true)) {
    op_error('action must be verify or return.', 422);
}
if ($action === 'return') {
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

    $newStatus = $action === 'verify' ? 'verified' : 'returned';
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
    $pdo->commit();

    op_success([
        'message' => $action === 'verify'
            ? 'After-action report verified.'
            : 'After-action report returned for revision.',
        'report' => op_after_action_response($updated ?? $report),
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
