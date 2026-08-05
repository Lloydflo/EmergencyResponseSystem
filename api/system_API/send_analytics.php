<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

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
    ers_analytics_ensure_sync_log_table($pdo);

    $payload = ers_analytics_build_payload($pdo, $input);
    $endpoint = ers_external_clean(
        $input['group5_endpoint']
            ?? $input['endpoint_url']
            ?? $input['target_url']
            ?? ers_env('GROUP5_ANALYTICS_ENDPOINT', ''),
        500
    );

    $dryRun = ers_analytics_truthy($input['dry_run'] ?? $input['dryRun'] ?? false);
    if ($dryRun) {
        ers_external_json(200, [
            'success' => true,
            'dry_run' => true,
            'message' => 'Analytics payload prepared. No data was sent.',
            'payload' => $payload,
        ]);
    }

    if ($endpoint === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Missing Group 5 endpoint',
            'hint' => 'Send group5_endpoint in the JSON body or set GROUP5_ANALYTICS_ENDPOINT in .env.',
            'payload' => $payload,
        ]);
    }

    if (!preg_match('/^https?:\/\//i', $endpoint)) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Group 5 endpoint must start with http:// or https://',
        ]);
    }

    $entityId = ers_analytics_entity_id($payload);
    $logId = ers_analytics_insert_sync_log($pdo, 'pending', $endpoint, $payload, $entityId);
    $result = ers_analytics_post_json($endpoint, $payload, $input);
    $sent = $result['http_code'] >= 200 && $result['http_code'] < 300;

    ers_analytics_update_sync_log(
        $pdo,
        $logId,
        $sent ? 'sent' : 'failed',
        $result['response_body'],
        $sent ? null : ('HTTP ' . $result['http_code'] . ($result['error'] !== '' ? ': ' . $result['error'] : ''))
    );

    ers_external_json($sent ? 200 : 502, [
        'success' => $sent,
        'message' => $sent ? 'Analytics data sent to Group 5.' : 'Group 5 endpoint returned an error.',
        'sync_log_id' => $logId,
        'target_endpoint' => $endpoint,
        'http_code' => $result['http_code'],
        'payload' => $payload,
        'group5_response' => ers_analytics_decode_response($result['response_body']),
        'error' => $result['error'] !== '' ? $result['error'] : null,
    ]);
} catch (Throwable $e) {
    error_log('send_analytics.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to send analytics data',
    ]);
}

function ers_analytics_build_payload(PDO $pdo, array $input): array
{
    $dispatchId = (int)($input['dispatch_id'] ?? $input['dispatchId'] ?? 0);
    $incidentId = (int)($input['incident_id'] ?? $input['incidentId'] ?? 0);

    if ($dispatchId > 0) {
        $row = ers_analytics_load_dispatch_row($pdo, $dispatchId);
        if (!$row) {
            ers_external_json(404, [
                'success' => false,
                'error' => 'Dispatch not found',
            ]);
        }
        return ers_analytics_row_to_item($row);
    }

    if ($incidentId > 0) {
        $rows = ers_analytics_load_incident_rows($pdo, $incidentId);
        if ($rows === []) {
            ers_external_json(404, [
                'success' => false,
                'error' => 'Incident dispatch analytics not found',
            ]);
        }

        if (count($rows) === 1) {
            return ers_analytics_row_to_item($rows[0]);
        }

        return [
            'source_system' => 'ERS',
            'module' => 'response_time_analytics',
            'incident_id' => $incidentId,
            'items' => array_map('ers_analytics_row_to_item', $rows),
        ];
    }

    $limit = max(1, min(100, (int)($input['limit'] ?? 25)));
    $status = ers_external_clean($input['status'] ?? '', 50);
    $rows = ers_analytics_load_recent_rows($pdo, $limit, $status);

    return [
        'source_system' => 'ERS',
        'module' => 'response_time_analytics',
        'items' => array_map('ers_analytics_row_to_item', $rows),
    ];
}

