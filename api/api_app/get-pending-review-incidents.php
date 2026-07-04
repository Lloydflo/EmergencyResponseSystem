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
        SELECT id, reference_no, type, priority, title, description,
               location_address, completion_notes, completion_image_path,
               review_status, completed_at
        FROM incidents
        WHERE completed_by_responder_id = ?
          AND review_status IS NOT NULL
        ORDER BY completed_at DESC
    ");
    $stmt->execute([$responder_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["success" => true, "incidents" => $rows]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}