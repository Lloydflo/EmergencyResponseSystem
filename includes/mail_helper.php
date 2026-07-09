<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

if (!function_exists('setLastOtpEmailErrorMessage')) {
    function setLastOtpEmailErrorMessage(string $message): void {
        $GLOBALS['last_otp_email_error_message'] = $message;
    }
}

if (!function_exists('getLastOtpEmailErrorMessage')) {
    function getLastOtpEmailErrorMessage(string $default = 'OTP email could not be delivered. Configure a backup SMTP sender or server mail fallback.'): string {
        $message = trim((string)($GLOBALS['last_otp_email_error_message'] ?? ''));
        return $message !== '' ? $message : $default;
    }
}

if (!function_exists('detectOtpEmailErrorMessage')) {
    function detectOtpEmailErrorMessage(array $errors): string {
        $combined = strtolower(implode(' ', $errors));

        if (strpos($combined, 'daily user sending limit exceeded') !== false) {
            return 'OTP email failed because the Gmail sender reached its daily sending limit and no backup SMTP sender accepted the OTP. Use another SMTP sender or a transactional email provider.';
        }

        if (strpos($combined, 'invalid credentials') !== false || strpos($combined, 'authentication failed') !== false) {
            return 'OTP email failed because the SMTP username or app password is invalid.';
        }

        if (strpos($combined, 'could not connect to smtp host') !== false || strpos($combined, 'failed to connect') !== false) {
            return 'OTP email failed because the server cannot connect to the SMTP host.';
        }

        if (strpos($combined, 'missing smtp host') !== false || strpos($combined, 'missing smtp') !== false) {
            return 'OTP email failed because no working backup SMTP sender is configured.';
        }

        return 'OTP email could not be delivered. Configure a backup SMTP sender or server mail fallback.';
    }
}

