<?php
declare(strict_types=1);

/**
 * Shared helper for the additive responder-app endpoints.
 *
 * This file intentionally reuses the live api/api_app/connect.php and its db()
 * PDO factory. Do not replace the existing connect.php when deploying this pack.
 */
require_once __DIR__ . '/connect.php';

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/** @param array<string,mixed> $payload */
function op_json(array $payload, int $status = 200): void
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
function op_success(array $payload = [], int $status = 200): void
{
    op_json(['success' => true] + $payload, $status);
}

/** @param array<string,mixed> $payload */
function op_error(string $message, int $status = 400, array $payload = []): void
{
    op_json(['success' => false, 'message' => $message] + $payload, $status);
}

function op_require_method(string $method): void
{
    $expected = strtoupper($method);
    $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actual !== $expected) {
        header('Allow: ' . $expected);
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

    $data = is_array($_POST) ? $_POST : [];
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                op_error('Invalid JSON request body.', 400);
            }
            $data = $decoded + $data;
        }
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

function op_post_nullable_float(string $name, float $min, float $max): ?float
{
    $data = op_request_data();
    if (!array_key_exists($name, $data) || trim((string)$data[$name]) === '') {
        return null;
    }

    $value = filter_var($data[$name], FILTER_VALIDATE_FLOAT);
    if ($value === false || $value < $min || $value > $max) {
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
    $statement = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $statement->execute([$tableName]);
    return (bool)$statement->fetchColumn();
}

/** @return array<string,mixed>|null */
function op_active_responder(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        "SELECT id, name, email, role, department, unit_code, unit_type, unit_status
         FROM users
         WHERE id = ?
           AND role = 'responder'
           AND status = 'active'
           AND is_active = 1
         LIMIT 1"
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
    $statement = $pdo->prepare(
        "SELECT id, name, email, role, department
         FROM users
         WHERE id = ?
           AND role IN ('admin', 'dispatcher', 'operator')
           AND status = 'active'
           AND is_active = 1
         LIMIT 1"
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
    $statement = $pdo->prepare(
        'SELECT 1 FROM interagency_group_threads WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $statement->execute([$groupId]);
    return (bool)$statement->fetchColumn();
}

function op_is_group_member(PDO $pdo, int $groupId, int $userId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM interagency_group_members '
        . 'WHERE group_id = ? AND user_id = ? AND is_active = 1 LIMIT 1'
    );
    $statement->execute([$groupId, $userId]);
    return (bool)$statement->fetchColumn();
}

function op_responder_can_report_incident(PDO $pdo, int $incidentId, int $responderId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1
         FROM incidents i
         WHERE i.id = ?
           AND (
               i.completed_by_responder_id = ?
               OR EXISTS (
                   SELECT 1
                   FROM dispatch_operator_records d
                   WHERE d.incident_id = i.id
                     AND d.assigned_to = ?
               )
           )
         LIMIT 1'
    );
    $statement->execute([$incidentId, $responderId, $responderId]);
    return (bool)$statement->fetchColumn();
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
    $status = trim((string)($row['status'] ?? $payload['status'] ?? 'pending'));

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
        'status' => $status !== '' ? $status : 'pending',
        'created_at_ms' => (int)($row['created_at_ms'] ?? 0),
        'updated_at_ms' => (int)($row['updated_at_ms'] ?? 0),
    ];
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function op_after_action_response(array $row): array
{
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
        'status' => (string)($row['status'] ?? 'draft'),
        'reviewer_user_id' => isset($row['reviewer_user_id']) ? (int)$row['reviewer_user_id'] : null,
        'reviewer_notes' => (string)($row['reviewer_notes'] ?? ''),
        'submitted_at' => $row['submitted_at'] ?? null,
        'reviewed_at' => $row['reviewed_at'] ?? null,
        'created_at_ms' => (int)($row['created_at_ms'] ?? 0),
        'updated_at_ms' => (int)($row['updated_at_ms'] ?? 0),
    ];
}

set_exception_handler(static function (Throwable $error): void {
    error_log('[responder operational API] ' . $error->getMessage());
    op_error('The server could not complete the request.', 500);
});
