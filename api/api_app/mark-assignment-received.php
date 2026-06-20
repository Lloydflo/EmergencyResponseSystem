<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$incident_id  = intval($_POST["incident_id"] ?? 0);
$responder_id = intval($_POST["responder_id"] ?? 0);

if ($incident_id <= 0 || $responder_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Missing data"
    ]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        UPDATE dispatch_operator_records
        SET status = 'received'
        WHERE id = ?
        AND assigned_to = ?
        AND status IN ('pending', 'assigned')
    ");
    $stmt->execute([$incident_id, $responder_id]);

    echo json_encode([
        "success" => true,
        "message" => "Assignment received",
        "affected_rows" => $stmt->rowCount()
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}
