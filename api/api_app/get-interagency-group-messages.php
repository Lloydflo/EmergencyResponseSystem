<?php
header("Content-Type: application/json");
require_once "../db.php";

$group_id = isset($_GET["group_id"]) ? intval($_GET["group_id"]) : 0;

if ($group_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing group_id"]);
    exit;
}

$sql = "
    SELECT 
        m.id,
        m.group_id,
        m.sender_user_id,
        m.message_details,
        m.created_at,
        u.name AS sender_name,
        u.department
    FROM interagency_groups_threads_read m
    LEFT JOIN users u ON u.id = m.sender_user_id
    WHERE m.group_id = ?
    ORDER BY m.id ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];

while ($row = $result->fetch_assoc()) {
    $details = json_decode($row["message_details"], true);
    $text = $details["text"] ?? "";

    $messages[] = [
        "id" => intval($row["id"]),
        "groupId" => intval($row["group_id"]),
        "senderId" => strval($row["sender_user_id"]),
        "senderName" => $row["sender_name"] ?? "Unknown",
        "role" => $row["department"] ?? "",
        "text" => $text,
        "createdAt" => strtotime($row["created_at"]) * 1000
    ];
}

echo json_encode([
    "success" => true,
    "messages" => $messages
]);