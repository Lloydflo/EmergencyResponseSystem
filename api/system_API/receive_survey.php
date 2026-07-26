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
    ers_survey_ensure_tables($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        ers_external_json(200, [
            'success' => true,
            'items' => ers_survey_list($pdo),
        ]);
    }

    if ($method !== 'POST' && $method !== 'PATCH') {
        ers_external_json(405, [
            'success' => false,
            'error' => 'GET, POST, or PATCH method required',
        ]);
    }

    $input = ers_external_input();
    $items = ers_survey_normalize_items($input, $externalAuth['client'] ?? null);
    if ($items === []) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'No survey data found',
            'hint' => 'Send survey_id, incident_id, citizen_satisfaction, score, and response_rating.',
        ]);
    }

    $saved = [];
    foreach ($items as $item) {
        if ($item['survey_id'] === '' || $item['incident_id'] < 1) {
            ers_external_json(422, [
                'success' => false,
                'error' => 'survey_id and incident_id are required',
            ]);
        }

        if (!ers_survey_incident_exists($pdo, $item['incident_id'])) {
            ers_external_json(404, [
                'success' => false,
                'error' => 'Incident not found',
                'incident_id' => $item['incident_id'],
            ]);
        }

        $row = ers_survey_save($pdo, $item);
        ers_survey_log_sync($pdo, (int)$row['id'], $item);
        $saved[] = $row;
    }

    ers_external_json(201, [
        'success' => true,
        'message' => 'Incident survey feedback received.',
        'count' => count($saved),
        'items' => $saved,
    ]);
} catch (Throwable $e) {
    error_log('receive_survey.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to process incident survey feedback',
    ]);
}

function ers_survey_normalize_items(array $input, ?string $externalClient = null): array
{
    $rawItems = [];
    if (isset($input['surveys']) && is_array($input['surveys'])) {
        $rawItems = $input['surveys'];
    } elseif (isset($input['feedback']) && is_array($input['feedback']) && array_is_list($input['feedback'])) {
        $rawItems = $input['feedback'];
    } elseif (isset($input['data']) && is_array($input['data']) && array_is_list($input['data'])) {
        $rawItems = $input['data'];
    } else {
        $rawItems = [$input];
    }

    $items = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $surveyId = ers_external_clean(
            $raw['survey_id']
                ?? $raw['surveyId']
                ?? $raw['surveyID']
                ?? $raw['id']
                ?? '',
            120
        );
        $incidentId = (int)(
            $raw['incident_id']
                ?? $raw['incidentId']
                ?? $raw['incidentID']
                ?? 0
        );

        $score = ers_survey_decimal_or_null($raw['score'] ?? null);
        $responseRating = ers_survey_rating_or_null(
            $raw['response_rating']
                ?? $raw['responseRating']
                ?? $raw['rating']
                ?? null
        );

        $rawPayload = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($rawPayload)) {
            $rawPayload = '{}';
        }

        $items[] = [
            'survey_id' => $surveyId,
            'incident_id' => $incidentId,
            'citizen_satisfaction' => ers_external_clean(
                $raw['citizen_satisfaction']
                    ?? $raw['citizenSatisfaction']
                    ?? $raw['satisfaction']
                    ?? '',
                120
            ),
            'score' => $score,
            'response_rating' => $responseRating,
            'source_system' => ers_external_clean(
                $raw['source_system']
                    ?? $raw['sourceSystem']
                    ?? $input['source_system']
                    ?? $input['sourceSystem']
                    ?? $externalClient
                    ?? 'Group 6',
                120
            ),
            'raw_payload' => $rawPayload,
        ];
    }

    return $items;
}

function ers_survey_decimal_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'score must be numeric',
        ]);
    }
    return round((float)$value, 2);
}

function ers_survey_rating_or_null($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'response_rating must be numeric',
        ]);
    }
    $rating = (int)$value;
    if ($rating < 1 || $rating > 5) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'response_rating must be between 1 and 5',
        ]);
    }
    return $rating;
}

function ers_survey_incident_exists(PDO $pdo, int $incidentId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM incidents WHERE id = ? LIMIT 1');
    $stmt->execute([$incidentId]);
    return (bool)$stmt->fetchColumn();
}

