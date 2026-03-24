<?php
// test_smtp.php - Run this directly in browser to see exact SMTP error
// DELETE THIS FILE after debugging!

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/connect.php'; // loads .env

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

// ✅ Print what values are actually being loaded
echo "<pre>";
echo "MAIL_HOST     = " . getenv("MAIL_HOST") . "\n";
echo "MAIL_PORT     = " . getenv("MAIL_PORT") . "\n";
echo "MAIL_USERNAME = " . getenv("MAIL_USERNAME") . "\n";
echo "MAIL_PASSWORD = " . (getenv("MAIL_PASSWORD") ? str_repeat("*", strlen(getenv("MAIL_PASSWORD"))) . " (" . strlen(getenv("MAIL_PASSWORD")) . " chars)" : "NOT LOADED") . "\n";
echo "MAIL_FROM     = " . getenv("MAIL_FROM_ADDRESS") . "\n";
echo "</pre>";
echo "<hr>";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = getenv("MAIL_HOST") ?: "smtp.gmail.com";
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv("MAIL_USERNAME") ?: "";
    $mail->Password   = getenv("MAIL_PASSWORD") ?: "";
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)(getenv("MAIL_PORT") ?: 587);

    // ✅ Full debug output so we see exact SMTP handshake
    $mail->SMTPDebug  = 4;
    $mail->Debugoutput = function($str, $level) {
        echo htmlspecialchars($str) . "<br>";
    };

    $mail->setFrom(getenv("MAIL_FROM_ADDRESS"), "AlerTara QC");
    $mail->addAddress(getenv("MAIL_USERNAME")); // send test to self

    $mail->Subject = "SMTP Test";
    $mail->Body    = "If you see this, SMTP works!";

    $mail->send();
    echo "<b style='color:green'>✅ SMTP works! Email sent successfully.</b>";

} catch (Throwable $e) {
    echo "<b style='color:red'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</b>";
}