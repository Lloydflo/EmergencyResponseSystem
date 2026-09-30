<?php
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

header("Content-Type: application/json; charset=UTF-8");

// Read request body (Handles both Retrofit FormUrlEncoded and JSON)
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
    // 1. Ayusin ang Path sa Database Connection
    if (file_exists(__DIR__ . "/connect.php")) {
        require_once __DIR__ . "/connect.php";
    } elseif (file_exists(__DIR__ . "/../connect.php")) {
        require_once __DIR__ . "/../connect.php";
    } elseif (file_exists(__DIR__ . "/../includes/connect.php")) {
        require_once __DIR__ . "/../includes/connect.php";
    } else {
        throw new Exception("Database connection file (connect.php) not found.");
    }

    $pdo = db();

    // 2. Fetch responder
    $stmt = $pdo->prepare("SELECT id, email, name, is_active FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $responder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$responder || (int)$responder["is_active"] !== 1) {
        ob_end_clean();
        echo json_encode(["success" => false, "message" => "Account not found or inactive"]);
        exit;
    }

    // 3. Generate and store OTP
    $otp = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");

    $ins = $pdo->prepare("INSERT INTO responder_otps (responder_email, otp, expires_at) VALUES (?, ?, ?)");
    $ins->execute([$email, $otp, $expiresAt]);

    // 4. Send OTP via Brevo API or fallback to SMTP
    $apiKey = getenv("BREVO_API_KEY") ?: ($_ENV["BREVO_API_KEY"] ?? $_SERVER["BREVO_API_KEY"] ?? "");

    $otpSent = false;
    $errorMessage = "";

    if (!empty($apiKey)) {
        $fromEmail = getenv("MAIL_FROM_ADDRESS") ?: ($_ENV["MAIL_FROM_ADDRESS"] ?? $_SERVER["MAIL_FROM_ADDRESS"] ?? "lloydsamonte7@gmail.com");
        $payload = [
            "sender" => ["name" => "AlerTara QC", "email" => $fromEmail],
            "to" => [["email" => $email, "name" => $responder['name'] ?? "Responder"]],
            "subject" => "Your OTP Code - AlerTara QC",
            "htmlContent" => "<h3>Hello " . htmlspecialchars($responder['name'] ?? 'User') . "</h3><p>Your OTP code is: <b style='font-size:24px; color:blue;'>" . $otp . "</b></p><p>This OTP will expire in 5 minutes.</p>"
        ];

        $ch = curl_init("https://api.brevo.com/v3/smtp/email");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "accept: application/json",
            "api-key: " . $apiKey,
            "content-type: application/json"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $otpSent = true;
        } else {
            $errorMessage = "Brevo API Error HTTP " . $httpCode;
        }
    }

    if (!$otpSent) {
        // Fallback to SMTP using mail_helper
        $mailHelperPath = __DIR__ . '/../../includes/mail_helper.php';
        if (file_exists($mailHelperPath)) {
            require_once $mailHelperPath;
            if (function_exists('sendOtpEmail')) {
                $otpSent = sendOtpEmail($email, $otp, 'AlerTara QC');
                if (!$otpSent && function_exists('getLastOtpEmailErrorMessage')) {
                    $errorMessage = getLastOtpEmailErrorMessage($errorMessage ?: 'SMTP email failed');
                }
            }
        }
    }

    ob_end_clean();
    if ($otpSent) {
        echo json_encode(["success" => true, "message" => "OTP sent successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => $errorMessage ?: "Failed to send OTP email"]);
    }

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}