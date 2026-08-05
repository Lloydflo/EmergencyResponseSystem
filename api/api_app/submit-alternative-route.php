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

$requestId = (int) (
    $input['request_id'] ?? 0
);

$points = $input['points'] ?? [];

$distanceM =
    isset($input['distance_m']) &&
    is_numeric($input['distance_m'])
        ? (float) $input['distance_m']
        : null;

$durationS =
    isset($input['duration_s']) &&
    is_numeric($input['duration_s'])
        ? (float) $input['duration_s']
        : null;

if ($requestId <= 0) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Valid request_id is required'
    ]);
}

if (
    !is_array($points) ||
    count($points) < 2
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'At least two route points are required'
    ]);
}

$normalizedPoints = [];

foreach (
    array_values($points)
    as $index => $point
) {
    /*
     * Require an explicit object:
     *
     * {
     *   "lat": 14.5995,
     *   "lng": 120.9842
     * }
     */
    if (
        !is_array($point) ||
        !array_key_exists('lat', $point) ||
        !array_key_exists('lng', $point) ||
        !is_numeric($point['lat']) ||
        !is_numeric($point['lng'])
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' =>
                "Invalid route point at index {$index}"
        ]);
    }

    $lat = (float) $point['lat'];
    $lng = (float) $point['lng'];

    if ($lat < -90.0 || $lat > 90.0) {
        jsonResponse(422, [
            'success' => false,
            'message' =>
                "Invalid latitude at index {$index}"
        ]);
    }

    if ($lng < -180.0 || $lng > 180.0) {
        jsonResponse(422, [
            'success' => false,
            'message' =>
                "Invalid longitude at index {$index}"
        ]);
    }

    $normalizedPoints[] = [
        'sequence' => $index,
        'lat' => $lat,
        'lng' => $lng
    ];
}

try {
    $routePointsJson = json_encode(
        $normalizedPoints,
        JSON_THROW_ON_ERROR |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    $pdo = db();

    $statement = $pdo->prepare(
        "
        UPDATE alternative_route_requests
        SET
            route_points_json = :route_points_json,
            distance_m = :distance_m,
            duration_s = :duration_s,
            response_message = :response_message,
            status = 'ready',
            responded_at = NOW()
        WHERE id = :request_id
          AND status IN ('pending', 'processing')
        "
    );

    $statement->execute([
        'route_points_json' =>
            $routePointsJson,

        'distance_m' =>
            $distanceM,

        'duration_s' =>
            $durationS,

        'response_message' =>
            'Alternative route available',

        'request_id' =>
            $requestId
    ]);

    if ($statement->rowCount() === 0) {
        jsonResponse(404, [
            'success' => false,
            'message' =>
                'Request was not found or is no longer pending'
        ]);
    }

    jsonResponse(200, [
        'success' => true,
        'request_id' => $requestId,
        'status' => 'ready',
        'message' => 'Alternative route saved'
    ]);

} catch (JsonException $error) {
    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to encode route points'
    ]);

} catch (PDOException $error) {
    error_log(
        'submit-alternative-route.php: ' .
        $error->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Database error while saving route'
    ]);
}