<?php
header("Content-Type: application/json");
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/user_presence.php";

try {
    $pdo = db();

    $user_id = intval($_GET["user_id"] ?? 0);

    if ($user_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing user_id"]);
        exit;
    }
    touch_user_presence($pdo, $user_id);

    $sql = "
        SELECT
            gt.id,
            gt.name,

            CASE 
                WHEN gm.id IS NOT NULL THEN 1 
                ELSE 0 
            END AS is_member,

            CASE 
                WHEN req.id IS NOT NULL THEN 1 
                ELSE 0 
            END AS request_pending,

            COALESCE(r.last_read_id, 0) AS last_read_id

        FROM interagency_group_threads gt

        LEFT JOIN interagency_group_members gm
            ON gm.group_id = gt.id
            AND gm.user_id = :user_id1
            AND gm.is_active = 1

        LEFT JOIN interagency_group_member_requests req
            ON req.group_id = gt.id
            AND req.requested_user_id = :user_id2
            AND req.status = 'pending'

        LEFT JOIN interagency_group_thread_reads r
            ON r.group_id = gt.id
            AND r.user_id = :user_id3

        WHERE gt.is_active = 1
        ORDER BY gt.name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        "user_id1" => $user_id,
        "user_id2" => $user_id,
        "user_id3" => $user_id
    ]);

    $groups = [];

    while ($row = $stmt->fetch()) {
        $isMember = intval($row["is_member"]) === 1;
        $requestPending = intval($row["request_pending"]) === 1;

        $groups[] = [
            "id" => intval($row["id"]),
            "name" => $row["name"],
            "displayName" => $row["name"],
            "isMember" => $isMember,
            "requestPending" => $requestPending,
            "lastMessage" => $isMember
                ? "Tap to open group chat"
                : ($requestPending ? "Request pending approval" : "Request access to join"),
            "unreadCount" => 0,
            "lastReadId" => intval($row["last_read_id"])
        ];
    }

    echo json_encode([
        "success" => true,
        "groups" => $groups
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