function ers_survey_save(PDO $pdo, array $item): array
{
    $stmt = $pdo->prepare('SELECT id FROM incident_surveys WHERE survey_id = ? LIMIT 1');
    $stmt->execute([$item['survey_id']]);
    $existingId = (int)$stmt->fetchColumn();

    if ($existingId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE incident_surveys
             SET incident_id = ?,
                 citizen_satisfaction = ?,
                 score = ?,
                 response_rating = ?,
                 source_system = ?,
                 raw_payload = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $item['incident_id'],
            $item['citizen_satisfaction'] !== '' ? $item['citizen_satisfaction'] : null,
            $item['score'],
            $item['response_rating'],
            $item['source_system'] !== '' ? $item['source_system'] : 'Group 6',
            $item['raw_payload'],
            $existingId,
        ]);

        return ers_survey_find($pdo, $existingId);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO incident_surveys
            (survey_id, incident_id, citizen_satisfaction, score, response_rating,
             source_system, received_at, updated_at, raw_payload)
         VALUES
            (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)"
    );
    $stmt->execute([
        $item['survey_id'],
        $item['incident_id'],
        $item['citizen_satisfaction'] !== '' ? $item['citizen_satisfaction'] : null,
        $item['score'],
        $item['response_rating'],
        $item['source_system'] !== '' ? $item['source_system'] : 'Group 6',
        $item['raw_payload'],
    ]);

    return ers_survey_find($pdo, (int)$pdo->lastInsertId());
}

function ers_survey_find(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, survey_id, incident_id, citizen_satisfaction, score, response_rating,
                source_system, received_at, updated_at
         FROM incident_surveys
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function ers_survey_list(PDO $pdo): array
{
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 60)));
    $incidentId = (int)($_GET['incident_id'] ?? 0);
    $search = ers_external_clean($_GET['search'] ?? '', 120);

    $where = [];
    $params = [];
    if ($incidentId > 0) {
        $where[] = 's.incident_id = ?';
        $params[] = $incidentId;
    }
    if ($search !== '') {
        $where[] = '(s.survey_id LIKE ? OR s.citizen_satisfaction LIKE ? OR s.source_system LIKE ? OR i.reference_no LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT s.id, s.survey_id, s.incident_id, s.citizen_satisfaction, s.score,
                   s.response_rating, s.source_system, s.received_at, s.updated_at,
                   i.reference_no AS incident_reference, i.title AS incident_title,
                   i.status AS incident_status
            FROM incident_surveys s
            LEFT JOIN incidents i ON i.id = s.incident_id";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY s.received_at DESC, s.id DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ers_survey_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `incident_surveys` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `survey_id` VARCHAR(120) NOT NULL,
            `incident_id` BIGINT UNSIGNED NOT NULL,
            `citizen_satisfaction` VARCHAR(120) DEFAULT NULL,
            `score` DECIMAL(5,2) DEFAULT NULL,
            `response_rating` TINYINT UNSIGNED DEFAULT NULL,
            `source_system` VARCHAR(120) DEFAULT 'Group 6',
            `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `raw_payload` LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_incident_surveys_survey_id` (`survey_id`),
            KEY `idx_incident_surveys_incident_id` (`incident_id`),
            KEY `idx_incident_surveys_score` (`score`),
            KEY `idx_incident_surveys_rating` (`response_rating`),
            KEY `idx_incident_surveys_source` (`source_system`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'survey_id' => "ALTER TABLE `incident_surveys` ADD COLUMN `survey_id` VARCHAR(120) DEFAULT NULL AFTER `id`",
        'incident_id' => "ALTER TABLE `incident_surveys` ADD COLUMN `incident_id` BIGINT UNSIGNED NOT NULL AFTER `survey_id`",
        'citizen_satisfaction' => "ALTER TABLE `incident_surveys` ADD COLUMN `citizen_satisfaction` VARCHAR(120) DEFAULT NULL AFTER `incident_id`",
        'score' => "ALTER TABLE `incident_surveys` ADD COLUMN `score` DECIMAL(5,2) DEFAULT NULL AFTER `citizen_satisfaction`",
        'response_rating' => "ALTER TABLE `incident_surveys` ADD COLUMN `response_rating` TINYINT UNSIGNED DEFAULT NULL AFTER `score`",
        'source_system' => "ALTER TABLE `incident_surveys` ADD COLUMN `source_system` VARCHAR(120) DEFAULT 'Group 6' AFTER `response_rating`",
        'received_at' => "ALTER TABLE `incident_surveys` ADD COLUMN `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `source_system`",
        'updated_at' => "ALTER TABLE `incident_surveys` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `received_at`",
        'raw_payload' => "ALTER TABLE `incident_surveys` ADD COLUMN `raw_payload` LONGTEXT DEFAULT NULL AFTER `updated_at`",
    ];

    foreach ($columns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'incident_surveys', $column)) {
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

function ers_survey_log_sync(PDO $pdo, int $surveyRowId, array $item): void
{
    $payload = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        $payload = '{}';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, created_at, updated_at)
         VALUES
            ('incoming', 'Group 6', 'receive_survey', 'incident_survey', ?, 'received', ?, NOW(), NOW())"
    );
    $stmt->execute([$surveyRowId > 0 ? $surveyRowId : null, $payload]);
}
?>
