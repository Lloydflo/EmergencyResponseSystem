<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/unit_location_tracking.php";

try {
    $pdo = db();

    $input = json_decode(file_get_contents("php://input"), true);

    $incident_id  = intval($input["incident_id"] ?? 0);
    $responder_id = intval($input["responder_id"] ?? 0);
    $latitude     = $input["latitude"] ?? null;
    $longitude    = $input["longitude"] ?? null;
    $speed        = $input["speed"] ?? null;
    $heading      = $input["heading"] ?? null;
    $status       = $input["status"] ?? "en_route";

    if ($incident_id <= 0 || $responder_id <= 0 || $latitude === null || $longitude === null) {
        echo json_encode([
            "success" => false,
            "message" => "Missing required fields"
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO responder_route_history
        (incident_id, responder_id, latitude, longitude, speed, heading, status)
        VALUES
        (:incident_id, :responder_id, :latitude, :longitude, :speed, :heading, :status)
    ");

    $stmt->execute([
        ":incident_id"  => $incident_id,
        ":responder_id" => $responder_id,
        ":latitude"     => $latitude,
        ":longitude"    => $longitude,
        ":speed"        => $speed,
        ":heading"      => $heading,
        ":status"       => $status
    ]);

    try {
        $locationUpdate = ers_unit_location_update($pdo, [
            "responder_id" => $responder_id,
            "latitude" => $latitude,
            "longitude" => $longitude,
            "speed" => $speed,
            "heading" => $heading,
            "source" => "responder_route"
        ]);
    } catch (Throwable $e) {
        error_log("route point unit location update skipped: " . $e->getMessage());
        $locationUpdate = ["ok" => false, "error" => "Location update skipped"];
    }

    echo json_encode([
        "success" => true,
        "message" => "Route point saved",
        "location_update" => $locationUpdate
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
