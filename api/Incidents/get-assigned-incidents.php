<?php
header("Content-Type: application/json");
require __DIR__ . "/../connect.php";

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$department = trim($data["department"] ?? "");

if ($department === "") {
    echo json_encode([
        "success" => false,
        "message" => "Department required"
    ]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT i.*
        FROM incidents i
        JOIN incident_broadcasts ib ON ib.incident_id = i.id
        WHERE ib.department = ?
        AND ib.status != 'DONE'
        ORDER BY i.created_at DESC
    ");

    $stmt->execute([$department]);
    $incidents = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "incidents" => $incidents
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}