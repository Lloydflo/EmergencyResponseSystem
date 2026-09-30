<?php
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

header("Content-Type: application/json");

// Read request body
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}

$email = trim((string)($input["email"] ?? $_POST["email"] ?? $_GET["email"] ?? ""));

if ($email === "") {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Email is required"]);
    exit;
}

try {
    require __DIR__ . "/connect.php";
    $pdo = db();

    // 1. Fetch responder
    $stmt = $pdo->prepare("SELECT id, email, name, is_active FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $responder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$responder || (int)$responder["is_active"] !== 1) {
        ob_end_clean();
        echo json_encode(["success" => false, "message" => "Account not found or inactive"]);
        exit;
    }

    // 2. Generate and store OTP
    $otp = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");

    $ins = $pdo->prepare("INSERT INTO responder_otps (responder_email, otp, expires_at) VALUES (?, ?, ?)");
    $ins->execute([$email, $otp, $expiresAt]);

    // 3. Get API Key securely from Environment Variables
    $apiKey = getenv("BREVO_API_KEY") ?: ($_ENV["BREVO_API_KEY"] ?? "");

    if (empty($apiKey)) {
        throw new Exception("BREVO_API_KEY is missing in Environment Variables");
    }

    $payload = [
        "sender" => ["name" => "AlerTara QC", "email" => "lloydsamonte7@gmail.com"],
        "to" => [["email" => $email, "name" => $responder['name'] ?? "Responder"]],
        "subject" => "Your OTP Code - AlerTara QC",
        "htmlContent" => "<h3>Hello " . htmlspecialchars($responder['name'] ?? 'User') . "</h3><p>Your OTP code is: <b style='font-size:24px; color:blue;'>" . $otp . "</b></p><p>This OTP will expire in 5 minutes.</p>"
    ];

    $ch = curl_init("https://api.brevo.com/v3/smtp/email");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "accept: application/json",
        "api-key: " . $apiKey,
        "content-type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    ob_end_clean();
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode(["success" => true, "message" => "OTP sent successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Brevo API Error HTTP " . $httpCode, "response" => json_decode($response, true)]);
    }

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}