if (!function_exists('getRecentOtpRequestWaitSeconds')) {
    function getRecentOtpRequestWaitSeconds(string $email, int $cooldownSeconds = 60, string $table = 'otp_codes', string $emailColumn = 'email'): int {
        $email = trim($email);
        if ($email === '' || $cooldownSeconds <= 0) {
            return 0;
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $emailColumn)) {
            return 0;
        }

        require_once __DIR__ . '/db.php';
        $pdo = get_db_connection();
        if (!$pdo) {
            return 0;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `" . $table . "`");
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[$row['Field']] = true;
            }

            if (!isset($columns[$emailColumn]) || !isset($columns['created_at'])) {
                return 0;
            }

            $orderBy = '`created_at` DESC';
            if (isset($columns['id'])) {
                $orderBy .= ', `id` DESC';
            }

            $conditions = ["`" . $emailColumn . "` = ?"];
            if (isset($columns['status'])) {
                $conditions[] = "`status` = 'active'";
            }
            if (isset($columns['used_at'])) {
                $conditions[] = "`used_at` IS NULL";
            }
            if (isset($columns['expires_at'])) {
                $conditions[] = "`expires_at` > NOW()";
            }

            $sql = "SELECT TIMESTAMPDIFF(SECOND, `created_at`, NOW()) AS elapsed_seconds "
                . "FROM `" . $table . "` WHERE " . implode(' AND ', $conditions) . " "
                . "ORDER BY " . $orderBy . " LIMIT 1";
            $latestStmt = $pdo->prepare($sql);
            $latestStmt->execute([$email]);
            $row = $latestStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return 0;
            }

            $elapsedSeconds = (int)($row['elapsed_seconds'] ?? $cooldownSeconds);
            $waitSeconds = $cooldownSeconds - $elapsedSeconds;
            if ($waitSeconds <= 0) {
                return 0;
            }

            return min($cooldownSeconds, $waitSeconds);
        } catch (Throwable $e) {
            error_log('OTP cooldown check failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('markOtpEmailDeliveryFailed')) {
    function markOtpEmailDeliveryFailed(string $email, string $otpCode, string $table = 'otp_codes', string $emailColumn = 'email'): void {
        $email = trim($email);
        $otpCode = trim($otpCode);
        if ($email === '' || $otpCode === '') {
            return;
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $emailColumn)) {
            return;
        }

        require_once __DIR__ . '/db.php';
        $pdo = get_db_connection();
        if (!$pdo) {
            return;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `" . $table . "`");
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[$row['Field']] = true;
            }

            if (!isset($columns[$emailColumn])) {
                return;
            }

            $otpColumn = null;
            foreach (['otp_code', 'otp', 'code'] as $candidate) {
                if (isset($columns[$candidate])) {
                    $otpColumn = $candidate;
                    break;
                }
            }
            if ($otpColumn === null) {
                return;
            }

            if (isset($columns['status'])) {
                $sql = "UPDATE `" . $table . "` SET `status` = 'expired' "
                    . "WHERE `" . $emailColumn . "` = ? AND `" . $otpColumn . "` = ? AND `status` = 'active' "
                    . "ORDER BY `id` DESC LIMIT 1";
                $updateStmt = $pdo->prepare($sql);
                $updateStmt->execute([$email, $otpCode]);
                return;
            }

            if (isset($columns['used_at'])) {
                $sql = "UPDATE `" . $table . "` SET `used_at` = NOW() "
                    . "WHERE `" . $emailColumn . "` = ? AND `" . $otpColumn . "` = ? AND `used_at` IS NULL "
                    . "ORDER BY `id` DESC LIMIT 1";
                $updateStmt = $pdo->prepare($sql);
                $updateStmt->execute([$email, $otpCode]);
                return;
            }

            $sql = "DELETE FROM `" . $table . "` WHERE `" . $emailColumn . "` = ? AND `" . $otpColumn . "` = ? "
                . "ORDER BY `id` DESC LIMIT 1";
            $deleteStmt = $pdo->prepare($sql);
            $deleteStmt->execute([$email, $otpCode]);
        } catch (Throwable $e) {
            error_log('OTP failed-delivery cleanup failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getOtpCooldownMessage')) {
    function getOtpCooldownMessage(int $waitSeconds): string {
        $waitSeconds = max(1, $waitSeconds);
        return 'OTP was already requested. Please wait ' . $waitSeconds . ' second' . ($waitSeconds === 1 ? '' : 's') . ' before requesting another code.';
    }
}

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
    setLastOtpEmailErrorMessage('');

    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

    $readEnv = function ($key, $default = null) {
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } else {
            $envValue = getenv($key);
            if ($envValue !== false) {
                $value = $envValue;
            } elseif (array_key_exists($key, $_SERVER)) {
                $value = $_SERVER[$key];
            } else {
                $value = $default;
            }
        }

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

    $readFirstEnv = function (array $keys, $default = null) use ($readEnv) {
        foreach ($keys as $key) {
            $value = $readEnv($key, null);
            if (is_string($value)) {
                if (trim($value) !== '') {
                    return $value;
                }
                continue;
            }
            if ($value !== null) {
                return $value;
            }
        }
        return $default;
    };

    // Load .env if exists. File values should win so SMTP changes take effect immediately.
    $envPath = dirname(__DIR__) . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name !== '') {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv($name . '=' . $value);
            }
        }
    }

    $host = $readFirstEnv(['SMTP_HOST', 'MAIL_HOST'], 'localhost');
    $username = $readFirstEnv(['SMTP_USERNAME', 'MAIL_USERNAME'], '');
    $password = $readFirstEnv(['SMTP_PASSWORD', 'MAIL_PASSWORD'], '');
    $passwordNoSpaces = preg_replace('/\s+/', '', (string)$password);
    $encryption = strtolower((string)$readFirstEnv(['SMTP_ENCRYPTION', 'MAIL_ENCRYPTION'], 'tls'));
    $port = (int)$readFirstEnv(['SMTP_PORT', 'MAIL_PORT'], 587);
    $fromAddress = $readFirstEnv(['SMTP_FROM_ADDRESS', 'MAIL_FROM_ADDRESS'], 'no-reply@example.com');
    $fromName = $systemName ?? $readFirstEnv(['SMTP_FROM_NAME', 'MAIL_FROM_NAME'], 'System');
    $debugEnabled = filter_var($readEnv('MAIL_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);
    $allowPhpMailFallback = filter_var($readEnv('MAIL_ALLOW_PHP_FALLBACK', 'true'), FILTER_VALIDATE_BOOLEAN);

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

    $errors = [];
    $encryptionAliases = [
        'starttls' => 'tls',
        'tls' => 'tls',
        'ssl' => 'ssl',
        'smtps' => 'ssl',
        'none' => '',
        'off' => '',
        'false' => '',
        '' => '',
    ];

    $sendViaSmtp = static function (array $config, string $body) use (
        $to,
        $debugEnabled,
        $encryptionAliases,
        $readEnv,
        &$errors
    ): bool {
        $label = (string)($config['label'] ?? 'smtp');
        $host = trim((string)($config['host'] ?? ''));
        $username = trim((string)($config['username'] ?? ''));
        $passwordNoSpaces = preg_replace('/\s+/', '', (string)($config['password'] ?? ''));
        $encryption = strtolower(trim((string)($config['encryption'] ?? 'tls')));
        $port = (int)($config['port'] ?? 587);
        $fromAddress = trim((string)($config['from_address'] ?? ''));
        $fromName = trim((string)($config['from_name'] ?? 'System'));

        if ($host === '' || $username === '' || $passwordNoSpaces === '') {
            $errors[] = $label . ' -> missing SMTP host, username, or password';
            return false;
        }
        if ($fromAddress === '') {
            $fromAddress = $username;
        }

        $encryption = $encryptionAliases[$encryption] ?? $encryption;
        if ($port === 465 && $encryption === 'tls') {
            $encryption = 'ssl';
        } elseif ($port === 587 && $encryption === 'ssl') {
            $encryption = 'tls';
        }

        $attempts = [
            ['encryption' => $encryption, 'port' => $port],
        ];

        $retryAlternatePorts = filter_var($readEnv('MAIL_RETRY_ALTERNATE_PORTS', 'false'), FILTER_VALIDATE_BOOLEAN);
        if ($retryAlternatePorts) {
            $attempts[] = ['encryption' => 'tls', 'port' => 587];
            $attempts[] = ['encryption' => 'ssl', 'port' => 465];
        }

        $allowPlainSmtp = filter_var($readEnv('MAIL_ALLOW_PLAIN_SMTP', 'false'), FILTER_VALIDATE_BOOLEAN);
        if ($allowPlainSmtp) {
            $attempts[] = ['encryption' => '', 'port' => 25];
        }

        $seen = [];
        foreach ($attempts as $attempt) {
            $key = $label . ':' . $attempt['encryption'] . ':' . $attempt['port'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $passwordNoSpaces;
            if ($attempt['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($attempt['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
            }
            $mail->Port = (int)$attempt['port'];
            $mail->Timeout = 20;
            $mail->SMTPAutoTLS = $attempt['encryption'] === 'tls';
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            $debugLines = [];
            $mail->Debugoutput = static function ($str, $level) use (&$debugLines, $username, $passwordNoSpaces) {
                $line = (string)$str;
                $secrets = [
                    $username,
                    $passwordNoSpaces,
                    base64_encode($username),
                    base64_encode($passwordNoSpaces),
                    base64_encode("\0" . $username . "\0" . $passwordNoSpaces),
                ];
                foreach ($secrets as $secret) {
                    if ($secret !== '') {
                        $line = str_replace($secret, '[redacted]', $line);
                    }
                }
                if (stripos($line, 'AUTH') !== false) {
                    $line = '[auth redacted]';
                }
                $debugLines[] = '[' . $level . '] ' . $line;
            };

            try {
                $mail->setFrom($fromAddress, $fromName);
                $mail->Sender = $fromAddress;
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = 'Your OTP Code';
                $mail->Body = $body;

                if ($mail->send()) {
                    file_put_contents(
                        __DIR__ . '/../mail_error.log',
                        date('Y-m-d H:i:s') . " OTP email sent via " . $label . " SMTP to: " . $to . "\n",
                        FILE_APPEND
                    );
                    return true;
                }

                $errors[] = $key . ' -> ' . $mail->ErrorInfo;
            } catch (\Throwable $e) {
                $errors[] = $key . ' -> ' . $e->getMessage();
            }

            $debugBlock = implode(" | ", $debugLines);
            $hitDailyLimit = stripos($debugBlock, 'Daily user sending limit exceeded') !== false;
            if (!empty($debugLines) && ($debugEnabled || $hitDailyLimit)) {
                $errors[] = $key . ' debug -> ' . $debugBlock;
            }
            if ($hitDailyLimit) {
                break;
            }
        }

        return false;
    };

    $smtpConfigs = [];
    $smtpConfigKeys = [];
    $addSmtpConfig = static function (array $config) use (&$smtpConfigs, &$smtpConfigKeys): void {
        $host = trim((string)($config['host'] ?? ''));
        $username = trim((string)($config['username'] ?? ''));
        $password = trim((string)($config['password'] ?? ''));
        $fromAddress = trim((string)($config['from_address'] ?? ''));
        if ($host === '' && $username === '' && $password === '') {
            return;
        }

        $key = strtolower($host . '|' . $username . '|' . $fromAddress);
        if (isset($smtpConfigKeys[$key])) {
            return;
        }
        $smtpConfigKeys[$key] = true;
        $smtpConfigs[] = $config;
    };

    $addSmtpConfig([
        'label' => 'primary',
        'host' => $host,
        'username' => $username,
        'password' => $passwordNoSpaces,
        'encryption' => $encryption,
        'port' => $port,
        'from_address' => $fromAddress,
        'from_name' => $fromName,
    ]);

    $backupHost = $readEnv('MAIL_BACKUP_HOST', '');
    $backupUsername = $readEnv('MAIL_BACKUP_USERNAME', '');
    $backupPassword = $readEnv('MAIL_BACKUP_PASSWORD', '');
    $addSmtpConfig([
            'label' => 'backup',
            'host' => $backupHost,
            'username' => $backupUsername,
            'password' => $backupPassword,
            'encryption' => strtolower((string)$readEnv('MAIL_BACKUP_ENCRYPTION', $encryption)),
            'port' => (int)$readEnv('MAIL_BACKUP_PORT', $port),
            'from_address' => $readEnv('MAIL_BACKUP_FROM_ADDRESS', $backupUsername ?: $fromAddress),
            'from_name' => $readEnv('MAIL_BACKUP_FROM_NAME', $fromName),
    ]);

    for ($smtpIndex = 2; $smtpIndex <= 5; $smtpIndex++) {
        $prefix = 'MAIL_SMTP_' . $smtpIndex . '_';
        $smtpHost = $readEnv($prefix . 'HOST', '');
        $smtpUsername = $readEnv($prefix . 'USERNAME', '');
        $smtpPassword = $readEnv($prefix . 'PASSWORD', '');
        $addSmtpConfig([
            'label' => 'smtp_' . $smtpIndex,
            'host' => $smtpHost,
            'username' => $smtpUsername,
            'password' => $smtpPassword,
            'encryption' => strtolower((string)$readEnv($prefix . 'ENCRYPTION', $encryption)),
            'port' => (int)$readEnv($prefix . 'PORT', $port),
            'from_address' => $readEnv($prefix . 'FROM_ADDRESS', $smtpUsername ?: $fromAddress),
            'from_name' => $readEnv($prefix . 'FROM_NAME', $fromName),
        ]);
    }

    foreach ($smtpConfigs as $smtpConfig) {
        if ($sendViaSmtp($smtpConfig, $body)) {
            return true;
        }
    }

    $logMsg = date('Y-m-d H:i:s')
        . " PHPMailer Error: SMTP connect/send failed"
        . " host=" . $host
        . " attempts=" . implode('; ', $errors)
        . "\n";
    file_put_contents(__DIR__ . '/../mail_error.log', $logMsg, FILE_APPEND);
    $smtpFailureMessage = detectOtpEmailErrorMessage($errors);
    setLastOtpEmailErrorMessage($smtpFailureMessage);

    if (!$allowPhpMailFallback) {
        return false;
    }

    // Optional fallback to PHP mail() for hosts that block outbound SMTP ports.
    $fallbackSubject = 'Your OTP Code';
    $fallbackText = "Your OTP code is: " . $otpCode . "\nThis code will expire in 3 minutes.";
    $fallbackFromAddress = (string)$readEnv('MAIL_FALLBACK_FROM_ADDRESS', '');
    if (trim($fallbackFromAddress) === '') {
        $hostName = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'alertaraqc.com')));
        $hostName = preg_replace('/:\d+$/', '', $hostName);
        if ($hostName === '' || $hostName === 'localhost' || $hostName === '127.0.0.1') {
            $hostName = 'alertaraqc.com';
        }
        $fallbackFromAddress = 'no-reply@' . $hostName;
    }
    $fallbackFromName = (string)$readEnv('MAIL_FALLBACK_FROM_NAME', $fromName);
    $fallbackHeaders = [];
    $fallbackHeaders[] = 'MIME-Version: 1.0';
    $fallbackHeaders[] = 'Content-type: text/plain; charset=UTF-8';
    $fallbackHeaders[] = 'From: ' . $fallbackFromName . ' <' . $fallbackFromAddress . '>';
    $fallbackHeaders[] = 'Reply-To: ' . $fallbackFromAddress;
    $fallbackHeaders[] = 'X-Mailer: PHP/' . phpversion();
    $fallbackParams = stripos(PHP_OS_FAMILY, 'Windows') === false ? ('-f' . $fallbackFromAddress) : '';
    $fallbackResult = $fallbackParams !== ''
        ? @mail($to, $fallbackSubject, $fallbackText, implode("\r\n", $fallbackHeaders), $fallbackParams)
        : @mail($to, $fallbackSubject, $fallbackText, implode("\r\n", $fallbackHeaders));

    if ($fallbackResult) {
        file_put_contents(
            __DIR__ . '/../mail_error.log',
            date('Y-m-d H:i:s') . " PHP mail() fallback accepted for: " . $to . " from: " . $fallbackFromAddress . "\n",
            FILE_APPEND
        );
        return true;
    }

    file_put_contents(
        __DIR__ . '/../mail_error.log',
        date('Y-m-d H:i:s') . " PHP mail() fallback failed for: " . $to . " from: " . $fallbackFromAddress . "\n",
        FILE_APPEND
    );
    if ($smtpFailureMessage === 'OTP email could not be delivered. Configure a backup SMTP sender or server mail fallback.') {
        setLastOtpEmailErrorMessage('OTP email failed because primary SMTP failed and PHP/server mail fallback is not configured or was rejected.');
    }

    return false;
}
// PHPMailer-based mail sender for OTP and notifications

