<?php
header("Content-Type: application/json");

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/user_presence.php';
require_once __DIR__ . '/../../includes/unit_location_tracking.php';

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = [];
}

$email = trim((string)($input["email"] ?? ($_POST["email"] ?? "")));
$password = (string)($input["password"] ?? ($_POST["password"] ?? ""));

if ($email === "" || $password === "") {
    echo json_encode([
        "success" => false,
        "message" => "Email and password are required",
        "user" => null
    ]);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed",
        "user" => null
    ]);
    exit;
}

try {
    $unitCodeSelect = ers_unit_location_column_exists($pdo, 'users', 'unit_code') ? 'unit_code' : 'NULL AS unit_code';
    $unitTypeSelect = ers_unit_location_column_exists($pdo, 'users', 'unit_type') ? 'unit_type' : 'NULL AS unit_type';
    $unitStatusSelect = ers_unit_location_column_exists($pdo, 'users', 'unit_status') ? 'unit_status' : 'NULL AS unit_status';
    $vehiclePlateSelect = ers_unit_location_column_exists($pdo, 'users', 'vehicle_plate') ? 'vehicle_plate' : 'NULL AS vehicle_plate';

     $stmt = $pdo->prepare("
        SELECT
            id,
            email,
            password,
            name,
            username, role, status, department, profile_image_path,
               {$unitCodeSelect}, {$unitTypeSelect}, {$unitStatusSelect}, {$vehiclePlateSelect}
        FROM users
        WHERE email = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid email or password",
            "user" => null
        ]);
        exit;
    }

    if ((string)$user["status"] !== "active") {
        echo json_encode([
            "success" => false,
            "message" => "Account is inactive",
            "user" => null
        ]);
        exit;
    }

    $storedPassword = (string)($user["password"] ?? "");
    $passwordValid = password_verify($password, $storedPassword) || hash_equals($storedPassword, $password);

    if (!$passwordValid) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid email or password",
            "user" => null
        ]);
        exit;
    }
        if ((string)$user["role"] !== "responder") {
        echo json_encode([
            "success" => false,
            "message" => "Access denied. Only responders can log in.",
            "user" => null
        ]);
        exit;
    }

    $upd = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $upd->execute([(int)$user["id"]]);
    mark_user_online($pdo, (int)$user["id"]);

    $locationUpdate = null;
    $hasLocationPayload = array_key_exists('latitude', $input)
        || array_key_exists('lat', $input)
        || array_key_exists('longitude', $input)
        || array_key_exists('lng', $input)
        || array_key_exists('lon', $input);
    if ($hasLocationPayload) {
        $locationPayload = $input;
        $locationPayload['responder_id'] = (int)$user['id'];
        $locationPayload['unit_code'] = (string)($user['unit_code'] ?? '');
        $locationPayload['source'] = $locationPayload['source'] ?? 'responder_login';
        try {
            $locationUpdate = ers_unit_location_update($pdo, $locationPayload);
        } catch (Throwable $e) {
            error_log('responder login location update skipped: ' . $e->getMessage());
            $locationUpdate = ['ok' => false, 'error' => 'Location update skipped'];
        }
    } else {
        $locationUpdate = [
            'ok' => false,
            'error' => 'Responder GPS is required on login to place the assigned vehicle on the dispatch map'
        ];
    }

    $unit = ers_unit_location_resolve_unit($pdo, [
        'responder_id' => (int)$user['id'],
        'unit_code' => (string)($user['unit_code'] ?? ''),
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "user" => [
            "id" => (int)$user["id"],
            "name" => (string)$user["name"],
            "username" => (string)($user["username"] ?? ""),
            "email" => (string)$user["email"],
            "role" => (string)$user["role"],
            "department" => (string)($user["department"] ?? ""),
            "unit_id" => $unit ? (int)$unit["id"] : null,
            "unit_code" => (string)($user["unit_code"] ?? ($unit["identifier"] ?? "")),
            "unit_type" => (string)($user["unit_type"] ?? ($unit["unit_type"] ?? "")),
            "unit_status" => (string)($user["unit_status"] ?? ($unit["status"] ?? "available")),
            "vehicle_plate" => (string)($user["vehicle_plate"] ?? ""),
            "profile_image_path" => (string)($user["profile_image_path"] ?? ""),
        ],
        "location_update" => $locationUpdate,
        "location_tracking" => [
            "enabled" => $unit !== null,
            "location_required" => true,
            "syncs_vehicle_location" => true,
            "endpoint" => "api/unit_location_update.php",
            "api_app_endpoint" => "api/api_app/update-location.php"
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "user" => null
    ]);
}
