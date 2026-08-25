<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

$pdo = ers_external_db();
$sessionAllowed = is_logged_in() && in_array(current_session_role(), ['admin', 'dispatcher'], true);
$externalAuth = null;

if (!$sessionAllowed) {
    $externalAuth = ers_external_authenticate();
}

try {
    ers_event_ensure_tables($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        ers_external_json(200, [
            'success' => true,
            'items' => ers_event_list($pdo),
        ]);
    }

    if ($method !== 'POST' && $method !== 'PATCH') {
        ers_external_json(405, [
            'success' => false,
            'error' => 'GET, POST, or PATCH method required',
        ]);
    }

    $input = ers_external_input();
    $action = strtolower(ers_external_clean($input['action'] ?? '', 40));

    if ($action === 'sync_alertara_campaigns') {
        $result = ers_event_sync_alertara_campaigns($pdo);
        ers_external_json(200, [
            'success' => true,
            'message' => 'Alertara campaigns synchronized.',
            'sync' => $result,
        ]);
    }

    if ($action === 'send' || !empty($input['send_to_group6'])) {
        $sent = ers_event_send_to_group6($pdo, $input);
        ers_external_json(200, [
            'success' => true,
            'message' => 'Event coordination sent.',
            'item' => $sent['item'],
            'group6_response' => $sent['response'],
        ]);
    }

    $item = ers_event_normalize($input, $externalAuth['client'] ?? null);
    if ($item['coordination_id'] === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'coordination_id is required',
        ]);
    }

    $saved = ers_event_save($pdo, $item);
    ers_event_log_sync($pdo, 'incoming', 'received', $saved['id'], $item, null, null);

    ers_external_json(201, [
        'success' => true,
        'message' => 'Event coordination data saved.',
        'item' => $saved,
    ]);
} catch (Throwable $e) {
    error_log('event_coordination.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to process event coordination data',
    ]);
}

function ers_event_normalize(array $input, ?string $externalClient = null): array
{
    $contacts = $input['emergency_contact_persons']
        ?? $input['emergencyContactPersons']
        ?? $input['contacts']
        ?? '';
    if (is_array($contacts)) {
        $contactsJson = json_encode($contacts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $contacts = is_string($contactsJson) ? $contactsJson : '';
    }

    $hazard = strtolower(ers_external_clean(
        $input['on_site_safety_hazard_level']
            ?? $input['onSiteSafetyHazardLevel']
            ?? $input['hazard_level']
            ?? $input['hazardLevel']
            ?? 'medium',
        40
    ));
    $hazard = str_replace([' ', '_'], '-', $hazard);
    $hazardAliases = [
        'very-high' => 'critical',
        'severe' => 'critical',
        'urgent' => 'high',
        'moderate' => 'medium',
    ];
    $hazard = $hazardAliases[$hazard] ?? $hazard;
    if (!in_array($hazard, ['low', 'medium', 'high', 'critical'], true)) {
        $hazard = 'medium';
    }

    $status = strtolower(ers_external_clean($input['status'] ?? 'active', 40));
    if (!in_array($status, ['draft', 'active', 'standby', 'completed', 'cancelled'], true)) {
        $status = 'active';
    }

    $eventSchedule = ers_event_normalize_datetime(
        $input['event_schedule']
            ?? $input['eventSchedule']
            ?? $input['event_datetime']
            ?? $input['eventDateTime']
            ?? $input['date_time']
            ?? $input['dateTime']
            ?? $input['schedule']
            ?? ''
    );

    $rawPayload = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        $rawPayload = '{}';
    }

    return [
        'id' => max(0, (int)($input['id'] ?? 0)),
        'coordination_id' => ers_external_clean(
            $input['coordination_id']
                ?? $input['coordinationId']
                ?? $input['coordinationID']
                ?? '',
            120
        ),
        'event_profile' => ers_external_clean(
            $input['event_profile']
                ?? $input['eventProfile']
                ?? $input['profile']
                ?? '',
            255
        ),
        'event_location' => ers_external_clean(
            $input['event_location']
                ?? $input['eventLocation']
                ?? $input['location_address']
                ?? $input['location']
                ?? $input['venue']
                ?? '',
            255
        ),
        'event_schedule' => $eventSchedule,
        'on_site_safety_hazard_level' => $hazard,
        'required_standby_responders' => max(0, (int)(
            $input['required_standby_responders']
                ?? $input['requiredStandbyResponders']
                ?? $input['responders_required']
                ?? 0
        )),
        'emergency_contact_persons' => trim((string)$contacts),
        'status' => $status,
        'source_system' => ers_external_clean(
            $input['source_system']
                ?? $input['sourceSystem']
                ?? $externalClient
                ?? 'ERS',
            120
        ),
        'raw_payload' => $rawPayload,
    ];
}

