<?php

header("Content-Type: application/json");

require __DIR__ . "/connect.php";

$responder_id = intval($_POST["responder_id"] ?? 0);
$presence = strtolower(trim($_POST["presence"] ?? ""));

if ($responder_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Missing responder_id"
    ]);
    exit;
}

if (!in_array($presence, ["online", "offline"], true)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid presence"
    ]);
    exit;
}

try {
    $pdo = db();

    // Para malaman kung anong database talaga ang ginagamit
    $databaseName = $pdo
        ->query("SELECT DATABASE()")
        ->fetchColumn();

    if ($presence === "offline") {
        $unitStatus = "offline";
    } else {
        $q = $pdo->prepare("
            SELECT status
            FROM dispatch_operator_records
            WHERE assigned_to = ?
              AND status IN (
                  'pending',
                  'assigned',
                  'received',
                  'accepted',
                  'acknowledged',
                  'busy',
                  'in_use',
                  'enroute',
                  'en_route',
                  'on_scene'
              )
            ORDER BY assigned_at DESC, id DESC
            LIMIT 1
        ");

        $q->execute([$responder_id]);

        $latestStatus = strtolower(
            trim((string) $q->fetchColumn())
        );

        $unitStatus = match ($latestStatus) {
            "pending",
            "assigned",
            "received",
            "accepted",
            "acknowledged",
            "busy",
            "in_use" => "busy",

            "enroute",
            "en_route" => "en_route",

            "on_scene" => "on_scene",

            default => "available"
        };
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET
            unit_status = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        $unitStatus,
        $responder_id
    ]);

    // Basahin ulit ang na-save na status
    $verifyStmt = $pdo->prepare("
        SELECT unit_status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $verifyStmt->execute([$responder_id]);

    $savedStatus = $verifyStmt->fetchColumn();

    echo json_encode([
        "success" => true,
        "presence" => $presence,
        "unit_status" => $unitStatus,
        "saved_unit_status" => $savedStatus,
        "affected_rows" => $stmt->rowCount(),
        "database" => $databaseName
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}