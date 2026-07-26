<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/unit_location_tracking.php";

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
  $u = $pdo->prepare("
    SELECT
        id,
        name,
        username,
        email,
        role,
        department,
        unit_code,
        unit_type,
        unit_status,
        profile_image_path
    FROM users
    WHERE email=?
    LIMIT 1
    ");
  $u->execute([$email]);
  $responder = $u->fetch();

  if (!$responder) {
    echo json_encode(["success"=>false, "message"=>"Account not found", "user"=>null]);
    exit;
  }

  mark_user_online($pdo, (int)$responder["id"]);

  $locationUpdate = null;
  $hasLocationPayload = array_key_exists("latitude", $input)
      || array_key_exists("lat", $input)
      || array_key_exists("longitude", $input)
      || array_key_exists("lng", $input)
      || array_key_exists("lon", $input);
  if ($hasLocationPayload) {
    $locationPayload = $input;
    $locationPayload["responder_id"] = (int)$responder["id"];
    $locationPayload["unit_code"] = (string)($responder["unit_code"] ?? "");
    $locationPayload["source"] = $locationPayload["source"] ?? "responder_otp_verify";
    try {
      $locationUpdate = ers_unit_location_update($pdo, $locationPayload);
    } catch (Throwable $e) {
      error_log("responder OTP location update skipped: " . $e->getMessage());
      $locationUpdate = ["ok" => false, "error" => "Location update skipped"];
    }
  }

  $unit = ers_unit_location_resolve_unit($pdo, [
      "responder_id" => (int)$responder["id"],
      "unit_code" => (string)($responder["unit_code"] ?? "")
  ]);

  echo json_encode([
    "success" => true,
    "message" => "OTP verified",
    "user" => [
    "id"          => (int)$responder["id"],
    "name"        => (string)$responder["name"],
    "username"    => (string)($responder["username"] ?? ""),
    "email"       => (string)$responder["email"],
    "role"        => (string)($responder["role"] ?? ""),
    "department"  => (string)($responder["department"] ?? ""),
    "unit_id"     => $unit ? (int)$unit["id"] : null,
    "unit_code"   => (string)($responder["unit_code"] ?? ""),
    "unit_type"   => (string)($responder["unit_type"] ?? ""),
    "unit_status" => (string)($responder["unit_status"] ?? "available"),
    "profile_image_path" => (string)($responder["profile_image_path"] ?? "")
],
    "location_update" => $locationUpdate,
    "location_tracking" => [
      "enabled" => $unit !== null,
      "endpoint" => "api/unit_location_update.php",
      "api_app_endpoint" => "api/api_app/update-location.php"
    ]
]);

} catch (Throwable $e) {
  echo json_encode([
    "success"=>false,
    "message"=>"Server error: " . $e->getMessage(),
    "user"=>null
  ]);
}
