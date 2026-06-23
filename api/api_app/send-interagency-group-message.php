<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";

try {
    $pdo = db();

    $group_id = isset($_POST["group_id"]) ? intval($_POST["group_id"]) : 0;
    $sender_user_id = isset($_POST["sender_user_id"]) ? intval($_POST["sender_user_id"]) : 0;
    $text = trim($_POST["text"] ?? "");

    if ($group_id <= 0 || $sender_user_id <= 0 || $text === "") {
        echo json_encode(["success" => false, "message" => "Missing fields"]);
        exit;
    }

    $message_details = json_encode([
        "text" => $text,
        "attachments" => []
    ]);

    $sql = "
        INSERT INTO interagency_groups_threads_read
        (activity_log_id, group_id, sender_user_id, message_details, created_at)
        VALUES
        (NULL, :group_id, :sender_user_id, :message_details, NOW())
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
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