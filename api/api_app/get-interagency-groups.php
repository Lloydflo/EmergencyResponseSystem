<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../db.php";

$user_id = isset($_GET["user_id"]) ? intval($_GET["user_id"]) : 0;

if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing user_id"]);
    exit;
}

$sql = "
    SELECT 
        gt.id,
        gt.name,
        COALESCE(r.last_read_id, 0) AS last_read_id
    FROM interagency_group_members gm
    INNER JOIN interagency_group_threads gt
        ON gt.id = gm.group_id
    LEFT JOIN interagency_group_thread_reads r
        ON r.group_id = gt.id
        AND r.user_id = gm.user_id
    WHERE gm.user_id = ?
      AND gm.is_active = 1
      AND gt.is_active = 1
    ORDER BY gt.name ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(["success" => false, "message" => $conn->error]);
    exit;
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$groups = [];

while ($row = $result->fetch_assoc()) {
    $groups[] = [
        "id" => intval($row["id"]),
        "name" => $row["name"],
        "displayName" => $row["name"],
        "lastMessage" => "Tap to open group chat",
        "unreadCount" => 0,
        "lastReadId" => intval($row["last_read_id"])
    ];
}

echo json_encode([
    "success" => true,
    "groups" => $groups
]);