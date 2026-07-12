<?php
header("Content-Type: application/json");
require_once __DIR__ . "/connect.php";

try {
    $pdo = db();

    $group_id = intval($_POST["group_id"] ?? 0);
    $user_id = intval($_POST["user_id"] ?? 0);
    $last_read_id = intval($_POST["last_read_id"] ?? 0);

    if ($group_id <= 0 || $user_id <= 0 || $last_read_id <= 0) {
        echo json_encode(["success" => false, "message" => "Missing params"]);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO interagency_group_thread_reads (user_id, group_id, last_read_id, updated_at)
        VALUES (:user_id, :group_id, :last_read_id, NOW())
        ON DUPLICATE KEY UPDATE
            last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
            updated_at = NOW()
    ");

    $stmt->execute([
        "user_id" => $user_id,
        "group_id" => $group_id,
        "last_read_id" => $last_read_id
    ]);

    echo json_encode(["success" => true]);

} catch (Throwable $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}