<?php
header("Content-Type: application/json");
require_once "../db.php";

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
    (NULL, ?, ?, ?, NOW())
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iis", $group_id, $sender_user_id, $message_details);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Message sent",
        "id" => $stmt->insert_id
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => $stmt->error
    ]);
}