function ers_event_normalize_datetime($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $time = strtotime($raw);
    if ($time === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $time);
}

/**
 * Imports operational campaigns published by Alertara into Event Coordination.
 *
 * The upstream endpoint is a public feed, not a webhook.  Keeping the URL
 * fixed here prevents an authenticated user from using this endpoint as an
 * arbitrary server-side request proxy.
 */
function ers_event_sync_alertara_campaigns(PDO $pdo): array
{
    $endpoint = 'https://campaign.alertaraqc.com/api/v1/campaigns/public';
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError !== '' || $httpCode < 200 || $httpCode >= 300 || !is_string($raw)) {
        throw new RuntimeException('Alertara campaign feed is unavailable.');
    }

    $decoded = json_decode($raw, true);
    $campaigns = is_array($decoded) && is_array($decoded['campaigns'] ?? null) ? $decoded['campaigns'] : null;
    if ($campaigns === null) {
        throw new RuntimeException('Alertara campaign feed returned an invalid response.');
    }

    $result = ['received' => count($campaigns), 'created' => 0, 'updated' => 0, 'skipped' => 0];
    foreach ($campaigns as $campaign) {
        if (!is_array($campaign) || !isset($campaign['id'])) {
            $result['skipped']++;
            continue;
        }

        $sourceStatus = strtolower(trim((string)($campaign['status'] ?? '')));
        // Draft and archived campaigns are visible in the public feed but are
        // not operational events that responders should be asked to plan for.
        if (!in_array($sourceStatus, ['approved', 'scheduled', 'active'], true)) {
            $result['skipped']++;
            continue;
        }

        $coordinationId = 'ALERTARA-CAMPAIGN-' . (int)$campaign['id'];
        $existing = $pdo->prepare('SELECT id FROM interagency_event_profiles WHERE coordination_id = ? LIMIT 1');
        $existing->execute([$coordinationId]);
        $exists = (bool)$existing->fetchColumn();

        $item = ers_event_normalize([
            'coordination_id' => $coordinationId,
            'event_profile' => $campaign['title'] ?? ('Alertara campaign #' . $campaign['id']),
            'event_location' => $campaign['location'] ?? $campaign['geographic_scope'] ?? '',
            'event_schedule' => $campaign['start_date'] ?? '',
            'on_site_safety_hazard_level' => ers_event_alertara_hazard($campaign['category'] ?? ''),
            'required_standby_responders' => $campaign['staff_count'] ?? 0,
            'emergency_contact_persons' => '',
            'status' => $sourceStatus === 'active' ? 'active' : 'standby',
            'source_system' => 'Alertara Campaign API',
            'alertara_campaign' => $campaign,
        ], 'Alertara Campaign API');
        $saved = ers_event_save($pdo, $item);
        ers_event_log_sync($pdo, 'incoming', 'received', (int)($saved['id'] ?? 0), $item, null, null);
        $result[$exists ? 'updated' : 'created']++;
    }

    return $result;
}

function ers_event_alertara_hazard($category): string
{
    $category = strtolower(trim((string)$category));
    if (in_array($category, ['fire', 'earthquake', 'flood'], true)) {
        return 'high';
    }
    return 'medium';
}

