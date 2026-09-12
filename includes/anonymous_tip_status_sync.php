<?php
declare(strict_types=1);

function ers_anonymous_tip_sync_env(string $key, string $default = ''): string
{
    if (function_exists('ers_env')) {
        $value = trim((string)ers_env($key, ''));
        return $value !== '' ? $value : $default;
    }
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    $value = trim((string)$value);
    return $value !== '' ? $value : $default;
}

function ers_anonymous_tip_status_payload(PDO $pdo, int $incidentId, string $status, string $note = ''): ?array
{
    if ($incidentId <= 0) {
        return null;
    }

    try {
        $row = ers_anonymous_tip_status_source_row($pdo, $incidentId);
        if (!is_array($row)) {
            return null;
        }

        $rawStatus = strtolower(trim($status !== '' ? $status : (string)($row['incident_status'] ?? '')));
        $syncStatus = match ($rawStatus) {
            'assigned', 'acknowledged', 'dispatching', 'dispatched' => 'dispatched',
            'enroute', 'en_route', 'on_scene', 'ongoing', 'ongoing_dispatch', 'in_progress' => 'ongoing_dispatch',
            'resolved', 'complete', 'completed', 'closed' => 'completed',
            default => $rawStatus !== '' ? $rawStatus : 'new',
        };
        $partnerStatus = match ($syncStatus) {
            'dispatched', 'ongoing_dispatch' => 'Dispatched',
            'completed' => 'Completed',
            default => ucfirst(str_replace('_', ' ', $syncStatus)),
        };

        $payload = [
            'success' => true,
            'tip_id' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'tipId' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'tipID' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'anonymous_tip_id' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'anonymousTipId' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'backup_id' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'backupId' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'id' => (int)($row['local_tip_id'] ?? 0),
            'action' => 'update_status',
            'status' => $syncStatus,
            'status_text' => $partnerStatus,
            'statusText' => $partnerStatus,
            'display_status' => $syncStatus,
            'displayStatus' => $partnerStatus,
            'interagency_status' => $syncStatus,
            'inter_agency_status' => $syncStatus,
            'backup_status' => $partnerStatus,
            'backupStatus' => $partnerStatus,
            'backup_status_raw' => $syncStatus,
            'request_status' => $partnerStatus,
            'requestStatus' => $partnerStatus,
            'request_status_raw' => $syncStatus,
            'dispatch_status' => $partnerStatus,
            'dispatchStatus' => $partnerStatus,
            'dispatch_status_raw' => $syncStatus,
            'completed_status' => $partnerStatus,
            'completedStatus' => $partnerStatus,
            'completed_status_raw' => $syncStatus,
            'tip_status' => (string)($row['tip_status'] ?? ''),
            'incident_id' => (int)($row['incident_id'] ?? $incidentId),
            'incidentId' => (int)($row['incident_id'] ?? $incidentId),
            'incident_reference' => (string)($row['reference_no'] ?? ''),
            'incidentReference' => (string)($row['reference_no'] ?? ''),
            'incident_status' => (string)($row['incident_status'] ?? $syncStatus),
            'incidentStatus' => (string)($row['incident_status'] ?? $syncStatus),
            'dispatched' => in_array($syncStatus, ['dispatched', 'ongoing_dispatch', 'completed'], true),
            'is_dispatched' => in_array($syncStatus, ['dispatched', 'ongoing_dispatch', 'completed'], true),
            'completed' => $syncStatus === 'completed',
            'is_completed' => $syncStatus === 'completed',
            'resolved' => $syncStatus === 'completed',
            'is_resolved' => $syncStatus === 'completed',
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'resolved_at' => (string)($row['resolved_at'] ?? ''),
            'source_system' => ers_anonymous_tip_sync_env(
                'ANONYMOUS_TIP_SOURCE_SYSTEM',
                !empty($row['tip_source_system']) ? (string)$row['tip_source_system'] : (!empty($row['link_source_system']) ? (string)$row['link_source_system'] : 'ERS')
            ),
            'sourceSystem' => ers_anonymous_tip_sync_env(
                'ANONYMOUS_TIP_SOURCE_SYSTEM',
                !empty($row['tip_source_system']) ? (string)$row['tip_source_system'] : (!empty($row['link_source_system']) ? (string)$row['link_source_system'] : 'ERS')
            ),
            'source' => ers_anonymous_tip_sync_env(
                'ANONYMOUS_TIP_SOURCE_SYSTEM',
                !empty($row['tip_source_system']) ? (string)$row['tip_source_system'] : (!empty($row['link_source_system']) ? (string)$row['link_source_system'] : 'ERS')
            ),
            'agency' => ers_anonymous_tip_sync_env(
                'ANONYMOUS_TIP_SOURCE_SYSTEM',
                !empty($row['tip_source_system']) ? (string)$row['tip_source_system'] : (!empty($row['link_source_system']) ? (string)$row['link_source_system'] : 'ERS')
            ),
            'agency_name' => ers_anonymous_tip_sync_env(
                'ANONYMOUS_TIP_SOURCE_SYSTEM',
                !empty($row['tip_source_system']) ? (string)$row['tip_source_system'] : (!empty($row['link_source_system']) ? (string)$row['link_source_system'] : 'ERS')
            ),
            'system' => ers_anonymous_tip_sync_env(
                'ANONYMOUS_TIP_SOURCE_SYSTEM',
                !empty($row['tip_source_system']) ? (string)$row['tip_source_system'] : (!empty($row['link_source_system']) ? (string)$row['link_source_system'] : 'ERS')
            ),
        ];

        if ($note !== '') {
            $payload['note'] = $note;
        }

        return $payload;
    } catch (Throwable $e) {
        error_log('Anonymous tip status payload skipped: ' . $e->getMessage());
        return null;
    }
}

