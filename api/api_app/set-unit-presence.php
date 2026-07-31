<?php

header("Content-Type: application/json");

require __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/user_presence.php";
require_once __DIR__ . "/_location.php";

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}
$input = array_merge($_POST ?? [], $input);

$responder_id = intval($input["responder_id"] ?? $input["user_id"] ?? 0);
$presence = strtolower(trim((string)($input["presence"] ?? "")));

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
    $locationUpdate = null;

    // Para malaman kung anong database talaga ang ginagamit
    $databaseName = $pdo
        ->query("SELECT DATABASE()")
        ->fetchColumn();

    if ($presence === "offline") {
        $unitStatus = "offline";
        mark_user_offline($pdo, $responder_id);
    } else {
        mark_user_online($pdo, $responder_id);
        $hasLocationPayload = array_key_exists("latitude", $input)
            || array_key_exists("lat", $input)
            || array_key_exists("longitude", $input)
            || array_key_exists("lng", $input)
            || array_key_exists("lon", $input);
        if ($hasLocationPayload) {
            $locationPayload = $input;
            $locationPayload["responder_id"] = $responder_id;
            $locationPayload["source"] = $locationPayload["source"] ?? "responder_online";
            try {
                $locationUpdate = app_location_update($pdo, $locationPayload);
            } catch (Throwable $locationError) {
                error_log("[set-unit-presence] location update skipped: " . $locationError->getMessage());
                $locationUpdate = [
                    "ok" => false,
                    "error" => "Location update skipped"
                ];
            }
        } else {
            $locationUpdate = [
                "ok" => false,
                "error" => "Responder GPS is required when going online to place the assigned vehicle on the dispatch map"
            ];
        }
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
        "database" => $databaseName,
        "location_update" => $locationUpdate,
        "location_tracking" => [
            "enabled" => true,
            "location_required" => $presence === "online",
            "syncs_vehicle_location" => true,
            "endpoint" => "api/unit_location_update.php",
            "api_app_endpoint" => "api/api_app/update-location.php"
        ]
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
