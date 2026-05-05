<?php
// ✅ Buffer ALL output so nothing leaks before our JSON
ob_start();

// ✅ Suppress PHP warnings/notices from printing to output
error_reporting(0);
ini_set('display_errors', 0);

header("Content-Type: application/json");

require __DIR__ . "/connect.php";

$env = parse_ini_file(__DIR__ . '/../.env');

if ($env) {
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

// PHPMailer
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$raw = file_get_contents("php://input");

// 1) Try JSON
$input = json_decode($raw, true);

// 2) If not JSON, try x-www-form-urlencoded
if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}

$email = "";
if (is_array($input) && isset($input["email"])) {
    $email = trim((string)$input["email"]);
} elseif (isset($_POST["email"])) {
    $email = trim((string)$_POST["email"]);
}

if ($email === "") {
    // ✅ Clear buffer before sending JSON
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Email is required", "otp" => null]);
    exit;
}

try {
    $pdo = db();

    // 1) Check responder exists and is active
    $stmt = $pdo->prepare("SELECT id, email, name, department, is_active FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $responder = $stmt->fetch();

    if (!$responder || $responder["is_active"] != 1) {
        ob_end_clean();
        echo json_encode(["success" => false, "message" => "Account not found", "otp" => null]);
        exit;
    }

    // 2) Generate OTP & expiry
    $otp = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");

    // 3) Store OTP
    $ins = $pdo->prepare("INSERT INTO responder_otps (responder_email, otp, expires_at) VALUES (?, ?, ?)");
    $ins->execute([$email, $otp, $expiresAt]);

    // 4) Send email via PHPMailer
    $mail = new PHPMailer(true);
    $mail->isSMTP();

    $host = trim(getenv("MAIL_HOST") ?: "smtp.gmail.com");
    $mail->Host = $host;

    $mail->SMTPAuth   = true;
    $mail->Username = trim($_ENV["MAIL_USERNAME"] ?? "");
    $mail->Password = trim($_ENV["MAIL_PASSWORD"] ?? "");

    // ✅ FIXED: SMTPDebug = 0 so debug output does NOT leak into response
    $mail->SMTPDebug  = 0;

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)(getenv("MAIL_PORT") ?: 587);

    $fromAddr = trim(getenv("MAIL_FROM_ADDRESS") ?: "alertaraqc@gmail.com");
    $fromName = trim(getenv("MAIL_FROM_NAME") ?: "AlerTara QC");
    $mail->setFrom($fromAddr, $fromName);

    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Your OTP Code - AlerTara QC";
    $mail->Body    = "
        <h3>Hello " . htmlspecialchars($responder['name'] ?? 'Responder') . "</h3>
        <p>Your OTP code is:</p>
        <h2 style='color:blue;'>$otp</h2>
        <p>This will expire in 5 minutes.</p>
    ";
    $mail->AltBody = "Your OTP is: $otp (expires in 5 minutes)";

    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) {
    file_put_contents('mail_debug.log', "[$level] $str" . PHP_EOL, FILE_APPEND);
    };

    $mail->send();

    // ✅ Clear any buffered SMTP debug output before sending JSON
    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "OTP sent to email",
        "otp"     => null
    ]);

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage(),
        "otp"     => null
    ]);
}