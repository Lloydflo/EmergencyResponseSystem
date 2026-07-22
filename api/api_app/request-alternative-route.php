<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/connect.php';

function jsonResponse(
    int $statusCode,
    array $body
): void {
    http_response_code($statusCode);

    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function readCoordinate(
    array $input,
    string $key,
    float $minimum,
    float $maximum
): float {
    if (
        !array_key_exists($key, $input) ||
        !is_numeric($input[$key])
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => "{$key} must be numeric"
        ]);
    }

    $value = (float) $input[$key];

    if (
        $value < $minimum ||
        $value > $maximum
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => "{$key} is outside the valid range"
        ]);
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, [
        'success' => false,
        'message' => 'POST method required'
    ]);
}

$rawBody = file_get_contents('php://input');

if (
    $rawBody === false ||
    trim($rawBody) === ''
) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'JSON request body is required'
    ]);
}

try {
    $input = json_decode(
        $rawBody,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $error) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Invalid JSON request body'
    ]);
}

if (!is_array($input)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'JSON body must be an object'
    ]);
}

$incidentId = trim(
    (string) ($input['incident_id'] ?? '')
);

$assignmentValue = trim(
    (string) ($input['assignment_id'] ?? '')
);

$assignmentId =
    $assignmentValue === ''
        ? null
        : $assignmentValue;

$responderId = (int) (
    $input['responder_id'] ?? 0
);

if ($incidentId === '') {
    jsonResponse(422, [
        'success' => false,
        'message' => 'incident_id is required'
    ]);
}

if ($responderId <= 0) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Valid responder_id is required'
    ]);
}

/*
 * Responder's current position.
 */
$startLat = readCoordinate(
    $input,
    'start_lat',
    -90.0,
    90.0
);

$startLng = readCoordinate(
    $input,
    'start_lng',
    -180.0,
    180.0
);

/*
 * Incident destination.
 */
$destinationLat = readCoordinate(
    $input,
    'destination_lat',
    -90.0,
    90.0
);

$destinationLng = readCoordinate(
    $input,
    'destination_lng',
    -180.0,
    180.0
);

try {
    $pdo = db();

    /*
     * Check if the same responder already has a pending request
     * for this incident.
     */
    $existingStatement = $pdo->prepare(
        "
        SELECT
            id,
            status
        FROM alternative_route_requests
        WHERE incident_id = :incident_id
          AND responder_id = :responder_id
          AND status IN ('pending', 'processing')
        ORDER BY id DESC
        LIMIT 1
        "
    );

    $existingStatement->execute([
        'incident_id' => $incidentId,
        'responder_id' => $responderId
    ]);

    $existing = $existingStatement->fetch();

    if ($existing !== false) {
        /*
         * Update the stored coordinates to the responder's
         * latest position.
         */
        $updateStatement = $pdo->prepare(
            "
            UPDATE alternative_route_requests
            SET
                assignment_id = :assignment_id,
                start_lat = :start_lat,
                start_lng = :start_lng,
                destination_lat = :destination_lat,
                destination_lng = :destination_lng
            WHERE id = :request_id
            "
        );

        $updateStatement->execute([
            'assignment_id' => $assignmentId,
            'start_lat' => $startLat,
            'start_lng' => $startLng,
            'destination_lat' => $destinationLat,
            'destination_lng' => $destinationLng,
            'request_id' => (int) $existing['id']
        ]);

        jsonResponse(200, [
            'success' => true,
            'request_id' => (int) $existing['id'],
            'status' => $existing['status'],
            'message' => 'Existing request updated'
        ]);
    }

    $insertStatement = $pdo->prepare(
        "
        INSERT INTO alternative_route_requests (
            incident_id,
            assignment_id,
            responder_id,
            start_lat,
            start_lng,
            destination_lat,
            destination_lng,
            status
        )
        VALUES (
            :incident_id,
            :assignment_id,
            :responder_id,
            :start_lat,
            :start_lng,
            :destination_lat,
            :destination_lng,
            'pending'
        )
        "
    );

    $insertStatement->execute([
        'incident_id' => $incidentId,
        'assignment_id' => $assignmentId,
        'responder_id' => $responderId,
        'start_lat' => $startLat,
        'start_lng' => $startLng,
        'destination_lat' => $destinationLat,
        'destination_lng' => $destinationLng
    ]);

    jsonResponse(201, [
        'success' => true,
        'request_id' => (int) $pdo->lastInsertId(),
        'status' => 'pending',
        'message' => 'Alternative-route request created'
    ]);

} catch (PDOException $error) {
    error_log(
        'request-alternative-route.php: ' .
        $error->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Database error while creating request'
    ]);
}