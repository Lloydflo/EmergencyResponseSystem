<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/../../includes/activity_log.php';

function route_distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadius * (2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
}

try {
    $pdo = db();
    $input = json_decode((string)file_get_contents('php://input'), true);
    $input = is_array($input) ? $input : [];

    $incidentId = (int)($input['incident_id'] ?? 0);
    $assignmentId = (int)($input['assignment_id'] ?? 0);
    $responderId = (int)($input['responder_id'] ?? 0);
    if ($incidentId <= 0 || $responderId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Missing incident_id or responder_id']);
        exit;
    }

    $check = $pdo->prepare(
        'SELECT id, started_at, arrived_at, duration_seconds, total_points, '
        . 'total_distance_meters, average_speed_kmh, max_speed_kmh '
        . 'FROM responder_route_summary '
        . 'WHERE incident_id = :incident_id AND responder_id = :responder_id '
        . 'AND arrived_at IS NOT NULL LIMIT 1'
    );
    $check->execute([':incident_id' => $incidentId, ':responder_id' => $responderId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (is_array($existing)) {
        echo json_encode([
            'success' => true,
            'message' => 'Arrival already recorded',
            'idempotent' => true,
            'duration_seconds' => (int)($existing['duration_seconds'] ?? 0),
            'total_points' => (int)($existing['total_points'] ?? 0),
            'total_distance_meters' => (float)($existing['total_distance_meters'] ?? 0),
            'average_speed_kmh' => (float)($existing['average_speed_kmh'] ?? 0),
            'max_speed_kmh' => (float)($existing['max_speed_kmh'] ?? 0),
        ]);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT latitude, longitude, speed, recorded_at FROM responder_route_history '
        . 'WHERE incident_id = :incident_id AND responder_id = :responder_id '
        . 'ORDER BY recorded_at ASC, id ASC'
    );
    $statement->execute([':incident_id' => $incidentId, ':responder_id' => $responderId]);
    $points = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (!$points) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'No route history points found']);
        exit;
    }

    $totalDistance = 0.0;
    for ($index = 1, $count = count($points); $index < $count; $index++) {
        $previous = $points[$index - 1];
        $current = $points[$index];
        $totalDistance += route_distance_meters(
            (float)$previous['latitude'],
            (float)$previous['longitude'],
            (float)$current['latitude'],
            (float)$current['longitude']
        );
    }

    $startedAt = (string)$points[0]['recorded_at'];
    $arrivedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $totalPoints = count($points);
    $duration = $pdo->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, ?, ?))');
    $duration->execute([$startedAt, $arrivedAt]);
    $durationSeconds = (int)$duration->fetchColumn();
    $averageSpeedKmh = $durationSeconds > 0
        ? ($totalDistance / 1000) / ($durationSeconds / 3600)
        : 0.0;
    $maxSpeedKmh = 0.0;
    foreach ($points as $point) {
        $maxSpeedKmh = max($maxSpeedKmh, (float)($point['speed'] ?? 0) * 3.6);
    }

    $save = $pdo->prepare(
        'INSERT INTO responder_route_summary '
        . '(incident_id, responder_id, started_at, arrived_at, duration_seconds, total_points, '
        . 'total_distance_meters, average_speed_kmh, max_speed_kmh) '
        . 'VALUES (:incident_id, :responder_id, :started_at, :arrived_at, :duration_seconds, '
        . ':total_points, :total_distance_meters, :average_speed_kmh, :max_speed_kmh) '
        . 'ON DUPLICATE KEY UPDATE arrived_at = VALUES(arrived_at), '
        . 'duration_seconds = VALUES(duration_seconds), total_points = VALUES(total_points), '
        . 'total_distance_meters = VALUES(total_distance_meters), '
        . 'average_speed_kmh = VALUES(average_speed_kmh), max_speed_kmh = VALUES(max_speed_kmh)'
    );
    $save->execute([
        ':incident_id' => $incidentId,
        ':responder_id' => $responderId,
        ':started_at' => $startedAt,
        ':arrived_at' => $arrivedAt,
        ':duration_seconds' => $durationSeconds,
        ':total_points' => $totalPoints,
        ':total_distance_meters' => round($totalDistance, 2),
        ':average_speed_kmh' => round($averageSpeedKmh, 2),
        ':max_speed_kmh' => round($maxSpeedKmh, 2),
    ]);

    $referenceNo = ers_audit_reference_no($pdo, 'incident', $incidentId, [
        'incident_id' => $incidentId,
        'assignment_id' => $assignmentId,
    ]);
    record_operational_audit_event(
        $pdo,
        $responderId,
        'route_arrived',
        'incident',
        $incidentId,
        'Responder route reached the incident destination for '
            . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId)) . '.',
        [
            'actor_role' => 'responder',
            'source_channel' => 'responder_app',
            'event_category' => 'arrival',
            'event_outcome' => 'success',
            'reference_no' => $referenceNo,
            'incident_id' => $incidentId,
            'assignment_id' => $assignmentId,
            'occurred_at' => $arrivedAt,
            'event_key' => 'incident:' . $incidentId . ':responder:' . $responderId . ':route_arrived',
            'metadata' => [
                'assignment_id' => $assignmentId > 0 ? $assignmentId : null,
                'route_started_at' => $startedAt,
                'travel_duration_seconds' => $durationSeconds,
                'distance_meters' => round($totalDistance, 2),
                'route_points' => $totalPoints,
                'average_speed_kmh' => round($averageSpeedKmh, 2),
                'max_speed_kmh' => round($maxSpeedKmh, 2),
            ],
        ]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Route summary saved',
        'duration_seconds' => $durationSeconds,
        'total_points' => $totalPoints,
        'total_distance_meters' => round($totalDistance, 2),
        'average_speed_kmh' => round($averageSpeedKmh, 2),
        'max_speed_kmh' => round($maxSpeedKmh, 2),
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('[mark-route-arrived] ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to save route arrival']);
}
