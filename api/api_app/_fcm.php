<?php
declare(strict_types=1);

/**
 * Firebase Cloud Messaging HTTP v1 helper.
 *
 * Required server configuration (outside public_html):
 *   FIREBASE_SERVICE_ACCOUNT_PATH=/absolute/private/path/service-account.json
 *
 * The service-account file is a server secret. Never bundle it in Android or
 * upload it to a public web directory.
 */

function ers_fcm_credentials_path(): string
{
    $path = trim((string)(getenv('FIREBASE_SERVICE_ACCOUNT_PATH') ?: ($_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? '')));
    if ($path === '') {
        $path = trim((string)(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? '')));
    }
    return $path;
}

/** @return array<string,mixed> */
function ers_fcm_credentials(): array
{
    static $credentials = null;
    if (is_array($credentials)) {
        return $credentials;
    }

    $path = ers_fcm_credentials_path();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('FCM service-account credentials are not configured.');
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)
        || trim((string)($decoded['project_id'] ?? '')) === ''
        || trim((string)($decoded['client_email'] ?? '')) === ''
        || trim((string)($decoded['private_key'] ?? '')) === '') {
        throw new RuntimeException('FCM service-account credentials are invalid.');
    }

    $credentials = $decoded;
    return $credentials;
}

function ers_fcm_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Truncate a notification preview without requiring the optional mbstring
 * extension on shared hosting. UTF-8 aware truncation is used when available.
 */
function ers_notification_preview(string $value, int $maxLength): string
{
    $maxLength = max(1, $maxLength);
    if (function_exists('mb_substr')) {
        return (string)mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

/** @return array{token:string,expires_at:int} */
function ers_fcm_access_token_record(): array
{
    static $memory = null;
    $now = time();
    if (is_array($memory) && (int)$memory['expires_at'] > $now + 90) {
        return $memory;
    }

    $credentials = ers_fcm_credentials();
    $projectId = (string)$credentials['project_id'];
    $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'ers_fcm_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $projectId) . '.json';

    if (is_file($cachePath) && is_readable($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)
            && trim((string)($cached['token'] ?? '')) !== ''
            && (int)($cached['expires_at'] ?? 0) > $now + 90) {
            $memory = [
                'token' => (string)$cached['token'],
                'expires_at' => (int)$cached['expires_at'],
            ];
            return $memory;
        }
    }

    $issuedAt = $now;
    $claims = [
        'iss' => (string)$credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
    ];
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $unsigned = ers_fcm_base64url((string)json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.'
        . ers_fcm_base64url((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

    $signature = '';
    $signed = openssl_sign(
        $unsigned,
        $signature,
        (string)$credentials['private_key'],
        OPENSSL_ALGO_SHA256
    );
    if (!$signed) {
        throw new RuntimeException('Unable to sign the Firebase OAuth request.');
    }

    $assertion = $unsigned . '.' . ers_fcm_base64url($signature);
    $curl = curl_init('https://oauth2.googleapis.com/token');
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize Firebase OAuth request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]),
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        throw new RuntimeException(
            'Firebase OAuth failed.' . ($curlError !== '' ? ' ' . $curlError : '')
        );
    }
    $response = json_decode($raw, true);
    $token = is_array($response) ? trim((string)($response['access_token'] ?? '')) : '';
    $expiresIn = is_array($response) ? max(300, (int)($response['expires_in'] ?? 3600)) : 3600;
    if ($token === '') {
        throw new RuntimeException('Firebase OAuth did not return an access token.');
    }

    $memory = [
        'token' => $token,
        'expires_at' => $now + $expiresIn,
    ];
    @file_put_contents($cachePath, json_encode($memory), LOCK_EX);
    @chmod($cachePath, 0600);
    return $memory;
}

/** @param array<string,mixed> $data @return array{ok:bool,status:int,error:string,invalid_token:bool} */
function ers_fcm_send_one(string $deviceToken, array $data): array
{
    $deviceToken = trim($deviceToken);
    if ($deviceToken === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Empty device token', 'invalid_token' => true];
    }

    $credentials = ers_fcm_credentials();
    $access = ers_fcm_access_token_record();
    $projectId = rawurlencode((string)$credentials['project_id']);

    $stringData = [];
    foreach ($data as $key => $value) {
        if ($value === null) {
            continue;
        }
        $stringData[(string)$key] = is_bool($value)
            ? ($value ? '1' : '0')
            : (string)$value;
    }

    $payload = [
        'message' => [
            'token' => $deviceToken,
            'data' => $stringData,
            'android' => [
                'priority' => 'high',
                'ttl' => '86400s',
            ],
        ],
    ];

    $curl = curl_init('https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send');
    if ($curl === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'Unable to initialize FCM', 'invalid_token' => false];
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access['token'],
            'Content-Type: application/json; charset=utf-8',
        ],
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ),
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $statusText = '';
    if (is_array($decoded)) {
        $statusText = strtoupper((string)($decoded['error']['status'] ?? ''));
    }
    $invalid = in_array($statusText, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)
        || $status === 404;
    $ok = $status >= 200 && $status < 300;
    $error = $ok ? '' : trim(
        (string)($decoded['error']['message'] ?? $curlError ?: 'FCM request failed')
    );

    return [
        'ok' => $ok,
        'status' => $status,
        'error' => $error,
        'invalid_token' => $invalid,
    ];
}

