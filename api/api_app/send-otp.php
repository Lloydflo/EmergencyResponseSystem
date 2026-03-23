<?php
header("Content-Type: application/json");

require __DIR__ . "/connect.php";

// PHPMailer
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

$raw = file_get_contents("php://input");

// 1) Try JSON
$input = json_decode($raw, true);

// 2) If not JSON, try x-www-form-urlencoded (email=...)
// (Ito yung case ng logcat mo: Body: email=lloyds...)
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
    echo json_encode(["success" => false, "message" => "Email is required", "otp" => null]);
    exit;
}

try {
    $pdo = db();

    // 1) check responder exists and active
    $stmt = $pdo->prepare("SELECT id, name, email, department, is_active FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $responder = $stmt->fetch();

    if (!$responder || intval($responder["is_active"]) !== 1) {
        echo json_encode(["success" => false, "message" => "Account not found", "otp" => null]);
        exit;
    }

    // 2) generate OTP & expiry
    $otp = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");

    // 3) store OTP
    $ins = $pdo->prepare("INSERT INTO responder_otps (responder_email, otp, expires_at) VALUES (?, ?, ?)");
    $ins->execute([$email, $otp, $expiresAt]);

    // 4) send email via PHPMailer
    $mail = new PHPMailer(true);
    $mail->isSMTP();

    $host = trim(getenv("MAIL_HOST") ?: "smtp.gmail.com");
    $mail->Host = $host;

    $mail->SMTPAuth = true;
    $mail->Username = trim(getenv("MAIL_USERNAME") ?: "");
    $mail->Password = trim(getenv("MAIL_PASSWORD") ?: "");

    // ✅ Use 587 + STARTTLS (mas stable sa VPS)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int)(getenv("MAIL_PORT") ?: 587);

    $fromAddr = trim(getenv("MAIL_FROM_ADDRESS") ?: "alertaraqc@gmail.com");
    $fromName = trim(getenv("MAIL_FROM_NAME") ?: "AlerTara QC");
    $mail->setFrom($fromAddr, $fromName);

    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Your OTP Code - AlerTara QC";
    $mail->Body = "
        <h3>Hello " . htmlspecialchars($responder['name'] ?? 'Responder') . "</h3>
        <p>Your OTP code is:</p>
        <h2 style='color:blue;'>$otp</h2>
        <p>This will expire in 5 minutes.</p>
    ";
    $mail->AltBody = "Your OTP is: $otp (expires in 5 minutes)";

    $mail->send();

    echo json_encode([
        "success" => true,
        "message" => "OTP sent to email",
        "otp" => null
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage(),
        "otp" => null
    ]);
}