<?php
declare(strict_types=1);

/** Shared request, validation, database, and response helpers. */
require_once __DIR__ . '/connect.php';

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

/** @param array<string,mixed> $payload */
function op_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/** @param array<string,mixed> $payload */
function op_success(array $payload = [], int $status = 200): never
{
    op_json(['success' => true] + $payload, $status);
}

/** @param array<string,mixed> $payload */
function op_error(string $message, int $status = 400, array $payload = []): never
{
    op_json(['success' => false, 'message' => $message] + $payload, $status);
}

/** @param string|list<string> $methods */
function op_require_method(string|array $methods): void
{
    $allowed = is_array($methods) ? $methods : [$methods];
    $allowed = array_values(array_unique(array_map(
        static fn(string $method): string => strtoupper(trim($method)),
        $allowed
    )));
    $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($actual, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        op_error('Method not allowed.', 405);
    }
}

/** @return array<string,mixed> */
function op_request_data(): array
{
    static $data = null;
    if (is_array($data)) {
        return $data;
    }

    $data = is_array($_POST ?? null) ? $_POST : [];
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $data;
    }

    $trimmed = trim($raw);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $looksJson = str_contains($contentType, 'application/json')
        || str_starts_with($trimmed, '{')
        || str_starts_with($trimmed, '[');

    if ($looksJson) {
        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            op_error('Invalid JSON request body.', 400);
        }
        if (!is_array($decoded)) {
            op_error('Invalid JSON request body.', 400);
        }
        $data = $decoded + $data;
        return $data;
    }

    $form = [];
    parse_str($raw, $form);
    if (is_array($form)) {
        $data = $form + $data;
    }
    return $data;
}

function op_post_string(string $name, string $default = '', int $maxLength = 10000): string
{
    $data = op_request_data();
    $value = trim((string)($data[$name] ?? $default));
    if (strlen($value) > $maxLength) {
        op_error($name . ' is too long.', 422);
    }
    return $value;
}

