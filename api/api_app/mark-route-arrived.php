<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";

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
        INSERT INTO responder_route_summary
        (incident_id, responder_id, started_at, arrived_at, duration_seconds, total_points)
        SELECT
            :incident_id,
            :responder_id,
            MIN(recorded_at),
            NOW(),
            TIMESTAMPDIFF(SECOND, MIN(recorded_at), NOW()),
            COUNT(*)
        FROM responder_route_history
        WHERE incident_id = :incident_id
        AND responder_id = :responder_id

        ON DUPLICATE KEY UPDATE
            arrived_at = NOW(),
            duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
            total_points = (
                SELECT COUNT(*)
                FROM responder_route_history
                WHERE incident_id = :incident_id
                AND responder_id = :responder_id
            )
    ");

    $stmt->execute([
        ":incident_id" => $incident_id,
        ":responder_id" => $responder_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Arrival recorded"
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}