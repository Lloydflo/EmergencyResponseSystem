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
    ers_group2_ensure_sync_log_table($pdo);

    $module = ers_group2_normalize_module($input['module'] ?? $input['type'] ?? '');
    if ($module === '' && ers_group2_looks_like_incoming_road_condition($input)) {
        $module = 'receive_road_condition';
    }

    if ($module === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Provide module',
            'allowed_modules' => ['road_condition', 'vehicle_routing', 'receive_road_condition'],
        ]);
    }

    if ($module === 'receive_road_condition') {
        $received = ers_group2_receive_road_condition($pdo, $input);
        ers_external_json(201, [
            'success' => true,
            'message' => 'Road condition update received from Group 2.',
            'module' => $module,
            'data' => $received,
        ]);
    }

    if ($module === 'road_condition') {
        $prepared = ers_group2_prepare_road_condition($pdo, $input);
    } else {
        $prepared = ers_group2_prepare_vehicle_routing($pdo, $input);
    }

    $payload = $prepared['payload'];
    $endpoint = ers_group2_resolve_endpoint($module, $input);

    $dryRun = ers_group2_truthy($input['dry_run'] ?? $input['dryRun'] ?? false);
    if ($dryRun) {
        ers_external_json(200, [
            'success' => true,
            'dry_run' => true,
            'message' => 'Payload prepared. No data was sent.',
            'module' => $module,
            'payload' => $payload,
        ]);
    }

    if ($endpoint === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Missing Group 2 endpoint',
            'hint' => $module === 'road_condition'
                ? 'Send group2_endpoint in the JSON body or set GROUP2_ROAD_ENDPOINT in .env.'
                : 'Send group2_endpoint in the JSON body or set GROUP2_ROUTING_ENDPOINT in .env.',
            'module' => $module,
            'payload' => $payload,
        ]);
    }

    if (!preg_match('/^https?:\/\//i', $endpoint)) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Group 2 endpoint must start with http:// or https://',
        ]);
    }

    $logId = ers_group2_insert_sync_log($pdo, 'pending', $endpoint, $payload, $prepared);
    $result = ers_group2_post_json($endpoint, $payload, $input);
    $sent = $result['http_code'] >= 200 && $result['http_code'] < 300;

    ers_group2_update_sync_log(
        $pdo,
        $logId,
        $sent ? 'sent' : 'failed',
        $result['response_body'],
        $sent ? null : ('HTTP ' . $result['http_code'] . ($result['error'] !== '' ? ': ' . $result['error'] : ''))
    );

    ers_external_json($sent ? 200 : 502, [
        'success' => $sent,
        'message' => $sent ? 'Group 2 data sent.' : 'Group 2 endpoint returned an error.',
        'module' => $module,
        'sync_log_id' => $logId,
        'target_endpoint' => $endpoint,
        'http_code' => $result['http_code'],
        'payload' => $payload,
        'group2_response' => ers_group2_decode_response($result['response_body']),
        'error' => $result['error'] !== '' ? $result['error'] : null,
    ]);
} catch (Throwable $e) {
    error_log('send_route.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to send Group 2 data',
    ]);
}

function ers_group2_normalize_module($value): string
{
    $module = strtolower(trim((string)$value));
    $module = str_replace(['-', ' '], '_', $module);

    if (in_array($module, ['road', 'road_condition', 'road_conditions', 'incident_road_feed', 'incident_feed'], true)) {
        return 'road_condition';
    }

    if (in_array($module, ['receive_road_condition', 'road_condition_update', 'road_condition_updates', 'incoming_road_condition', 'from_group2'], true)) {
        return 'receive_road_condition';
    }

    if (in_array($module, ['route', 'routing', 'vehicle_route', 'vehicle_routing', 'diversion', 'vehicle_routing_and_diversion'], true)) {
        return 'vehicle_routing';
    }

    return '';
}

