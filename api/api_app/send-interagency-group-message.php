<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/user_presence.php";

try {
    $pdo = db();
    $now = date("Y-m-d H:i:s");

    $group_id = intval($_POST["group_id"] ?? 0);
    $sender_user_id = trim($_POST["sender_user_id"] ?? "");
    $text = trim($_POST["text"] ?? "");

    if ($group_id <= 0 || $sender_user_id === "" || $text === "") {
        echo json_encode(["success" => false, "message" => "Missing fields"]);
        exit;
    }
    if (is_numeric($sender_user_id)) {
        touch_user_presence($pdo, intval($sender_user_id));
    }

    $message_details = json_encode([
        "text" => $text,
        "attachments" => []
    ]);

    $nextIdStmt = $pdo->query("
        SELECT COALESCE(MAX(id), 0) + 1 AS next_id 
        FROM activity_log
    ");
    $activity_log_id = intval($nextIdStmt->fetch()["next_id"]);

    $logStmt = $pdo->prepare("
        INSERT INTO activity_log
        (id, user_id, action, entity_type, entity_id, details, created_at)
        VALUES
        (:id, :user_id, 'chat', 'agency_group_chat', :entity_id, :details, :created_at)
    ");

    $logStmt->execute([
        "id" => $activity_log_id,
        "user_id" => is_numeric($sender_user_id) ? intval($sender_user_id) : null,
        "entity_id" => $group_id,
        "details" => $message_details,
        "created_at" => $now
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO interagency_groups_threads_read
        (activity_log_id, group_id, sender_user_id, message_details, created_at)
        VALUES
        (:activity_log_id, :group_id, :sender_user_id, :message_details, :created_at)
    ");

    $stmt->execute([
        "activity_log_id" => $activity_log_id,
        "group_id" => $group_id,
        "sender_user_id" => $sender_user_id,
        "message_details" => $message_details,
        "created_at" => $now
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Message sent"
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
