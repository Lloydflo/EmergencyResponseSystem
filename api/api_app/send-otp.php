<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: application/json");

require __DIR__ . "/connect.php";
require_once dirname(__DIR__, 2) . "/includes/mail_helper.php";

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}

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

    $cooldownWait = getRecentOtpRequestWaitSeconds($email, 60, 'responder_otps', 'responder_email');
    if ($cooldownWait > 0) {
        ob_end_clean();
        echo json_encode(["success" => false, "message" => getOtpCooldownMessage($cooldownWait)]);
        exit;
    }

    $otp = (string)random_int(100000, 999999);
    $expiresAt = (new DateTime("+5 minutes"))->format("Y-m-d H:i:s");
    $ins = $pdo->prepare("INSERT INTO responder_otps (responder_email, otp, expires_at) VALUES (?, ?, ?)");
    $ins->execute([$email, $otp, $expiresAt]);

    if (!sendOtpEmail($email, $otp, 'AlerTara QC')) {
        markOtpEmailDeliveryFailed($email, $otp, 'responder_otps', 'responder_email');
        ob_end_clean();
        echo json_encode(["success" => false, "message" => getLastOtpEmailErrorMessage("OTP email could not be delivered. Configure a backup SMTP sender or server mail fallback.")]);
        exit;
    }

    ob_end_clean();
    echo json_encode(["success" => true, "message" => "OTP sent successfully"]);
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}
