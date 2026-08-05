<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$responder_id = intval($_GET["responder_id"] ?? 0);

if ($responder_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = db();

    $q = $pdo->prepare("
        SELECT
            d.id,
            d.name,
            d.vehicle,
            d.location,
            d.latitude,
            d.longitude,
            d.priority,
            d.description,
            d.status,
            d.assigned_to,
            d.assigned_responder_name,
            d.assigned_unit_code,
            d.assigned_unit_type,
            d.assigned_at
        FROM dispatch_operator_records d
        WHERE d.status IN ('assigned','received','en_route','on_scene')
        AND d.assigned_to <> ?
        ORDER BY d.assigned_at DESC, d.id DESC
    ");

    $q->execute([$responder_id]);

    $rows = [];

    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            "assignment_id" => (string)$r["id"],
            "id" => (string)$r["id"],
            "type" => (string)($r["vehicle"] ?? ""),
            "priority" => (string)($r["priority"] ?? "medium"),
            "location" => (string)($r["location"] ?? ""),
            "status" => (string)($r["status"] ?? ""),
            "description" => (string)($r["description"] ?? ""),
            "assignedTo" => (string)($r["assigned_responder_name"] ?? ""),
            "latitude" => $r["latitude"] !== null ? (float)$r["latitude"] : null,
            "longitude" => $r["longitude"] !== null ? (float)$r["longitude"] : null,
            "unit_code" => (string)($r["assigned_unit_code"] ?? ""),
            "unit_type" => (string)($r["assigned_unit_type"] ?? ""),
            "unit_status" => (string)($r["status"] ?? ""),
            "assigned_at" => (string)($r["assigned_at"] ?? "")
        ];
    }

    echo json_encode($rows);

} catch (Throwable $e) {
    echo json_encode([]);
}