<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$assignment_id = intval($_POST["assignment_id"] ?? 0);
$responder_id  = intval($_POST["responder_id"] ?? 0);
$status        = trim($_POST["status"] ?? "");

$allowed = ["received", "en_route", "on_scene", "completed"];

if ($assignment_id <= 0 || $responder_id <= 0 || !in_array($status, $allowed)) {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE dispatch_operator_records
        SET status = ?
        WHERE id = ?
        AND assigned_to = ?
    ");
    $stmt->execute([$status, $assignment_id, $responder_id]);

    $unit_status = match ($status) {
        "received"  => "assigned",
        "en_route"  => "en_route",
        "on_scene"  => "on_scene",
        "completed" => "available",
        default     => "assigned"
    };

    $unit = $pdo->prepare("
        UPDATE users
        SET unit_status = ?
        WHERE id = ?
    ");
    $unit->execute([$unit_status, $responder_id]);

    echo json_encode([
        "success" => true,
        "assignment_status" => $status,
        "unit_status" => $unit_status
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}