function ers_group2_looks_like_incoming_road_condition(array $input): bool
{
    return isset($input['route_id'])
        || isset($input['routeId'])
        || isset($input['congestion_level'])
        || isset($input['congestionLevel'])
        || isset($input['road_blockages'])
        || isset($input['roadBlockages'])
        || isset($input['incident_obstructions'])
        || isset($input['incidentObstructions']);
}

function ers_group2_receive_road_condition(PDO $pdo, array $input): array
{
    ers_group2_ensure_road_condition_table($pdo);

    $routeId = ers_external_clean($input['route_id'] ?? $input['routeId'] ?? '', 120);
    if ($routeId === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'route_id is required',
        ]);
    }

    $congestionLevel = ers_external_clean($input['congestion_level'] ?? $input['congestionLevel'] ?? '', 50);
    $roadBlockages = ers_external_clean($input['road_blockages'] ?? $input['roadBlockages'] ?? '', 0);
    $incidentObstructions = ers_external_clean($input['incident_obstructions'] ?? $input['incidentObstructions'] ?? '', 0);
    $status = ers_external_clean($input['status'] ?? 'active', 50);
    $sourceSystem = ers_external_clean($input['source_system'] ?? $input['sourceSystem'] ?? 'Group 2', 120);
    $rawPayload = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        $rawPayload = '{}';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO road_condition_updates
            (route_id, congestion_level, road_blockages, incident_obstructions, status, source_system, received_at, raw_payload)
         VALUES
            (?, ?, ?, ?, ?, ?, NOW(), ?)"
    );
    $stmt->execute([
        $routeId,
        $congestionLevel !== '' ? $congestionLevel : null,
        $roadBlockages !== '' ? $roadBlockages : null,
        $incidentObstructions !== '' ? $incidentObstructions : null,
        $status !== '' ? $status : 'active',
        $sourceSystem !== '' ? $sourceSystem : 'Group 2',
        $rawPayload,
    ]);

    $id = (int)$pdo->lastInsertId();
    ers_group2_insert_incoming_sync_log($pdo, $id, $input);

    return [
        'id' => $id,
        'route_id' => $routeId,
        'congestion_level' => $congestionLevel,
        'road_blockages' => $roadBlockages,
        'incident_obstructions' => $incidentObstructions,
        'status' => $status !== '' ? $status : 'active',
        'source_system' => $sourceSystem !== '' ? $sourceSystem : 'Group 2',
    ];
}

function ers_group2_ensure_road_condition_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `road_condition_updates` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `route_id` VARCHAR(120) NOT NULL,
            `congestion_level` VARCHAR(50) DEFAULT NULL,
            `road_blockages` TEXT DEFAULT NULL,
            `incident_obstructions` TEXT DEFAULT NULL,
            `status` VARCHAR(50) DEFAULT 'active',
            `source_system` VARCHAR(120) DEFAULT 'Group 2',
            `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `raw_payload` LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_road_route_id` (`route_id`),
            KEY `idx_road_received_at` (`received_at`),
            KEY `idx_road_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'congestion_level' => "ALTER TABLE `road_condition_updates` ADD COLUMN `congestion_level` VARCHAR(50) DEFAULT NULL AFTER `route_id`",
        'road_blockages' => "ALTER TABLE `road_condition_updates` ADD COLUMN `road_blockages` TEXT DEFAULT NULL AFTER `congestion_level`",
        'incident_obstructions' => "ALTER TABLE `road_condition_updates` ADD COLUMN `incident_obstructions` TEXT DEFAULT NULL AFTER `road_blockages`",
        'status' => "ALTER TABLE `road_condition_updates` ADD COLUMN `status` VARCHAR(50) DEFAULT 'active' AFTER `incident_obstructions`",
        'source_system' => "ALTER TABLE `road_condition_updates` ADD COLUMN `source_system` VARCHAR(120) DEFAULT 'Group 2' AFTER `status`",
        'received_at' => "ALTER TABLE `road_condition_updates` ADD COLUMN `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `source_system`",
        'raw_payload' => "ALTER TABLE `road_condition_updates` ADD COLUMN `raw_payload` LONGTEXT DEFAULT NULL AFTER `received_at`",
    ];

    foreach ($columns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'road_condition_updates', $column)) {
            $pdo->exec($sql);
        }
    }
}

