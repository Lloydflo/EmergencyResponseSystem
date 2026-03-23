<?php
header("Content-Type: application/json");

require_once __DIR__ . '/../includes/db.php';

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
    $stmt = $pdo->prepare("
        SELECT id, email, password, name, role, status
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

    $upd = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $upd->execute([(int)$user["id"]]);

    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "user" => [
            "id" => (int)$user["id"],
            "name" => (string)$user["name"],
            "email" => (string)$user["email"],
            "role" => (string)$user["role"]
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
