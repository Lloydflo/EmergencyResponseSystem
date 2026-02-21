<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

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

  // ✅ DEV ONLY: return OTP (remove later)
  echo json_encode(["success"=>true, "message"=>"OTP sent", "otp"=>$otp]);

} catch (Throwable $e) {
  echo json_encode(["success"=>false, "message"=>"Server error", "otp"=>null]);
}