function ers_ensure_anonymous_tips_status_column(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        if (!ers_anonymous_tip_sync_table_exists($pdo, 'anonymous_tips')) {
            return;
        }
        $pdo->exec("ALTER TABLE `anonymous_tips` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'new'");
        $ensured = true;
    } catch (Throwable $e) {
        error_log('Ensure anonymous_tips status column failed: ' . $e->getMessage());
    }
}

/**
 * @return array<string,mixed>|null
 */
function ers_anonymous_tip_status_source_row(PDO $pdo, int $incidentId): ?array
{
    $incidentUpdatedExpr = ers_anonymous_tip_sync_column_exists($pdo, 'incidents', 'updated_at')
        ? 'i.updated_at'
        : 'NULL AS updated_at';
    $incidentResolvedExpr = ers_anonymous_tip_sync_column_exists($pdo, 'incidents', 'resolved_at')
        ? 'i.resolved_at'
        : (ers_anonymous_tip_sync_column_exists($pdo, 'incidents', 'completed_at') ? 'i.completed_at AS resolved_at' : 'NULL AS resolved_at');

    if (ers_anonymous_tip_sync_table_exists($pdo, 'external_incident_links')) {
        $stmt = $pdo->prepare("
            SELECT
                at.id AS local_tip_id,
                at.tip_id,
                at.status AS tip_status,
                at.outcome,
                at.source_system AS tip_source_system,
                i.id AS incident_id,
                i.reference_no,
                i.status AS incident_status,
                {$incidentUpdatedExpr},
                {$incidentResolvedExpr},
                l.external_incident_id,
                l.source_system AS link_source_system
            FROM external_incident_links l
            INNER JOIN incidents i ON i.id = l.incident_id
            LEFT JOIN anonymous_tips at
                ON at.tip_id = l.external_incident_id
                OR l.external_incident_id = CONCAT('anonymous-tip-', at.id)
                OR l.external_incident_id = CAST(at.id AS CHAR)
                OR (at.tip_id IS NOT NULL AND at.tip_id <> '' AND (l.external_incident_id LIKE CONCAT('%', at.tip_id, '%') OR at.tip_id LIKE CONCAT('%', l.external_incident_id, '%')))
            WHERE l.incident_id = ?
              AND (
                l.source_system IN ('Anonymous Tip Inbox', 'Responder App Coordination', 'Group 6', 'anonymous_tip', 'alertaraqc')
                OR l.external_incident_id LIKE 'TIP-%'
                OR l.external_incident_id LIKE 'anonymous-tip-%'
                OR at.id IS NOT NULL
              )
            ORDER BY l.id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['local_tip_id'])) {
            return $row;
        }
    }

    return ers_anonymous_tip_status_source_row_from_incident($pdo, $incidentId, $incidentUpdatedExpr, $incidentResolvedExpr);
}