function ers_event_save(PDO $pdo, array $item): array
{
    $existingId = 0;
    if ($item['id'] > 0) {
        $stmt = $pdo->prepare('SELECT id FROM interagency_event_profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$item['id']]);
        $existingId = (int)$stmt->fetchColumn();
    }

    if ($existingId <= 0) {
        $stmt = $pdo->prepare('SELECT id FROM interagency_event_profiles WHERE coordination_id = ? LIMIT 1');
        $stmt->execute([$item['coordination_id']]);
        $existingId = (int)$stmt->fetchColumn();
    }

    if ($existingId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE interagency_event_profiles
             SET event_profile = ?,
                 event_location = ?,
                 event_schedule = ?,
                 on_site_safety_hazard_level = ?,
                 required_standby_responders = ?,
                 emergency_contact_persons = ?,
                 status = ?,
                 source_system = ?,
                 raw_payload = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $item['event_profile'],
            $item['event_location'],
            $item['event_schedule'],
            $item['on_site_safety_hazard_level'],
            $item['required_standby_responders'],
            $item['emergency_contact_persons'],
            $item['status'],
            $item['source_system'],
            $item['raw_payload'],
            $existingId,
        ]);
        return ers_event_find($pdo, $existingId);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO interagency_event_profiles
            (coordination_id, event_profile, event_location, event_schedule, on_site_safety_hazard_level,
             required_standby_responders, emergency_contact_persons, status, source_system,
             received_at, updated_at, raw_payload)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)"
    );
    $stmt->execute([
        $item['coordination_id'],
        $item['event_profile'],
        $item['event_location'],
        $item['event_schedule'],
        $item['on_site_safety_hazard_level'],
        $item['required_standby_responders'],
        $item['emergency_contact_persons'],
        $item['status'],
        $item['source_system'],
        $item['raw_payload'],
    ]);

    return ers_event_find($pdo, (int)$pdo->lastInsertId());
}

function ers_event_find(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, coordination_id, event_profile, event_location, event_schedule, on_site_safety_hazard_level,
                required_standby_responders, emergency_contact_persons, status, source_system,
                received_at, updated_at
         FROM interagency_event_profiles
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function ers_event_list(PDO $pdo): array
{
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
    $status = strtolower(ers_external_clean($_GET['status'] ?? '', 40));
    $search = ers_external_clean($_GET['search'] ?? '', 120);

    $where = [];
    $params = [];
    if ($status !== '' && $status !== 'all') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(coordination_id LIKE ? OR event_profile LIKE ? OR source_system LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT id, coordination_id, event_profile, event_location, event_schedule, on_site_safety_hazard_level,
                   required_standby_responders, emergency_contact_persons, status, source_system,
                   received_at, updated_at
            FROM interagency_event_profiles";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY COALESCE(event_schedule, updated_at, received_at) DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ers_event_send_to_group6(PDO $pdo, array $input): array
{
    $endpoint = trim((string)(
        $input['group6_endpoint']
            ?? $input['group6Endpoint']
            ?? ers_env('GROUP6_EVENT_ENDPOINT', '')
    ));
    if ($endpoint === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Group 6 endpoint is required',
            'hint' => 'Send group6_endpoint or set GROUP6_EVENT_ENDPOINT.',
        ]);
    }

    $item = null;
    $id = max(0, (int)($input['id'] ?? 0));
    if ($id > 0) {
        $item = ers_event_find($pdo, $id);
    }

    if (!$item) {
        $normalized = ers_event_normalize($input, null);
        if ($normalized['coordination_id'] === '') {
            ers_external_json(422, [
                'success' => false,
                'error' => 'coordination_id is required',
            ]);
        }
        $item = ers_event_save($pdo, $normalized);
    }

    $payload = [
        'coordination_id' => $item['coordination_id'] ?? '',
        'event_profile' => $item['event_profile'] ?? '',
        'event_location' => $item['event_location'] ?? '',
        'event_schedule' => $item['event_schedule'] ?? null,
        'on_site_safety_hazard_level' => $item['on_site_safety_hazard_level'] ?? '',
        'required_standby_responders' => (int)($item['required_standby_responders'] ?? 0),
        'emergency_contact_persons' => $item['emergency_contact_persons'] ?? '',
        'status' => $item['status'] ?? '',
        'source_system' => 'ERS',
    ];

    $logId = ers_event_log_sync($pdo, 'outgoing', 'pending', (int)($item['id'] ?? 0), $payload, null, null);
    $response = ers_event_post_json($endpoint, $payload, trim((string)($input['group6_api_key'] ?? $input['group6ApiKey'] ?? ers_env('GROUP6_API_KEY', ''))));
    ers_event_update_sync_log($pdo, $logId, $response['ok'] ? 'sent' : 'failed', $response, $response['ok'] ? null : ($response['error'] ?? 'Send failed'));

    if (!$response['ok']) {
        ers_external_json(502, [
            'success' => false,
            'error' => 'Unable to send event coordination',
            'details' => $response,
        ]);
    }

    return [
        'item' => $item,
        'response' => $response,
    ];
}

function ers_event_post_json(string $endpoint, array $payload, string $apiKey = ''): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        $body = '{}';
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => $curlError === '' && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'body' => is_array($decoded) ? $decoded : $raw,
        'error' => $curlError !== '' ? $curlError : null,
    ];
}

