<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$raw = file_get_contents("php://input");

// Try JSON first
$input = json_decode($raw, true);

// If not JSON, try x-www-form-urlencoded (email=...&otp=...)
if (!is_array($input)) {
  $input = [];
  parse_str($raw, $input);
}

$email = trim((string)($input["email"] ?? ($_POST["email"] ?? "")));
$otp   = trim((string)($input["otp"]   ?? ($_POST["otp"]   ?? "")));

if ($email === "" || $otp === "") {
  echo json_encode(["success"=>false, "message"=>"Email and OTP are required", "user"=>null]);
  exit;
}

try {
  $pdo = db();

  // latest unused OTP for this email
  $q = $pdo->prepare("
    SELECT id, otp, expires_at, used_at
    FROM responder_otps
    WHERE responder_email = ? AND used_at IS NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  $q->execute([$email]);
  $row = $q->fetch();

  if (!$row) {
    echo json_encode(["success"=>false, "message"=>"No OTP found", "user"=>null]);
    exit;
  }

  if (strtotime($row["expires_at"]) < time()) {
    echo json_encode(["success"=>false, "message"=>"OTP expired", "user"=>null]);
    exit;
  }

  if ((string)$row["otp"] !== (string)$otp) {
    echo json_encode(["success"=>false, "message"=>"Invalid OTP", "user"=>null]);
    exit;
  }

  // mark used
  $upd = $pdo->prepare("UPDATE responder_otps SET used_at = NOW() WHERE id = ?");
  $upd->execute([$row["id"]]);

  // return responder user
  $u = $pdo->prepare("SELECT id, name, email FROM responders WHERE email=? LIMIT 1");
  $u->execute([$email]);
  $responder = $u->fetch();

  if (!$responder) {
    echo json_encode(["success"=>false, "message"=>"Account not found", "user"=>null]);
    exit;
  }

  echo json_encode([
    "success"=>true,
    "message"=>"OTP verified",
    "user"=>$responder
  ]);

} catch (Throwable $e) {
  echo json_encode([
    "success"=>false,
    "message"=>"Server error: " . $e->getMessage(),
    "user"=>null
  ]);
}