/**
 * @param list<array{id:int,token:string}> $tokenRows
 * @param array<string,mixed> $data
 * @return array{attempted:int,delivered:int,failed:int,errors:list<string>}
 */
function ers_fcm_send_rows(PDO $pdo, array $tokenRows, array $data): array
{
    $attempted = 0;
    $delivered = 0;
    $failed = 0;
    $errors = [];
    $invalidIds = [];
    $usedIds = [];

    foreach ($tokenRows as $row) {
        $tokenId = (int)($row['id'] ?? 0);
        $token = trim((string)($row['token'] ?? ''));
        if ($tokenId <= 0 || $token === '') {
            continue;
        }
        $attempted++;
        try {
            $result = ers_fcm_send_one($token, $data);
            if ($result['ok']) {
                $delivered++;
                $usedIds[] = $tokenId;
            } else {
                $failed++;
                if ($result['invalid_token']) {
                    $invalidIds[] = $tokenId;
                }
                if ($result['error'] !== '' && count($errors) < 5) {
                    $errors[] = $result['error'];
                }
            }
        } catch (Throwable $error) {
            $failed++;
            if (count($errors) < 5) {
                $errors[] = $error->getMessage();
            }
        }
    }

    if ($usedIds !== []) {
        $placeholders = implode(',', array_fill(0, count($usedIds), '?'));
        $statement = $pdo->prepare(
            "UPDATE responder_device_tokens SET last_used_at = NOW() WHERE id IN ($placeholders)"
        );
        $statement->execute($usedIds);
    }
    if ($invalidIds !== []) {
        $placeholders = implode(',', array_fill(0, count($invalidIds), '?'));
        $statement = $pdo->prepare(
            "UPDATE responder_device_tokens SET is_active = 0, updated_at = NOW() WHERE id IN ($placeholders)"
        );
        $statement->execute($invalidIds);
    }

    return compact('attempted', 'delivered', 'failed', 'errors');
}

/** @return list<array{id:int,token:string}> */
function ers_fcm_tokens_for_user(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, token FROM responder_device_tokens '
        . 'WHERE responder_id = ? AND is_active = 1 ORDER BY last_registered_at DESC'
    );
    $statement->execute([$userId]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** @return list<array{id:int,token:string}> */
function ers_fcm_tokens_for_group(PDO $pdo, int $groupId, int $excludeUserId = 0): array
{
    $sql = 'SELECT DISTINCT dt.id, dt.token '
        . 'FROM responder_device_tokens dt '
        . 'INNER JOIN interagency_group_members gm ON gm.user_id = dt.responder_id AND gm.is_active = 1 '
        . "INNER JOIN users u ON u.id = dt.responder_id AND u.role = 'responder' AND u.status = 'active' AND u.is_active = 1 "
        . 'WHERE gm.group_id = ? AND dt.is_active = 1';
    $params = [$groupId];
    if ($excludeUserId > 0) {
        $sql .= ' AND dt.responder_id <> ?';
        $params[] = $excludeUserId;
    }
    $sql .= ' ORDER BY dt.last_registered_at DESC';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** @return list<array{id:int,token:string}> */
function ers_fcm_tokens_for_all_responders(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT DISTINCT dt.id, dt.token '
        . 'FROM responder_device_tokens dt '
        . "INNER JOIN users u ON u.id = dt.responder_id AND u.role = 'responder' "
        . "AND u.status = 'active' AND u.is_active = 1 "
        . 'WHERE dt.is_active = 1 ORDER BY dt.last_registered_at DESC'
    );
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/** @param array<string,mixed> $data @return array{attempted:int,delivered:int,failed:int,errors:list<string>} */
function ers_fcm_send_to_user(PDO $pdo, int $userId, array $data): array
{
    return ers_fcm_send_rows($pdo, ers_fcm_tokens_for_user($pdo, $userId), $data);
}

/** @param array<string,mixed> $data @return array{attempted:int,delivered:int,failed:int,errors:list<string>} */
function ers_fcm_send_to_group(PDO $pdo, int $groupId, int $excludeUserId, array $data): array
{
    return ers_fcm_send_rows($pdo, ers_fcm_tokens_for_group($pdo, $groupId, $excludeUserId), $data);
}

/** @param array<string,mixed> $data @return array{attempted:int,delivered:int,failed:int,errors:list<string>} */
function ers_fcm_send_to_all_responders(PDO $pdo, array $data): array
{
    return ers_fcm_send_rows($pdo, ers_fcm_tokens_for_all_responders($pdo), $data);
}