/**
 * @return array<string,mixed>|null
 */
function ers_anonymous_tip_status_source_row_from_incident(
    PDO $pdo,
    int $incidentId,
    string $incidentUpdatedExpr,
    string $incidentResolvedExpr
): ?array {
    if (
        !ers_anonymous_tip_sync_table_exists($pdo, 'incidents')
        || !ers_anonymous_tip_sync_table_exists($pdo, 'anonymous_tips')
    ) {
        return null;
    }

    $titleExpr = ers_anonymous_tip_sync_column_exists($pdo, 'incidents', 'title') ? 'i.title' : "'' AS title";
    $descriptionExpr = ers_anonymous_tip_sync_column_exists($pdo, 'incidents', 'description') ? 'i.description' : "'' AS description";
    $stmt = $pdo->prepare("
        SELECT
            i.id AS incident_id,
            i.reference_no,
            i.status AS incident_status,
            {$incidentUpdatedExpr},
            {$incidentResolvedExpr},
            {$titleExpr},
            {$descriptionExpr}
        FROM incidents i
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($incident)) {
        return null;
    }

    $haystack = implode(' ', [
        (string)($incident['reference_no'] ?? ''),
        (string)($incident['title'] ?? ''),
        (string)($incident['description'] ?? ''),
    ]);
    $candidates = ers_anonymous_tip_status_candidate_tip_ids($haystack);
    foreach ($candidates as $candidate) {
        $stmt = $pdo->prepare(
            "SELECT id AS local_tip_id, tip_id, status AS tip_status, outcome, source_system AS tip_source_system
             FROM anonymous_tips
             WHERE tip_id = ? OR id = ?
             LIMIT 1"
        );
        $stmt->execute([$candidate, ctype_digit($candidate) ? (int)$candidate : 0]);
        $tip = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($tip)) {
            continue;
        }

        return [
            'local_tip_id' => (int)$tip['local_tip_id'],
            'tip_id' => (string)$tip['tip_id'],
            'tip_status' => (string)($tip['tip_status'] ?? ''),
            'outcome' => (string)($tip['outcome'] ?? ''),
            'tip_source_system' => (string)($tip['tip_source_system'] ?? ''),
            'incident_id' => (int)$incident['incident_id'],
            'reference_no' => (string)($incident['reference_no'] ?? ''),
            'incident_status' => (string)($incident['incident_status'] ?? ''),
            'updated_at' => (string)($incident['updated_at'] ?? ''),
            'resolved_at' => (string)($incident['resolved_at'] ?? ''),
            'external_incident_id' => (string)$tip['tip_id'],
        ];
    }

    return null;
}

/**
 * @return list<string>
 */
function ers_anonymous_tip_status_candidate_tip_ids(string $value): array
{
    $ids = [];
    if (preg_match_all('/\bTIP-[A-Za-z0-9_-]+\b/i', $value, $matches)) {
        foreach ($matches[0] ?? [] as $match) {
            $ids[strtoupper($match)] = true;
        }
    }
    if (preg_match_all('/\b(?:anonymous\s*tip\s*|tip\s*#?|#)(\d+)\b/i', $value, $matches)) {
        foreach ($matches[1] ?? [] as $match) {
            $ids[(string)$match] = true;
            $ids['anonymous-tip-' . $match] = true;
        }
    }
    return array_keys($ids);
}

