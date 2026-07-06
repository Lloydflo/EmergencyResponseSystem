<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$request_id   = intval($_POST["request_id"] ?? 0);
$responder_id = intval($_POST["responder_id"] ?? 0);

if ($request_id <= 0 || $responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

try {
    $pdo = db();

    // Only allow cancelling your OWN pending request
    $stmt = $pdo->prepare("
        SELECT status FROM responder_resource_requests
        WHERE id = ? AND responder_id = ?
        LIMIT 1
    ");
    $stmt->execute([$request_id, $responder_id]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(["success" => false, "message" => "Request not found"]);
        exit;
    }

    if (strtolower($row["status"]) !== "pending") {
        echo json_encode(["success" => false, "message" => "Only pending requests can be cancelled"]);
        exit;
    }

    $update = $pdo->prepare("
        UPDATE responder_resource_requests
        SET status = 'cancelled'
        WHERE id = ? AND responder_id = ?
    ");
    $update->execute([$request_id, $responder_id]);

    echo json_encode([
        "success" => true,
        "message" => "Request cancelled"
    ]);
} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}