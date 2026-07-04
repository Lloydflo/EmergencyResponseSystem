<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$request_id = intval($_GET["request_id"] ?? $_POST["request_id"] ?? 0);

if ($request_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid request_id"]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, status, requested_department, resources, is_full_backup, created_at, updated_at
        FROM responder_backup_requests
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$request_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(["success" => false, "message" => "Request not found"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "request" => $row
    ]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}