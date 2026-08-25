<?php
declare(strict_types=1);

/**
 * Incident Logging and Classification outbound API.
 *
 * This endpoint can resend a logged ERS incident on demand. New incidents are
 * automatically sent by api/calls_create.php through the function below.
 */
require_once __DIR__ . '/group1_incident_client.php';

function ers_send_incident_logging_classification(PDO $pdo, int $callId, int $incidentId = 0, array $options = []): array
{
    return ers_group1_send_logged_incident($pdo, $callId, $incidentId, $options);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ers_external_authenticate();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        ers_external_json(200, [
            'success' => true,
            'service' => 'Incident Logging and Classification',
            'message' => 'Use POST with call_id or incident_id to send a logged incident to the configured receiving system.',
            'method' => 'POST',
            'data_sent' => [
                'call_id',
                'timestamp',
                'caller_location',
                'emergency_level',
                'incident_description',
            ],
            'configuration' => 'Set GROUP1_INCIDENT_ENDPOINT on the ERS server to the receiving system\'s POST endpoint.',
            'example' => [
                'body' => [
                    'call_id' => 1,
                    'dry_run' => true,
                ],
            ],
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ers_external_json(405, ['success' => false, 'error' => 'POST method required']);
    }

    $input = ers_external_input();
    $callId = (int)($input['call_id'] ?? $input['callId'] ?? 0);
    $incidentId = (int)($input['incident_id'] ?? $input['incidentId'] ?? 0);
    $result = ers_send_incident_logging_classification(ers_external_db(), $callId, $incidentId, $input);
    $status = (string)($result['status'] ?? 'failed');
    $httpStatus = (bool)($result['success'] ?? false)
        ? 200
        : (in_array($status, ['invalid_request', 'not_found', 'missing_endpoint', 'invalid_endpoint'], true) ? 422 : 502);
    ers_external_json($httpStatus, $result);
}
