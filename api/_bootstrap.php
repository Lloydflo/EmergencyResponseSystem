<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . (string)ers_env('ERS_EXTERNAL_API_CORS_ORIGIN', '*'));
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-ERS-API-Key, X-API-Key, X-ERS-Client');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function ers_external_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ers_external_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return trim((string)$_SERVER[$key]);
    }

    if (strtolower($name) === 'authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return '';
}

function ers_external_expected_keys(): array
{
    $raw = [
        (string)ers_env('ERS_EXTERNAL_API_KEYS', ''),
        (string)ers_env('ERS_EXTERNAL_API_KEY', ''),
        (string)ers_env('INTEGRATION_API_KEY', ''),
        (string)ers_env('INTERAGENCY_EXTERNAL_API_KEY', ''),
        (string)ers_env('ALERTARA_TRANSFER_API_KEY', ''),
    ];

    $keys = [];
    foreach ($raw as $value) {
        foreach (explode(',', $value) as $key) {
            $key = trim($key);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
    }

    return array_keys($keys);
}

function ers_external_intake_enabled(): bool
{
    $value = strtolower((string)ers_env('ERS_EXTERNAL_INTAKE_ENABLED', ''));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function ers_external_authenticate(): array
{
    $expectedKeys = ers_external_expected_keys();
    if (count($expectedKeys) === 0) {
        ers_external_json(500, [
            'success' => false,
            'error' => 'External API key is not configured',
            'hint' => 'Set ERS_EXTERNAL_API_KEY in the server .env file.',
        ]);
    }

    $provided = ers_external_header('X-ERS-API-Key');
    if ($provided === '') {
        $provided = ers_external_header('X-API-Key');
    }
    if ($provided === '') {
        $authorization = ers_external_header('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
            $provided = trim($match[1]);
        }
    }
    if ($provided === '' && isset($_GET['api_key'])) {
        $provided = trim((string)$_GET['api_key']);
    }

    foreach ($expectedKeys as $expected) {
        if ($provided !== '' && hash_equals($expected, $provided)) {
            return [
                'client' => trim(ers_external_header('X-ERS-Client')) ?: 'external',
            ];
        }
    }

    ers_external_json(401, ['success' => false, 'error' => 'Invalid API key']);
}

function ers_external_db(): PDO
{
    $pdo = get_db_connection();
    if (!$pdo) {
        ers_external_json(500, ['success' => false, 'error' => 'DB connection unavailable']);
    }
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}

function ers_external_input(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    if (!empty($_POST)) {
        $cached = $_POST;
        return $cached;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        $cached = [];
        return $cached;
    }

    $trimmed = trim($raw);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $looksJson = strpos($contentType, 'application/json') !== false
        || strpos($trimmed, '{') === 0
        || strpos($trimmed, '[') === 0;

    if ($looksJson) {
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            ers_external_json(400, ['success' => false, 'error' => 'Invalid JSON body']);
        }

        $cached = $decoded;
        return $cached;
    }

    $form = [];
    if (strpos($contentType, 'application/x-www-form-urlencoded') !== false || strpos($trimmed, '=') !== false) {
        parse_str($trimmed, $form);
    }
    if (is_array($form) && $form !== []) {
        $cached = $form;
        return $cached;
    }

    if (!is_array(json_decode($trimmed, true))) {
        ers_external_json(400, ['success' => false, 'error' => 'Invalid JSON body']);
    }

    $cached = [];
    return $cached;
}

function ers_external_clean($value, int $maxLength = 0): string
{
    $clean = trim(preg_replace('/\s+/', ' ', (string)$value) ?? '');
    if ($maxLength > 0 && strlen($clean) > $maxLength) {
        return substr($clean, 0, $maxLength);
    }
    return $clean;
}

function ers_external_normalize_type($value): string
{
    $aliases = [
        'ambulance' => 'medical',
        'ems' => 'medical',
        'health' => 'medical',
        'crime' => 'police',
        'pnp' => 'police',
        'law enforcement' => 'police',
        'accident' => 'traffic',
    ];

    $rawItems = is_array($value) ? $value : preg_split('/[,|]+/', (string)$value);
    $allowed = ['medical', 'fire', 'police', 'traffic', 'rescue', 'other'];
    $items = [];

    foreach (($rawItems ?: []) as $item) {
        $item = strtolower(trim((string)$item));
        $item = $aliases[$item] ?? $item;
        if (in_array($item, $allowed, true) && !in_array($item, $items, true)) {
            $items[] = $item;
        }
    }

    return implode(', ', $items);
}

function ers_external_normalize_priority($value): string
{
    $priority = strtolower(trim((string)$value));
    if ($priority === 'moderate') {
        return 'medium';
    }
    if ($priority === 'urgent') {
        return 'high';
    }
    if (in_array($priority, ['critical', 'high', 'medium', 'low'], true)) {
        return $priority;
    }
    return 'medium';
}

function ers_external_normalize_status($value): string
{
    $status = strtolower(trim((string)$value));
    if (in_array($status, [
        'pending', 'received', 'dispatching', 'dispatched',
        'ongoing_dispatch', 'in_progress', 'resolved', 'completed', 'cancelled'
    ], true)) {
        return $status;
    }
    return '';
}

function ers_external_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function ers_external_column_exists(PDO $pdo, string $table, string $column): bool
{
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
}

function ers_external_ensure_identity(PDO $pdo, string $table): void
{
    if (!in_array($table, ['calls', 'incidents'], true)) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COLUMN_TYPE, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = 'id'
             LIMIT 1"
        );
        $stmt->execute([$table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || stripos((string)($row['EXTRA'] ?? ''), 'auto_increment') !== false) {
            return;
        }

        $columnType = trim((string)($row['COLUMN_TYPE'] ?? ''));
        if ($columnType !== '') {
            $pdo->exec('ALTER TABLE `' . $table . '` MODIFY `id` ' . $columnType . ' NOT NULL AUTO_INCREMENT');
        }
    } catch (Throwable $e) {
        error_log('external api identity check skipped: ' . $e->getMessage());
    }
}

