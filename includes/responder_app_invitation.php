<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Responder application invitation helper.
 *
 * This file intentionally keeps APK paths and signing secrets on the server.
 * It creates an expiring HMAC-signed URL and sends it through the same SMTP
 * environment variables already used by the system's OTP mailer.
 */

if (!function_exists('ers_responder_app_load_env')) {
    function ers_responder_app_load_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $envPath = dirname(__DIR__) . '/.env';
        if (!is_file($envPath) || !is_readable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || strpos($trimmed, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '') {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

if (!function_exists('ers_responder_app_env')) {
    /** @return mixed */
    function ers_responder_app_env(string $key, $default = null)
    {
        ers_responder_app_load_env();

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        return $default;
    }
}

if (!function_exists('ers_responder_app_base_url')) {
    function ers_responder_app_base_url(): string
    {
        $baseUrl = rtrim(trim((string)ers_responder_app_env(
            'RESPONDER_APP_INVITE_BASE_URL',
            'https://emergency-response.alertaraqc.com/responder-app'
        )), '/');

        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('RESPONDER_APP_INVITE_BASE_URL is missing or invalid.');
        }

        if (strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Responder app invitation URL must use HTTPS.');
        }

        return $baseUrl;
    }
}

if (!function_exists('ers_responder_app_invite_secret')) {
    function ers_responder_app_invite_secret(): string
    {
        $secret = trim((string)ers_responder_app_env('RESPONDER_APP_INVITE_SECRET', ''));
        if (strlen($secret) < 32) {
            throw new RuntimeException('RESPONDER_APP_INVITE_SECRET must contain at least 32 characters.');
        }
        return $secret;
    }
}

if (!function_exists('ers_responder_app_invite_ttl')) {
    function ers_responder_app_invite_ttl(): int
    {
        $seconds = (int)ers_responder_app_env('RESPONDER_APP_INVITE_TTL_SECONDS', 172800);
        // Keep links between 15 minutes and 7 days.
        return max(900, min($seconds, 604800));
    }
}

if (!function_exists('ers_responder_app_signature')) {
    function ers_responder_app_signature(int $userId, string $email, int $expiresAt): string
    {
        $canonical = $userId . '|' . strtolower(trim($email)) . '|' . $expiresAt;
        $binary = hash_hmac('sha256', $canonical, ers_responder_app_invite_secret(), true);
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}

if (!function_exists('ers_build_responder_app_invite_url')) {
    function ers_build_responder_app_invite_url(int $userId, string $email, ?int $expiresAt = null): string
    {
        if ($userId <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid responder id and email are required.');
        }

        $expiresAt = $expiresAt ?? (time() + ers_responder_app_invite_ttl());
        $signature = ers_responder_app_signature($userId, $email, $expiresAt);

        return ers_responder_app_base_url() . '/invite.php?' . http_build_query([
            'uid' => $userId,
            'exp' => $expiresAt,
            'sig' => $signature,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('ers_validate_responder_app_invite')) {
    function ers_validate_responder_app_invite(
        int $userId,
        string $email,
        int $expiresAt,
        string $signature
    ): bool {
        if ($userId <= 0 || $expiresAt <= time() || $signature === '') {
            return false;
        }

        // Do not accept links issued more than seven days into the future.
        if ($expiresAt > time() + 604800) {
            return false;
        }

        try {
            $expected = ers_responder_app_signature($userId, $email, $expiresAt);
        } catch (Throwable $e) {
            error_log('Responder app invitation validation failed: ' . $e->getMessage());
            return false;
        }

        return hash_equals($expected, $signature);
    }
}

if (!function_exists('ers_responder_app_mail_config')) {
    /** @return array<string,mixed> */
    function ers_responder_app_mail_config(): array
    {
        $host = trim((string)ers_responder_app_env('SMTP_HOST', ers_responder_app_env('MAIL_HOST', '')));
        $username = trim((string)ers_responder_app_env('SMTP_USERNAME', ers_responder_app_env('MAIL_USERNAME', '')));
        $password = preg_replace('/\s+/', '', (string)ers_responder_app_env(
            'SMTP_PASSWORD',
            ers_responder_app_env('MAIL_PASSWORD', '')
        ));
        $fromAddress = trim((string)ers_responder_app_env(
            'SMTP_FROM_ADDRESS',
            ers_responder_app_env('MAIL_FROM_ADDRESS', $username)
        ));
        $fromName = trim((string)ers_responder_app_env(
            'SMTP_FROM_NAME',
            ers_responder_app_env('MAIL_FROM_NAME', 'Emergency Response System')
        ));
        $encryption = strtolower(trim((string)ers_responder_app_env(
            'SMTP_ENCRYPTION',
            ers_responder_app_env('MAIL_ENCRYPTION', 'tls')
        )));
        $port = (int)ers_responder_app_env('SMTP_PORT', ers_responder_app_env('MAIL_PORT', 587));

        return [
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'from_address' => $fromAddress !== '' ? $fromAddress : $username,
            'from_name' => $fromName !== '' ? $fromName : 'Emergency Response System',
            'encryption' => $encryption,
            'port' => $port > 0 ? $port : 587,
        ];
    }
}

if (!function_exists('ers_send_responder_app_invitation_email')) {
    /**
     * @return array{attempted:bool,sent:bool,message:string}
     */
    function ers_send_responder_app_invitation_email(
        int $userId,
        string $toEmail,
        string $responderName,
        string $department
    ): array {
        $toEmail = trim($toEmail);
        $responderName = trim($responderName);
        $department = trim($department);

        if ($userId <= 0 || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'attempted' => false,
                'sent' => false,
                'message' => 'Responder account was created, but the invitation email address is invalid.',
            ];
        }

        try {
            $inviteUrl = ers_build_responder_app_invite_url($userId, $toEmail);
            $config = ers_responder_app_mail_config();

            if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
                throw new RuntimeException('SMTP configuration is incomplete.');
            }

            require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
            require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
            require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

            $version = trim((string)ers_responder_app_env('RESPONDER_APP_VERSION', 'Current release'));
            $supportEmail = trim((string)ers_responder_app_env(
                'RESPONDER_APP_SUPPORT_EMAIL',
                $config['from_address']
            ));
            $ttlHours = max(1, (int)ceil(ers_responder_app_invite_ttl() / 3600));

            $safeName = htmlspecialchars($responderName !== '' ? $responderName : 'Responder', ENT_QUOTES, 'UTF-8');
            $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
            $safeDepartment = htmlspecialchars($department !== '' ? $department : 'Responder Unit', ENT_QUOTES, 'UTF-8');
            $safeVersion = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
            $safeSupport = htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8');
            $safeInviteUrl = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');

            $subject = 'Install the Official Emergency Responder App';
            $body = <<<HTML
<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f4f6f7;font-family:Arial,sans-serif;color:#17212b;">
  <div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #dce3e6;border-radius:16px;overflow:hidden;">
    <div style="padding:22px 26px;background:#4C8A89;color:#ffffff;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.5px;">AUTHORIZED RESPONDER ACCESS</div>
      <div style="font-size:23px;font-weight:800;margin-top:6px;">Emergency Responder App</div>
    </div>
    <div style="padding:26px;">
      <p style="margin-top:0;">Hello <strong>{$safeName}</strong>,</p>
      <p>Your responder account has been created by the system administrator.</p>
      <table style="width:100%;border-collapse:collapse;background:#f7f9fa;border-radius:10px;margin:18px 0;">
        <tr><td style="padding:10px 12px;color:#64717d;">Registered email</td><td style="padding:10px 12px;font-weight:700;">{$safeEmail}</td></tr>
        <tr><td style="padding:10px 12px;color:#64717d;">Department</td><td style="padding:10px 12px;font-weight:700;">{$safeDepartment}</td></tr>
        <tr><td style="padding:10px 12px;color:#64717d;">App version</td><td style="padding:10px 12px;font-weight:700;">{$safeVersion}</td></tr>
      </table>
      <p style="text-align:center;margin:24px 0;">
        <a href="{$safeInviteUrl}" style="display:inline-block;padding:14px 22px;background:#4C8A89;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:800;">Download Emergency Responder App</a>
      </p>
      <p style="font-size:13px;color:#596773;">This private link expires in approximately {$ttlHours} hour(s). Do not forward it.</p>
      <ol style="padding-left:20px;line-height:1.7;">
        <li>Open the private download page.</li>
        <li>Tap <strong>Download APK</strong>.</li>
        <li>Open the downloaded file and tap <strong>Install</strong>.</li>
        <li>Open the app, tap <strong>Proceed</strong>, allow notifications and location, and turn on GPS.</li>
        <li>Enter this registered email, request the six-digit OTP, then tap <strong>Verify</strong>.</li>
      </ol>
      <p style="font-size:13px;color:#596773;">Support: {$safeSupport}</p>
    </div>
  </div>
</body>
</html>
HTML;

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string)$config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = (string)$config['username'];
            $mail->Password = (string)$config['password'];
            $mail->Port = (int)$config['port'];
            $mail->Timeout = 20;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = SMTP::DEBUG_OFF;

            if (in_array((string)$config['encryption'], ['ssl', 'smtps'], true) || (int)$config['port'] === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif (!in_array((string)$config['encryption'], ['', 'none', 'off', 'false'], true)) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom((string)$config['from_address'], (string)$config['from_name']);
            $mail->Sender = (string)$config['from_address'];
            $mail->addAddress($toEmail, $responderName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = "Hello {$responderName},\n\nYour responder account has been created.\n\nDownload the official app here:\n{$inviteUrl}\n\nThis private link expires in approximately {$ttlHours} hour(s).\n\nSupport: {$supportEmail}";
            $mail->send();

            error_log('Responder app invitation sent to user_id=' . $userId . ' email=' . $toEmail);

            return [
                'attempted' => true,
                'sent' => true,
                'message' => 'Responder account created and app invitation email sent.',
            ];
        } catch (Throwable $e) {
            error_log(
                'Responder app invitation failed for user_id=' . $userId .
                ' email=' . $toEmail . ': ' . $e->getMessage()
            );

            return [
                'attempted' => true,
                'sent' => false,
                'message' => 'Responder account created, but the app invitation email was not delivered.',
            ];
        }
    }
}
