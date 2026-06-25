<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";

function distanceMeters($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a =
        sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

try {
    $pdo = db();
    $input = json_decode(file_get_contents("php://input"), true);

    $incident_id = intval($input["incident_id"] ?? 0);
    $responder_id = intval($input["responder_id"] ?? 0);

    if ($incident_id <= 0 || $responder_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Missing incident_id or responder_id"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT latitude, longitude, speed, recorded_at
        FROM responder_route_history
        WHERE incident_id = :incident_id
        AND responder_id = :responder_id
        ORDER BY recorded_at ASC, id ASC
    ");

    $stmt->execute([
        ":incident_id" => $incident_id,
        ":responder_id" => $responder_id
    ]);

    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($points) === 0) {
        echo json_encode([
            "success" => false,
            "message" => "No route history points found"
        ]);
        exit;
    }

    $totalDistance = 0;

    for ($i = 1; $i < count($points); $i++) {
        $prev = $points[$i - 1];
        $curr = $points[$i];

        $totalDistance += distanceMeters(
            floatval($prev["latitude"]),
            floatval($prev["longitude"]),
            floatval($curr["latitude"]),
            floatval($curr["longitude"])
        );
    }

    $startedAt = $points[0]["recorded_at"];
    $arrivedAt = date("Y-m-d H:i:s");
    $totalPoints = count($points);

    $durationStmt = $pdo->prepare("
        SELECT TIMESTAMPDIFF(SECOND, :started_at, :arrived_at) AS duration_seconds
    ");
    $durationStmt->execute([
        ":started_at" => $startedAt,
        ":arrived_at" => $arrivedAt
    ]);
    $durationSeconds = intval($durationStmt->fetchColumn());

    $averageSpeedKmh = 0;
    if ($durationSeconds > 0) {
        $averageSpeedKmh = ($totalDistance / 1000) / ($durationSeconds / 3600);
    }

    $maxSpeedKmh = 0;
    foreach ($points as $p) {
        $speedMs = floatval($p["speed"] ?? 0);
        $speedKmh = $speedMs * 3.6;
        if ($speedKmh > $maxSpeedKmh) {
            $maxSpeedKmh = $speedKmh;
        }
    }

    $save = $pdo->prepare("
        INSERT INTO responder_route_summary
        (
            incident_id,
            responder_id,
            started_at,
            arrived_at,
            duration_seconds,
            total_points,
            total_distance_meters,
            average_speed_kmh,
            max_speed_kmh
        )
        VALUES
        (
            :incident_id,
            :responder_id,
            :started_at,
            :arrived_at,
            :duration_seconds,
            :total_points,
            :total_distance_meters,
            :average_speed_kmh,
            :max_speed_kmh
        )
        ON DUPLICATE KEY UPDATE
            arrived_at = VALUES(arrived_at),
            duration_seconds = VALUES(duration_seconds),
            total_points = VALUES(total_points),
            total_distance_meters = VALUES(total_distance_meters),
            average_speed_kmh = VALUES(average_speed_kmh),
            max_speed_kmh = VALUES(max_speed_kmh)
    ");

    $save->execute([
        ":incident_id" => $incident_id,
        ":responder_id" => $responder_id,
        ":started_at" => $startedAt,
        ":arrived_at" => $arrivedAt,
        ":duration_seconds" => $durationSeconds,
        ":total_points" => $totalPoints,
        ":total_distance_meters" => round($totalDistance, 2),
        ":average_speed_kmh" => round($averageSpeedKmh, 2),
        ":max_speed_kmh" => round($maxSpeedKmh, 2)
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Route summary saved",
        "duration_seconds" => $durationSeconds,
        "total_points" => $totalPoints,
        "total_distance_meters" => round($totalDistance, 2),
        "average_speed_kmh" => round($averageSpeedKmh, 2),
        "max_speed_kmh" => round($maxSpeedKmh, 2)
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}