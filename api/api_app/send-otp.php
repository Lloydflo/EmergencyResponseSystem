<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: application/json");

require __DIR__ . "/connect.php";

// ✅ 1. TAMA NA ANG PATH: /api/api_app/.env
$envPath = __DIR__ . '/.env';

if (!file_exists($envPath)) {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => ".env file not found at " . $envPath]);
    exit;
}

$env = parse_ini_file($envPath);


// PHPMailer Files
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) { $input = []; parse_str($raw, $input); }

$email = trim((string)($input["email"] ?? $_POST["email"] ?? ""));

if ($email === "") {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Email is required"]);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email, name, is_active FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $responder = $stmt->fetch();

    if (!$responder || $responder["is_active"] != 1) {
        ob_end_clean();
        echo json_encode(["success" => false, "message" => "Account not found or inactive"]);
        exit;
    }

    $otp = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");
    $ins = $pdo->prepare("INSERT INTO responder_otps (responder_email, otp, expires_at) VALUES (?, ?, ?)");
    $ins->execute([$email, $otp, $expiresAt]);

    // ✅ 2. PHPMailer CONFIG gamit ang data mula sa .env
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->SMTPAuth   = true;
    
    // SMTP SETTINGS
    $mail->Host = $env['MAIL_HOST'];
    $mail->Username = $env['MAIL_USERNAME'];
    $mail->Password = $env['MAIL_PASSWORD'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int)$env['MAIL_PORT'];

    // Logging para sa debugging
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) {
        file_put_contents(__DIR__ . '/mail_debug.log', "[$level] $str" . PHP_EOL, FILE_APPEND);
    };

    $mail->setFrom($env['MAIL_FROM_ADDRESS'] ?? 'alertaraqc@gmail.com', $env['MAIL_FROM_NAME'] ?? 'AlerTara QC');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = "Your OTP Code - AlerTara QC";
    $mail->Body    = "<h3>Hello " . htmlspecialchars($responder['name']) . "</h3><p>Your OTP code is: <b style='font-size:24px; color:blue;'>$otp</b></p>";

    $mail->send();

    ob_end_clean();
    echo json_encode(["success" => true, "message" => "OTP sent successfully"]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "SMTP Error: " . $mail->ErrorInfo]);
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}