<?php

require_once __DIR__ . '/config.php';

function ers_turnstile_is_truthy($value): bool
{
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function ers_turnstile_current_hostname(): string
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if (strpos($host, ':') !== false) {
        $host = explode(':', $host, 2)[0];
    }
    return $host;
}

function ers_turnstile_is_local_hostname(string $host): bool
{
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function ers_turnstile_use_local_test_keys(): bool
{
    $host = ers_turnstile_current_hostname();

    // Fail-safe: Cloudflare testing keys must never be used on a live hostname,
    // even if TURNSTILE_USE_LOCAL_TEST_KEYS is accidentally set in production.
    if (!ers_turnstile_is_local_hostname($host)) {
        return false;
    }

    return ers_turnstile_is_truthy(ers_env('TURNSTILE_USE_LOCAL_TEST_KEYS', 'false'));
}

function ers_turnstile_site_key(): string
{
    if (ers_turnstile_use_local_test_keys()) {
        return '1x00000000000000000000AA';
    }

    return trim((string)ers_env('TURNSTILE_SITE_KEY', ''));
}

function ers_turnstile_secret_key(): string
{
    if (ers_turnstile_use_local_test_keys()) {
        return '1x0000000000000000000000000000000AA';
    }

    return trim((string)ers_env('TURNSTILE_SECRET_KEY', ''));
}

function ers_turnstile_is_configured(): bool
{
    return ers_turnstile_site_key() !== '' && ers_turnstile_secret_key() !== '';
}

function ers_verify_turnstile_response(string $token, ?string &$errorMessage = null): bool
{
    $errorMessage = null;

    if (!ers_turnstile_is_configured()) {
        $errorMessage = 'Cloudflare Turnstile is not configured. Please contact the administrator.';
        return false;
    }

    $token = trim($token);
    if ($token === '') {
        $errorMessage = 'Please complete the Cloudflare verification.';
        return false;
    }

    $payload = [
        'secret' => ers_turnstile_secret_key(),
        'response' => $token,
    ];

    $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteIp !== '') {
        $payload['remoteip'] = $remoteIp;
    }

    $body = http_build_query($payload);
    $responseBody = false;

    if (function_exists('curl_init')) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $responseBody = curl_exec($ch);
            curl_close($ch);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 10,
            ],
        ]);
        $responseBody = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    }

    if (!is_string($responseBody) || trim($responseBody) === '') {
        $errorMessage = 'Unable to verify Cloudflare Turnstile right now. Please try again.';
        return false;
    }

    $result = json_decode($responseBody, true);
    if (!is_array($result) || empty($result['success'])) {
        $errorMessage = 'Cloudflare verification failed. Please try again.';
        return false;
    }

    return true;
}
