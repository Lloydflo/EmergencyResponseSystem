<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";

try {
    $pdo = db();

    $group_id = intval($_GET["group_id"] ?? 0);

    if ($group_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing group_id"]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.group_id,
            m.sender_user_id,
            m.message_details,
            m.created_at,
            u.name AS sender_name,
            u.department
        FROM interagency_groups_threads_read m
        LEFT JOIN users u
            ON CAST(u.id AS CHAR) = m.sender_user_id
        WHERE m.group_id = :group_id
        ORDER BY m.created_at ASC, m.id ASC
    ");

    $stmt->execute([
        "group_id" => $group_id
    ]);

    $messages = [];

    while ($row = $stmt->fetch()) {
        $details = json_decode($row["message_details"], true);
        $text = $details["text"] ?? "";

        $messages[] = [
            "id" => strval($row["id"]),
            "groupId" => intval($row["group_id"]),
            "senderId" => strval($row["sender_user_id"]),
            "senderName" => $row["sender_name"] ?: "Unknown",
            "role" => $row["department"] ?: "",
            "text" => $text,
            "createdAt" => strtotime($row["created_at"]) * 1000
        ];
    }

    echo json_encode([
        "success" => true,
        "messages" => $messages
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}