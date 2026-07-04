<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$request_id = intval($_GET["request_id"] ?? 0);

if ($request_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid request_id"]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, resource_name, category, quantity, urgency, status, created_at, updated_at
        FROM responder_resource_requests
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$request_id]);
    $row = $stmt->fetch();

    if ($row) {
        echo json_encode([
            "success" => true,
            "request" => $row
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Request not found"]);
    }
} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}