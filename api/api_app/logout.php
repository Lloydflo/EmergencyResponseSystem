<?php
header("Content-Type: application/json");

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/user_presence.php';
require_once __DIR__ . '/../../includes/activity_log.php';

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

    $responder = null;
    try {
        $lookup = $pdo->prepare('SELECT name, email, role, department, unit_code FROM users WHERE id = ? LIMIT 1');
        $lookup->execute([$userId]);
        $responder = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $lookupError) {
        $responder = null;
    }

    mark_user_offline($pdo, $userId);
    record_operational_audit_event(
        $pdo,
        $userId,
        'responder_logout',
        'authentication',
        $userId,
        'Responder signed out of the mobile application.',
        [
            'actor_name' => (string)($responder['name'] ?? ''),
            'actor_email' => (string)($responder['email'] ?? ''),
            'actor_role' => 'responder',
            'source_channel' => 'responder_app',
            'event_category' => 'authentication',
            'event_outcome' => 'success',
            'metadata' => [
                'department' => (string)($responder['department'] ?? ''),
                'unit_code' => (string)($responder['unit_code'] ?? ''),
            ],
        ]
    );

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
