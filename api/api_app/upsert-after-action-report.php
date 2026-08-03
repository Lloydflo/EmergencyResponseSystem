<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_after_action_schema.php';

op_require_method('POST');
$pdo = db();
op_require_after_action_schema($pdo);

$incidentId = op_post_int('incident_id');
$responderId = op_post_int('responder_id');
$clientIncidentType = op_post_string('incident_type', '', 100);
$clientResponderName = op_post_string('responder_name', '', 150);
$operationalOutcome = op_post_string('operational_outcome', 'Resolved', 80);
$incidentSummary = op_post_string('incident_summary', '', 20000);
$actionsTaken = op_post_string('actions_taken', '', 20000);
$personsAssisted = max(0, op_post_int('persons_assisted'));
$injuries = max(0, op_post_int('injuries'));
$fatalities = max(0, op_post_int('fatalities'));
$resourcesUsed = op_post_string('resources_used', '', 10000);
$agenciesInvolved = op_post_string('agencies_involved', '', 10000);
$handoffDetails = op_post_string('handoff_details', '', 10000);
$safetyIssues = op_post_string('safety_issues', '', 10000);
$followUpRequired = op_post_bool('follow_up_required');
$followUpDetails = op_post_string('follow_up_details', '', 10000);
$lessonsLearned = op_post_string('lessons_learned', '', 10000);
$status = strtolower(op_post_string('status', 'draft', 16));

op_require_positive($incidentId, 'incident_id');
op_require_positive($responderId, 'responder_id');
op_require_text($operationalOutcome, 'operational_outcome');
if (!in_array($status, ['draft', 'submitted'], true)) {
    op_error('Responders may save only draft or submitted reports.', 422);
}
if ($status === 'submitted') {
    op_require_text($incidentSummary, 'incident_summary');
    op_require_text($actionsTaken, 'actions_taken');
    if ($followUpRequired) {
        op_require_text($followUpDetails, 'follow_up_details');
    }
}

$responder = op_require_active_responder($pdo, $responderId);
$incidentStatement = $pdo->prepare(
    'SELECT id, type, status, completed_at, completed_by_responder_id '
    . 'FROM incidents WHERE id = ? LIMIT 1'
);
$incidentStatement->execute([$incidentId]);
$incident = op_fetch_one($incidentStatement);
if ($incident === null) {
    op_error('Incident was not found.', 404);
}
$isCompleted = (string)($incident['status'] ?? '') === 'resolved' || !empty($incident['completed_at']);
if (!$isCompleted) {
    op_error('An after-action report can be created only after incident completion.', 409);
}
if (!op_responder_can_report_incident($pdo, $incidentId, $responderId)) {
    op_error('This incident is not assigned to the responder account.', 403);
}

$incidentType = trim((string)($incident['type'] ?? ''));
if ($incidentType === '') {
    $incidentType = $clientIncidentType !== '' ? $clientIncidentType : 'general';
}
$responderName = trim((string)($responder['name'] ?? ''));
if ($responderName === '') {
    $responderName = $clientResponderName !== '' ? $clientResponderName : 'Responder';
}

try {
    $pdo->beginTransaction();
    $existingStatement = $pdo->prepare(
        'SELECT * FROM responder_after_action_reports '
        . 'WHERE incident_id = ? AND responder_id = ? LIMIT 1 FOR UPDATE'
    );
    $existingStatement->execute([$incidentId, $responderId]);
    $existing = op_fetch_one($existingStatement);

    if ($existing !== null) {
        $existingStatus = strtolower((string)($existing['status'] ?? 'draft'));
        if ($existingStatus === 'verified') {
            $pdo->rollBack();
            op_error('The verified after-action report is read-only.', 409);
        }
        if ($existingStatus === 'submitted') {
            if ($status === 'submitted') {
                $reload = $pdo->prepare(
                    'SELECT aar.*, UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
                    . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms '
                    . 'FROM responder_after_action_reports aar WHERE aar.id = ? LIMIT 1'
                );
                $reload->execute([(int)$existing['id']]);
                $row = op_fetch_one($reload);
                $pdo->commit();
                op_success([
                    'message' => 'The after-action report is already submitted.',
                    'report' => op_after_action_response($row ?? $existing),
                    'idempotent' => true,
                ]);
            }
            $pdo->rollBack();
            op_error('A submitted report cannot be changed until it is returned for revision.', 409);
        }

        $update = $pdo->prepare(
            'UPDATE responder_after_action_reports SET '
            . 'incident_type = ?, responder_name = ?, operational_outcome = ?, '
            . 'incident_summary = ?, actions_taken = ?, persons_assisted = ?, injuries = ?, '
            . 'fatalities = ?, resources_used = ?, agencies_involved = ?, handoff_details = ?, '
            . 'safety_issues = ?, follow_up_required = ?, follow_up_details = ?, lessons_learned = ?, '
            . 'status = ?, submitted_at = CASE WHEN ? = \'submitted\' THEN CURRENT_TIMESTAMP ELSE NULL END, '
            . 'reviewer_user_id = CASE WHEN ? = \'submitted\' THEN NULL ELSE reviewer_user_id END, '
            . 'reviewer_notes = CASE WHEN ? = \'submitted\' THEN NULL ELSE reviewer_notes END, '
            . 'reviewed_at = CASE WHEN ? = \'submitted\' THEN NULL ELSE reviewed_at END, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $update->execute([
            $incidentType, $responderName, $operationalOutcome, $incidentSummary, $actionsTaken,
            $personsAssisted, $injuries, $fatalities, $resourcesUsed, $agenciesInvolved,
            $handoffDetails, $safetyIssues, $followUpRequired ? 1 : 0, $followUpDetails,
            $lessonsLearned, $status, $status, $status, $status, $status, (int)$existing['id'],
        ]);
        $reportId = (int)$existing['id'];
        $created = false;
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO responder_after_action_reports ('
            . 'incident_id, responder_id, incident_type, responder_name, operational_outcome, '
            . 'incident_summary, actions_taken, persons_assisted, injuries, fatalities, resources_used, '
            . 'agencies_involved, handoff_details, safety_issues, follow_up_required, follow_up_details, '
            . 'lessons_learned, status, submitted_at, created_at, updated_at'
            . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '
            . 'CASE WHEN ? = \'submitted\' THEN CURRENT_TIMESTAMP ELSE NULL END, '
            . 'CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $insert->execute([
            $incidentId, $responderId, $incidentType, $responderName, $operationalOutcome,
            $incidentSummary, $actionsTaken, $personsAssisted, $injuries, $fatalities,
            $resourcesUsed, $agenciesInvolved, $handoffDetails, $safetyIssues,
            $followUpRequired ? 1 : 0, $followUpDetails, $lessonsLearned, $status, $status,
        ]);
        $reportId = (int)$pdo->lastInsertId();
        $created = true;
    }

    $reload = $pdo->prepare(
        'SELECT aar.*, UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
        . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms '
        . 'FROM responder_after_action_reports aar WHERE aar.id = ? LIMIT 1'
    );
    $reload->execute([$reportId]);
    $report = op_fetch_one($reload);
    if ($report === null) {
        throw new RuntimeException('The after-action report could not be reloaded.');
    }

    $pdo->commit();
    op_success([
        'message' => $status === 'submitted'
            ? 'After-action report submitted for verification.'
            : 'After-action report draft saved.',
        'report' => op_after_action_response($report),
        'created' => $created,
        'idempotent' => false,
    ], $created ? 201 : 200);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}
