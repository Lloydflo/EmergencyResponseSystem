<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

function ers_group1_send_logged_incident(PDO $pdo, int $callId, int $incidentId = 0, array $options = []): array
{
    try {
        ers_group1_ensure_sync_log_table_safe($pdo);

        if ($callId <= 0 && $incidentId <= 0) {
            return ers_group1_sync_result(false, 'invalid_request', 'Provide call_id or incident_id.');
        }

        $record = ers_group1_load_logged_incident($pdo, $callId, $incidentId);
        if (!$record) {
            return ers_group1_sync_result(false, 'not_found', 'Call or incident not found.');
        }

        $payload = ers_group1_logged_incident_payload($record);
        $endpoint = ers_external_clean(
            $options['group1_endpoint']
                ?? $options['endpoint_url']
                ?? $options['target_url']
                ?? ers_env('GROUP1_INCIDENT_ENDPOINT', ''),
            500
        );

        if (ers_group1_sync_truthy($options['dry_run'] ?? $options['dryRun'] ?? false)) {
            return [
                'success' => true,
                'status' => 'dry_run',
                'message' => 'Payload prepared. No data was sent.',
                'payload' => $payload,
            ];
        }

        if ($endpoint === '') {
            $logId = ers_group1_log_sync_safe($pdo, 'failed', '', $payload, $record, null, 'Missing Group 1 endpoint.');
            return ers_group1_sync_result(false, 'missing_endpoint', 'Missing Group 1 endpoint.', $payload, $logId);
        }

        if (!preg_match('/^https?:\/\//i', $endpoint)) {
            $logId = ers_group1_log_sync_safe($pdo, 'failed', $endpoint, $payload, $record, null, 'Invalid Group 1 endpoint URL.');
            return ers_group1_sync_result(false, 'invalid_endpoint', 'Group 1 endpoint must start with http:// or https://.', $payload, $logId, $endpoint);
        }

        $logId = ers_group1_log_sync_safe($pdo, 'pending', $endpoint, $payload, $record);
        $httpResult = ers_group1_post_logged_incident($endpoint, $payload, $options);
        $sent = $httpResult['http_code'] >= 200 && $httpResult['http_code'] < 300;
        $error = $sent ? null : ('HTTP ' . $httpResult['http_code'] . ($httpResult['error'] !== '' ? ': ' . $httpResult['error'] : ''));

        ers_group1_update_sync_log_safe(
            $pdo,
            $logId,
            $sent ? 'sent' : 'failed',
            $httpResult['response_body'],
            $error
        );

        return [
            'success' => $sent,
            'status' => $sent ? 'sent' : 'failed',
            'message' => $sent ? 'Incident data sent to Group 1.' : 'Group 1 endpoint returned an error.',
            'sync_log_id' => $logId,
            'target_endpoint' => $endpoint,
            'http_code' => $httpResult['http_code'],
            'payload' => $payload,
            'group1_response' => ers_group1_decode_logged_response($httpResult['response_body']),
            'error' => $httpResult['error'] !== '' ? $httpResult['error'] : $error,
        ];
    } catch (Throwable $e) {
        error_log('Group 1 incident sync failed: ' . $e->getMessage());
        return ers_group1_sync_result(false, 'failed', 'Unable to send incident data.');
    }
}

function ers_group1_sync_result(
    bool $success,
    string $status,
    string $message,
    array $payload = [],
    ?int $logId = null,
    ?string $endpoint = null
): array {
    $result = [
        'success' => $success,
        'status' => $status,
        'message' => $message,
    ];
    if ($logId !== null) {
        $result['sync_log_id'] = $logId;
    }
    if ($endpoint !== null) {
        $result['target_endpoint'] = $endpoint;
    }
    if ($payload !== []) {
        $result['payload'] = $payload;
    }
    return $result;
}

