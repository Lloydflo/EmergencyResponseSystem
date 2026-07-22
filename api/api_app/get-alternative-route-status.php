<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, [
        'success' => false,
        'message' => 'GET method required'
    ]);
}

$requestId = isset($_GET['request_id'])
    ? (int) $_GET['request_id']
    : 0;

$responderId = isset($_GET['responder_id'])
    ? (int) $_GET['responder_id']
    : 0;

if (
    $requestId <= 0 ||
    $responderId <= 0
) {
    jsonResponse(422, [
        'success' => false,
        'message' =>
            'Valid request_id and responder_id are required'
    ]);
}

try {
    $pdo = db();

    $statement = $pdo->prepare(
        "
        SELECT
            id,
            responder_id,
            status,
            route_points_json,
            distance_m,
            duration_s,
            response_message,
            updated_at
        FROM alternative_route_requests
        WHERE id = :request_id
          AND responder_id = :responder_id
        LIMIT 1
        "
    );

    $statement->execute([
        'request_id' => $requestId,
        'responder_id' => $responderId
    ]);

    $row = $statement->fetch();

    if ($row === false) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Route request not found'
        ]);
    }

    $points = [];

    if (
        $row['route_points_json'] !== null &&
        trim($row['route_points_json']) !== ''
    ) {
        try {
            $decodedPoints = json_decode(
                $row['route_points_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (is_array($decodedPoints)) {
                $points = $decodedPoints;
            }
        } catch (JsonException $error) {
            error_log(
                'Invalid stored route JSON for request ' .
                $requestId
            );
        }
    }

    jsonResponse(200, [
        'success' => true,

        'request_id' =>
            (int) $row['id'],

        'status' =>
            $row['status'],

        'points' =>
            $points,

        'distance_m' =>
            $row['distance_m'] !== null
                ? (float) $row['distance_m']
                : null,

        'duration_s' =>
            $row['duration_s'] !== null
                ? (float) $row['duration_s']
                : null,

        'message' =>
            $row['response_message'],

        'updated_at' =>
            $row['updated_at']
    ]);

} catch (PDOException $error) {
    error_log(
        'get-alternative-route-status.php: ' .
        $error->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Database error while checking route status'
    ]);
}