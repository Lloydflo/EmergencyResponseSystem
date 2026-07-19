<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/interagency_time.php';
require_once __DIR__ . '/../../includes/activity_log.php';

function external_json_response(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function external_load_env_value(string $key): string {
    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    $envPath = dirname(__DIR__, 2) . '/.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return '';
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        if (trim($parts[0]) !== $key) {
            continue;
        }
        return trim(trim($parts[1]), "\"'");
    }

    return '';
}

function external_expected_api_keys(): array {
    $keys = [];
    foreach (['ERS_EXTERNAL_API_KEYS', 'ERS_EXTERNAL_API_KEY', 'INTERAGENCY_EXTERNAL_API_KEY'] as $envKey) {
        $raw = external_load_env_value($envKey);
        foreach (explode(',', $raw) as $key) {
            $key = trim($key);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
    }
    return array_keys($keys);
}

function external_intake_enabled(): bool {
    $value = strtolower(external_load_env_value('ERS_EXTERNAL_INTAKE_ENABLED'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function external_request_header(string $name): string {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }
    if (strtolower($name) === 'authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    return trim((string)($_SERVER[$serverKey] ?? ''));
}

function external_table_has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return (bool)$cache[$key];
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return (bool)$cache[$key];
}

function external_next_numeric_id(PDO $pdo, string $table): int {
    if (!in_array($table, ['activity_log', 'incidents'], true)) {
        throw new InvalidArgumentException('Unsupported id allocation table.');
    }
    $stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM `$table`");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    return max(1, (int)($row['next_id'] ?? 1));
}

function external_ensure_activity_log_auto_increment(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM activity_log LIKE 'id'");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $extra = strtolower((string)($row['Extra'] ?? ''));
        if (strpos($extra, 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE activity_log MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
        }
    } catch (Throwable $e) {
        // Manual id fallback below still supports legacy schemas.
    }
}

function external_ensure_solo_chat_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_solo_chat` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activity_log_id` INT NOT NULL,
            `sender_user_id` VARCHAR(255) NOT NULL,
            `recipient_user_id` INT UNSIGNED NOT NULL,
            `message_details` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_solo_chat_activity_log` (`activity_log_id`),
            KEY `idx_interagency_solo_chat_participants` (`sender_user_id`, `recipient_user_id`),
            KEY `idx_interagency_solo_chat_recipient_created` (`recipient_user_id`, `created_at`),
            KEY `idx_interagency_solo_chat_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columnStmt = $pdo->query("SHOW COLUMNS FROM interagency_solo_chat LIKE 'sender_user_id'");
    $column = $columnStmt ? $columnStmt->fetch(PDO::FETCH_ASSOC) : null;
    $columnType = strtolower((string)($column['Type'] ?? ''));
    if (strpos($columnType, 'varchar') !== 0) {
        $pdo->exec("ALTER TABLE interagency_solo_chat MODIFY sender_user_id VARCHAR(255) NOT NULL");
    }
}

function external_ensure_incident_cards_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_incident_cards` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT NOT NULL,
            `incident_id` INT NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
            `decided_by` INT DEFAULT NULL,
            `decided_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_incident_card_message` (`message_id`),
            KEY `idx_interagency_incident_card_incident` (`incident_id`),
            KEY `idx_interagency_incident_card_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function external_existing_incident_messages(PDO $pdo, int $incidentId, string $systemName): array {
    if ($incidentId <= 0 || $systemName === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT s.recipient_user_id, s.activity_log_id AS message_id
         FROM interagency_incident_cards c
         INNER JOIN interagency_solo_chat s ON s.activity_log_id = c.message_id
         LEFT JOIN activity_log a ON a.id = s.activity_log_id
         WHERE c.incident_id = ?
           AND s.sender_user_id = ?
           AND COALESCE(a.user_id, 0) = 0
         ORDER BY s.activity_log_id ASC"
    );
    $stmt->execute([$incidentId, $systemName]);

    $messages = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recipientId = (int)($row['recipient_user_id'] ?? 0);
        $messageId = (int)($row['message_id'] ?? 0);
        if ($recipientId > 0 && $messageId > 0) {
            $messages[] = [
                'recipient_user_id' => $recipientId,
                'message_id' => $messageId,
            ];
        }
    }
    return $messages;
}

function external_requires_manual_id(Throwable $e): bool {
    $message = $e->getMessage();
    return strpos($message, "Duplicate entry '0' for key 'PRIMARY'") !== false
        || strpos($message, "Field 'id' doesn't have a default value") !== false
        || strpos($message, "Field 'id' doesn't have a default") !== false;
}

function external_normalize_priority(string $priority): string {
    $value = strtolower(trim($priority));
    if (in_array($value, ['critical', 'high', 'medium', 'low'], true)) {
        return $value;
    }
    return 'medium';
}

function external_clean(string $value, int $maxLength): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($maxLength > 0 && strlen($value) > $maxLength) {
        return substr($value, 0, $maxLength);
    }
    return $value;
}


