<?php
header("Content-Type: application/json");
require "connect.php";

$incident_id = intval($_POST["incident_id"] ?? 0);
$responder_id = intval($_POST["responder_id"] ?? 0);

if ($incident_id <= 0 || $responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing data"]);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE dispatch_operator_records
    SET status = 'received'
    WHERE id = ?
    AND assigned_to = ?
    AND status = 'assigned'
");

$ok = $stmt->execute([$incident_id, $responder_id]);

echo json_encode([
    "success" => $ok,
    "message" => $ok ? "Assignment received" : "Failed"
]);