function ers_group2_prepare_road_condition(PDO $pdo, array $input): array
{
    $incidentId = (int)($input['incident_id'] ?? $input['incidentId'] ?? 0);
    $dispatchId = (int)($input['dispatch_id'] ?? $input['dispatchId'] ?? 0);

    if ($incidentId <= 0 && $dispatchId > 0) {
        $incidentId = ers_group2_incident_id_from_dispatch($pdo, $dispatchId);
    }

    if ($incidentId <= 0) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Provide incident_id for road_condition module',
        ]);
    }

    $incident = ers_group2_load_incident($pdo, $incidentId);
    if (!$incident) {
        ers_external_json(404, [
            'success' => false,
            'error' => 'Incident not found',
        ]);
    }

    return [
        'module' => 'road_condition',
        'entity_type' => 'incident',
        'entity_id' => $incidentId,
        'payload' => [
            'module' => 'road_condition',
            'incident_id' => (int)($incident['id'] ?? 0),
            'location' => (string)($incident['location_address'] ?? ''),
            'incident_type' => (string)($incident['type'] ?? ''),
            'latitude' => isset($incident['latitude']) && $incident['latitude'] !== null ? (float)$incident['latitude'] : null,
            'longitude' => isset($incident['longitude']) && $incident['longitude'] !== null ? (float)$incident['longitude'] : null,
            'reference_no' => (string)($incident['reference_no'] ?? ''),
            'status' => (string)($incident['status'] ?? ''),
            'timestamp' => (string)($incident['updated_at'] ?? $incident['created_at'] ?? ''),
            'source_system' => 'ERS',
        ],
    ];
}

function ers_group2_prepare_vehicle_routing(PDO $pdo, array $input): array
{
    $dispatchId = (int)($input['dispatch_id'] ?? $input['dispatchId'] ?? 0);
    if ($dispatchId <= 0) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Provide dispatch_id for vehicle_routing module',
        ]);
    }

    $dispatch = ers_group2_load_dispatch_route_source($pdo, $dispatchId);
    if (!$dispatch) {
        ers_external_json(404, [
            'success' => false,
            'error' => 'Dispatch not found',
        ]);
    }

    $incidentId = (int)($dispatch['incident_id'] ?? 0);
    $unitCount = ers_group2_dispatch_unit_count($pdo, $incidentId);
    $recommendedRouteRequest = $input['recommended_route_request']
        ?? $input['recommendedRouteRequest']
        ?? true;

    return [
        'module' => 'vehicle_routing',
        'entity_type' => 'dispatch',
        'entity_id' => $dispatchId,
        'payload' => [
            'module' => 'vehicle_routing',
            'dispatch_id' => $dispatchId,
            'incident_id' => $incidentId,
            'emergency_type' => (string)($dispatch['emergency_type'] ?? ''),
            'target_destination' => (string)($dispatch['target_destination'] ?? ''),
            'target_latitude' => isset($dispatch['target_latitude']) && $dispatch['target_latitude'] !== null ? (float)$dispatch['target_latitude'] : null,
            'target_longitude' => isset($dispatch['target_longitude']) && $dispatch['target_longitude'] !== null ? (float)$dispatch['target_longitude'] : null,
            'dispatch_unit_count' => $unitCount,
            'recommended_route_request' => $recommendedRouteRequest,
            'unit_id' => isset($dispatch['unit_id']) && $dispatch['unit_id'] !== null ? (int)$dispatch['unit_id'] : null,
            'unit_identifier' => (string)($dispatch['unit_identifier'] ?? ''),
            'unit_type' => (string)($dispatch['unit_type'] ?? ''),
            'reference_no' => (string)($dispatch['reference_no'] ?? ''),
            'timestamp' => (string)($dispatch['assigned_at'] ?? ''),
            'source_system' => 'ERS',
        ],
    ];
}

