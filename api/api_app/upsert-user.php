<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) { $input = []; parse_str($raw, $input); }

// Merge POST and JSON input
$input = array_merge($_POST ?? [], $input);

$id = intval($input["id"] ?? 0);
$email = trim((string)($input["email"] ?? ""));

if ($id <= 0 || $email === "") {
    echo json_encode(["success" => false, "message" => "Missing required fields: id and email"]);
    exit;
}

try {
    $pdo = db();

    // Update is allowed only for existing responder accounts managed by admin.
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND email = ? AND role = 'responder' LIMIT 1");
    $checkStmt->execute([$id, $email]);
    $existingUser = $checkStmt->fetch();
    if (!$existingUser) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Responder account not found. Account creation is admin-only.",
            "error_code" => "RESPONDER_NOT_FOUND"
        ]);
        exit;
    }

    // Prepare data - only include non-empty fields to preserve existing values
    $updateFields = [];
    $params = [];

    if (!empty($input["email"])) {
        $updateFields[] = "email = ?";
        $params[] = $email;
    }

    if (!empty($input["password"])) {
        // Hash password server-side
        $hashedPassword = password_hash((string)$input["password"], PASSWORD_BCRYPT);
        $updateFields[] = "password = ?";
        $params[] = $hashedPassword;
    }

    if (!empty($input["name"])) {
        $updateFields[] = "name = ?";
        $params[] = (string)$input["name"];
    }

    if (!empty($input["department"])) {
        $updateFields[] = "department = ?";
        $params[] = (string)$input["department"];
    }

    if (!empty($input["profile_image_path"])) {
        $updateFields[] = "profile_image_path = ?";
        $params[] = (string)$input["profile_image_path"];
    }

    if (!empty($input["role"])) {
        $updateFields[] = "role = ?";
        $params[] = (string)$input["role"];
    }

    if (!empty($input["status"])) {
        $updateFields[] = "status = ?";
        $params[] = (string)$input["status"];
    }

    if (isset($input["inactive_at"])) {
        $updateFields[] = "inactive_at = ?";
        $params[] = $input["inactive_at"] ?: null;
    }

    if (!empty($input["last_login"])) {
        $updateFields[] = "last_login = ?";
        $params[] = (string)$input["last_login"];
    }

    if (isset($input["is_active"])) {
        $updateFields[] = "is_active = ?";
        $params[] = intval($input["is_active"]);
    }

    if (!empty($input["unit_code"])) {
        $updateFields[] = "unit_code = ?";
        $params[] = (string)$input["unit_code"];
    }

    if (!empty($input["unit_type"])) {
        $updateFields[] = "unit_type = ?";
        $params[] = (string)$input["unit_type"];
    }

    if (!empty($input["vehicle_plate"])) {
        $updateFields[] = "vehicle_plate = ?";
        $params[] = (string)$input["vehicle_plate"];
    }

    if (!empty($input["unit_status"])) {
        $updateFields[] = "unit_status = ?";
        $params[] = (string)$input["unit_status"];
    }

    // Always update timestamp
    $updateFields[] = "updated_at = NOW()";

    // UPDATE existing user only
    $params[] = $id;
    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch and return updated user
    $fetchStmt = $pdo->prepare("
        SELECT id, email, name, department, profile_image_path, role, status,
               is_active, unit_code, unit_type, vehicle_plate, unit_status, last_login, updated_at
        FROM users WHERE id = ?
    ");
    $fetchStmt->execute([$id]);
    $user = $fetchStmt->fetch();

    echo json_encode([
        "success" => true,
        "message" => "User profile updated successfully",
        "user" => $user,
        "user_id" => $id
    ]);

} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}

