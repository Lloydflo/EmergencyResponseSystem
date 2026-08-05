<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_fcm.php';
require_once __DIR__ . '/_assignment.php';

op_require_method('POST');
$configuredKey = trim((string)(
    getenv('APP_ASSIGNMENT_PUSH_KEY')
    ?: ($_ENV['APP_ASSIGNMENT_PUSH_KEY'] ?? '')
));
$providedKey = trim((string)(
    $_SERVER['HTTP_X_ASSIGNMENT_KEY']
    ?? op_post_string('assignment_key', '', 512)
));
if (
    $configuredKey === ''
    || $providedKey === ''
    || !hash_equals($configuredKey, $providedKey)
) {
    op_error('Assignment push authorization failed.', 403);
}

$assignmentId = op_post_int('assignment_id');
op_require_positive($assignmentId, 'assignment_id');

try {
    $pdo = db();
    op_require_tables($pdo, ['dispatch_operator_records']);
    op_require_columns($pdo, 'dispatch_operator_records', ['id', 'assigned_to']);

    $wanted = [
        'id', 'incident_id', 'assigned_to', 'name', 'vehicle', 'location',
        'priority', 'description', 'status', 'assigned_unit_code', 'assigned_unit_type'
    ];
    $select = [];
    foreach ($wanted as $column) {
        $select[] = op_column_exists($pdo, 'dispatch_operator_records', $column)
            ? 'd.`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }

    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', $select)
        . ' FROM dispatch_operator_records d WHERE d.id = ? LIMIT 1'
    );
    $statement->execute([$assignmentId]);
    $assignment = op_fetch_one($statement);
    if ($assignment === null) {
        op_error('Assignment was not found.', 404);
    }

    $responderId = (int)($assignment['assigned_to'] ?? 0);
    if ($responderId <= 0) {
        op_error('Assignment has no responder recipient.', 409);
    }

    $assignmentStatus = strtolower(trim((string)($assignment['status'] ?? 'assigned')));
    if (!in_array($assignmentStatus, app_assignment_active_statuses(), true)) {
        op_error('Only an active assignment can produce a new-assignment alert.', 409);
    }

    // Reassert the operational lock before sending. Presence may still be online
    // so the device remains reachable, but the unit is no longer eligible for a
    // second incident assignment.
    $operationalStatus = app_assignment_current_unit_status($pdo, $responderId);
    $assignedUnitCode = trim((string)($assignment['assigned_unit_code'] ?? ''));
    app_assignment_set_unit_status(
        $pdo,
        $responderId,
        $assignedUnitCode,
        $operationalStatus === 'available' ? 'busy' : $operationalStatus
    );

    $incidentId = (int)($assignment['incident_id'] ?? 0);
    $reference = '';
    $incidentType = trim((string)($assignment['name'] ?? ''));
    $location = trim((string)($assignment['location'] ?? ''));
    $description = trim((string)($assignment['description'] ?? ''));

    if (
        $incidentId > 0
        && op_table_exists($pdo, 'incidents')
        && op_column_exists($pdo, 'incidents', 'id')
    ) {
        $incidentSelect = ['id'];
        foreach (['reference_no', 'type', 'location_address', 'description'] as $column) {
            if (op_column_exists($pdo, 'incidents', $column)) {
                $incidentSelect[] = '`' . $column . '`';
            }
        }
        $incidentStatement = $pdo->prepare(
            'SELECT ' . implode(', ', $incidentSelect) . ' FROM incidents WHERE id = ? LIMIT 1'
        );
        $incidentStatement->execute([$incidentId]);
        $incident = op_fetch_one($incidentStatement);
        if ($incident !== null) {
            $reference = trim((string)($incident['reference_no'] ?? ''));
            $incidentType = trim((string)($incident['type'] ?? $incidentType));
            $location = trim((string)($incident['location_address'] ?? $location));
            $description = trim((string)($incident['description'] ?? $description));
        }
    }

    $vehicle = strtolower(trim((string)($assignment['vehicle'] ?? '')));
    if ($incidentType === '') {
        $incidentType = str_contains($vehicle, 'fire')
            ? 'fire'
            : (str_contains($vehicle, 'ambulance')
                ? 'medical'
                : (str_contains($vehicle, 'police') ? 'police' : 'emergency'));
    }

    $result = ers_fcm_send_to_user($pdo, $responderId, [
        'type' => 'assigned_incident',
        'responder_id' => $responderId,
        'recipient_id' => $responderId,
        'assignment_id' => (int)$assignment['id'],
        'incident_id' => $incidentId,
        'reference_no' => $reference,
        'incident_type' => $incidentType,
        'priority' => (string)($assignment['priority'] ?? ''),
        'location' => $location,
        'body' => ers_notification_preview(
            $description !== ''
                ? $description
                : 'Open the responder app to review the new dispatch assignment.',
            700
        ),
    ]);

    op_success([
        'message' => 'Assignment notification processed.',
        'responder_id' => $responderId,
        'assignment_id' => $assignmentId,
        'unit_status' => $operationalStatus === 'available' ? 'busy' : $operationalStatus,
        'attempted' => $result['attempted'],
        'delivered' => $result['delivered'],
        'failed' => $result['failed'],
        'errors' => $result['errors'],
    ]);
} catch (Throwable $error) {
    error_log('[send-assignment-notification] ' . $error->getMessage());
    op_error('The assignment was saved, but its push notification could not be processed.', 502);
}
