<?php

header("Content-Type: application/json");
require_once __DIR__ . "/connect.php";

try {

    $pdo = db();

    $group_id = intval($_POST["group_id"] ?? 0);
    $user_id = intval($_POST["user_id"] ?? 0);

    if ($group_id <= 0 || $user_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Missing fields"
        ]);
        exit;
    }

    $check = $pdo->prepare("
        SELECT id
        FROM interagency_group_member_requests
        WHERE group_id = ?
        AND requested_user_id = ?
        AND status = 'pending'
        LIMIT 1
    ");

    $check->execute([$group_id, $user_id]);

    if ($check->fetch()) {

        echo json_encode([
            "success" => false,
            "message" => "Request already pending"
        ]);

        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO interagency_group_member_requests
        (
            group_id,
            requested_user_id,
            requested_by_user_id,
            status,
            created_at,
            updated_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            'pending',
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        $group_id,
        $user_id,
        $user_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Request submitted"
    ]);

} catch(Throwable $e) {

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}