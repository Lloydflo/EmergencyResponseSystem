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

try {
    $pdo = db();

    $statement = $pdo->query(
        "
        SELECT
            id,
            incident_id,
            assignment_id,
            responder_id,
            start_lat,
            start_lng,
            destination_lat,
            destination_lng,
            status,
            created_at,
            updated_at
        FROM alternative_route_requests
        WHERE status IN ('pending', 'processing')
        ORDER BY created_at ASC
        LIMIT 100
        "
    );

    $requests = [];

    foreach ($statement->fetchAll() as $row) {
        $requests[] = [
            'request_id' =>
                (int) $row['id'],

            'incident_id' =>
                $row['incident_id'],

            'assignment_id' =>
                $row['assignment_id'],

            'responder_id' =>
                (int) $row['responder_id'],

            /*
             * Responder location.
             */
            'start_lat' =>
                (float) $row['start_lat'],

            'start_lng' =>
                (float) $row['start_lng'],

            /*
             * Incident location.
             */
            'destination_lat' =>
                (float) $row['destination_lat'],

            'destination_lng' =>
                (float) $row['destination_lng'],

            'status' =>
                $row['status'],

            'created_at' =>
                $row['created_at'],

            'updated_at' =>
                $row['updated_at']
        ];
    }

    jsonResponse(200, [
        'success' => true,
        'requests' => $requests
    ]);

} catch (PDOException $error) {
    error_log(
        'get-pending-alternative-routes.php: ' .
        $error->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'Database error while loading requests'
    ]);
}