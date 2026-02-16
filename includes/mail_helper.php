<?php
// Save OTP to database
function saveOtpToDatabase($email, $otpCode, $expiryMinutes = 5) {
    require_once __DIR__ . '/db.php';
    $pdo = get_db_connection();
    if (!$pdo) {
        error_log('OTP save failed: database connection not available');
        return false;
    }

    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMinutes * 60);

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM otp_codes");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[$row['Field']] = true;
        }

        $otpColumnCandidates = ['otp_code', 'otp', 'code'];
        $expiryColumnCandidates = ['expires_at', 'expiry_at', 'expiry', 'expired_at'];
        $statusColumnCandidates = ['status'];

        $otpColumn = null;
        foreach ($otpColumnCandidates as $candidate) {
            if (isset($columns[$candidate])) {
                $otpColumn = $candidate;
                break;
            }
        }

        $expiryColumn = null;
        foreach ($expiryColumnCandidates as $candidate) {
            if (isset($columns[$candidate])) {
                $expiryColumn = $candidate;
                break;
            }
        }

        if (!isset($columns['email']) || $otpColumn === null || $expiryColumn === null) {
            error_log('OTP save failed: otp_codes schema mismatch');
            return false;
        }

        $insertColumns = ['email', $otpColumn, $expiryColumn];
        $insertValues = [$email, (string)$otpCode, $expiresAt];

        foreach ($statusColumnCandidates as $statusColumn) {
            if (isset($columns[$statusColumn])) {
                $insertColumns[] = $statusColumn;
                $insertValues[] = 'active';
                break;
            }
        }

        $columnSql = implode(', ', array_map(function ($column) {
            return "`" . $column . "`";
        }, $insertColumns));
        $placeholders = implode(', ', array_fill(0, count($insertValues), '?'));
        $sql = "INSERT INTO otp_codes (" . $columnSql . ") VALUES (" . $placeholders . ")";

        $insertStmt = $pdo->prepare($sql);
        return $insertStmt->execute($insertValues);
    } catch (PDOException $e) {
        error_log('OTP save failed: ' . $e->getMessage());
        return false;
    }
}
// Send OTP Email with HTML template
function sendOtpEmail($to, $otpCode, $systemName = null, $logoUrl = 'Email.png') {
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

    // Load .env if exists
    $envPath = dirname(__DIR__) . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (!strpos($line, '=')) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!isset($_ENV[$name])) {
                $_ENV[$name] = $value;
            }
        }
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer();
    $mail->isSMTP();
    $mail->Host = $_ENV['MAIL_HOST'] ?? 'localhost';
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['MAIL_USERNAME'] ?? '';
    $mail->Password = $_ENV['MAIL_PASSWORD'] ?? '';
    $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? 'tls';
    $mail->Port = isset($_ENV['MAIL_PORT']) ? (int)$_ENV['MAIL_PORT'] : 587;

    $fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@example.com';
    $fromName = $systemName ?? ($_ENV['MAIL_FROM_NAME'] ?? 'System');
    $mail->setFrom($fromAddress, $fromName);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = 'Your OTP Code';

    $logoImg = $logoUrl ? '<img src="Email.png"' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($fromName) . ' Logo" style="height:40px; margin-bottom:10px;" />' : '';

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
        <p>Thank you for using ' . htmlspecialchars($fromName) . '!</p>
        <div style="text-align:center; color:#bbb; font-size:12px; margin-top:24px;">© ' . date('Y') . ' ' . htmlspecialchars($fromName) . '</div>
    </div>';

        $result = $mail->send();
        if (!$result) {
            $logMsg = date('Y-m-d H:i:s') . " PHPMailer Error: " . $mail->ErrorInfo . "\n";
            file_put_contents(__DIR__ . '/../mail_error.log', $logMsg, FILE_APPEND);
        }
        return $result;
}
// PHPMailer-based mail sender for OTP and notifications

use PHPMailer\PHPMailer\PHPMailer;

