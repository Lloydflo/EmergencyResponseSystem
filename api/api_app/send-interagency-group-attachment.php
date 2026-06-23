<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";

try {
    $pdo = db();

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

    $messageText = $is_image === 1 ? "Image" : $file_name;

    $message_details = json_encode([
        "text" => $messageText,
        "attachments" => [
            [
                "file_name" => $file_name,
                "file_url" => $file_url,
                "mime_type" => $mime_type,
                "file_size" => $file_size,
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
        (:id, :user_id, 'chat_attachment', 'agency_chat', :entity_id, :details, NOW())
    ");

    $logStmt->execute([
        "id" => $activity_log_id,
        "user_id" => is_numeric($sender_user_id) ? intval($sender_user_id) : null,
        "entity_id" => $group_id,
        "details" => $message_details
    ]);

    $msgStmt = $pdo->prepare("
        INSERT INTO interagency_groups_threads_read
        (activity_log_id, group_id, sender_user_id, message_details, created_at)
        VALUES
        (:activity_log_id, :group_id, :sender_user_id, :message_details, NOW())
    ");

    $msgStmt->execute([
        "activity_log_id" => $activity_log_id,
        "group_id" => $group_id,
        "sender_user_id" => $sender_user_id,
        "message_details" => $message_details
    ]);

    $message_id = $pdo->lastInsertId();

    $attStmt = $pdo->prepare("
        INSERT INTO interagency_message_attachments
        (message_id, file_name, file_url, file_path, mime_type, file_size, is_image, created_at)
        VALUES
        (:message_id, :file_name, :file_url, NULL, :mime_type, :file_size, :is_image, NOW())
    ");

    $attStmt->execute([
        "message_id" => $message_id,
        "file_name" => $file_name,
        "file_url" => $file_url,
        "mime_type" => $mime_type,
        "file_size" => $file_size,
        "is_image" => $is_image
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