function ers_analytics_load_dispatch_row(PDO $pdo, int $dispatchId): ?array
{
    $stmt = $pdo->prepare(ers_analytics_base_sql('WHERE d.id = ? LIMIT 1'));
    $stmt->execute([$dispatchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ers_analytics_load_incident_rows(PDO $pdo, int $incidentId): array
{
    $stmt = $pdo->prepare(ers_analytics_base_sql('WHERE i.id = ? ORDER BY d.assigned_at DESC, d.id DESC'));
    $stmt->execute([$incidentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ers_analytics_load_recent_rows(PDO $pdo, int $limit, string $status): array
{
    $where = '';
    $params = [];
    if ($status !== '') {
        $where = 'WHERE i.status = ?';
        $params[] = $status;
    }

    $stmt = $pdo->prepare(ers_analytics_base_sql($where . ' ORDER BY d.assigned_at DESC, d.id DESC LIMIT ' . $limit));
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ers_analytics_base_sql(string $suffix): string
{
    return "
        SELECT
            d.id AS dispatch_id,
            i.id AS incident_id,
            i.reference_no,
            d.assigned_at AS dispatch_timestamp,
            d.on_scene_at AS arrival_timestamp,
            d.cleared_at,
            d.status AS dispatch_status,
            i.status AS incident_status,
            i.resolved_at,
            CASE
                WHEN d.assigned_at IS NOT NULL AND d.on_scene_at IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, d.assigned_at, d.on_scene_at)
                ELSE NULL
            END AS total_response_time_min,
            CASE
                WHEN d.assigned_at IS NOT NULL AND COALESCE(i.resolved_at, d.cleared_at) IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(i.resolved_at, d.cleared_at))
                ELSE NULL
            END AS duration_min
         FROM dispatches d
         INNER JOIN incidents i ON i.reference_no = d.reference_no
         {$suffix}
    ";
}

function ers_analytics_row_to_item(array $row): array
{
    return [
        'source_system' => 'ERS',
        'module' => 'response_time_analytics',
        'incident_id' => isset($row['incident_id']) ? (int)$row['incident_id'] : null,
        'reference_no' => (string)($row['reference_no'] ?? ''),
        'dispatch_id' => isset($row['dispatch_id']) ? (int)$row['dispatch_id'] : null,
        'dispatch_timestamp' => (string)($row['dispatch_timestamp'] ?? ''),
        'arrival_timestamp' => $row['arrival_timestamp'] ?? null,
        'total_response_time' => isset($row['total_response_time_min']) && $row['total_response_time_min'] !== null
            ? (int)$row['total_response_time_min']
            : null,
        'total_response_time_unit' => 'minutes',
        'duration' => isset($row['duration_min']) && $row['duration_min'] !== null ? (int)$row['duration_min'] : null,
        'duration_unit' => 'minutes',
        'status' => (string)($row['incident_status'] ?? $row['dispatch_status'] ?? ''),
        'dispatch_status' => (string)($row['dispatch_status'] ?? ''),
    ];
}

function ers_analytics_entity_id(array $payload): int
{
    if (isset($payload['dispatch_id'])) {
        return (int)$payload['dispatch_id'];
    }
    if (isset($payload['incident_id'])) {
        return (int)$payload['incident_id'];
    }
    return 0;
}

function ers_analytics_post_json(string $endpoint, array $payload, array $input): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to encode payload');
    }

    $apiKey = ers_external_clean(
        $input['group5_api_key']
            ?? $input['target_api_key']
            ?? ers_env('GROUP5_API_KEY', ''),
        500
    );
    $apiKeyHeader = ers_external_clean(
        $input['group5_api_key_header']
            ?? $input['target_api_key_header']
            ?? ers_env('GROUP5_API_KEY_HEADER', 'X-API-Key'),
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

function ers_analytics_decode_response(string $responseBody)
{
    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : $responseBody;
}

function ers_analytics_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function ers_analytics_ensure_sync_log_table(PDO $pdo): void
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

function ers_analytics_insert_sync_log(PDO $pdo, string $status, string $endpoint, array $payload, int $entityId): int
{
    $payloadForLog = $payload;
    $payloadForLog['_target_endpoint'] = $endpoint;
    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, created_at, updated_at)
         VALUES
            ('outgoing', 'Group 5', 'send_analytics', 'response_time_analytics', ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $entityId,
        $status,
        json_encode($payloadForLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function ers_analytics_update_sync_log(PDO $pdo, int $logId, string $status, string $responsePayload, ?string $errorMessage): void
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
