<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$responder_id = intval($_GET["responder_id"] ?? $_POST["responder_id"] ?? 0);

if ($responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid responder_id"]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, responder_id, requested_department, resources, is_full_backup, incident_id, status, created_at, updated_at
        FROM responder_backup_requests
        WHERE responder_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$responder_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "requests" => $rows
    ]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}