function op_post_int(string $name, int $default = 0): int
{
    $data = op_request_data();
    $raw = $data[$name] ?? $default;
    if (is_int($raw)) {
        return $raw;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    return $value === false ? $default : (int)$value;
}

function op_query_int(string $name, int $default = 0): int
{
    $value = filter_var($_GET[$name] ?? $default, FILTER_VALIDATE_INT);
    return $value === false ? $default : (int)$value;
}

function op_query_string(string $name, string $default = '', int $maxLength = 1000): string
{
    $value = trim((string)($_GET[$name] ?? $default));
    if (strlen($value) > $maxLength) {
        op_error($name . ' is too long.', 422);
    }
    return $value;
}

function op_post_bool(string $name, bool $default = false): bool
{
    $data = op_request_data();
    if (!array_key_exists($name, $data)) {
        return $default;
    }
    $value = filter_var($data[$name], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $value ?? $default;
}

function op_query_bool(string $name, bool $default = false): bool
{
    if (!array_key_exists($name, $_GET)) {
        return $default;
    }
    $value = filter_var($_GET[$name], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $value ?? $default;
}

function op_post_nullable_float(string $name, float $min, float $max): ?float
{
    $data = op_request_data();
    if (!array_key_exists($name, $data) || trim((string)$data[$name]) === '') {
        return null;
    }
    $value = filter_var($data[$name], FILTER_VALIDATE_FLOAT);
    if ($value === false || (float)$value < $min || (float)$value > $max) {
        op_error($name . ' is outside the valid range.', 422);
    }
    return (float)$value;
}

function op_query_nullable_float(string $name, float $min, float $max): ?float
{
    if (!array_key_exists($name, $_GET) || trim((string)$_GET[$name]) === '') {
        return null;
    }
    $value = filter_var($_GET[$name], FILTER_VALIDATE_FLOAT);
    if ($value === false || (float)$value < $min || (float)$value > $max) {
        op_error($name . ' is outside the valid range.', 422);
    }
    return (float)$value;
}

function op_require_positive(int $value, string $name): void
{
    if ($value <= 0) {
        op_error($name . ' is invalid.', 422);
    }
}

function op_require_text(string $value, string $name): void
{
    if ($value === '') {
        op_error($name . ' is required.', 422);
    }
}

/** @param list<string> $allowed */
function op_require_one_of(string $value, array $allowed, string $name): string
{
    $normalized = strtolower(trim($value));
    if (!in_array($normalized, $allowed, true)) {
        op_error($name . ' is invalid.', 422);
    }
    return $normalized;
}

function op_require_email(string $email, string $name = 'email'): string
{
    $email = strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        op_error($name . ' is invalid.', 422);
    }
    return $email;
}

/** @return array<string,mixed>|null */
function op_fetch_one(PDOStatement $statement): ?array
{
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return list<array<string,mixed>> */
function op_fetch_all(PDOStatement $statement): array
{
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function op_table_exists(PDO $pdo, string $tableName): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . strtolower($tableName);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $statement = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $statement->execute([$tableName]);
    return $cache[$key] = (bool)$statement->fetchColumn();
}

function op_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . strtolower($tableName) . ':' . strtolower($columnName);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $statement = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $statement->execute([$tableName, $columnName]);
    return $cache[$key] = (bool)$statement->fetchColumn();
}

/** @param list<string> $tableNames */
function op_require_tables(PDO $pdo, array $tableNames): void
{
    $missing = [];
    foreach ($tableNames as $tableName) {
        if (!op_table_exists($pdo, $tableName)) {
            $missing[] = $tableName;
        }
    }
    if ($missing !== []) {
        error_log('[api_app] missing database tables: ' . implode(', ', $missing));
        op_error('This feature is not installed on the database yet.', 503);
    }
}

/** @param list<string> $columns */
function op_require_columns(PDO $pdo, string $tableName, array $columns): void
{
    $missing = [];
    foreach ($columns as $column) {
        if (!op_column_exists($pdo, $tableName, $column)) {
            $missing[] = $tableName . '.' . $column;
        }
    }
    if ($missing !== []) {
        error_log('[api_app] missing database columns: ' . implode(', ', $missing));
        op_error('This feature requires a database update.', 503);
    }
}

/** @param list<string> $columns */
function op_has_columns(PDO $pdo, string $tableName, array $columns): bool
{
    if (!op_table_exists($pdo, $tableName)) {
        return false;
    }
    foreach ($columns as $column) {
        if (!op_column_exists($pdo, $tableName, $column)) {
            return false;
        }
    }
    return true;
}

/** @return array<string,mixed>|null */
function op_active_responder(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !op_table_exists($pdo, 'users')) {
        return null;
    }

    $wanted = [
        'id', 'name', 'email', 'role', 'department', 'unit_code', 'unit_type',
        'vehicle_plate', 'unit_status', 'status', 'profile_image_path', 'username',
        'is_active', 'last_login',
    ];
    $select = [];
    foreach ($wanted as $column) {
        $select[] = op_column_exists($pdo, 'users', $column)
            ? '`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }

    $where = ['id = ?'];
    if (op_column_exists($pdo, 'users', 'role')) {
        $where[] = "LOWER(COALESCE(role, '')) = 'responder'";
    }
    if (op_column_exists($pdo, 'users', 'status')) {
        $where[] = "LOWER(COALESCE(status, '')) = 'active'";
    }
    if (op_column_exists($pdo, 'users', 'is_active')) {
        $where[] = 'COALESCE(is_active, 1) = 1';
    }

    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . ' FROM users WHERE '
        . implode(' AND ', $where) . ' LIMIT 1'
    );
    $statement->execute([$userId]);
    return op_fetch_one($statement);
}

/** @return array<string,mixed> */
function op_require_active_responder(PDO $pdo, int $userId): array
{
    op_require_positive($userId, 'responder_id');
    $responder = op_active_responder($pdo, $userId);
    if ($responder === null) {
        op_error('Responder account was not found or is inactive.', 403);
    }
    return $responder;
}

/** @return array<string,mixed> */
function op_require_active_reviewer(PDO $pdo, int $userId): array
{
    op_require_positive($userId, 'reviewer_id');
    op_require_tables($pdo, ['users']);

    $select = ['id'];
    foreach (['name', 'email', 'role', 'department', 'status', 'is_active'] as $column) {
        $select[] = op_column_exists($pdo, 'users', $column)
            ? '`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }
    $where = ['id = ?'];
    if (op_column_exists($pdo, 'users', 'role')) {
        $where[] = "LOWER(COALESCE(role, '')) IN ('admin','dispatcher','operator')";
    }
    if (op_column_exists($pdo, 'users', 'status')) {
        $where[] = "LOWER(COALESCE(status, '')) = 'active'";
    }
    if (op_column_exists($pdo, 'users', 'is_active')) {
        $where[] = 'COALESCE(is_active, 1) = 1';
    }
    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . ' FROM users WHERE '
        . implode(' AND ', $where) . ' LIMIT 1'
    );
    $statement->execute([$userId]);
    $reviewer = op_fetch_one($statement);
    if ($reviewer === null) {
        op_error('Reviewer account was not found or is not authorized.', 403);
    }
    return $reviewer;
}

function op_active_group_exists(PDO $pdo, int $groupId): bool
{
    if ($groupId <= 0 || !op_table_exists($pdo, 'interagency_group_threads')) {
        return false;
    }
    $statement = $pdo->prepare(
        'SELECT 1 FROM interagency_group_threads WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $statement->execute([$groupId]);
    return (bool)$statement->fetchColumn();
}

function op_is_group_member(PDO $pdo, int $groupId, int $userId): bool
{
    if (!op_table_exists($pdo, 'interagency_group_members')) {
        return false;
    }
    $statement = $pdo->prepare(
        'SELECT 1 FROM interagency_group_members '
        . 'WHERE group_id = ? AND user_id = ? AND is_active = 1 LIMIT 1'
    );
    $statement->execute([$groupId, $userId]);
    return (bool)$statement->fetchColumn();
}

function op_responder_can_report_incident(PDO $pdo, int $incidentId, int $responderId): bool
{
    if (!op_table_exists($pdo, 'incidents')) {
        return false;
    }
    $clauses = [];
    $params = [$incidentId];
    if (op_column_exists($pdo, 'incidents', 'completed_by_responder_id')) {
        $clauses[] = 'i.completed_by_responder_id = ?';
        $params[] = $responderId;
    }
    if (
        op_table_exists($pdo, 'dispatch_operator_records')
        && op_column_exists($pdo, 'dispatch_operator_records', 'incident_id')
        && op_column_exists($pdo, 'dispatch_operator_records', 'assigned_to')
    ) {
        $clauses[] = 'EXISTS (SELECT 1 FROM dispatch_operator_records d '
            . 'WHERE d.incident_id = i.id AND d.assigned_to = ?)';
        $params[] = $responderId;
    }
    if (
        op_table_exists($pdo, 'dispatches')
        && op_table_exists($pdo, 'units')
        && op_column_exists($pdo, 'users', 'unit_code')
    ) {
        $clauses[] = 'EXISTS (SELECT 1 FROM dispatches dp '
            . 'INNER JOIN units un ON un.id = dp.unit_id '
            . 'INNER JOIN users usr ON UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(un.identifier)) '
            . 'WHERE dp.incident_id = i.id AND usr.id = ?)';
        $params[] = $responderId;
    }
    if ($clauses === []) {
        return false;
    }
    $statement = $pdo->prepare(
        'SELECT 1 FROM incidents i WHERE i.id = ? AND (' . implode(' OR ', $clauses) . ') LIMIT 1'
    );
    $statement->execute($params);
    return (bool)$statement->fetchColumn();
}

/** Lightweight presence update: no DDL and no global resource synchronization. */
function op_touch_presence(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !op_table_exists($pdo, 'user_presence')) {
        return;
    }
    try {
        $sessionId = null;
        if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
            $sessionId = substr(hash('sha256', session_id()), 0, 128);
        }

        $update = $pdo->prepare(
            'UPDATE user_presence SET '
            . 'session_id = COALESCE(?, session_id), is_online = 1, last_seen_at = NOW(), logged_out_at = NULL '
            . 'WHERE user_id = ? AND (is_online <> 1 OR last_seen_at < NOW() - INTERVAL 20 SECOND)'
        );
        $update->execute([$sessionId, $userId]);
        if ($update->rowCount() === 0) {
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO user_presence '
                . '(user_id, session_id, is_online, last_seen_at, logged_in_at, logged_out_at) '
                . 'VALUES (?, ?, 1, NOW(), NOW(), NULL)'
            );
            $insert->execute([$userId, $sessionId]);
        }
    } catch (Throwable $error) {
        error_log('[api_app] presence touch skipped: ' . $error->getMessage());
    }
}

