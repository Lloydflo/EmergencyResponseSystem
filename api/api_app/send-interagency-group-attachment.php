<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/user_presence.php";

try {
    $pdo = db();
    $now = date("Y-m-d H:i:s");

    $group_id = intval($_POST["group_id"] ?? 0);
    $sender_user_id = trim($_POST["sender_user_id"] ?? "");
    $file_url = trim($_POST["file_url"] ?? "");
    $file_name = trim($_POST["file_name"] ?? "");
    $mime_type = trim($_POST["mime_type"] ?? "");
    $file_size = intval($_POST["file_size"] ?? 0);
    $is_image = intval($_POST["is_image"] ?? 0);

    if ($group_id <= 0 || $sender_user_id === "" || $file_url === "" || $file_name === "") {
        echo json_encode(["success" => false, "message" => "Missing fields"]);
        exit;
    }
    if (is_numeric($sender_user_id)) {
        touch_user_presence($pdo, intval($sender_user_id));
    }

    $messageText = $is_image === 1 ? "Image" : $file_name;

    $message_details = json_encode([
        "text" => $messageText,
        "attachments" => [
            [
                "name" => $file_name,
                "url" => $file_url,
                "mime_type" => $mime_type,
                "size" => $file_size,
                "is_image" => $is_image
            ]
        ]
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
        (:id, :user_id, 'chat_attachment', 'agency_group_chat', :entity_id, :details, :created_at)
    ");

    $logStmt->execute([
        "id" => $activity_log_id,
        "user_id" => is_numeric($sender_user_id) ? intval($sender_user_id) : null,
        "entity_id" => $group_id,
        "details" => $message_details,
        "created_at" => $now
    ]);

    $msgStmt = $pdo->prepare("
        INSERT INTO interagency_groups_threads_read
        (activity_log_id, group_id, sender_user_id, message_details, created_at)
        VALUES
        (:activity_log_id, :group_id, :sender_user_id, :message_details, :created_at)
    ");

    $msgStmt->execute([
        "activity_log_id" => $activity_log_id,
        "group_id" => $group_id,
        "sender_user_id" => $sender_user_id,
        "message_details" => $message_details,
        "created_at" => $now
    ]);

    $message_id = $pdo->lastInsertId();

    $attStmt = $pdo->prepare("
        INSERT INTO interagency_message_attachments
        (message_id, file_name, file_url, file_path, mime_type, file_size, is_image, created_at)
        VALUES
        (:message_id, :file_name, :file_url, NULL, :mime_type, :file_size, :is_image, :created_at)
    ");

    $attStmt->execute([
        "message_id" => $activity_log_id,
        "file_name" => $file_name,
        "file_url" => $file_url,
        "mime_type" => $mime_type,
        "file_size" => $file_size,
        "is_image" => $is_image,
        "created_at" => $now
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Attachment sent",
        "message_id" => $message_id
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