function ers_group2_resolve_endpoint(string $module, array $input): string
{
    $fallbackEnv = $module === 'road_condition'
        ? (ers_env('GROUP2_ROAD_ENDPOINT', '') ?: ers_env('GROUP2_ENDPOINT', ''))
        : (ers_env('GROUP2_ROUTING_ENDPOINT', '') ?: ers_env('GROUP2_ROUTE_ENDPOINT', '') ?: ers_env('GROUP2_ENDPOINT', ''));

    return ers_external_clean(
        $input['group2_endpoint']
            ?? $input['endpoint_url']
            ?? $input['target_url']
            ?? $fallbackEnv,
        500
    );
}

function ers_group2_load_incident(PDO $pdo, int $incidentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            id,
            reference_no,
            type,
            status,
            location_address,
            latitude,
            longitude,
            created_at,
            updated_at
         FROM incidents
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ers_group2_load_dispatch_route_source(PDO $pdo, int $dispatchId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            d.id AS dispatch_id,
            d.incident_id,
            d.unit_id,
            d.assigned_at,
            i.reference_no,
            i.type AS emergency_type,
            i.location_address AS target_destination,
            i.latitude AS target_latitude,
            i.longitude AS target_longitude,
            u.identifier AS unit_identifier,
            u.unit_type
         FROM dispatches d
         LEFT JOIN incidents i ON i.id = d.incident_id
         LEFT JOIN units u ON u.id = d.unit_id
         WHERE d.id = ?
         LIMIT 1"
    );
    $stmt->execute([$dispatchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ers_group2_incident_id_from_dispatch(PDO $pdo, int $dispatchId): int
{
    $stmt = $pdo->prepare('SELECT incident_id FROM dispatches WHERE id = ? LIMIT 1');
    $stmt->execute([$dispatchId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function ers_group2_dispatch_unit_count(PDO $pdo, int $incidentId): int
{
    if ($incidentId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM dispatches WHERE incident_id = ?');
    $stmt->execute([$incidentId]);
    return (int)$stmt->fetchColumn();
}

function ers_group2_post_json(string $endpoint, array $payload, array $input): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to encode payload');
    }

    $apiKey = ers_external_clean(
        $input['group2_api_key']
            ?? $input['target_api_key']
            ?? ers_env('GROUP2_API_KEY', ''),
        500
    );
    $apiKeyHeader = ers_external_clean(
        $input['group2_api_key_header']
            ?? $input['target_api_key_header']
            ?? ers_env('GROUP2_API_KEY_HEADER', 'X-API-Key'),
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

function ers_group2_decode_response(string $responseBody)
{
    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : $responseBody;
}

function ers_group2_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function ers_group2_ensure_sync_log_table(PDO $pdo): void
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

function ers_group2_insert_sync_log(PDO $pdo, string $status, string $endpoint, array $payload, array $prepared): int
{
    $payloadForLog = $payload;
    $payloadForLog['_target_endpoint'] = $endpoint;
    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, created_at, updated_at)
         VALUES
            ('outgoing', 'Group 2', ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        'send_route:' . (string)($prepared['module'] ?? ''),
        (string)($prepared['entity_type'] ?? ''),
        (int)($prepared['entity_id'] ?? 0),
        $status,
        json_encode($payloadForLog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function ers_group2_insert_incoming_sync_log(PDO $pdo, int $roadConditionId, array $payload): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, created_at, updated_at)
         VALUES
            ('incoming', 'Group 2', 'send_route:receive_road_condition', 'road_condition_update', ?, 'received', ?, NOW(), NOW())"
    );
    $stmt->execute([
        $roadConditionId,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function ers_group2_update_sync_log(PDO $pdo, int $logId, string $status, string $responsePayload, ?string $errorMessage): void
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