function ers_event_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_event_profiles` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `coordination_id` VARCHAR(120) NOT NULL,
            `event_profile` VARCHAR(255) NOT NULL,
            `event_location` VARCHAR(255) DEFAULT NULL,
            `event_schedule` DATETIME DEFAULT NULL,
            `on_site_safety_hazard_level` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
            `required_standby_responders` INT UNSIGNED NOT NULL DEFAULT 0,
            `emergency_contact_persons` TEXT DEFAULT NULL,
            `status` ENUM('draft','active','standby','completed','cancelled') NOT NULL DEFAULT 'active',
            `source_system` VARCHAR(120) DEFAULT 'Group 6',
            `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `raw_payload` LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_event_coordination_id` (`coordination_id`),
            KEY `idx_event_schedule` (`event_schedule`),
            KEY `idx_event_hazard` (`on_site_safety_hazard_level`),
            KEY `idx_event_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'coordination_id' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `coordination_id` VARCHAR(120) DEFAULT NULL AFTER `id`",
        'event_profile' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `event_profile` VARCHAR(255) DEFAULT NULL AFTER `coordination_id`",
        'event_location' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `event_location` VARCHAR(255) DEFAULT NULL AFTER `event_profile`",
        'event_schedule' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `event_schedule` DATETIME DEFAULT NULL AFTER `event_location`",
        'on_site_safety_hazard_level' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `on_site_safety_hazard_level` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER `event_schedule`",
        'required_standby_responders' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `required_standby_responders` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `on_site_safety_hazard_level`",
        'emergency_contact_persons' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `emergency_contact_persons` TEXT DEFAULT NULL AFTER `required_standby_responders`",
        'status' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `status` ENUM('draft','active','standby','completed','cancelled') NOT NULL DEFAULT 'active' AFTER `emergency_contact_persons`",
        'source_system' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `source_system` VARCHAR(120) DEFAULT 'Group 6' AFTER `status`",
        'received_at' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `source_system`",
        'updated_at' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `received_at`",
        'raw_payload' => "ALTER TABLE `interagency_event_profiles` ADD COLUMN `raw_payload` LONGTEXT DEFAULT NULL AFTER `updated_at`",
    ];

    foreach ($columns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'interagency_event_profiles', $column)) {
            $pdo->exec($sql);
        }
    }

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

    $syncColumns = [
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

    foreach ($syncColumns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'api_sync_logs', $column)) {
            $pdo->exec($sql);
        }
    }
}

function ers_event_log_sync(PDO $pdo, string $direction, string $status, int $eventId, array $payload, ?array $response, ?string $error): int
{
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $encodedResponse = $response !== null ? json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, response_payload, error_message, created_at, updated_at)
         VALUES
            (?, 'Group 6', 'event_coordination', 'interagency_event_profile', ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $direction,
        $eventId > 0 ? $eventId : null,
        $status,
        is_string($encodedPayload) ? $encodedPayload : '{}',
        is_string($encodedResponse) ? $encodedResponse : null,
        $error,
    ]);

    return (int)$pdo->lastInsertId();
}

function ers_event_update_sync_log(PDO $pdo, int $logId, string $status, array $response, ?string $error): void
{
    if ($logId <= 0) {
        return;
    }

    $encodedResponse = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        "UPDATE api_sync_logs
         SET status = ?, response_payload = ?, error_message = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([
        $status,
        is_string($encodedResponse) ? $encodedResponse : '{}',
        $error,
        $logId,
    ]);
}
?>
