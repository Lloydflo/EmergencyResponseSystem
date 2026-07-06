<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$responder_id = intval($_GET["responder_id"] ?? 0);

if ($responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid responder_id"]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, resource_name, category, quantity, urgency, status, incident_id, location, notes, created_at, updated_at
        FROM responder_resource_requests
        WHERE responder_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$responder_id]);
    $rows = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "requests" => $rows
    ]);
} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}