function op_mark_offline(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !op_table_exists($pdo, 'user_presence')) {
        return;
    }
    $statement = $pdo->prepare(
        'UPDATE user_presence SET is_online = 0, last_seen_at = NOW(), logged_out_at = NOW() WHERE user_id = ?'
    );
    $statement->execute([$userId]);
}

function op_base_url(): string
{
    $configured = '';
    if (function_exists('ers_env')) {
        $configured = trim((string)ers_env('APP_URL', ers_env('BASE_URL', '')));
    }
    if ($configured !== '' && preg_match('~^https?://[A-Za-z0-9.-]+(?::\d{1,5})?(?:/.*)?$~', $configured)) {
        return rtrim($configured, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host)) {
        return '';
    }
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function op_client_ip(): string
{
    $value = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : '';
}

function op_env(string $name, string $default = ''): string
{
    if (function_exists('ers_env')) {
        return trim((string)ers_env($name, $default));
    }
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    return is_string($value) ? trim($value) : $default;
}

function op_request_header(string $name): string
{
    $normalized = strtoupper(str_replace('-', '_', trim($name)));
    if ($normalized === '') {
        return '';
    }
    $serverName = str_starts_with($normalized, 'HTTP_') ? $normalized : 'HTTP_' . $normalized;
    return trim((string)($_SERVER[$serverName] ?? ''));
}

/**
 * Protects server-to-server endpoints with a secret from the canonical app env.
 * The secret may be supplied through the named HTTP header or request field.
 */
function op_require_service_key(
    string $environmentName,
    string $headerName,
    string $requestField = 'service_key'
): void {
    $configured = op_env($environmentName);
    if ($configured === '') {
        error_log('[api_app] missing required service-key configuration: ' . $environmentName);
        op_error('This server-to-server feature is not configured.', 503);
    }

    $provided = op_request_header($headerName);
    if ($provided === '' && $requestField !== '') {
        $data = op_request_data();
        $provided = trim((string)($data[$requestField] ?? $_GET[$requestField] ?? ''));
    }
    if ($provided === '' || !hash_equals($configured, $provided)) {
        op_error('Service authorization failed.', 403);
    }
}

/** @return array<string,mixed> */
function op_decode_object(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function op_tip_response(array $row): array
{
    $payload = op_decode_object(isset($row['raw_payload']) ? (string)$row['raw_payload'] : null);
    $sourceStatus = strtolower(trim((string)($row['status'] ?? $payload['status'] ?? 'pending')));
    $status = match ($sourceStatus) {
        'new' => 'pending',
        'reviewing' => 'processing',
        'verified' => 'approved',
        'dismissed' => 'rejected',
        'converted_to_incident' => 'completed',
        default => $sourceStatus !== '' ? $sourceStatus : 'pending',
    };
    return [
        'id' => (int)($row['id'] ?? 0),
        'reference_no' => (string)($row['tip_id'] ?? $payload['client_reference'] ?? ''),
        'sender_user_id' => (int)($payload['sender_user_id'] ?? 0),
        'sender_name' => (string)($payload['sender_name'] ?? ''),
        'recipient_type' => (string)($payload['recipient_type'] ?? ''),
        'recipient_id' => (string)($payload['recipient_id'] ?? ''),
        'incident_type' => (string)($payload['incident_type'] ?? 'general'),
        'priority' => (string)($payload['priority'] ?? 'medium'),
        'location' => (string)($payload['location'] ?? $row['location'] ?? ''),
        'latitude' => array_key_exists('latitude', $payload) ? $payload['latitude'] : null,
        'longitude' => array_key_exists('longitude', $payload) ? $payload['longitude'] : null,
        'contact_number' => (string)($payload['contact_number'] ?? ''),
        'description' => (string)($payload['description'] ?? $row['tip_description'] ?? ''),
        'police_backup_reason' => (string)($payload['police_backup_reason'] ?? ''),
        'status' => $status,
        'source_status' => $sourceStatus,
        'created_at_ms' => (int)($row['created_at_ms'] ?? 0),
        'updated_at_ms' => (int)($row['updated_at_ms'] ?? 0),
    ];
}

/** Number of hours an approved report remains in the active Approved queue. */
function op_after_action_history_hours(): int
{
    return 24;
}

/** @return array<string,mixed> */
function op_after_action_history_policy(): array
{
    $hours = op_after_action_history_hours();
    return [
        'approved_queue_hours' => $hours,
        'description' => 'Approved reports remain in the active Approved queue for 24 hours, then appear in monthly history.',
        'server_time_ms' => (int)round(microtime(true) * 1000),
    ];
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function op_after_action_response(array $row): array
{
    // Keep the existing database values for backward compatibility while
    // exposing the responder-facing Pending -> Submitted -> Approved workflow.
    $legacyStatus = strtolower(trim((string)($row['status'] ?? 'draft')));
    $workflowStatus = match ($legacyStatus) {
        'submitted' => 'submitted',
        'verified', 'approved' => 'approved',
        'returned' => 'revision_required',
        default => 'pending',
    };
    $statusLabel = match ($workflowStatus) {
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'revision_required' => 'Needs Revision',
        default => 'Pending',
    };

    $createdAtMs = (int)($row['created_at_ms'] ?? 0);
    $updatedAtMs = (int)($row['updated_at_ms'] ?? 0);
    $reviewedAtMs = (int)($row['reviewed_at_ms'] ?? 0);
    if ($reviewedAtMs <= 0 && !empty($row['reviewed_at'])) {
        $parsedReviewedAt = strtotime((string)$row['reviewed_at']);
        $reviewedAtMs = $parsedReviewedAt !== false ? $parsedReviewedAt * 1000 : 0;
    }

    // Legacy verified rows may predate reviewed_at. Because approved reports
    // are immutable, updated_at is a safe fallback for their approval time.
    $approvedAtMs = $workflowStatus === 'approved'
        ? ($reviewedAtMs > 0 ? $reviewedAtMs : $updatedAtMs)
        : 0;
    $historyEligibleAtMs = $approvedAtMs > 0
        ? $approvedAtMs + (op_after_action_history_hours() * 60 * 60 * 1000)
        : 0;
    $serverTimeMs = (int)round(microtime(true) * 1000);
    $isHistory = $workflowStatus === 'approved'
        && $historyEligibleAtMs > 0
        && $serverTimeMs >= $historyEligibleAtMs;
    $historyMonth = $approvedAtMs > 0
        ? date('Y-m', (int)floor($approvedAtMs / 1000))
        : null;

    return [
        'id' => (int)($row['id'] ?? 0),
        'incident_id' => (int)($row['incident_id'] ?? 0),
        'responder_id' => (int)($row['responder_id'] ?? 0),
        'incident_type' => (string)($row['incident_type'] ?? ''),
        'responder_name' => (string)($row['responder_name'] ?? ''),
        'operational_outcome' => (string)($row['operational_outcome'] ?? ''),
        'incident_summary' => (string)($row['incident_summary'] ?? ''),
        'actions_taken' => (string)($row['actions_taken'] ?? ''),
        'persons_assisted' => (int)($row['persons_assisted'] ?? 0),
        'injuries' => (int)($row['injuries'] ?? 0),
        'fatalities' => (int)($row['fatalities'] ?? 0),
        'resources_used' => (string)($row['resources_used'] ?? ''),
        'agencies_involved' => (string)($row['agencies_involved'] ?? ''),
        'handoff_details' => (string)($row['handoff_details'] ?? ''),
        'safety_issues' => (string)($row['safety_issues'] ?? ''),
        'follow_up_required' => (int)($row['follow_up_required'] ?? 0),
        'follow_up_details' => (string)($row['follow_up_details'] ?? ''),
        'lessons_learned' => (string)($row['lessons_learned'] ?? ''),
        'status' => $legacyStatus,
        'workflow_status' => $workflowStatus,
        'status_label' => $statusLabel,
        'is_editable' => in_array($legacyStatus, ['draft', 'returned'], true),
        'reviewer_user_id' => isset($row['reviewer_user_id']) && $row['reviewer_user_id'] !== null
            ? (int)$row['reviewer_user_id'] : null,
        'reviewer_notes' => (string)($row['reviewer_notes'] ?? ''),
        'submitted_at' => $row['submitted_at'] ?? null,
        'reviewed_at' => $row['reviewed_at'] ?? null,
        'reviewed_at_ms' => $reviewedAtMs,
        'approved_at_ms' => $approvedAtMs,
        'history_eligible_at_ms' => $historyEligibleAtMs,
        'is_history' => $isHistory,
        'history_month' => $historyMonth,
        'created_at_ms' => $createdAtMs,
        'updated_at_ms' => $updatedAtMs,
    ];
}

set_exception_handler(static function (Throwable $error): void {
    $reference = substr(bin2hex(random_bytes(8)), 0, 12);
    error_log('[api_app][' . $reference . '] ' . get_class($error) . ': ' . $error->getMessage());
    op_error('The server could not complete the request.', 500, ['reference' => $reference]);
});
