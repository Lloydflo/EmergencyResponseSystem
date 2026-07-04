<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$responder_id = intval($_POST["responder_id"] ?? 0);
$responder_name = trim($_POST["responder_name"] ?? "");
$department = trim($_POST["department"] ?? "");
$requested_department = trim($_POST["requested_department"] ?? "");
$resources = trim($_POST["resources"] ?? "");
$is_full_backup = intval($_POST["is_full_backup"] ?? 0);
$incident_id = trim($_POST["incident_id"] ?? "");

if ($responder_id <= 0 || $responder_name === "" || $requested_department === "" || $resources === "") {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO responder_backup_requests
            (responder_id, responder_name, department, requested_department, resources, is_full_backup, incident_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $responder_id,
        $responder_name,
        $department,
        $requested_department,
        $resources,
        $is_full_backup,
        $incident_id
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        "success" => true,
        "message" => "Backup request sent",
        "id" => $newId
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}