function ers_group1_load_logged_incident(PDO $pdo, int $callId, int $incidentId): ?array
{
    if ($callId > 0) {
        $stmt = $pdo->prepare(
            "SELECT
                c.id AS call_id,
                COALESCE(c.received_at, c.created_at) AS call_timestamp,
                c.location_address AS caller_location,
                c.priority AS emergency_level,
                c.description AS incident_description,
                i.id AS incident_id
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
            COALESCE(c.received_at, c.created_at, i.created_at) AS call_timestamp,
            COALESCE(c.location_address, i.location_address) AS caller_location,
            COALESCE(c.priority, i.priority) AS emergency_level,
            COALESCE(c.description, i.description) AS incident_description,
            i.id AS incident_id
         FROM incidents i
         LEFT JOIN calls c ON c.id = i.reported_by_call_id
         WHERE i.id = ?
         LIMIT 1"
    );
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ers_group1_logged_incident_payload(array $record): array
{
    return [
        'call_id' => (int)($record['call_id'] ?? 0),
        'timestamp' => (string)($record['call_timestamp'] ?? ''),
        'caller_location' => (string)($record['caller_location'] ?? ''),
        'emergency_level' => (string)($record['emergency_level'] ?? ''),
        'incident_description' => (string)($record['incident_description'] ?? ''),
    ];
}

function ers_group1_post_logged_incident(string $endpoint, array $payload, array $options): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to encode payload.');
    }

    $apiKey = ers_external_clean(
        $options['group1_api_key']
            ?? $options['target_api_key']
            ?? ers_env('GROUP1_API_KEY', ''),
        500
    );
    $apiKeyHeader = ers_external_clean(
        $options['group1_api_key_header']
            ?? $options['target_api_key_header']
            ?? ers_env('GROUP1_API_KEY_HEADER', 'X-API-Key'),
        80
    );

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-ERS-Client: ERS',
    ];
    if ($apiKey !== '') {
        $headers[] = strtolower($apiKeyHeader) === 'authorization'
            ? 'Authorization: Bearer ' . $apiKey
            : $apiKeyHeader . ': ' . $apiKey;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize HTTP client.');
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

function ers_group1_decode_logged_response(string $responseBody)
{
    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : $responseBody;
}

function ers_group1_sync_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function ers_group1_ensure_sync_log_table_safe(PDO $pdo): void
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

    $columns = [
        'direction' => "ALTER TABLE `api_sync_logs` ADD COLUMN `direction` ENUM('incoming','outgoing') NOT NULL DEFAULT 'incoming' AFTER `id`",
        'target_group' => "ALTER TABLE `api_sync_logs` ADD COLUMN `target_group` VARCHAR(100) DEFAULT NULL AFTER `direction`",
        'endpoint_name' => "ALTER TABLE `api_sync_logs` ADD COLUMN `endpoint_name` VARCHAR(150) DEFAULT NULL AFTER `target_group`",
        'entity_type' => "ALTER TABLE `api_sync_logs` ADD COLUMN `entity_type` VARCHAR(100) DEFAULT NULL AFTER `endpoint_name`",
        'entity_id' => "ALTER TABLE `api_sync_logs` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
        'status' => "ALTER TABLE `api_sync_logs` ADD COLUMN `status` ENUM('pending','sent','received','failed') NOT NULL DEFAULT 'pending' AFTER `entity_id`",
        'request_payload' => "ALTER TABLE `api_sync_logs` ADD COLUMN `request_payload` LONGTEXT DEFAULT NULL AFTER `status`",
        'response_payload' => "ALTER TABLE `api_sync_logs` ADD COLUMN `response_payload` LONGTEXT DEFAULT NULL AFTER `request_payload`",
        'error_message' => "ALTER TABLE `api_sync_logs` ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `response_payload`",
        'created_at' => "ALTER TABLE `api_sync_logs` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `error_message`",
        'updated_at' => "ALTER TABLE `api_sync_logs` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];

    foreach ($columns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'api_sync_logs', $column)) {
            $pdo->exec($sql);
        }
    }
}

function ers_group1_log_sync_safe(
    PDO $pdo,
    string $status,
    string $endpoint,
    array $payload,
    array $record,
    ?string $responsePayload = null,
    ?string $errorMessage = null
): int {
    $payloadForLog = $payload;
    if ($endpoint !== '') {
        $payloadForLog['_target_endpoint'] = $endpoint;
    }

    $entityId = (int)($record['call_id'] ?? 0);
    $entityType = 'call';
    if ($entityId <= 0) {
        $entityId = (int)($record['incident_id'] ?? 0);
        $entityType = 'incident';
    }

    $encodedPayload = json_encode($payloadForLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, response_payload, error_message, created_at, updated_at)
         VALUES
            ('outgoing', 'Group 1', 'send_incident', ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $entityType,
        $entityId > 0 ? $entityId : null,
        $status,
        is_string($encodedPayload) ? $encodedPayload : '{}',
        $responsePayload,
        $errorMessage,
    ]);
    return (int)$pdo->lastInsertId();
}

function ers_group1_update_sync_log_safe(PDO $pdo, int $logId, string $status, string $responsePayload, ?string $errorMessage): void
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
