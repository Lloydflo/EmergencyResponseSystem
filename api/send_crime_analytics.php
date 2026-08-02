<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in() || current_session_role() !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST method required']);
    exit;
}

$input = crime_analytics_request_input();
$incidentId = max(0, (int)($input['incident_id'] ?? $input['incidentId'] ?? 0));
if ($incidentId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'incident_id is required']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

try {
    $incident = crime_analytics_load_incident($pdo, $incidentId);
    if (!$incident) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Incident not found']);
        exit;
    }

    if (!crime_analytics_is_resolved($incident)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Only resolved incidents can be sent']);
        exit;
    }

    if (!crime_analytics_is_police_case($incident)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Only police or crime incidents can be sent']);
        exit;
    }

    $payload = crime_analytics_build_payload($incident);
    $endpoint = trim((string)ers_env('CRIME_ANALYTICS_ENDPOINT', 'https://crime-analytics.alertaraqc.com/api/crimes'));
    if ($endpoint === '' || !preg_match('/^https?:\/\//i', $endpoint)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Crime Analytics endpoint is not configured']);
        exit;
    }

    $latestSync = crime_analytics_latest_sync_log($pdo, $incidentId);
    $sendEnabled = strtolower(trim((string)ers_env('CRIME_ANALYTICS_SEND_ENABLED', 'false')));
    $confirmedSend = !empty($input['confirm_send']) || !empty($input['confirmSend']);
    if (!in_array($sendEnabled, ['1', 'true', 'yes', 'on'], true) || !$confirmedSend || !empty($input['dry_run']) || !empty($input['dryRun'])) {
        echo json_encode([
            'ok' => true,
            'dry_run' => true,
            'message' => 'Crime Analytics payload prepared. No data was sent.',
            'send_enabled' => in_array($sendEnabled, ['1', 'true', 'yes', 'on'], true),
            'latest_sync' => $latestSync,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $forceResend = !empty($input['force_resend']) || !empty($input['forceResend']);
    if (!$forceResend && is_array($latestSync) && strtolower((string)($latestSync['status'] ?? '')) === 'sent') {
        echo json_encode([
            'ok' => true,
            'already_sent' => true,
            'message' => 'Crime incident was already sent to Crime Analytics.',
            'sync_log_id' => (int)($latestSync['id'] ?? 0),
            'latest_sync' => $latestSync,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $logId = crime_analytics_insert_sync_log($pdo, $incidentId, $endpoint, $payload);
    $result = crime_analytics_post_json($endpoint, $payload);
    $sent = $result['http_code'] >= 200 && $result['http_code'] < 300;
    $upstreamMessage = crime_analytics_upstream_message($result['response_body']);
    $failureMessage = $upstreamMessage !== ''
        ? 'Crime Analytics endpoint returned an error: ' . $upstreamMessage
        : 'Crime Analytics endpoint returned an error.';
    crime_analytics_update_sync_log(
        $pdo,
        $logId,
        $sent ? 'sent' : 'failed',
        $result['response_body'],
        $sent ? null : ('HTTP ' . $result['http_code'] . ($result['error'] !== '' ? ': ' . $result['error'] : ''))
    );

    http_response_code($sent ? 200 : 502);
    echo json_encode([
        'ok' => $sent,
        'message' => $sent ? 'Crime incident sent to Crime Analytics.' : $failureMessage,
        'sync_log_id' => $logId,
        'http_code' => $result['http_code'],
        'payload' => $payload,
        'response' => crime_analytics_decode_response($result['response_body']),
        'upstream_message' => $upstreamMessage !== '' ? $upstreamMessage : null,
        'error' => $result['error'] !== '' ? $result['error'] : null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('send_crime_analytics.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to send crime analytics data']);
}

function crime_analytics_request_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function crime_analytics_load_incident(PDO $pdo, int $incidentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT i.*, c.caller_name, c.caller_phone,
                iar.sent_at AS admin_review_sent_at,
                iar.sent_by_name AS admin_review_sent_by_name,
                feedback_stats.feedback_count,
                feedback_stats.rating_count,
                feedback_stats.avg_rating
         FROM incidents i
         LEFT JOIN calls c ON c.id = i.reported_by_call_id
         LEFT JOIN incident_admin_reviews iar ON iar.incident_id = i.id
         LEFT JOIN (
            SELECT
                combined.incident_id,
                COUNT(*) AS feedback_count,
                SUM(CASE WHEN combined.rating IS NOT NULL THEN 1 ELSE 0 END) AS rating_count,
                ROUND(AVG(combined.rating), 1) AS avg_rating
            FROM (
                SELECT incident_id, rating
                FROM incident_notes
                WHERE note NOT LIKE 'Resolution proof uploaded:%'
                UNION ALL
                SELECT incident_id, response_rating AS rating
                FROM incident_surveys
            ) combined
            GROUP BY combined.incident_id
         ) feedback_stats ON feedback_stats.incident_id = i.id
         WHERE i.id = ?
         LIMIT 1"
    );
    try {
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            "SELECT i.*, c.caller_name, c.caller_phone
             FROM incidents i
             LEFT JOIN calls c ON c.id = i.reported_by_call_id
             WHERE i.id = ?
             LIMIT 1"
        );
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

function crime_analytics_is_resolved(array $incident): bool
{
    $status = strtolower(trim((string)($incident['status'] ?? '')));
    return in_array($status, ['resolved', 'closed'], true) || trim((string)($incident['resolved_at'] ?? '')) !== '';
}

function crime_analytics_is_police_case(array $incident): bool
{
    $haystack = strtolower(implode(' ', [
        $incident['type'] ?? '',
        $incident['title'] ?? '',
        $incident['description'] ?? '',
        $incident['indicator_incident_type'] ?? '',
    ]));
    return preg_match('/\b(police|crime|robbery|theft|fraud|assault|homicide|violence|weapon|gun|knife|patalim|riot)\b/', $haystack) === 1;
}

function crime_analytics_build_payload(array $incident): array
{
    $createdAt = crime_analytics_datetime((string)($incident['created_at'] ?? ''));
    $categoryName = crime_analytics_category($incident);
    $address = trim((string)($incident['location_address'] ?? ''));
    $barangay = crime_analytics_barangay($address);

    return [
        'incident_code' => (string)($incident['reference_no'] ?? ('ERS-' . (int)$incident['id'])),
        'incident_title' => trim((string)($incident['title'] ?? '')) !== ''
            ? (string)$incident['title']
            : ($categoryName . ' incident'),
        'incident_description' => (string)($incident['description'] ?? ''),
        'incident_date' => $createdAt['date'],
        'incident_time' => $createdAt['time'],
        'record_type' => 'crime',
        'status' => 'closed',
        'clearance_status' => 'cleared',
        'latitude' => crime_analytics_nullable_float($incident['latitude'] ?? null),
        'longitude' => crime_analytics_nullable_float($incident['longitude'] ?? null),
        'address_details' => $address,
        'victim_count' => 0,
        'suspect_count' => 0,
        'modus_operandi' => crime_analytics_modus($incident),
        'weather_condition' => 'Unknown',
        'assigned_officer' => 'ERS Admin',
        'admin_review' => [
            'source' => 'Review & Feedback',
            'sent_at' => $incident['admin_review_sent_at'] ?? null,
            'sent_by_name' => $incident['admin_review_sent_by_name'] ?? null,
            'feedback_count' => isset($incident['feedback_count']) && $incident['feedback_count'] !== null ? (int)$incident['feedback_count'] : 0,
            'rating_count' => isset($incident['rating_count']) && $incident['rating_count'] !== null ? (int)$incident['rating_count'] : 0,
            'avg_rating' => isset($incident['avg_rating']) && $incident['avg_rating'] !== null ? (float)$incident['avg_rating'] : null,
        ],
        'category' => [
            'category_name' => $categoryName,
        ],
        'barangay' => [
            'barangay_name' => $barangay,
        ],
        'source_system' => 'ERS',
    ];
}

function crime_analytics_datetime(string $value): array
{
    $time = strtotime($value);
    if ($time === false) {
        $time = time();
    }
    return [
        'date' => date('Y-m-d', $time),
        'time' => date('H:i:s', $time),
    ];
}

function crime_analytics_category(array $incident): string
{
    $text = strtolower(implode(' ', [$incident['type'] ?? '', $incident['title'] ?? '', $incident['description'] ?? '']));
    $map = [
        'homicide' => 'Homicide',
        'robbery' => 'Robbery',
        'theft' => 'Theft',
        'snatching' => 'Theft',
        'fraud' => 'Fraud',
        'scam' => 'Fraud',
        'assault' => 'Assault',
        'riot' => 'Public Disorder',
        'domestic' => 'Domestic Violence',
        'vehicle theft' => 'Vehicle Theft',
        'carnapping' => 'Vehicle Theft',
        'drug' => 'Drug-Related',
    ];
    foreach ($map as $needle => $label) {
        if (strpos($text, $needle) !== false) {
            return $label;
        }
    }
    return 'Police';
}

function crime_analytics_modus(array $incident): string
{
    $description = trim((string)($incident['description'] ?? ''));
    if ($description === '') {
        return 'Not specified';
    }
    return substr(preg_replace('/\s+/', ' ', $description) ?? $description, 0, 255);
}

function crime_analytics_barangay(string $address): string
{
    if (preg_match('/barangay\s+([^,]+)/i', $address, $match)) {
        return trim($match[1]);
    }
    return 'San Agustin';
}

function crime_analytics_nullable_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

function crime_analytics_post_json(string $endpoint, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('Unable to encode payload');
    }

    $apiKey = trim((string)ers_env('CRIME_ANALYTICS_API_KEY', ''));
    $apiKeyHeader = trim((string)ers_env('CRIME_ANALYTICS_API_KEY_HEADER', 'X-API-KEY'));
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
        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize HTTP client');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
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

function crime_analytics_decode_response(string $responseBody)
{
    $decoded = json_decode($responseBody, true);
    return is_array($decoded) ? $decoded : $responseBody;
}

function crime_analytics_upstream_message(string $responseBody): string
{
    $decoded = json_decode($responseBody, true);
    if (is_array($decoded)) {
        foreach (['message', 'error', 'detail'] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                return trim((string)$decoded[$key]);
            }
        }
    }
    return '';
}

function crime_analytics_insert_sync_log(PDO $pdo, int $incidentId, string $endpoint, array $payload): int
{
    if (!crime_analytics_ensure_sync_log($pdo)) {
        return 0;
    }
    $logPayload = $payload;
    $logPayload['_target_endpoint'] = $endpoint;
    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, created_at, updated_at)
         VALUES
            ('outgoing', 'Crime Analytics', 'send_crime_analytics', 'incident', ?, 'pending', ?, NOW(), NOW())"
    );
    $stmt->execute([
        $incidentId,
        json_encode($logPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function crime_analytics_update_sync_log(PDO $pdo, int $logId, string $status, string $responsePayload, ?string $errorMessage): void
{
    if ($logId <= 0 || !crime_analytics_sync_log_available($pdo)) {
        return;
    }
    $stmt = $pdo->prepare(
        "UPDATE api_sync_logs
         SET status = ?, response_payload = ?, error_message = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$status, $responsePayload, $errorMessage, $logId]);
}

function crime_analytics_latest_sync_log(PDO $pdo, int $incidentId): ?array
{
    if ($incidentId <= 0 || !crime_analytics_ensure_sync_log($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT id, status, response_payload, error_message, created_at, updated_at
             FROM api_sync_logs
             WHERE direction = 'outgoing'
               AND target_group = 'Crime Analytics'
               AND endpoint_name = 'send_crime_analytics'
               AND entity_type = 'incident'
               AND entity_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'status' => $row['status'] ?? '',
            'error_message' => $row['error_message'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'response' => isset($row['response_payload']) ? crime_analytics_decode_response((string)$row['response_payload']) : null,
        ] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function crime_analytics_ensure_sync_log(PDO $pdo): bool
{
    static $ensured = null;
    if ($ensured !== null) {
        return $ensured;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `api_sync_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `direction` ENUM('incoming','outgoing') NOT NULL DEFAULT 'incoming',
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
                KEY `idx_api_sync_logs_entity` (`entity_type`, `entity_id`),
                KEY `idx_api_sync_logs_endpoint` (`target_group`, `endpoint_name`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

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
            if (!crime_analytics_column_exists($pdo, 'api_sync_logs', $column)) {
                $pdo->exec($sql);
            }
        }

        $ensured = crime_analytics_sync_log_available($pdo);
    } catch (Throwable $e) {
        error_log('Crime Analytics sync log unavailable: ' . $e->getMessage());
        $ensured = false;
    }

    return $ensured;
}

function crime_analytics_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function crime_analytics_sync_log_available(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'api_sync_logs'
               AND COLUMN_NAME IN ('direction','target_group','endpoint_name','entity_type','entity_id','status','request_payload','response_payload','error_message','created_at','updated_at')"
        );
        $stmt->execute();
        $available = (int)$stmt->fetchColumn() >= 11;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}
?>
