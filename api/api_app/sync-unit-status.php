<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/vehicle_resource_units.php";

$responder_id = intval($_POST["responder_id"] ?? 0);

if ($responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing responder_id"]);
    exit;
}

try {
    $pdo = db();

    $q = $pdo->prepare("
        SELECT status
        FROM dispatch_operator_records
        WHERE assigned_to = ?
        AND status IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
        ORDER BY assigned_at DESC, id DESC
        LIMIT 1
    ");
    $q->execute([$responder_id]);

    $latestStatus = strtolower(trim((string)$q->fetchColumn()));

    $unitStatus = match ($latestStatus) {
        "pending", "assigned", "received", "accepted", "acknowledged", "busy", "in_use" => "busy",
        "enroute", "en_route" => "en_route",
        "on_scene" => "on_scene",
        default => "available",
    };

    ers_update_responder_unit_status($pdo, $responder_id, $unitStatus);

    echo json_encode([
        "success" => true,
        "unit_status" => $unitStatus
    ]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
