<?php
declare(strict_types=1);

header("Content-Type: application/json");

require __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/unit_location_tracking.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "ok" => false, "message" => "Method not allowed"]);
    exit;
}

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}
$input = array_merge($_POST ?? [], $input);

try {
    $pdo = db();
    $result = ers_unit_location_update($pdo, $input);
    if (!$result["ok"]) {
        http_response_code(400);
    }

    echo json_encode([
        "success" => (bool)$result["ok"],
        "ok" => (bool)$result["ok"],
        "message" => $result["ok"] ? "Location updated" : (string)($result["error"] ?? "Location update failed"),
        "location" => $result,
    ]);
} catch (Throwable $e) {
    error_log("api_app update-location failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "ok" => false,
        "message" => "Server error",
    ]);
}