function external_map_department_to_type(string $department): string {
    $value = strtolower(trim($department));

    $map = [
        'police' => 'crime',
        'pnp' => 'crime',
        'crime' => 'crime',
        'law enforcement' => 'crime',

        'fire' => 'fire',
        'fire department' => 'fire',
        'bfp' => 'fire',

        'medical' => 'medical',
        'ems' => 'medical',
        'ambulance' => 'medical',
        'health' => 'medical',
    ];

    return $map[$value] ?? ($value !== '' ? $value : 'other');
}

function external_prepare_incident(array $incident, string $systemName): array {
    $tipId = external_clean((string)(
        $incident['tip_id']
        ?? $incident['reference_no']
        ?? $incident['incident_code']
        ?? ''
    ), 100);

    $requestedDepartment = external_clean((string)(
        $incident['requested_department']
        ?? $incident['department']
        ?? $incident['type']
        ?? $incident['incident_type']
        ?? ''
    ), 100);

    $type = external_map_department_to_type($requestedDepartment);

    $location = external_clean((string)(
        $incident['location']
        ?? $incident['location_address']
        ?? ''
    ), 500);

    $contactNumber = external_clean((string)(
        $incident['contact_number']
        ?? $incident['contact']
        ?? ''
    ), 50);

    $reasonForBackup = trim((string)(
        $incident['reason_for_backup']
        ?? $incident['backup_reason']
        ?? ''
    ));

    $reportedTimestamp = external_clean((string)(
        $incident['timestamp']
        ?? $incident['reported_at']
        ?? ''
    ), 50);

    $baseDescription = trim((string)(
        $incident['description']
        ?? $incident['details']
        ?? ''
    ));

    $descriptionParts = [];
    if ($baseDescription !== '') {
        $descriptionParts[] = $baseDescription;
    }
    if ($reasonForBackup !== '') {
        $descriptionParts[] = 'Reason for backup: ' . $reasonForBackup;
    }
    if ($contactNumber !== '') {
        $descriptionParts[] = 'Contact number: ' . $contactNumber;
    }
    if ($reportedTimestamp !== '') {
        $descriptionParts[] = 'Reported timestamp: ' . $reportedTimestamp;
    }

    $title = external_clean((string)($incident['title'] ?? ''), 200);
    if ($title === '') {
        $departmentLabel = $requestedDepartment !== ''
            ? ucfirst($requestedDepartment)
            : 'Inter-agency';
        $title = external_clean($departmentLabel . ' backup request', 200);
    }

    return [
        'reference_no' => $tipId,
        'incident_code' => $tipId,
        'type' => $type,
        'incident_type' => $type,
        'priority' => external_normalize_priority((string)($incident['priority'] ?? 'high')),
        'title' => $title,
        'description' => implode("\n", $descriptionParts),
        'location' => $location,
        'location_address' => $location,
        'latitude' => $incident['latitude'] ?? null,
        'longitude' => $incident['longitude'] ?? null,
        'contact_number' => $contactNumber,
        'reason_for_backup' => $reasonForBackup,
        'timestamp' => $reportedTimestamp,
        'source_system' => $systemName,
    ];
}

