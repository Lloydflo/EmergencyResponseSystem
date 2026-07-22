<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$auth = ers_external_authenticate();
$pdo = ers_external_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ers_external_json(405, [
        'success' => false,
        'error' => 'POST method required',
    ]);
}

$input = ers_external_input();

try {
    ers_group1_ensure_sync_log_table($pdo);

    $callId = (int)($input['call_id'] ?? $input['callId'] ?? 0);
    $incidentId = (int)($input['incident_id'] ?? $input['incidentId'] ?? 0);

    if ($callId <= 0 && $incidentId <= 0) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Provide call_id or incident_id',
        ]);
    }

    $record = ers_group1_load_incident_payload_source($pdo, $callId, $incidentId);
    if (!$record) {
        ers_external_json(404, [
            'success' => false,
            'error' => 'Call or incident not found',
        ]);
    }

    $payload = ers_group1_build_payload($record);
    $endpoint = ers_external_clean(
        $input['group1_endpoint']
            ?? $input['endpoint_url']
            ?? $input['target_url']
            ?? ers_env('GROUP1_INCIDENT_ENDPOINT', ''),
        500
    );

    $dryRun = ers_group1_truthy($input['dry_run'] ?? $input['dryRun'] ?? false);
    if ($dryRun) {
        ers_external_json(200, [
            'success' => true,
            'dry_run' => true,
            'message' => 'Payload prepared. No data was sent.',
            'payload' => $payload,
        ]);
    }

    if ($endpoint === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Missing Group 1 endpoint',
            'hint' => 'Send group1_endpoint in the JSON body or set GROUP1_INCIDENT_ENDPOINT in .env.',
            'payload' => $payload,
        ]);
    }

    if (!preg_match('/^https?:\/\//i', $endpoint)) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Group 1 endpoint must start with http:// or https://',
        ]);
    }

    $logId = ers_group1_insert_sync_log($pdo, 'pending', $endpoint, $payload, $record);
    $result = ers_group1_post_json($endpoint, $payload, $input);
    $sent = $result['http_code'] >= 200 && $result['http_code'] < 300;

    ers_group1_update_sync_log(
        $pdo,
        $logId,
        $sent ? 'sent' : 'failed',
        $result['response_body'],
        $sent ? null : ('HTTP ' . $result['http_code'] . ($result['error'] !== '' ? ': ' . $result['error'] : ''))
    );

    ers_external_json($sent ? 200 : 502, [
        'success' => $sent,
        'message' => $sent ? 'Incident data sent to Group 1.' : 'Group 1 endpoint returned an error.',
        'sync_log_id' => $logId,
        'target_endpoint' => $endpoint,
        'http_code' => $result['http_code'],
        'payload' => $payload,
        'group1_response' => ers_group1_decode_response($result['response_body']),
        'error' => $result['error'] !== '' ? $result['error'] : null,
    ]);
} catch (Throwable $e) {
    error_log('send_incident.api.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to send incident data',
    ]);
}

