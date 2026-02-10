<?php
// Save OTP to database
function saveOtpToDatabase($email, $otpCode, $expiryMinutes = 5) {
    require_once __DIR__ . '/db.php';
    $pdo = get_db_connection();
    if (!$pdo) return false;
    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMinutes * 60);
    $sql = "INSERT INTO otp_codes (email, otp_code, expires_at) VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$email, $otpCode, $expiresAt]);
}
// Send OTP Email with HTML template
function sendOtpEmail($to, $otpCode, $systemName = 'Emergency Response', $logoUrl = 'Email.png') {
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer();
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com'; // Palitan ng SMTP server
    $mail->SMTPAuth = true;
    $mail->Username = 'emergencyresponseteam8@gmail.com'; // Palitan ng SMTP username
    $mail->Password = 'gsyk kbtn vzhq ryuw'; // Palitan ng SMTP password
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom('no-reply@example.com', $systemName);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = 'Your OTP Code';

    $logoImg = $logoUrl ? '<img src="Email.png"' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($systemName) . ' Logo" style="height:40px; margin-bottom:10px;" />' : '';

    $mail->Body = '
    <div style="font-family: Arial, sans-serif; max-width: 400px; margin: auto; border-radius: 8px; background: #fff; padding: 24px; border: 1px solid #eee;">
        <div style="text-align:center;">'
        . $logoImg .
        '<h2 style="margin-top: 16px; font-size: 20px; color: #222;">Your OTP Code</h2>
        </div>
        <p>Hello,</p>
        <p>Your One-Time Password (OTP) for secure access is:</p>
        <div style="text-align:center; margin: 24px 0;">
            <span style="display:inline-block; background:#f6f6f6; border-radius:8px; padding:18px 40px; font-size:32px; font-weight:bold; color:#27ae60; letter-spacing:4px;">'
            . htmlspecialchars($otpCode) .
            '</span>
        </div>
        <p style="margin: 0 0 12px 0;">⏳ This code will expire in <b>3 minutes</b> for your security.</p>
        <p style="margin: 0 0 12px 0;">If you did not request this OTP, please ignore this email. If you need further assistance, feel free to contact our support team.</p>
        <p>Thank you for using ' . htmlspecialchars($systemName) . '!</p>
        <div style="text-align:center; color:#bbb; font-size:12px; margin-top:24px;">© ' . date('Y') . ' ' . htmlspecialchars($systemName) . '</div>
    </div>';

    return $mail->send();
}
// PHPMailer-based mail sender for OTP and notifications

use PHPMailer\PHPMailer\PHPMailer;