function external_find_or_create_incident(PDO $pdo, array $incident, string $systemName, string $externalIncidentId): int {
    $referenceNo = external_clean((string)($incident['reference_no'] ?? $incident['incident_code'] ?? $incident['tip_id'] ?? ''), 100);
    if ($referenceNo === '') {
        $source = preg_replace('/[^A-Z0-9]+/i', '-', strtoupper($systemName));
        $suffix = $externalIncidentId !== '' ? preg_replace('/[^A-Z0-9-]+/i', '-', strtoupper($externalIncidentId)) : date('YmdHis');
        $referenceNo = 'EXT-' . trim((string)$source, '-') . '-' . trim((string)$suffix, '-');
    }

    $lookup = $pdo->prepare("SELECT id FROM incidents WHERE reference_no = ? LIMIT 1");
    $lookup->execute([$referenceNo]);
    $existingId = (int)($lookup->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $type = external_clean((string)($incident['type'] ?? $incident['incident_type'] ?? 'other'), 100);
    $priority = external_normalize_priority((string)($incident['priority'] ?? 'medium'));
    $title = external_clean((string)($incident['title'] ?? ('Incident from ' . $systemName)), 200);
    $description = trim((string)($incident['description'] ?? $incident['details'] ?? ''));
    $location = external_clean((string)($incident['location'] ?? $incident['location_address'] ?? ''), 500);
    $latitude = isset($incident['latitude']) && is_numeric($incident['latitude']) ? (string)$incident['latitude'] : null;
    $longitude = isset($incident['longitude']) && is_numeric($incident['longitude']) ? (string)$incident['longitude'] : null;

    $columns = [
        'reference_no' => $referenceNo,
        'type' => $type !== '' ? $type : 'other',
        'priority' => $priority,
        'status' => 'pending',
        'title' => $title,
        'description' => $description,
        'location_address' => $location,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'created_at' => interagency_now(),
    ];

    if (external_table_has_column($pdo, 'incidents', 'reported_by_call_id')) {
        $columns['reported_by_call_id'] = null;
    }

    if (external_table_has_column($pdo, 'incidents', 'contact_number')) {
        $columns['contact_number'] = external_clean((string)($incident['contact_number'] ?? ''), 50);
    }

    if (external_table_has_column($pdo, 'incidents', 'external_source')) {
        $columns['external_source'] = $systemName;
    }

    if (external_table_has_column($pdo, 'incidents', 'external_incident_id')) {
        $columns['external_incident_id'] = $externalIncidentId;
    }

    if (
        external_table_has_column($pdo, 'incidents', 'reported_at')
        && !empty($incident['timestamp'])
    ) {
        $timestamp = strtotime((string)$incident['timestamp']);
        if ($timestamp !== false) {
            $columns['reported_at'] = date('Y-m-d H:i:s', $timestamp);
        }
    }

    $insert = function (array $values) use ($pdo): void {
        $columnNames = array_keys($values);
        $quoted = array_map(static fn($column) => "`$column`", $columnNames);
        $placeholders = array_map(static fn($column) => ':' . $column, $columnNames);
        $stmt = $pdo->prepare(
            'INSERT INTO incidents (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        foreach ($values as $column => $value) {
            $stmt->bindValue(':' . $column, $value);
        }
        $stmt->execute();
    };

    try {
        $insert($columns);
        $id = (int)$pdo->lastInsertId();
        if ($id > 0) {
            external_log_incident_created($id, $referenceNo, (string)$columns['type'], (string)$columns['priority'], (string)$columns['location_address'], $systemName);
            return $id;
        }
    } catch (Throwable $e) {
        if (!external_requires_manual_id($e)) {
            throw $e;
        }
        $columns = ['id' => external_next_numeric_id($pdo, 'incidents')] + $columns;
        $insert($columns);
        external_log_incident_created((int)$columns['id'], $referenceNo, (string)$columns['type'], (string)$columns['priority'], (string)$columns['location_address'], $systemName);
        return (int)$columns['id'];
    }

    $lookup->execute([$referenceNo]);
    $id = (int)($lookup->fetchColumn() ?: 0);
    if ($id <= 0) {
        throw new RuntimeException('Incident insert did not return an id.');
    }
    external_log_incident_created($id, $referenceNo, (string)$columns['type'], (string)$columns['priority'], (string)$columns['location_address'], $systemName);
    return $id;
}

function external_log_incident_created(int $incidentId, string $referenceNo, string $type, string $priority, string $location, string $systemName): void {
    if ($incidentId < 1) {
        return;
    }
    $details = 'Incoming incident ' . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId))
        . ' was received from ' . ($systemName !== '' ? $systemName : 'external system')
        . '. Type: ' . $type
        . ' | Priority: ' . $priority
        . ' | Location: ' . $location;
    log_activity_event(null, 'incident_created', 'incident', $incidentId, $details);
}

