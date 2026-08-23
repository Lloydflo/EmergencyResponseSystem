<?php
declare(strict_types=1);

require_once __DIR__ . '/_assignment.php';

op_require_method('POST');
$assignmentId = op_post_int('assignment_id');
if ($assignmentId <= 0) {
    // Backward compatibility: the existing Android app sends the assignment
    // id using the legacy field name incident_id.
    $assignmentId = op_post_int('incident_id');
}
$responderId = op_post_int('responder_id');
op_require_positive($assignmentId, 'assignment_id');
op_require_positive($responderId, 'responder_id');

try {
    $pdo = db();
    $responder = op_require_active_responder($pdo, $responderId);

    $pdo->beginTransaction();
    $assignment = app_assignment_row($pdo, $assignmentId, $responderId, true);
    if ($assignment === null) {
        $pdo->rollBack();
        op_error('Assignment not found.', 404);
    }

    $previousStatus = app_assignment_normalize_status((string)($assignment['status'] ?? 'pending'));
    $result = app_assignment_change_status($pdo, $assignment, 'received');
    $incidentId = (int)($result['incident_id'] ?? $assignment['incident_id'] ?? 0);
    $referenceNo = ers_audit_reference_no($pdo, 'assignment', $assignmentId, [
        'incident_id' => $incidentId,
        'assignment_id' => $assignmentId,
    ]);

    if ($previousStatus !== 'received') {
        record_operational_audit_event(
            $pdo,
            $responderId,
            'assignment_received',
            'assignment',
            $assignmentId,
            'Responder acknowledged and received assignment '
                . ($referenceNo !== '' ? $referenceNo : ('#' . $assignmentId)) . '.',
            [
                'actor_name' => (string)($responder['name'] ?? ''),
                'actor_email' => (string)($responder['email'] ?? ''),
                'actor_role' => 'responder',
                'source_channel' => 'responder_app',
                'event_category' => 'assignment',
                'event_outcome' => 'success',
                'reference_no' => $referenceNo,
                'incident_id' => $incidentId,
                'assignment_id' => $assignmentId,
                'event_key' => 'assignment:' . $assignmentId . ':received',
                'metadata' => [
                    'previous_status' => $previousStatus,
                    'assignment_status' => 'received',
                    'unit_status' => (string)($result['unit_status'] ?? 'busy'),
                    'unit_code' => (string)($assignment['assigned_unit_code'] ?? $assignment['responder_unit_code'] ?? ''),
                ],
            ]
        );
    }

    $pdo->commit();
    op_success([
        'message' => 'Assignment received.',
        'assignment_status' => (string)$result['assignment_status'],
        'unit_status' => (string)$result['unit_status'],
        'incident_id' => $incidentId > 0 ? $incidentId : null,
        'affected_rows' => $previousStatus === 'received' ? 0 : 1,
        'idempotent' => (bool)($result['idempotent'] ?? false),
    ]);
} catch (AppAssignmentException $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    op_error($error->getMessage(), $error->httpStatus);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[mark-assignment-received] ' . $error->getMessage());
    op_error('Unable to acknowledge the assignment.', 500);
}
