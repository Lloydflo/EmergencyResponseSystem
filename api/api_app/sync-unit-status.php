<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$responder_id = intval($_POST["responder_id"] ?? 0);

if ($responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing responder_id"]);
    exit;
}

try {
    $pdo = db();

    $q = $pdo->prepare("
        SELECT status
        FROM dispatch_operator_records
        WHERE assigned_to = ?
        AND status IN ('assigned','received','en_route','on_scene')
        ORDER BY assigned_at DESC, id DESC
        LIMIT 1
    ");
    $q->execute([$responder_id]);

    $latestStatus = $q->fetchColumn();

    $unitStatus = $latestStatus ?: "available";

    $u = $pdo->prepare("
        UPDATE users
        SET unit_status = ?
        WHERE id = ?
    ");
    $u->execute([$unitStatus, $responder_id]);

    echo json_encode([
        "success" => true,
        "unit_status" => $unitStatus
    ]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}