function external_insert_activity(PDO $pdo, ?int $recipientUserId, string $details): int {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
             VALUES (NULL, 'chat', 'agency_user_chat', ?, ?, ?)"
        );
        $stmt->execute([$recipientUserId, $details, interagency_now()]);
        $id = (int)$pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        if (!external_requires_manual_id($e)) {
            throw $e;
        }
        $id = external_next_numeric_id($pdo, 'activity_log');
        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details, created_at)
             VALUES (?, NULL, 'chat', 'agency_user_chat', ?, ?, ?)"
        );
        $stmt->execute([$id, $recipientUserId, $details, interagency_now()]);
        return $id;
    }

    $id = external_next_numeric_id($pdo, 'activity_log') - 1;
    if ($id <= 0) {
        throw new RuntimeException('Activity log insert did not return an id.');
    }
    return $id;
}

function external_active_interagency_recipients(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT id
         FROM users
         WHERE status = 'active'
           AND role IN ('admin', 'dispatcher', 'operator')
         ORDER BY FIELD(role, 'admin', 'dispatcher', 'operator'), id"
    );
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    external_json_response(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if (!external_intake_enabled()) {
    external_json_response(403, [
        'ok' => false,
        'error' => 'External incident intake is disabled',
        'hint' => 'Set ERS_EXTERNAL_INTAKE_ENABLED=true only when the external system is ready.',
    ]);
}

$expectedKeys = external_expected_api_keys();
if (count($expectedKeys) === 0) {
    external_json_response(500, ['ok' => false, 'error' => 'External API key is not configured']);
}

$providedKey = external_request_header('X-ERS-API-Key');
if ($providedKey === '') {
    $providedKey = external_request_header('X-API-Key');
}
if ($providedKey === '') {
    $authorization = external_request_header('Authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
        $providedKey = trim($match[1]);
    }
}
if ($providedKey === '') {
    $providedKey = trim((string)($_POST['api_key'] ?? ''));
}
if ($providedKey === '' && isset($_GET['api_key'])) {
    $providedKey = trim((string)$_GET['api_key']);
}

$validKey = false;
foreach ($expectedKeys as $expectedKey) {
    if ($providedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        $validKey = true;
        break;
    }
}
if (!$validKey) {
    external_json_response(401, ['ok' => false, 'error' => 'Invalid API key']);
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) {
    external_json_response(400, ['ok' => false, 'error' => 'Invalid payload']);
}

$systemName = external_clean((string)($input['system_name'] ?? $input['sender_name'] ?? 'External System'), 120);
if ($systemName === '') {
    $systemName = 'External System';
}
$conversationTitle = external_clean((string)($input['conversation_title'] ?? $systemName), 120);
if ($conversationTitle === '') {
    $conversationTitle = $systemName;
}
$externalIncidentId = external_clean((string)(
    $input['external_incident_id']
    ?? $input['external_id']
    ?? $input['tip_id']
    ?? ''
), 120);

$rawIncident = isset($input['incident']) && is_array($input['incident'])
    ? $input['incident']
    : $input;

$incident = external_prepare_incident($rawIncident, $systemName);

if (
    trim((string)($incident['location'] ?? $incident['location_address'] ?? '')) === ''
    || trim((string)($incident['description'] ?? $incident['details'] ?? '')) === ''
) {
    external_json_response(422, [
        'ok' => false,
        'error' => 'Missing required fields: location and description',
    ]);
}

if ($externalIncidentId === '') {
    $externalIncidentId = external_clean((string)($incident['reference_no'] ?? ''), 120);
}

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        external_json_response(500, ['ok' => false, 'error' => 'DB connection unavailable']);
    }

    interagency_apply_database_timezone($pdo);
    external_ensure_activity_log_auto_increment($pdo);
    external_ensure_solo_chat_table($pdo);
    external_ensure_incident_cards_table($pdo);

    $recipients = [];
    if (isset($input['recipient_user_id']) && (int)$input['recipient_user_id'] > 0) {
        $recipients[] = (int)$input['recipient_user_id'];
    } elseif (isset($input['recipient_user_ids']) && is_array($input['recipient_user_ids'])) {
        foreach ($input['recipient_user_ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $recipients[] = $id;
            }
        }
    } else {
        $recipients = external_active_interagency_recipients($pdo);
    }
    $recipients = array_values(array_unique(array_filter($recipients, static fn($id) => (int)$id > 0)));
    if (count($recipients) === 0) {
        external_json_response(422, ['ok' => false, 'error' => 'No active admin or dispatcher recipients found']);
    }

    $pdo->beginTransaction();

    $incidentId = external_find_or_create_incident($pdo, $incident, $systemName, $externalIncidentId);
    $referenceNo = external_clean((string)($incident['reference_no'] ?? $incident['incident_code'] ?? ''), 100);
    if ($referenceNo === '') {
        $stmt = $pdo->prepare("SELECT reference_no FROM incidents WHERE id = ? LIMIT 1");
        $stmt->execute([$incidentId]);
        $referenceNo = external_clean((string)($stmt->fetchColumn() ?: ''), 100);
    }

    $existingMessages = external_existing_incident_messages($pdo, $incidentId, $systemName);
    if (count($existingMessages) > 0) {
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'duplicate' => true,
            'incident_id' => $incidentId,
            'reference_no' => $referenceNo,
            'messages' => $existingMessages,
        ]);
        exit;
    }

    $card = [
        'incident_id' => $incidentId,
        'reference_no' => $referenceNo,
        'external_incident_id' => $externalIncidentId,
        'source_system' => $systemName,
        'title' => external_clean((string)($incident['title'] ?? ('Incident from ' . $systemName)), 200),
        'type' => external_clean((string)($incident['type'] ?? $incident['incident_type'] ?? 'other'), 100),
        'location' => external_clean((string)($incident['location'] ?? $incident['location_address'] ?? ''), 500),
        'priority' => external_normalize_priority((string)($incident['priority'] ?? 'medium')),
    ];

    $payload = [
        'text' => '[INCIDENT] Incident ' . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId)),
        'external_conversation_title' => $conversationTitle,
        'external_sender_name' => $systemName,
        'incident_card' => $card,
    ];
    $details = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($details) || $details === '') {
        throw new RuntimeException('Unable to encode message details.');
    }

    $createdMessages = [];
    foreach ($recipients as $recipientId) {
        $messageId = external_insert_activity($pdo, $recipientId, $details);

        $solo = $pdo->prepare(
            "INSERT INTO interagency_solo_chat
                (activity_log_id, sender_user_id, recipient_user_id, message_details, created_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $solo->execute([$messageId, $systemName, $recipientId, $details, interagency_now()]);

        $cardInsert = $pdo->prepare(
            "INSERT INTO interagency_incident_cards (message_id, incident_id, status, created_at)
             VALUES (?, ?, 'pending', ?)
             ON DUPLICATE KEY UPDATE
                incident_id = VALUES(incident_id),
                status = IF(status = '', 'pending', status)"
        );
        $cardInsert->execute([$messageId, $incidentId, interagency_now()]);

        $createdMessages[] = [
            'recipient_user_id' => $recipientId,
            'message_id' => $messageId,
        ];
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'incident_id' => $incidentId,
        'reference_no' => $referenceNo,
        'messages' => $createdMessages,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    external_json_response(500, [
        'ok' => false,
        'error' => 'External incident send failed',
        'detail' => $e->getMessage(),
    ]);
}
