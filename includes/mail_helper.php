<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

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

    $readEnv = function ($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key) ?? $_SERVER[$key] ?? $default;
        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        return trim($value);
    };

    // Load .env if exists
    $envPath = dirname(__DIR__) . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!isset($_ENV[$name])) {
                $_ENV[$name] = $value;
            }
        }
    }

    $host = $readEnv('MAIL_HOST', 'localhost');
    $username = $readEnv('MAIL_USERNAME', '');
    $password = $readEnv('MAIL_PASSWORD', '');
    $passwordNoSpaces = preg_replace('/\s+/', '', (string)$password);
    $encryption = strtolower((string)$readEnv('MAIL_ENCRYPTION', 'tls'));
    $port = (int)$readEnv('MAIL_PORT', 587);
    $fromAddress = $readEnv('MAIL_FROM_ADDRESS', 'no-reply@example.com');
    $fromName = $systemName ?? $readEnv('MAIL_FROM_NAME', 'System');
    $debugEnabled = filter_var($readEnv('MAIL_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    $allowPhpMailFallback = filter_var($readEnv('MAIL_ALLOW_PHP_FALLBACK', 'false'), FILTER_VALIDATE_BOOLEAN);

    $logoPath = trim((string)$logoUrl);
    $logoImg = $logoPath !== '' ? '<img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars((string)$fromName, ENT_QUOTES, 'UTF-8') . ' Logo" style="height:40px; margin-bottom:10px;" />' : '';
    $body = '
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

    $attempts = [];
    $attempts[] = ['encryption' => $encryption, 'port' => $port];

    if ($encryption === 'ssl' && $port !== 465) {
        $attempts[] = ['encryption' => 'ssl', 'port' => 465];
    } elseif ($encryption === 'tls' && $port !== 587) {
        $attempts[] = ['encryption' => 'tls', 'port' => 587];
    }

    if ($encryption === 'ssl') {
        $attempts[] = ['encryption' => 'tls', 'port' => 587];
    } elseif ($encryption === 'tls') {
        $attempts[] = ['encryption' => 'ssl', 'port' => 465];
    }
    $attempts[] = ['encryption' => '', 'port' => 25];

    $seen = [];
    $errors = [];

    foreach ($attempts as $attempt) {
        $key = $attempt['encryption'] . ':' . $attempt['port'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = (string)$username;
        $mail->Password = (string)$passwordNoSpaces;
        $mail->SMTPSecure = $attempt['encryption'];
        $mail->Port = (int)$attempt['port'];
        $mail->Timeout = 20;
        $mail->SMTPAutoTLS = true;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = $debugEnabled ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        $debugLines = [];
        if ($debugEnabled) {
            $mail->Debugoutput = static function ($str, $level) use (&$debugLines) {
                $debugLines[] = '[' . $level . '] ' . $str;
            };
        }

        try {
            $mail->setFrom((string)$fromAddress, (string)$fromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = 'Your OTP Code';
            $mail->Body = $body;

            if ($mail->send()) {
                return true;
            }

            $errors[] = $key . ' -> ' . $mail->ErrorInfo;
        } catch (\Throwable $e) {
            $errors[] = $key . ' -> ' . $e->getMessage();
        }

        if ($debugEnabled && !empty($debugLines)) {
            $debugBlock = implode(" | ", $debugLines);
            $errors[] = $key . ' debug -> ' . $debugBlock;
        }
    }

    $safeUsername = (string)$username;
    if (strlen($safeUsername) > 4) {
        $safeUsername = substr($safeUsername, 0, 2) . str_repeat('*', max(0, strlen($safeUsername) - 4)) . substr($safeUsername, -2);
    }

    $logMsg = date('Y-m-d H:i:s')
        . " PHPMailer Error: SMTP connect/send failed"
        . " host=" . $host
        . " user=" . $safeUsername
        . " attempts=" . implode('; ', $errors)
        . "\n";
    file_put_contents(__DIR__ . '/../mail_error.log', $logMsg, FILE_APPEND);

    if (!$allowPhpMailFallback) {
        return false;
    }

    // Optional fallback to PHP mail() for hosts that block outbound SMTP ports.
    $fallbackSubject = 'Your OTP Code';
    $fallbackText = "Your OTP code is: " . $otpCode . "\nThis code will expire in 3 minutes.";
    $fallbackHeaders = [];
    $fallbackHeaders[] = 'MIME-Version: 1.0';
    $fallbackHeaders[] = 'Content-type: text/plain; charset=UTF-8';
    $fallbackHeaders[] = 'From: ' . $fromName . ' <' . $fromAddress . '>';
    $fallbackHeaders[] = 'Reply-To: ' . $fromAddress;
    $fallbackHeaders[] = 'X-Mailer: PHP/' . phpversion();
    $fallbackResult = @mail($to, $fallbackSubject, $fallbackText, implode("\r\n", $fallbackHeaders));

    if ($fallbackResult) {
        file_put_contents(
            __DIR__ . '/../mail_error.log',
            date('Y-m-d H:i:s') . " PHP mail() fallback accepted for: " . $to . "\n",
            FILE_APPEND
        );
        return true;
    }

    file_put_contents(
        __DIR__ . '/../mail_error.log',
        date('Y-m-d H:i:s') . " PHP mail() fallback failed for: " . $to . "\n",
        FILE_APPEND
    );

    return false;
}
// PHPMailer-based mail sender for OTP and notifications

