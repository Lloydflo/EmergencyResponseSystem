<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";

try {
    $pdo = db();

    $group_id = intval($_POST["group_id"] ?? 0);
    $sender_user_id = trim($_POST["sender_user_id"] ?? "");
    $text = trim($_POST["text"] ?? "");

    if ($group_id <= 0 || $sender_user_id === "" || $text === "") {
        echo json_encode(["success" => false, "message" => "Missing fields"]);
        exit;
    }

    $message_details = json_encode([
        "text" => $text,
        "attachments" => []
    ]);

    $logStmt = $pdo->prepare("
        INSERT INTO activity_log
        (user_id, action, entity_type, entity_id, details, created_at)
        VALUES
        (:user_id, 'chat', 'agency_chat', :entity_id, :details, NOW())
    ");

   $logStmt->execute([
    "id" => $nextLogId,
    "user_id" => is_numeric($sender_user_id) ? intval($sender_user_id) : null,
    "entity_id" => $group_id,
    "details" => $message_details
]);

    $activity_log_id = $nextLogId;

    $nextIdStmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM activity_log");
    $nextLogId = intval($nextIdStmt->fetch()["next_id"]);

    $activity_log_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO activity_log
            (id, user_id, action, entity_type, entity_id, details, created_at)
            VALUES
            (:id, :user_id, 'chat', 'agency_chat', :entity_id, :details, NOW())
    ");

    $stmt->execute([
        "activity_log_id" => $activity_log_id,
        "group_id" => $group_id,
        "sender_user_id" => $sender_user_id,
        "message_details" => $message_details
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Message sent",
        "id" => $pdo->lastInsertId()
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}