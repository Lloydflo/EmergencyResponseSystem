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
        SELECT COUNT(*)
        FROM dispatch_operator_records
        WHERE assigned_to = ?
        AND status IN ('assigned','received','en_route','on_scene')
    ");
    $q->execute([$responder_id]);

    $activeCount = (int)$q->fetchColumn();

    $unitStatus = $activeCount > 0 ? "assigned" : "available";

    $u = $pdo->prepare("
        UPDATE users
        SET unit_status = ?
        WHERE id = ?
    ");
    $u->execute([$unitStatus, $responder_id]);

    echo json_encode([
        "success" => true,
        "active_assignments" => $activeCount,
        "unit_status" => $unitStatus
    ]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}