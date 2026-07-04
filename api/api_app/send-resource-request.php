<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$responder_id   = intval($_POST["responder_id"] ?? 0);
$responder_name = trim($_POST["responder_name"] ?? "");
$category       = trim($_POST["category"] ?? "");
$resource_name  = trim($_POST["resource_name"] ?? "");
$quantity       = intval($_POST["quantity"] ?? 0);
$urgency        = trim($_POST["urgency"] ?? "");
$incident_id    = trim($_POST["incident_id"] ?? "");
$location       = trim($_POST["location"] ?? "");
$notes          = trim($_POST["notes"] ?? "");

if ($responder_id <= 0 || $responder_name === "" || $resource_name === "" || $quantity <= 0 || $location === "") {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

$incident_id = $incident_id === "" || $incident_id === "N/A" ? null : $incident_id;

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO responder_resource_requests
            (responder_id, responder_name, category, resource_name, quantity, urgency, incident_id, location, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");

    $stmt->execute([
        $responder_id,
        $responder_name,
        $category,
        $resource_name,
        $quantity,
        $urgency,
        $incident_id,
        $location,
        $notes
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        "success" => true,
        "message" => "Resource request sent",
        "id" => $newId
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}