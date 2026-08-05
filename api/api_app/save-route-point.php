<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/../../includes/unit_location_tracking.php';
require_once __DIR__ . '/../../includes/activity_log.php';

try {
    $pdo = db();
    $input = json_decode((string)file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];

    $incidentId = (int)($input['incident_id'] ?? 0);
    $assignmentId = (int)($input['assignment_id'] ?? 0);
    $responderId = (int)($input['responder_id'] ?? 0);
    $latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $speed = isset($input['speed']) && $input['speed'] !== '' ? (float)$input['speed'] : null;
    $heading = isset($input['heading']) && $input['heading'] !== '' ? (float)$input['heading'] : null;
    $status = strtolower(trim((string)($input['status'] ?? 'en_route')));

    if (
        $incidentId <= 0
        || $responderId <= 0
        || $latitude === false
        || $longitude === false
        || (float)$latitude < -90
        || (float)$latitude > 90
        || (float)$longitude < -180
        || (float)$longitude > 180
    ) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid route data']);
        exit;
    }

    $statement = $pdo->prepare(
        'INSERT INTO responder_route_history '
        . '(incident_id, responder_id, latitude, longitude, speed, heading, status) '
        . 'VALUES (:incident_id, :responder_id, :latitude, :longitude, :speed, :heading, :status)'
    );
    $statement->execute([
        ':incident_id' => $incidentId,
        ':responder_id' => $responderId,
        ':latitude' => (float)$latitude,
        ':longitude' => (float)$longitude,
        ':speed' => $speed,
        ':heading' => $heading,
        ':status' => $status !== '' ? $status : 'en_route',
    ]);

    try {
        $locationUpdate = ers_unit_location_update($pdo, [
            'responder_id' => $responderId,
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
            'speed' => $speed,
            'heading' => $heading,
            'source' => 'responder_route',
        ]);
    } catch (Throwable $locationError) {
        error_log('route point unit location update skipped: ' . $locationError->getMessage());
        $locationUpdate = ['ok' => false, 'error' => 'Location update skipped'];
    }

    $referenceNo = ers_audit_reference_no($pdo, 'incident', $incidentId, [
        'incident_id' => $incidentId,
        'assignment_id' => $assignmentId,
    ]);
    record_operational_audit_event(
        $pdo,
        $responderId,
        'route_tracking_started',
        'incident',
        $incidentId,
        'Live route tracking started for incident '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId)) . '.',
        [
            'actor_role' => 'responder',
            'source_channel' => 'responder_app',
            'event_category' => 'navigation',
            'event_outcome' => 'success',
            'reference_no' => $referenceNo,
            'incident_id' => $incidentId,
            'assignment_id' => $assignmentId,
            // Only the first GPS point is an audit milestone. Every subsequent
            // point stays in route history and does not flood the audit trail.
            'event_key' => 'incident:' . $incidentId . ':responder:' . $responderId . ':route_tracking_started',
            'metadata' => [
                'assignment_id' => $assignmentId > 0 ? $assignmentId : null,
                'route_status' => $status !== '' ? $status : 'en_route',
                'location_sync_succeeded' => (bool)($locationUpdate['ok'] ?? false),
            ],
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Route point saved',
        'location_update' => $locationUpdate,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('[save-route-point] ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to save route point']);
}
