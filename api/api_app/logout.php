<?php
header("Content-Type: application/json");

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/user_presence.php';

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = [];
}

$userId = (int)($input["responder_id"] ?? $input["user_id"] ?? ($_POST["responder_id"] ?? $_POST["user_id"] ?? 0));

if ($userId <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Missing responder_id"
    ]);
    exit;
}

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException("DB connection unavailable");
    }

    mark_user_offline($pdo, $userId);

    echo json_encode([
        "success" => true,
        "message" => "Logged out",
        "user_id" => $userId
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}