function ers_external_requires_manual_id(Throwable $e): bool
{
    if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1048, 1364], true)) {
        return true;
    }
    $message = strtolower($e->getMessage());
    return strpos($message, "field 'id' doesn't have a default value") !== false
        || strpos($message, "column 'id' cannot be null") !== false;
}

function ers_external_next_id(PDO $pdo, string $table): int
{
    if (!in_array($table, ['calls', 'incidents'], true)) {
        throw new InvalidArgumentException('Unsupported table for id allocation.');
    }
    $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM `' . $table . '`');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    return max(1, (int)($row['next_id'] ?? 1));
}

function ers_external_insert_call(PDO $pdo, array $params): int
{
    $sql = "INSERT INTO calls
        (reference_no, caller_name, caller_phone, caller_email, location_address, latitude, longitude, incident_type, priority, status, description, received_at)
        VALUES
        (:reference_no, :caller_name, :caller_phone, :caller_email, :location_address, :latitude, :longitude, :incident_type, :priority, 'new', :description, NOW())";
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute($params);
    } catch (Throwable $e) {
        if (!ers_external_requires_manual_id($e)) {
            throw $e;
        }
        $params[':id'] = ers_external_next_id($pdo, 'calls');
        $sql = "INSERT INTO calls
            (id, reference_no, caller_name, caller_phone, caller_email, location_address, latitude, longitude, incident_type, priority, status, description, received_at)
            VALUES
            (:id, :reference_no, :caller_name, :caller_phone, :caller_email, :location_address, :latitude, :longitude, :incident_type, :priority, 'new', :description, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$params[':id'];
    }

    $id = (int)$pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }

    $lookup = $pdo->prepare('SELECT id FROM calls WHERE reference_no = ? LIMIT 1');
    $lookup->execute([$params[':reference_no']]);
    return (int)$lookup->fetchColumn();
}

function ers_external_insert_incident(PDO $pdo, array $params): int
{
    $sql = "INSERT INTO incidents
        (reference_no, type, priority, status, title, description, location_address, latitude, longitude, reported_by_call_id, created_at)
        VALUES
        (:reference_no, :type, :priority, 'pending', :title, :description, :location_address, :latitude, :longitude, :reported_by_call_id, NOW())";
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute($params);
    } catch (Throwable $e) {
        if (!ers_external_requires_manual_id($e)) {
            throw $e;
        }
        $params[':id'] = ers_external_next_id($pdo, 'incidents');
        $sql = "INSERT INTO incidents
            (id, reference_no, type, priority, status, title, description, location_address, latitude, longitude, reported_by_call_id, created_at)
            VALUES
            (:id, :reference_no, :type, :priority, 'pending', :title, :description, :location_address, :latitude, :longitude, :reported_by_call_id, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$params[':id'];
    }

    $id = (int)$pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }

    $lookup = $pdo->prepare('SELECT id FROM incidents WHERE reference_no = ? LIMIT 1');
    $lookup->execute([$params[':reference_no']]);
    return (int)$lookup->fetchColumn();
}

function ers_external_ensure_link_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `external_incident_links` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source_system` VARCHAR(120) NOT NULL,
            `external_incident_id` VARCHAR(120) NOT NULL,
            `incident_id` BIGINT UNSIGNED NOT NULL,
            `payload_json` LONGTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_external_incident_source` (`source_system`, `external_incident_id`),
            KEY `idx_external_incident_links_incident` (`incident_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ers_external_link_incident(PDO $pdo, string $sourceSystem, string $externalIncidentId, int $incidentId, array $payload): void
{
    if ($sourceSystem === '' || $externalIncidentId === '' || $incidentId <= 0) {
        return;
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare(
        "INSERT INTO external_incident_links
            (source_system, external_incident_id, incident_id, payload_json, created_at, updated_at)
         VALUES
            (?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            incident_id = VALUES(incident_id),
            payload_json = VALUES(payload_json),
            updated_at = NOW()"
    );
    $stmt->execute([$sourceSystem, $externalIncidentId, $incidentId, $encoded]);
}
?>
