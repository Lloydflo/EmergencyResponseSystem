<?php
ob_start();

// IMPORTANT: Keep errors hidden from the Android app.
// Errors are written to the PHP error log instead.
error_reporting(E_ALL);
ini_set('display_errors', '0');

header("Content-Type: application/json");

require __DIR__ . "/connect.php";

// .env is expected beside this file: /api/api_app/.env
$envPath = __DIR__ . '/.env';

if (!file_exists($envPath)) {
    ob_end_clean();
    echo json_encode([
        "success" => false,
        "message" => ".env file not found"
    ]);
    exit;
}

$env = parse_ini_file($envPath);

if ($env === false) {
    ob_end_clean();
    echo json_encode([
        "success" => false,
        "message" => "Unable to read .env file"
    ]);
    exit;
}

// PHPMailer
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}

$email = trim((string)($input["email"] ?? $_POST["email"] ?? ""));

if ($email === "") {
    ob_end_clean();
    echo json_encode([
        "success" => false,
        "message" => "Email is required"
    ]);
    exit;
}

$mail = null;

try {
    // -----------------------------
    // 1. CHECK RESPONDER ACCOUNT
    // -----------------------------
    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT id, email, name, is_active
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    $stmt->execute([$email]);
    $responder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$responder || (int)$responder["is_active"] !== 1) {
        ob_end_clean();
        echo json_encode([
            "success" => false,
            "message" => "Account not found or inactive"
        ]);
        exit;
    }

    // -----------------------------
    // 2. GENERATE OTP
    // -----------------------------
    $otp = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");

    $ins = $pdo->prepare(
        "INSERT INTO responder_otps
         (responder_email, otp, expires_at)
         VALUES (?, ?, ?)"
    );

    $ins->execute([$email, $otp, $expiresAt]);

    // -----------------------------
    // 3. CHECK SMTP SETTINGS
    // -----------------------------
    $requiredEnv = [
        'MAIL_HOST',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_PORT'
    ];

    foreach ($requiredEnv as $key) {
        if (!isset($env[$key]) || trim((string)$env[$key]) === '') {
            throw new RuntimeException(
                "Missing SMTP setting in .env: " . $key
            );
        }
    }

    // -----------------------------
    // 4. SEND OTP USING PHPMailer
    // -----------------------------
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = trim($env['MAIL_HOST']);
    $mail->SMTPAuth   = true;
    $mail->Username   = trim($env['MAIL_USERNAME']);
    $mail->Password   = $env['MAIL_PASSWORD'];

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$env['MAIL_PORT'];

    // Keep SMTP debug OFF in production.
    $mail->SMTPDebug = 0;

    $fromAddress = $env['MAIL_FROM_ADDRESS'] ?? $env['MAIL_USERNAME'];
    $fromName    = $env['MAIL_FROM_NAME'] ?? 'AlerTara QC';

    $mail->setFrom($fromAddress, $fromName);
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Your OTP Code - AlerTara QC";

    $safeName = htmlspecialchars(
        (string)$responder['name'],
        ENT_QUOTES,
        'UTF-8'
    );

    $mail->Body =
        "<h3>Hello " . $safeName . "</h3>" .
        "<p>Your OTP code is: " .
        "<b style='font-size:24px; color:blue;'>" . $otp . "</b>" .
        "</p>" .
        "<p>This OTP will expire in 5 minutes.</p>";

    $mail->AltBody =
        "Hello " . $responder['name'] .
        ". Your OTP code is: " . $otp .
        ". This OTP will expire in 5 minutes.";

    $mail->send();

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "OTP sent successfully"
    ]);

} catch (Exception $e) {

    // PHPMailer exception
    error_log("send-otp PHPMailer error: " . $e->getMessage());

    $message = "SMTP Error";

    if ($mail instanceof PHPMailer && !empty($mail->ErrorInfo)) {
        $message = "SMTP Error: " . $mail->ErrorInfo;
    }

    ob_end_clean();
    echo json_encode([
        "success" => false,
        "message" => $message
    ]);

} catch (Throwable $e) {

    // PDO errors, .env errors, PHP runtime errors, etc.
    error_log(
        "send-otp server error: " .
        $e->getMessage() .
        " in " . $e->getFile() .
        ":" . $e->getLine()
    );

    ob_end_clean();
    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}
?>