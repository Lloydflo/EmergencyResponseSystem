<?php
header("Content-Type: application/json");

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/user_presence.php";

try {
    $pdo = db();

    $group_id = intval($_GET["group_id"] ?? 0);
    $user_id = intval($_GET["user_id"] ?? 0);

    if ($group_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing group_id"]);
        exit;
    }
    if ($user_id > 0) {
        touch_user_presence($pdo, $user_id);
    }

    $stmt = $pdo->prepare("
    SELECT
        m.id,
        m.group_id,
        m.sender_user_id,
        m.message_details,
        m.created_at,
        u.name AS sender_name,
        u.department,
        a.file_name,
        a.file_url,
        a.mime_type,
        a.is_image
    FROM interagency_groups_threads_read m
   LEFT JOIN users u
    ON u.id = (
        SELECT MAX(u2.id)
        FROM users u2
        WHERE
            CAST(u2.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
            = m.sender_user_id COLLATE utf8mb4_unicode_ci
            OR
            u2.name COLLATE utf8mb4_unicode_ci
            = m.sender_user_id COLLATE utf8mb4_unicode_ci
    )
    LEFT JOIN interagency_message_attachments a
    ON a.message_id = m.id
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
        $text = preg_replace('/^\[ROUTINE\]\s*/', '', $text);

        $isImage = intval($row["is_image"] ?? 0) === 1;
        $fileUrl = $row["file_url"] ?? null;
        $fileName = $row["file_name"] ?? null;

        if (!$fileUrl && isset($details["attachments"][0])) {
        $att = $details["attachments"][0];

        $fileUrl =
            $att["file_url"] ??
            $att["fileUrl"] ??
            $att["url"] ??
            null;

        $fileName =
            $att["file_name"] ??
            $att["fileName"] ??
            $att["name"] ??
            null;

        $mimeType =
            $att["mime_type"] ??
            $att["mimeType"] ??
            $row["mime_type"] ??
            "";

        $isImage =
            intval($att["is_image"] ?? 0) === 1 ||
            str_starts_with(strtolower($mimeType), "image/");
    }

        if ($fileUrl && !str_starts_with($fileUrl, "http")) {
            $fileUrl = "https://emergency-response.alertaraqc.com/" . ltrim($fileUrl, "/");
        }

        $messages[] = [
            "id" => strval($row["id"]),
            "groupId" => intval($row["group_id"]),
            "senderId" => strval($row["sender_user_id"]),
            "senderName" => $row["sender_name"] ?: "Unknown",
            "role" => $row["department"] ?: "",
            "text" => $text,
            "type" => $fileUrl ? ($isImage ? "IMAGE" : "FILE") : "TEXT",
            "attachmentUri" => $fileUrl,
            "attachmentName" => $fileName,
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
