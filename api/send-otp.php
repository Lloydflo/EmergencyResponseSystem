<?php
header("Content-Type: application/json");

require __DIR__ . "/connect.php";

// 👉 DITO MO ILALAGAY
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$input = json_decode(file_get_contents("php://input"), true);
$email = trim($input["email"] ?? "");

if ($email === "") {
  echo json_encode(["success"=>false, "message"=>"Email is required", "otp"=>null]);
  exit;
}

try {
  $pdo = db();

  // 1) check responder exists and active
  $stmt = $pdo->prepare("SELECT id, name, email, department, is_active FROM responders WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $responder = $stmt->fetch();

  if (!$responder || intval($responder["is_active"]) !== 1) {
    echo json_encode(["success"=>false, "message"=>"Account not found", "otp"=>null]);
    exit;
  }

  // 2) generate OTP & expiry
  $otp = strval(random_int(100000, 999999));
  $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");

  // 3) store OTP
  $ins = $pdo->prepare("INSERT INTO responder_otps (responder_email, otp, expires_at) VALUES (?, ?, ?)");
  $ins->execute([$email, $otp, $expiresAt]);

// 4) send email via PHPMailer
$mail = new PHPMailer(true);

$mail->isSMTP();
$mail->Host = getenv("MAIL_HOST");
$mail->SMTPAuth   = true;
$mail->Username = getenv("MAIL_USERNAME");
$mail->Password = getenv("MAIL_PASSWORD");   
$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // because port 465
$mail->Port = getenv("MAIL_PORT");

$mail->setFrom("alertaraqc@gmail.com", "AlerTara QC");
$mail->addAddress($email);

$mail->isHTML(true);
$mail->Subject = "Your OTP Code - AlerTara QC";
$mail->Body = "
    <h3>Hello {$responder['name']}</h3>
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
    "success"=>false,
    "message"=>"Server error: " . $e->getMessage(),
    "otp"=>null
  ]);
}