function ers_group1_load_incident_payload_source(PDO $pdo, int $callId, int $incidentId): ?array
{
    if ($callId > 0) {
        $stmt = $pdo->prepare(
            "SELECT
                c.id AS call_id,
                c.reference_no AS call_reference_no,
                COALESCE(c.received_at, c.created_at) AS call_timestamp,
                c.location_address AS caller_location,
                c.latitude,
                c.longitude,
                c.priority AS emergency_level,
                c.description AS incident_description,
                i.id AS incident_id,
                i.reference_no AS incident_reference_no
             FROM calls c
             LEFT JOIN incidents i ON i.reported_by_call_id = c.id
             WHERE c.id = ?
             LIMIT 1"
        );
        $stmt->execute([$callId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    $stmt = $pdo->prepare(
        "SELECT
            c.id AS call_id,
            c.reference_no AS call_reference_no,
            COALESCE(c.received_at, c.created_at, i.created_at) AS call_timestamp,
            COALESCE(c.location_address, i.location_address) AS caller_location,
            COALESCE(c.latitude, i.latitude) AS latitude,
            COALESCE(c.longitude, i.longitude) AS longitude,
            COALESCE(c.priority, i.priority) AS emergency_level,
            COALESCE(c.description, i.description) AS incident_description,
            i.id AS incident_id,
            i.reference_no AS incident_reference_no
         FROM incidents i
         LEFT JOIN calls c ON c.id = i.reported_by_call_id
         WHERE i.id = ?
         LIMIT 1"
    );
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ers_group1_build_payload(array $record): array
{
    return [
        'call_id' => (int)($record['call_id'] ?? 0),
        'timestamp' => (string)($record['call_timestamp'] ?? ''),
        'caller_location' => (string)($record['caller_location'] ?? ''),
        'emergency_level' => (string)($record['emergency_level'] ?? ''),
        'incident_description' => (string)($record['incident_description'] ?? ''),
        'latitude' => isset($record['latitude']) && $record['latitude'] !== null ? (float)$record['latitude'] : null,
        'longitude' => isset($record['longitude']) && $record['longitude'] !== null ? (float)$record['longitude'] : null,
        'incident_id' => isset($record['incident_id']) && $record['incident_id'] !== null ? (int)$record['incident_id'] : null,
        'reference_no' => (string)($record['incident_reference_no'] ?? $record['call_reference_no'] ?? ''),
        'source_system' => 'ERS',
    ];
}

function ers_group1_post_json(string $endpoint, array $payload, array $input): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to encode payload');
    }

    $apiKey = ers_external_clean(
        $input['group1_api_key']
            ?? $input['target_api_key']
            ?? ers_env('GROUP1_API_KEY', ''),
        500
    );
    $apiKeyHeader = ers_external_clean(
        $input['group1_api_key_header']
            ?? $input['target_api_key_header']
            ?? ers_env('GROUP1_API_KEY_HEADER', 'X-API-Key'),
        80
    );

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-ERS-Client: ERS',
    ];
    if ($apiKey !== '') {
        if (strtolower($apiKeyHeader) === 'authorization') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        } else {
            $headers[] = $apiKeyHeader . ': ' . $apiKey;
        }
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize HTTP client');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'response_body' => is_string($response) ? $response : '',
            'error' => $error ?: '',
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($endpoint, false, $context);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $httpCode = (int)$match[1];
                break;
            }
        }
    }

    return [
        'http_code' => $httpCode,
        'response_body' => is_string($response) ? $response : '',
        'error' => $response === false ? 'HTTP request failed' : '',
    ];
}

function ers_group1_decode_response(string $responseBody)
{
    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : $responseBody;
}

function ers_group1_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function ers_group1_ensure_sync_log_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `api_sync_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `direction` ENUM('incoming','outgoing') NOT NULL,
            `target_group` VARCHAR(100) DEFAULT NULL,
            `endpoint_name` VARCHAR(150) DEFAULT NULL,
            `entity_type` VARCHAR(100) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `status` ENUM('pending','sent','received','failed') NOT NULL DEFAULT 'pending',
            `request_payload` LONGTEXT DEFAULT NULL,
            `response_payload` LONGTEXT DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_api_sync_entity` (`entity_type`, `entity_id`),
            KEY `idx_api_sync_status` (`status`),
            KEY `idx_api_sync_group` (`target_group`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ers_group1_insert_sync_log(PDO $pdo, string $status, string $endpoint, array $payload, array $record): int
{
    $payloadForLog = $payload;
    $payloadForLog['_target_endpoint'] = $endpoint;
    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, created_at, updated_at)
         VALUES
            ('outgoing', 'Group 1', 'send_incident.api', 'call', ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        (int)($record['call_id'] ?? 0),
        $status,
        json_encode($payloadForLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function ers_group1_update_sync_log(PDO $pdo, int $logId, string $status, string $responsePayload, ?string $errorMessage): void
{
    if ($logId <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        "UPDATE api_sync_logs
         SET status = ?, response_payload = ?, error_message = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$status, $responsePayload, $errorMessage, $logId]);
}
?>
