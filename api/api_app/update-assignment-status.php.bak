<?php
declare(strict_types=1);

require_once __DIR__ . '/_assignment.php';
require_once __DIR__ . '/../../includes/anonymous_tip_status_sync.php';

op_require_method('POST');
$assignmentId = op_post_int('assignment_id');
$responderId = op_post_int('responder_id');
$requestedStatus = app_assignment_normalize_status(op_post_string('status', '', 32));
op_require_positive($assignmentId, 'assignment_id');
op_require_positive($responderId, 'responder_id');
if (!in_array($requestedStatus, ['received', 'en_route', 'on_scene', 'completed'], true)) {
    op_error('Invalid assignment status.', 422);
}

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
    $result = app_assignment_change_status($pdo, $assignment, $requestedStatus);
    $incidentId = (int)($result['incident_id'] ?? $assignment['incident_id'] ?? 0);
    $referenceNo = ers_audit_reference_no($pdo, 'assignment', $assignmentId, [
        'incident_id' => $incidentId,
        'assignment_id' => $assignmentId,
    ]);

    $isTransition = $previousStatus !== $requestedStatus;
    $action = null;
    $category = 'assignment';
    $details = '';
    $outcome = 'success';

    if ($isTransition && $previousStatus === 'en_route' && $requestedStatus === 'received') {
        $action = 'navigation_cancelled';
        $category = 'navigation';
        $outcome = 'warning';
        $details = 'Responder stopped navigation and returned assignment '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $assignmentId))
            . ' to Received.';
    } elseif ($isTransition && $requestedStatus === 'received') {
        $action = 'assignment_received';
        $category = 'assignment';
        $details = 'Responder acknowledged assignment '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $assignmentId)) . '.';
    } elseif ($isTransition && $requestedStatus === 'en_route') {
        $action = 'navigation_started';
        $category = 'navigation';
        $details = 'Responder started navigation for incident '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId)) . '.';
    } elseif ($isTransition && $requestedStatus === 'on_scene') {
        $action = 'responder_on_scene';
        $category = 'arrival';
        $details = 'Responder reported arrival on scene for incident '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId)) . '.';
    } elseif ($isTransition && $requestedStatus === 'completed') {
        // This legacy transition remains supported. The normal app flow uses
        // mark-incident-complete.php so completion proof can be validated.
        $action = 'assignment_completed';
        $category = 'completion';
        $details = 'Responder marked assignment '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $assignmentId))
            . ' completed.';
    }

    if ($action !== null) {
        record_operational_audit_event(
            $pdo,
            $responderId,
            $action,
            'assignment',
            $assignmentId,
            $details,
            [
                'actor_name' => (string)($responder['name'] ?? ''),
                'actor_email' => (string)($responder['email'] ?? ''),
                'actor_role' => 'responder',
                'source_channel' => 'responder_app',
                'event_category' => $category,
                'event_outcome' => $outcome,
                'reference_no' => $referenceNo,
                'incident_id' => $incidentId,
                'assignment_id' => $assignmentId,
                'metadata' => [
                    'previous_status' => $previousStatus,
                    'assignment_status' => $requestedStatus,
                    'unit_status' => (string)($result['unit_status'] ?? ''),
                    'unit_code' => (string)($assignment['assigned_unit_code'] ?? $assignment['responder_unit_code'] ?? ''),
                ],
            ]
        );
    }

    $pdo->commit();
    $anonymousTipStatusSync = null;
    if ($isTransition && $requestedStatus === 'completed' && $incidentId > 0) {
        $anonymousTipStatusSync = ers_notify_anonymous_tip_status_result(
            $pdo,
            $incidentId,
            'completed',
            'Responder completed the assignment.'
        );
    }

    op_success([
        'message' => $isTransition ? 'Assignment status updated.' : 'Assignment status already current.',
        'assignment_status' => (string)$result['assignment_status'],
        'unit_status' => (string)$result['unit_status'],
        'incident_resolved' => false,
        'incident_id' => $incidentId > 0 ? $incidentId : null,
        'idempotent' => !$isTransition,
        'anonymous_tip_status_sync' => $anonymousTipStatusSync,
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
    error_log('[update-assignment-status] ' . $error->getMessage());
    op_error('Unable to update the assignment status.', 500);
}
