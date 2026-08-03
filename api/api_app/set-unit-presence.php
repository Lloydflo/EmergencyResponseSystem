<?php

declare(strict_types=1);

header("Content-Type: application/json");

require __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/user_presence.php";
require_once __DIR__ . "/_location.php";
require_once __DIR__ . "/_assignment.php";

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}
$input = array_merge($_POST ?? [], $input);

$responder_id = intval($input["responder_id"] ?? $input["user_id"] ?? 0);
$presence = strtolower(trim((string)($input["presence"] ?? "")));
$reason = substr(trim((string)($input["reason"] ?? "")), 0, 80);

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

/**
 * Presence and operational unit status are deliberately separate.
 * A responder can be background/offline while an already assigned unit remains
 * busy, en route, or on scene. This prevents a lifecycle update from making an
 * active unit look available for another dispatch.
 */

try {
    $pdo = db();
    $locationUpdate = null;

    $databaseName = $pdo
        ->query("SELECT DATABASE()")
        ->fetchColumn();

    $operationalStatus = app_assignment_current_unit_status($pdo, $responder_id);
    $hasActiveAssignment = $operationalStatus !== "available";

    if ($presence === "offline") {
        mark_user_offline($pdo, $responder_id);
        // Preserve a real response state. Only an idle responder becomes offline.
        $unitStatus = $hasActiveAssignment ? $operationalStatus : "offline";
    } else {
        mark_user_online($pdo, $responder_id);
        $unitStatus = $operationalStatus;

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
            // Presence renewal is allowed without GPS. Location is synchronized
            // separately by Home/RouteMonitoringService when permission is granted.
            $locationUpdate = [
                "ok" => false,
                "error" => "No location payload; presence lease was still renewed"
            ];
        }
    }

    // Synchronize the responder and its linked vehicle/resource row together.
    // The helper is schema-aware and preserves the same status vocabulary used
    // by assignment acknowledgement, navigation, arrival, and completion.
    app_assignment_set_unit_status($pdo, $responder_id, "", $unitStatus);

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
        "presence_reason" => $reason,
        "unit_status" => $unitStatus,
        "saved_unit_status" => $savedStatus,
        "active_assignment" => $hasActiveAssignment,
        "affected_rows" => null,
        "database" => $databaseName,
        "location_update" => $locationUpdate,
        "presence_policy" => [
            "background_grace_minutes" => 60,
            "push_token_retained_when_offline" => true,
            "active_assignment_preserves_busy_status" => true
        ],
        "location_tracking" => [
            "enabled" => true,
            "location_required" => false,
            "syncs_vehicle_location" => true,
            "endpoint" => "api/unit_location_update.php",
            "api_app_endpoint" => "api/api_app/update-location.php"
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