function ers_anonymous_tip_sync_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_anonymous_tip_sync_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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
        $stmt->execute([$tableName, $columnName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_anonymous_tip_status_ensure_sync_log(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `api_sync_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `direction` ENUM('incoming','outgoing') NOT NULL DEFAULT 'outgoing',
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
            KEY `idx_api_sync_logs_endpoint` (`endpoint_name`),
            KEY `idx_api_sync_logs_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'direction' => "ALTER TABLE `api_sync_logs` ADD COLUMN `direction` ENUM('incoming','outgoing') NOT NULL DEFAULT 'outgoing' AFTER `id`",
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
        if (!ers_anonymous_tip_sync_column_exists($pdo, 'api_sync_logs', $column)) {
            $pdo->exec($sql);
        }
    }
}

function ers_anonymous_tip_status_log_insert(PDO $pdo, int $incidentId, ?array $payload, string $status = 'pending', string $error = ''): int
{
    try {
        ers_anonymous_tip_status_ensure_sync_log($pdo);
        $requestPayload = $payload !== null
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode(['incident_id' => $incidentId, 'error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare(
            "INSERT INTO api_sync_logs
                (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, error_message, created_at, updated_at)
             VALUES
                ('outgoing', 'surveillance', 'anonymous_tip_status_callback', 'incident', ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([$incidentId > 0 ? $incidentId : null, $status, $requestPayload, $error !== '' ? $error : null]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('Anonymous tip status log insert failed: ' . $e->getMessage());
        return 0;
    }
}

function ers_anonymous_tip_status_log_update(PDO $pdo, int $logId, string $status, string $response, string $error = ''): void
{
    if ($logId <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            "UPDATE api_sync_logs
             SET status = ?, response_payload = ?, error_message = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$status, $response, $error !== '' ? $error : null, $logId]);
    } catch (Throwable $e) {
        error_log('Anonymous tip status log update failed: ' . $e->getMessage());
    }
}

function ers_sync_local_anonymous_tip_status(PDO $pdo, int $incidentId, string $status, string $note = ''): void
{
    if ($incidentId <= 0 || !ers_anonymous_tip_sync_table_exists($pdo, 'anonymous_tips')) {
        return;
    }

    try {
        ers_ensure_anonymous_tips_status_column($pdo);
        $row = ers_anonymous_tip_status_source_row($pdo, $incidentId);
        if (!is_array($row) || empty($row['local_tip_id'])) {
            return;
        }

        $localTipId = (int)$row['local_tip_id'];
        $rawStatus = strtolower(trim($status));
        $targetTipStatus = match ($rawStatus) {
            'assigned', 'acknowledged', 'dispatching', 'dispatched',
            'enroute', 'en_route', 'on_scene', 'ongoing', 'ongoing_dispatch', 'in_progress' => 'dispatched',
            'resolved', 'complete', 'completed', 'closed' => 'resolved',
            default => 'pending',
        };

        $outcomeUpdate = '';
        $params = [':status' => $targetTipStatus, ':id' => $localTipId];
        if ($note !== '') {
            $outcomeUpdate = ', outcome = CONCAT(COALESCE(outcome, ""), "\n", :note)';
            $params[':note'] = $note;
        }

        $stmt = $pdo->prepare("UPDATE anonymous_tips SET status = :status{$outcomeUpdate}, updated_at = NOW() WHERE id = :id");
        $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('Local anonymous tip status update failed: ' . $e->getMessage());
    }
}

/**
 * @return array<string,mixed>
 */
function ers_notify_anonymous_tip_status_result(PDO $pdo, int $incidentId, string $status, string $note = ''): array
{
    ers_sync_local_anonymous_tip_status($pdo, $incidentId, $status, $note);
    $payload = ers_anonymous_tip_status_payload($pdo, $incidentId, $status, $note);
    if (!$payload) {
        $logId = ers_anonymous_tip_status_log_insert($pdo, $incidentId, null, 'failed', 'No anonymous tip link found for incident.');
        return [
            'ok' => false,
            'log_id' => $logId,
            'error' => 'No anonymous tip link found for incident.',
        ];
    }
    $logId = ers_anonymous_tip_status_log_insert($pdo, $incidentId, $payload);

    if (!function_exists('curl_init')) {
        ers_anonymous_tip_status_log_update($pdo, $logId, 'failed', '', 'PHP cURL extension is not available.');
        return [
            'ok' => false,
            'log_id' => $logId,
            'error' => 'PHP cURL extension is not available.',
            'payload' => $payload,
        ];
    }

    $callbackUrl = ers_anonymous_tip_sync_env(
        'ANONYMOUS_TIP_STATUS_CALLBACK_URL',
        'https://surveillance.alertaraqc.com/api/tip_backup_status_receive.php'
    );
    if ($callbackUrl === '') {
        ers_anonymous_tip_status_log_update($pdo, $logId, 'failed', '', 'Anonymous tip callback URL is empty.');
        return [
            'ok' => false,
            'log_id' => $logId,
            'error' => 'Anonymous tip callback URL is empty.',
            'payload' => $payload,
        ];
    }

    $apiKey = ers_anonymous_tip_sync_env(
        'SURVEILLANCE_API_KEY',
        ers_anonymous_tip_sync_env('ALERTARA_TRANSFER_API_KEY', ers_anonymous_tip_sync_env('ERS_EXTERNAL_API_KEY'))
    );

    $headers = [
        'Accept: application/json',
    ];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
        $headers[] = 'X-ERS-API-Key: ' . $apiKey;
    }

    $formResult = ers_post_anonymous_tip_status_callback(
        $callbackUrl,
        $headers,
        'application/x-www-form-urlencoded',
        http_build_query($payload)
    );
    if ($formResult['ok']) {
        ers_anonymous_tip_status_log_update($pdo, $logId, 'sent', $formResult['response']);
        return [
            'ok' => true,
            'log_id' => $logId,
            'transport' => 'form',
            'http_status' => $formResult['status'],
            'response' => $formResult['response'],
            'payload' => $payload,
        ];
    }

    $jsonResult = ers_post_anonymous_tip_status_callback(
        $callbackUrl,
        $headers,
        'application/json',
        (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($jsonResult['ok']) {
        ers_anonymous_tip_status_log_update($pdo, $logId, 'sent', $jsonResult['response']);
        return [
            'ok' => true,
            'log_id' => $logId,
            'transport' => 'json',
            'http_status' => $jsonResult['status'],
            'response' => $jsonResult['response'],
            'payload' => $payload,
        ];
    }

    $failure = 'form HTTP ' . $formResult['status']
        . ' ' . $formResult['error']
        . ' ' . substr($formResult['response'], 0, 300)
        . '; json HTTP ' . $jsonResult['status']
        . ' ' . $jsonResult['error']
        . ' ' . substr($jsonResult['response'], 0, 300);
    ers_anonymous_tip_status_log_update($pdo, $logId, 'failed', $jsonResult['response'] !== '' ? $jsonResult['response'] : $formResult['response'], $failure);
    error_log('Anonymous tip status callback failed: ' . $failure);
    return [
        'ok' => false,
        'log_id' => $logId,
        'error' => $failure,
        'form' => $formResult,
        'json' => $jsonResult,
        'payload' => $payload,
    ];
}

function ers_notify_anonymous_tip_status(PDO $pdo, int $incidentId, string $status, string $note = ''): bool
{
    $result = ers_notify_anonymous_tip_status_result($pdo, $incidentId, $status, $note);
    return !empty($result['ok']);
}

/**
 * @param list<string> $headers
 * @return array{ok:bool,status:int,error:string,response:string}
 */
function ers_post_anonymous_tip_status_callback(
    string $callbackUrl,
    array $headers,
    string $contentType,
    string $body
): array {
    $requestHeaders = array_merge(['Content-Type: ' . $contentType], $headers);
    $ch = curl_init($callbackUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $bodyText = is_string($response) ? $response : '';
    return [
        'ok' => $error === '' && $httpStatus >= 200 && $httpStatus < 300
            && ers_anonymous_tip_callback_response_accepted($bodyText),
        'status' => $httpStatus,
        'error' => $error,
        'response' => $bodyText,
    ];
}

function ers_anonymous_tip_callback_response_accepted(string $body): bool
{
    $trimmed = trim($body);
    if ($trimmed === '') {
        return true;
    }

    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded)) {
        return stripos($trimmed, 'error') === false && stripos($trimmed, 'failed') === false;
    }

    foreach (['success', 'ok'] as $key) {
        if (array_key_exists($key, $decoded) && !$decoded[$key]) {
            return false;
        }
    }

    return true;
}
