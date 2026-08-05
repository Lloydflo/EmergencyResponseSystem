<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/audit.php');
require_once $rootDir . '/includes/db.php';

date_default_timezone_set('Asia/Manila');

$pageTitle = 'Operational Audit Trail';
$adminName = trim((string)($_SESSION['user_name'] ?? 'Admin'));

function audit_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function audit_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $key = spl_object_id($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

/** @return array<string,bool> */
function audit_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }
    $key = spl_object_id($pdo) . ':' . $table;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $columns = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $name = (string)($row['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
    } catch (Throwable $e) {
        // The page will show a controlled database error later.
    }
    return $cache[$key] = $columns;
}

/** @return array<string,string> */
function audit_log_expressions(array $columns, bool $usersAvailable): array
{
    $userName = $usersAvailable ? "NULLIF(u.name, '')" : 'NULL';
    $userEmail = $usersAvailable ? "NULLIF(u.email, '')" : 'NULL';
    $userRole = $usersAvailable ? "NULLIF(LOWER(u.role), '')" : 'NULL';

    $actorName = isset($columns['actor_name'])
        ? "COALESCE(NULLIF(a.actor_name, ''), {$userName}, 'System')"
        : "COALESCE({$userName}, 'System')";
    $actorEmail = isset($columns['actor_email'])
        ? "COALESCE(NULLIF(a.actor_email, ''), {$userEmail}, '')"
        : "COALESCE({$userEmail}, '')";
    $actorRole = isset($columns['actor_role'])
        ? "LOWER(COALESCE(NULLIF(a.actor_role, ''), {$userRole}, CASE WHEN a.user_id IS NULL THEN 'system' ELSE 'user' END))"
        : "LOWER(COALESCE({$userRole}, CASE WHEN a.user_id IS NULL THEN 'system' ELSE 'user' END))";

    $sourceFallback = "CASE
        WHEN {$actorRole} = 'responder' THEN 'responder_app'
        WHEN {$actorRole} IN ('dispatcher', 'operator') THEN 'dispatcher_web'
        WHEN {$actorRole} = 'admin' THEN 'admin_web'
        WHEN {$actorRole} = 'system' THEN 'system'
        ELSE 'server_api'
    END";
    $source = isset($columns['source_channel'])
        ? "LOWER(COALESCE(NULLIF(a.source_channel, ''), {$sourceFallback}))"
        : $sourceFallback;

    $haystack = "LOWER(CONCAT(COALESCE(a.action, ''), ' ', COALESCE(a.entity_type, '')))";
    $categoryFallback = "CASE
        WHEN {$haystack} REGEXP 'login|logout|auth|otp|session' THEN 'authentication'
        WHEN {$haystack} REGEXP 'call|intake|hotline' THEN 'call_intake'
        WHEN {$haystack} REGEXP 'dispatch|allocation' THEN 'dispatch'
        WHEN {$haystack} REGEXP 'navigate|navigation|enroute|en_route|route' THEN 'navigation'
        WHEN {$haystack} REGEXP 'arriv|on_scene' THEN 'arrival'
        WHEN {$haystack} REGEXP 'complete|resolved|cleared|closure' THEN 'completion'
        WHEN {$haystack} REGEXP 'after_action|report|review|approval|verified' THEN 'report_review'
        WHEN {$haystack} REGEXP 'resource|backup|equipment|supply' THEN 'resource'
        WHEN {$haystack} REGEXP 'chat|message|broadcast|interagency|coordination' THEN 'coordination'
        WHEN {$haystack} REGEXP 'presence|online|offline|location' THEN 'presence'
        WHEN {$haystack} REGEXP 'assignment|acknowledg|received' THEN 'assignment'
        WHEN {$haystack} REGEXP 'incident|priority|triage' THEN 'incident'
        WHEN {$haystack} REGEXP 'user|account|setting|admin' THEN 'administration'
        ELSE 'system'
    END";
    $category = isset($columns['event_category'])
        ? "LOWER(COALESCE(NULLIF(a.event_category, ''), {$categoryFallback}))"
        : $categoryFallback;

    $outcomeFallback = "CASE
        WHEN LOWER(COALESCE(a.action, '')) REGEXP 'failed|failure|error|rejected|declined|denied' THEN 'failed'
        WHEN LOWER(COALESCE(a.action, '')) REGEXP 'cancelled|canceled|returned|warning' THEN 'warning'
        ELSE 'success'
    END";
    $outcome = isset($columns['event_outcome'])
        ? "LOWER(COALESCE(NULLIF(a.event_outcome, ''), {$outcomeFallback}))"
        : $outcomeFallback;

    return [
        'actor_name' => $actorName,
        'actor_email' => $actorEmail,
        'actor_role' => $actorRole,
        'source' => $source,
        'category' => $category,
        'outcome' => $outcome,
        'reference' => isset($columns['reference_no']) ? "COALESCE(NULLIF(a.reference_no, ''), '')" : "''",
        'metadata' => isset($columns['metadata_json']) ? 'a.metadata_json' : 'NULL',
        'ip' => isset($columns['ip_address']) ? 'a.ip_address' : 'NULL',
        'user_agent' => isset($columns['user_agent']) ? 'a.user_agent' : 'NULL',
        'request_id' => isset($columns['request_id']) ? 'a.request_id' : 'NULL',
    ];
}

function audit_label(string $value): string
{
    $value = strtolower(trim($value));
    $labels = [
        'call_received' => 'Emergency Call Received',
        'call_accepted' => 'Emergency Call Accepted',
        'call_rejected' => 'Emergency Call Rejected',
        'call_ended' => 'Emergency Call Ended',
        'call_logged' => 'Emergency Call Logged',
        'incident_created' => 'Incident Record Created',
        'dispatch_confirmed' => 'Response Units Dispatched',
        'dispatch_failed' => 'Dispatch Attempt Failed',
        'assignment_received' => 'Assignment Received',
        'navigation_started' => 'Navigation Started',
        'navigation_cancelled' => 'Navigation Cancelled',
        'route_tracking_started' => 'Route Tracking Started',
        'responder_on_scene' => 'Responder On Scene',
        'route_arrived' => 'Incident Location Reached',
        'assignment_completed' => 'Assignment Completed',
        'incident_resolved' => 'Incident Completed',
        'after_action_report_saved' => 'Report Saved as Pending',
        'after_action_report_submitted' => 'Report Submitted for Review',
        'after_action_report_approved' => 'Report Approved',
        'after_action_report_returned' => 'Report Returned for Revision',
        'backup_requested' => 'Backup Requested',
        'backup_request_cancelled' => 'Backup Request Cancelled',
        'resource_requested' => 'Resource Requested',
        'resource_request_cancelled' => 'Resource Request Cancelled',
        'responder_login' => 'Responder Signed In',
        'responder_logout' => 'Responder Signed Out',
        'login' => 'Web User Signed In',
        'logout' => 'Web User Signed Out',
        'chat' => 'Coordination Message Sent',
        'message' => 'Message Sent',
        'incident_review_approved' => 'Incident Validated',
        'incident_review_rejected' => 'Incident Rejected',
    ];
    if (isset($labels[$value])) {
        return $labels[$value];
    }
    if ($value === '') {
        return 'System Event';
    }
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function audit_source_label(string $value): string
{
    $labels = [
        'responder_app' => 'Responder App',
        'dispatcher_web' => 'Dispatcher Website',
        'admin_web' => 'Admin Website',
        'external_api' => 'External API',
        'server_api' => 'Server API',
        'system' => 'System',
    ];
    $value = strtolower(trim($value));
    return $labels[$value] ?? audit_label($value);
}

function audit_category_label(string $value): string
{
    $labels = [
        'authentication' => 'Authentication',
        'call_intake' => 'Call Intake',
        'dispatch' => 'Dispatch',
        'assignment' => 'Assignment',
        'navigation' => 'Navigation',
        'arrival' => 'Arrival',
        'completion' => 'Completion',
        'report_review' => 'Report Review',
        'resource' => 'Resources / Backup',
        'coordination' => 'Coordination',
        'presence' => 'Presence / Location',
        'incident' => 'Incident Management',
        'administration' => 'Administration',
        'system' => 'System',
    ];
    $value = strtolower(trim($value));
    return $labels[$value] ?? audit_label($value);
}

function audit_format_date(?string $value, bool $withSeconds = true): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Not recorded';
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Manila'));
        return $date->format($withSeconds ? 'M d, Y h:i:s A' : 'M d, Y h:i A');
    } catch (Throwable $e) {
        return $value;
    }
}

function audit_iso_datetime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM);
    } catch (Throwable $e) {
        return '';
    }
}

function audit_limit_text(string $value, int $limit = 260): string
{
    $value = trim((string)preg_replace('/\s+/u', ' ', $value));
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length <= $limit) {
        return $value;
    }
    $slice = function_exists('mb_substr') ? mb_substr($value, 0, $limit - 1) : substr($value, 0, $limit - 1);
    return rtrim($slice) . '…';
}

function audit_details_summary(?string $details): string
{
    $details = trim((string)$details);
    if ($details === '') {
        return 'No additional details were recorded.';
    }
    if ($details[0] === '{' || $details[0] === '[') {
        $decoded = json_decode($details, true);
        if (is_array($decoded)) {
            foreach (['message', 'summary', 'description', 'text'] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string)$decoded[$key]) !== '') {
                    return audit_limit_text((string)$decoded[$key]);
                }
            }
        }
    }
    return audit_limit_text($details);
}

/** @return array<string,mixed> */
function audit_decode_metadata(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function audit_json_pretty(?string $raw): string
{
    $decoded = audit_decode_metadata($raw);
    if ($decoded === []) {
        return '';
    }
    $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '';
}

function audit_valid_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return '';
    }
    return $date->format('Y-m-d');
}

function audit_query_url(array $overrides = []): string
{
    $params = $_GET;
    unset($params['export']);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return 'admin/audit.php' . ($params ? ('?' . http_build_query($params)) : '');
}

function audit_csv_value($value): string
{
    $value = (string)$value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function audit_datetime_key(?string $value): ?int
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : $timestamp;
}

/** @param array<string,string|null> $milestones */
function audit_set_milestone(array &$milestones, string $key, ?string $timestamp): void
{
    $timestamp = trim((string)$timestamp);
    if ($timestamp === '' || audit_datetime_key($timestamp) === null) {
        return;
    }
    if (!isset($milestones[$key]) || audit_datetime_key($timestamp) < audit_datetime_key($milestones[$key])) {
        $milestones[$key] = $timestamp;
    }
}

function audit_duration_label(?string $start, ?string $end): string
{
    $from = audit_datetime_key($start);
    $to = audit_datetime_key($end);
    if ($from === null || $to === null || $to < $from) {
        return 'Not yet recorded';
    }
    $seconds = $to - $from;
    if ($seconds < 60) {
        return $seconds . ' sec';
    }
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0 || $parts === []) {
        $parts[] = $minutes . 'm';
    }
    return implode(' ', $parts);
}

/** @param array<int,array<string,mixed>> $events */
function audit_add_timeline_event(array &$events, array $event): void
{
    $timestamp = trim((string)($event['timestamp'] ?? ''));
    if ($timestamp === '' || audit_datetime_key($timestamp) === null) {
        return;
    }
    $event['timestamp'] = $timestamp;
    $event['stage_key'] = (string)($event['stage_key'] ?? 'system');
    $event['label'] = (string)($event['label'] ?? 'Operational Event');
    $event['actor'] = trim((string)($event['actor'] ?? '')) ?: 'System';
    $event['role'] = strtolower(trim((string)($event['role'] ?? 'system')));
    $event['source'] = strtolower(trim((string)($event['source'] ?? 'system')));
    $event['outcome'] = strtolower(trim((string)($event['outcome'] ?? 'success')));
    $event['details'] = trim((string)($event['details'] ?? ''));
    $event['unit'] = trim((string)($event['unit'] ?? ''));
    $events[] = $event;
}

/** @return array<string,mixed> */
function audit_load_lifecycle(PDO $pdo, string $reference, array $auditColumns, bool $usersAvailable): array
{
    $result = [
        'found' => false,
        'reference' => $reference,
        'incident' => null,
        'call' => null,
        'events' => [],
        'durations' => [],
    ];
    $events = [];
    $milestones = [];
    $stagesFromAudit = [];
    $call = null;
    $incident = null;

    if (audit_table_exists($pdo, 'calls')) {
        $columns = audit_table_columns($pdo, 'calls');
        if (isset($columns['reference_no'])) {
            $stmt = $pdo->prepare('SELECT * FROM calls WHERE reference_no = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$reference]);
            $call = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if (audit_table_exists($pdo, 'incidents')) {
        $columns = audit_table_columns($pdo, 'incidents');
        if (isset($columns['reference_no'])) {
            $stmt = $pdo->prepare('SELECT * FROM incidents WHERE reference_no = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$reference]);
            $incident = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$incident && $call && isset($columns['reported_by_call_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM incidents WHERE reported_by_call_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([(int)$call['id']]);
            $incident = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if (!$call && $incident && !empty($incident['reported_by_call_id']) && audit_table_exists($pdo, 'calls')) {
        $stmt = $pdo->prepare('SELECT * FROM calls WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$incident['reported_by_call_id']]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $result['call'] = $call;
    $result['incident'] = $incident;
    $incidentId = (int)($incident['id'] ?? 0);
    $callId = (int)($call['id'] ?? 0);

    // Structured audit events are the primary lifecycle source. Canonical
    // workflow tables below fill older records that predate structured audit.
    $expr = audit_log_expressions($auditColumns, $usersAvailable);
    $joins = $usersAvailable ? ' LEFT JOIN users u ON u.id = a.user_id ' : '';
    $or = [];
    $params = [];
    if (isset($auditColumns['reference_no'])) {
        $or[] = 'a.reference_no = :life_reference';
        $params[':life_reference'] = $reference;
    }
    $or[] = 'a.details LIKE :life_details';
    $params[':life_details'] = '%' . $reference . '%';
    if ($incidentId > 0) {
        $or[] = "(a.entity_id = :life_incident_id AND a.entity_type IN ('incident','navigation','route','arrival'))";
        $params[':life_incident_id'] = $incidentId;
    }
    if ($callId > 0) {
        $or[] = "(a.entity_id = :life_call_id AND a.entity_type = 'call')";
        $params[':life_call_id'] = $callId;
    }

    $stageMap = [
        'call_received' => ['call_received', 'Call Received', 'call_received'],
        'call_accepted' => ['call_accepted', 'Call Accepted by Dispatcher', 'call_accepted'],
        'call_rejected' => ['call_rejected', 'Call Rejected by Dispatcher', null],
        'call_ended' => ['call_ended', 'Call Session Ended', null],
        'call_logged' => ['call_received', 'Call Logged', 'call_received'],
        'incident_created' => ['incident_created', 'Incident Logged', 'incident_created'],
        'dispatch_confirmed' => ['dispatch', 'Units Dispatched', 'dispatch'],
        'dispatch_failed' => ['dispatch_attempt', 'Dispatch Attempt Failed', null],
        'assignment_received' => ['assignment_received', 'Assignment Received', 'assignment_received'],
        'navigation_started' => ['navigation_started', 'Navigation Started', 'navigation_started'],
        'navigation_cancelled' => ['navigation_cancelled', 'Navigation Cancelled', null],
        'route_tracking_started' => ['navigation_started', 'Route Tracking Started', 'navigation_started'],
        'responder_on_scene' => ['arrival', 'Responder On Scene', 'arrival'],
        'route_arrived' => ['arrival', 'Incident Location Reached', 'arrival'],
        'assignment_completed' => ['completion', 'Assignment Completed', 'completion'],
        'incident_resolved' => ['completion', 'Incident Completed', 'completion'],
        'after_action_report_saved' => ['report_pending', 'Report Saved as Pending', null],
        'after_action_report_submitted' => ['report_submitted', 'Report Submitted for Review', 'report_submitted'],
        'after_action_report_approved' => ['report_reviewed', 'Report Approved', 'report_reviewed'],
        'after_action_report_returned' => ['report_reviewed', 'Report Returned for Revision', 'report_reviewed'],
        'backup_requested' => ['support_request', 'Backup Requested', null],
        'backup_request_cancelled' => ['support_request', 'Backup Request Cancelled', null],
        'resource_requested' => ['support_request', 'Resource Requested', null],
        'resource_request_cancelled' => ['support_request', 'Resource Request Cancelled', null],
    ];

    try {
        $select = "SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.created_at,
            {$expr['actor_name']} AS actor_name,
            {$expr['actor_role']} AS actor_role,
            {$expr['source']} AS source_channel,
            {$expr['outcome']} AS event_outcome,
            {$expr['metadata']} AS metadata_json
            FROM activity_log a {$joins}
            WHERE (" . implode(' OR ', $or) . ")
            ORDER BY a.created_at ASC, a.id ASC LIMIT 500";
        $stmt = $pdo->prepare($select);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $action = strtolower(trim((string)($row['action'] ?? '')));
            if (!isset($stageMap[$action])) {
                continue;
            }
            [$stageKey, $label, $milestone] = $stageMap[$action];
            $metadata = audit_decode_metadata($row['metadata_json'] ?? null);
            $unit = trim((string)($metadata['unit_code'] ?? $metadata['unit_identifier'] ?? ''));
            audit_add_timeline_event($events, [
                'stage_key' => $stageKey,
                'label' => $label,
                'timestamp' => (string)$row['created_at'],
                'actor' => (string)$row['actor_name'],
                'role' => (string)$row['actor_role'],
                'source' => (string)$row['source_channel'],
                'outcome' => (string)$row['event_outcome'],
                'details' => audit_details_summary($row['details'] ?? ''),
                'unit' => $unit,
                'origin' => 'audit',
            ]);
            $stagesFromAudit[$stageKey] = true;
            if (is_string($milestone)) {
                audit_set_milestone($milestones, $milestone, (string)$row['created_at']);
            }
        }
    } catch (Throwable $e) {
        // Canonical tables below still provide a usable lifecycle.
    }

    if ($call && !empty($call['received_at'])) {
        audit_set_milestone($milestones, 'call_received', (string)$call['received_at']);
        if (empty($stagesFromAudit['call_received'])) {
            audit_add_timeline_event($events, [
                'stage_key' => 'call_received',
                'label' => 'Call Received',
                'timestamp' => (string)$call['received_at'],
                'actor' => 'Dispatcher / Call Intake',
                'role' => 'dispatcher',
                'source' => 'dispatcher_web',
                'outcome' => 'success',
                'details' => 'Emergency call entered the system.',
                'origin' => 'canonical',
            ]);
        }
    }
    if ($incident && !empty($incident['created_at'])) {
        audit_set_milestone($milestones, 'incident_created', (string)$incident['created_at']);
        if (empty($stagesFromAudit['incident_created'])) {
            audit_add_timeline_event($events, [
                'stage_key' => 'incident_created',
                'label' => 'Incident Logged',
                'timestamp' => (string)$incident['created_at'],
                'actor' => 'Dispatcher / System',
                'role' => 'dispatcher',
                'source' => 'dispatcher_web',
                'outcome' => 'success',
                'details' => 'Incident record was created for validation and dispatch.',
                'origin' => 'canonical',
            ]);
        }
    }

    if (audit_table_exists($pdo, 'dispatches')) {
        $dispatchColumns = audit_table_columns($pdo, 'dispatches');
        $where = [];
        $dispatchParams = [];
        if ($incidentId > 0 && isset($dispatchColumns['incident_id'])) {
            $where[] = 'd.incident_id = ?';
            $dispatchParams[] = $incidentId;
        }
        if (isset($dispatchColumns['reference_no'])) {
            $where[] = 'd.reference_no = ?';
            $dispatchParams[] = $reference;
        }
        if ($where) {
            $unitJoin = audit_table_exists($pdo, 'units') && isset($dispatchColumns['unit_id'])
                ? ' LEFT JOIN units un ON un.id = d.unit_id '
                : '';
            $unitSelect = $unitJoin !== '' ? ', un.identifier AS unit_identifier, un.unit_type AS unit_type' : ", '' AS unit_identifier, '' AS unit_type";
            try {
                $stmt = $pdo->prepare(
                    'SELECT d.*' . $unitSelect . ' FROM dispatches d ' . $unitJoin
                    . ' WHERE (' . implode(' OR ', $where) . ') ORDER BY d.assigned_at ASC, d.id ASC'
                );
                $stmt->execute($dispatchParams);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dispatch) {
                    $unit = trim((string)($dispatch['unit_identifier'] ?? ''));
                    $unitLabel = $unit !== '' ? ('Unit ' . $unit) : ('Unit record #' . (int)($dispatch['unit_id'] ?? 0));
                    $responderName = 'Assigned Responder';
                    if ($unit !== '' && $usersAvailable) {
                        try {
                            $userStmt = $pdo->prepare(
                                "SELECT name FROM users WHERE LOWER(role) = 'responder' AND UPPER(TRIM(unit_code)) = UPPER(TRIM(?)) ORDER BY id DESC LIMIT 1"
                            );
                            $userStmt->execute([$unit]);
                            $responderName = trim((string)$userStmt->fetchColumn()) ?: $responderName;
                        } catch (Throwable $e) {
                            // Keep generic actor label.
                        }
                    }
                    $canonical = [
                        ['assigned_at', 'dispatch', 'Units Dispatched', 'Dispatcher / Dispatch Center', 'dispatcher', 'dispatcher_web', 'dispatch', $unitLabel . ' was assigned.'],
                        ['acknowledged_at', 'assignment_received', 'Assignment Received', $responderName, 'responder', 'responder_app', 'assignment_received', $unitLabel . ' acknowledged the assignment.'],
                        ['enroute_at', 'navigation_started', 'Navigation Started', $responderName, 'responder', 'responder_app', 'navigation_started', $unitLabel . ' started travel to the incident.'],
                        ['on_scene_at', 'arrival', 'Responder On Scene', $responderName, 'responder', 'responder_app', 'arrival', $unitLabel . ' reported arrival on scene.'],
                        ['cleared_at', 'completion', 'Assignment Completed', $responderName, 'responder', 'responder_app', 'completion', $unitLabel . ' cleared the incident assignment.'],
                    ];
                    foreach ($canonical as [$column, $stage, $label, $actor, $role, $source, $milestone, $detail]) {
                        $timestamp = trim((string)($dispatch[$column] ?? ''));
                        if ($timestamp === '') {
                            continue;
                        }
                        audit_set_milestone($milestones, $milestone, $timestamp);
                        if (empty($stagesFromAudit[$stage])) {
                            audit_add_timeline_event($events, [
                                'stage_key' => $stage,
                                'label' => $label,
                                'timestamp' => $timestamp,
                                'actor' => $actor,
                                'role' => $role,
                                'source' => $source,
                                'outcome' => 'success',
                                'details' => $detail,
                                'unit' => $unit,
                                'origin' => 'canonical',
                            ]);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Legacy dispatch schema may not expose all optional columns.
            }
        }
    }

    if ($incidentId > 0 && audit_table_exists($pdo, 'responder_route_summary')) {
        try {
            $routeJoin = $usersAvailable ? ' LEFT JOIN users ru ON ru.id = rs.responder_id ' : '';
            $routeActor = $usersAvailable ? "COALESCE(NULLIF(ru.name, ''), 'Responder')" : "'Responder'";
            $stmt = $pdo->prepare(
                "SELECT rs.*, {$routeActor} AS responder_name FROM responder_route_summary rs {$routeJoin} WHERE rs.incident_id = ?"
            );
            $stmt->execute([$incidentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $route) {
                $actor = trim((string)($route['responder_name'] ?? 'Responder')) ?: 'Responder';
                if (!empty($route['started_at'])) {
                    audit_set_milestone($milestones, 'navigation_started', (string)$route['started_at']);
                    if (empty($stagesFromAudit['navigation_started'])) {
                        audit_add_timeline_event($events, [
                            'stage_key' => 'navigation_started',
                            'label' => 'Route Tracking Started',
                            'timestamp' => (string)$route['started_at'],
                            'actor' => $actor,
                            'role' => 'responder',
                            'source' => 'responder_app',
                            'outcome' => 'success',
                            'details' => 'Live navigation route tracking began.',
                            'origin' => 'canonical',
                        ]);
                    }
                }
                if (!empty($route['arrived_at'])) {
                    audit_set_milestone($milestones, 'arrival', (string)$route['arrived_at']);
                    if (empty($stagesFromAudit['arrival'])) {
                        $distance = isset($route['total_distance_meters']) ? round((float)$route['total_distance_meters'] / 1000, 2) : null;
                        audit_add_timeline_event($events, [
                            'stage_key' => 'arrival',
                            'label' => 'Incident Location Reached',
                            'timestamp' => (string)$route['arrived_at'],
                            'actor' => $actor,
                            'role' => 'responder',
                            'source' => 'responder_app',
                            'outcome' => 'success',
                            'details' => $distance !== null ? ('Route completed over approximately ' . $distance . ' km.') : 'Route arrival was recorded.',
                            'origin' => 'canonical',
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            // Route analytics are optional in older deployments.
        }
    }

    $completionAt = trim((string)($incident['completed_at'] ?? $incident['resolved_at'] ?? ''));
    if ($completionAt !== '') {
        audit_set_milestone($milestones, 'completion', $completionAt);
        if (empty($stagesFromAudit['completion'])) {
            audit_add_timeline_event($events, [
                'stage_key' => 'completion',
                'label' => 'Incident Completed',
                'timestamp' => $completionAt,
                'actor' => 'Responder / Operations',
                'role' => 'responder',
                'source' => 'responder_app',
                'outcome' => 'success',
                'details' => 'The incident was marked completed or resolved.',
                'origin' => 'canonical',
            ]);
        }
    }

    if ($incidentId > 0 && audit_table_exists($pdo, 'responder_after_action_reports')) {
        try {
            $reviewJoin = $usersAvailable ? ' LEFT JOIN users reviewer ON reviewer.id = aar.reviewer_user_id ' : '';
            $reviewName = $usersAvailable ? "COALESCE(NULLIF(reviewer.name, ''), 'Admin Reviewer')" : "'Admin Reviewer'";
            $stmt = $pdo->prepare(
                "SELECT aar.*, {$reviewName} AS reviewer_name FROM responder_after_action_reports aar {$reviewJoin} WHERE aar.incident_id = ? ORDER BY aar.id ASC"
            );
            $stmt->execute([$incidentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $report) {
                $responder = trim((string)($report['responder_name'] ?? 'Responder')) ?: 'Responder';
                if (!empty($report['submitted_at'])) {
                    audit_set_milestone($milestones, 'report_submitted', (string)$report['submitted_at']);
                    if (empty($stagesFromAudit['report_submitted'])) {
                        audit_add_timeline_event($events, [
                            'stage_key' => 'report_submitted',
                            'label' => 'Report Submitted for Review',
                            'timestamp' => (string)$report['submitted_at'],
                            'actor' => $responder,
                            'role' => 'responder',
                            'source' => 'responder_app',
                            'outcome' => 'success',
                            'details' => 'After-action report was submitted to the admin review queue.',
                            'origin' => 'canonical',
                        ]);
                    }
                }
                if (!empty($report['reviewed_at'])) {
                    audit_set_milestone($milestones, 'report_reviewed', (string)$report['reviewed_at']);
                    if (empty($stagesFromAudit['report_reviewed'])) {
                        $status = strtolower(trim((string)($report['status'] ?? '')));
                        $approved = in_array($status, ['verified', 'approved'], true);
                        audit_add_timeline_event($events, [
                            'stage_key' => 'report_reviewed',
                            'label' => $approved ? 'Report Approved' : 'Report Returned / Reviewed',
                            'timestamp' => (string)$report['reviewed_at'],
                            'actor' => (string)($report['reviewer_name'] ?? 'Admin Reviewer'),
                            'role' => 'admin',
                            'source' => 'admin_web',
                            'outcome' => $approved ? 'success' : 'warning',
                            'details' => $approved ? 'Admin approved the after-action report.' : 'Admin reviewed the report and requested follow-up or revision.',
                            'origin' => 'canonical',
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            // Report review module is optional in older installations.
        }
    }

    usort($events, static function (array $left, array $right): int {
        $timeCompare = (audit_datetime_key((string)$left['timestamp']) ?? 0) <=> (audit_datetime_key((string)$right['timestamp']) ?? 0);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        return strcmp((string)$left['label'], (string)$right['label']);
    });

    $durationDefinitions = [
        ['Call receipt to acceptance', 'call_received', 'call_accepted'],
        ['Acceptance to incident logging', 'call_accepted', 'incident_created'],
        ['Incident logging to dispatch', 'incident_created', 'dispatch'],
        ['Dispatch to assignment receipt', 'dispatch', 'assignment_received'],
        ['Receipt to navigation', 'assignment_received', 'navigation_started'],
        ['Navigation to arrival', 'navigation_started', 'arrival'],
        ['Arrival to completion', 'arrival', 'completion'],
        ['Completion to report submission', 'completion', 'report_submitted'],
        ['Submission to admin review', 'report_submitted', 'report_reviewed'],
    ];
    $durations = [];
    foreach ($durationDefinitions as [$label, $startKey, $endKey]) {
        $durations[] = [
            'label' => $label,
            'value' => audit_duration_label($milestones[$startKey] ?? null, $milestones[$endKey] ?? null),
            'start' => $milestones[$startKey] ?? null,
            'end' => $milestones[$endKey] ?? null,
        ];
    }

    $result['found'] = $call !== null || $incident !== null || $events !== [];
    $result['events'] = $events;
    $result['durations'] = $durations;
    return $result;
}

$allowedRoles = ['admin', 'dispatcher', 'operator', 'responder', 'system', 'user', 'viewer'];
$allowedSources = ['admin_web', 'dispatcher_web', 'responder_app', 'external_api', 'server_api', 'system'];
$allowedCategories = [
    'authentication', 'call_intake', 'incident', 'dispatch', 'assignment', 'navigation',
    'arrival', 'completion', 'report_review', 'resource', 'coordination', 'presence',
    'administration', 'system',
];
$allowedOutcomes = ['success', 'warning', 'failed', 'info'];
$allowedPageSizes = [25, 50, 100];

$search = substr(trim((string)($_GET['q'] ?? '')), 0, 160);
$roleFilter = strtolower(trim((string)($_GET['role'] ?? '')));
$sourceFilter = strtolower(trim((string)($_GET['source'] ?? '')));
$categoryFilter = strtolower(trim((string)($_GET['category'] ?? '')));
$outcomeFilter = strtolower(trim((string)($_GET['outcome'] ?? '')));
$referenceFilter = substr(trim((string)($_GET['reference'] ?? '')), 0, 64);
$dateFrom = audit_valid_date((string)($_GET['date_from'] ?? ''));
$dateTo = audit_valid_date((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 50);
$perPage = in_array($perPage, $allowedPageSizes, true) ? $perPage : 50;

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = '';
}
if (!in_array($sourceFilter, $allowedSources, true)) {
    $sourceFilter = '';
}
if (!in_array($categoryFilter, $allowedCategories, true)) {
    $categoryFilter = '';
}
if (!in_array($outcomeFilter, $allowedOutcomes, true)) {
    $outcomeFilter = '';
}

$auditRows = [];
$matchingCount = 0;
$pageCount = 1;
$offset = 0;
$loadError = '';
$lifecycle = null;
$stats = [
    'total' => 0,
    'today' => 0,
    'responder' => 0,
    'dispatcher' => 0,
    'attention' => 0,
    'latest' => null,
];

try {
    $pdo = get_db_connection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }
    if (!audit_table_exists($pdo, 'activity_log')) {
        throw new RuntimeException('The activity_log table is not installed.');
    }

    $auditColumns = audit_table_columns($pdo, 'activity_log');
    if (!isset($auditColumns['id'], $auditColumns['action'], $auditColumns['created_at'])) {
        throw new RuntimeException('The activity_log table is missing required columns.');
    }
    $usersAvailable = audit_table_exists($pdo, 'users');
    $expr = audit_log_expressions($auditColumns, $usersAvailable);
    $joinSql = $usersAvailable ? ' LEFT JOIN users u ON u.id = a.user_id ' : '';

    $statsSql = "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN DATE(a.created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today,
        COALESCE(SUM(CASE WHEN {$expr['actor_role']} = 'responder' THEN 1 ELSE 0 END), 0) AS responder,
        COALESCE(SUM(CASE WHEN {$expr['actor_role']} IN ('dispatcher','operator') THEN 1 ELSE 0 END), 0) AS dispatcher,
        COALESCE(SUM(CASE WHEN {$expr['outcome']} IN ('failed','warning') THEN 1 ELSE 0 END), 0) AS attention,
        MAX(a.created_at) AS latest
        FROM activity_log a {$joinSql}";
    $statsRow = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['total', 'today', 'responder', 'dispatcher', 'attention'] as $key) {
        $stats[$key] = (int)($statsRow[$key] ?? 0);
    }
    $stats['latest'] = $statsRow['latest'] ?? null;

    $where = [];
    $params = [];
    if ($search !== '') {
        $searchExpressions = [
            'a.action', 'a.entity_type', 'a.details', $expr['actor_name'], $expr['actor_email'], $expr['reference'],
        ];
        if (isset($auditColumns['metadata_json'])) {
            $searchExpressions[] = 'a.metadata_json';
        }
        $parts = [];
        foreach ($searchExpressions as $index => $searchExpression) {
            $param = ':q' . $index;
            $parts[] = $searchExpression . ' LIKE ' . $param;
            $params[$param] = '%' . $search . '%';
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    if ($roleFilter !== '') {
        $where[] = $expr['actor_role'] . ' = :role_filter';
        $params[':role_filter'] = $roleFilter;
    }
    if ($sourceFilter !== '') {
        $where[] = $expr['source'] . ' = :source_filter';
        $params[':source_filter'] = $sourceFilter;
    }
    if ($categoryFilter !== '') {
        $where[] = $expr['category'] . ' = :category_filter';
        $params[':category_filter'] = $categoryFilter;
    }
    if ($outcomeFilter !== '') {
        $where[] = $expr['outcome'] . ' = :outcome_filter';
        $params[':outcome_filter'] = $outcomeFilter;
    }
    if ($referenceFilter !== '') {
        if (isset($auditColumns['reference_no'])) {
            $where[] = '(' . $expr['reference'] . ' = :reference_filter OR a.details LIKE :reference_details)';
            $params[':reference_filter'] = $referenceFilter;
            $params[':reference_details'] = '%' . $referenceFilter . '%';
        } else {
            $where[] = 'a.details LIKE :reference_details';
            $params[':reference_details'] = '%' . $referenceFilter . '%';
        }
    }
    if ($dateFrom !== '') {
        $where[] = 'a.created_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $dateToExclusive = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $where[] = 'a.created_at < :date_to';
        $params[':date_to'] = $dateToExclusive;
    }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM activity_log a ' . $joinSql . $whereSql);
    $countStmt->execute($params);
    $matchingCount = (int)$countStmt->fetchColumn();
    $pageCount = max(1, (int)ceil($matchingCount / $perPage));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $perPage;

    $selectSql = "SELECT
        a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.details, a.created_at,
        {$expr['actor_name']} AS actor_name,
        {$expr['actor_email']} AS actor_email,
        {$expr['actor_role']} AS actor_role,
        {$expr['source']} AS source_channel,
        {$expr['category']} AS event_category,
        {$expr['outcome']} AS event_outcome,
        {$expr['reference']} AS reference_no,
        {$expr['metadata']} AS metadata_json,
        {$expr['ip']} AS ip_address,
        {$expr['user_agent']} AS user_agent,
        {$expr['request_id']} AS request_id
        FROM activity_log a {$joinSql} {$whereSql}
        ORDER BY a.created_at DESC, a.id DESC";

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $exportStmt = $pdo->prepare($selectSql . ' LIMIT 5000');
        $exportStmt->execute($params);
        $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = 'operational-audit-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to open CSV output stream.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'Log No.', 'Date and Time (Asia/Manila)', 'Actor', 'Actor Role', 'Source',
            'Category', 'Process / Action', 'Incident Reference', 'Outcome', 'Details',
            'Metadata', 'Request ID', 'IP Address',
        ]);
        foreach ($exportRows as $index => $row) {
            fputcsv($output, array_map('audit_csv_value', [
                (string)($index + 1),
                (string)($row['created_at'] ?? ''),
                (string)($row['actor_name'] ?? 'System'),
                (string)($row['actor_role'] ?? 'system'),
                audit_source_label((string)($row['source_channel'] ?? 'system')),
                audit_category_label((string)($row['event_category'] ?? 'system')),
                audit_label((string)($row['action'] ?? '')),
                (string)($row['reference_no'] ?? ''),
                (string)($row['event_outcome'] ?? 'success'),
                audit_details_summary($row['details'] ?? ''),
                audit_json_pretty($row['metadata_json'] ?? null),
                (string)($row['request_id'] ?? ''),
                (string)($row['ip_address'] ?? ''),
            ]));
        }
        fclose($output);
        exit;
    }

    $rowStmt = $pdo->prepare($selectSql . ' LIMIT :audit_limit OFFSET :audit_offset');
    foreach ($params as $name => $value) {
        $rowStmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $rowStmt->bindValue(':audit_limit', $perPage, PDO::PARAM_INT);
    $rowStmt->bindValue(':audit_offset', $offset, PDO::PARAM_INT);
    $rowStmt->execute();
    $auditRows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($referenceFilter !== '') {
        $lifecycle = audit_load_lifecycle($pdo, $referenceFilter, $auditColumns, $usersAvailable);
    }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$showStart = $matchingCount > 0 ? $offset + 1 : 0;
$showEnd = min($offset + count($auditRows), $matchingCount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo audit_h($pageTitle); ?></title>
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <style>
        :root {
            --audit-bg: #f4f7fb;
            --audit-card: #ffffff;
            --audit-text: #172033;
            --audit-muted: #64748b;
            --audit-border: #dbe4ee;
            --audit-primary: #0f766e;
            --audit-primary-dark: #115e59;
            --audit-soft: #eef8f7;
            --audit-header: #f8fafc;
            --audit-success: #047857;
            --audit-success-bg: #d1fae5;
            --audit-warning: #a16207;
            --audit-warning-bg: #fef3c7;
            --audit-danger: #b91c1c;
            --audit-danger-bg: #fee2e2;
            --audit-info: #1d4ed8;
            --audit-info-bg: #dbeafe;
            --audit-shadow: 0 8px 24px rgba(15, 23, 42, .07);
        }
        html[data-theme="dark"] {
            --audit-bg: #0b1220;
            --audit-card: #111827;
            --audit-text: #f8fafc;
            --audit-muted: #94a3b8;
            --audit-border: #334155;
            --audit-primary: #2dd4bf;
            --audit-primary-dark: #14b8a6;
            --audit-soft: #102725;
            --audit-header: #172033;
            --audit-success: #6ee7b7;
            --audit-success-bg: #063c32;
            --audit-warning: #fde68a;
            --audit-warning-bg: #49340a;
            --audit-danger: #fca5a5;
            --audit-danger-bg: #4c1717;
            --audit-info: #93c5fd;
            --audit-info-bg: #172e58;
            --audit-shadow: 0 8px 24px rgba(0, 0, 0, .22);
        }
        .main-content {
            flex: 1 0 auto;
            padding: 4rem 1.35rem 3rem;
            background: radial-gradient(circle at 100% 0, rgba(20, 184, 166, .12), transparent 32%), var(--audit-bg);
        }
        .audit-shell { display: grid; gap: 1rem; }
        .audit-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; }
        .audit-head h1 { margin: 0; color: var(--audit-text); font-size: 1.72rem; line-height: 1.2; }
        .audit-head p { max-width: 860px; margin: .4rem 0 0; color: var(--audit-muted); font-size: .93rem; line-height: 1.5; }
        .audit-updated { display: inline-flex; align-items: center; gap: .45rem; padding: .52rem .72rem; border: 1px solid var(--audit-border); border-radius: 999px; background: var(--audit-card); color: var(--audit-muted); font-size: .8rem; font-weight: 800; white-space: nowrap; }
        .audit-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: .72rem; }
        .audit-stat { position: relative; overflow: hidden; padding: .92rem; border: 1px solid var(--audit-border); border-radius: 13px; background: var(--audit-card); box-shadow: var(--audit-shadow); }
        .audit-stat::after { position: absolute; right: -.75rem; bottom: -.9rem; width: 3.6rem; height: 3.6rem; border-radius: 50%; background: rgba(20, 184, 166, .08); content: ""; }
        .audit-stat span { display: block; color: var(--audit-muted); font-size: .72rem; font-weight: 850; letter-spacing: .04em; text-transform: uppercase; }
        .audit-stat strong { display: block; margin-top: .36rem; color: var(--audit-text); font-size: 1.46rem; line-height: 1; }
        .audit-card { overflow: hidden; border: 1px solid var(--audit-border); border-radius: 14px; background: var(--audit-card); box-shadow: var(--audit-shadow); }
        .audit-card-title { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem 1rem; border-bottom: 1px solid var(--audit-border); }
        .audit-card-title h2 { margin: 0; color: var(--audit-text); font-size: 1.03rem; }
        .audit-card-title p { margin: .25rem 0 0; color: var(--audit-muted); font-size: .8rem; }
        .audit-toolbar { display: grid; grid-template-columns: minmax(230px, 1.4fr) minmax(180px, .9fr) repeat(4, minmax(145px, .65fr)); gap: .62rem; padding: .9rem; background: var(--audit-soft); border-bottom: 1px solid var(--audit-border); }
        .audit-toolbar-secondary { display: grid; grid-template-columns: repeat(3, minmax(150px, .6fr)) minmax(120px, .45fr) auto; gap: .62rem; grid-column: 1 / -1; }
        .audit-field { display: grid; gap: .28rem; }
        .audit-field label { color: var(--audit-muted); font-size: .68rem; font-weight: 850; letter-spacing: .04em; text-transform: uppercase; }
        .audit-input, .audit-select { width: 100%; min-height: 41px; padding: .58rem .68rem; border: 1px solid #cbd5e1; border-radius: 9px; background: #fff; color: #172033; font: inherit; font-size: .84rem; }
        html[data-theme="dark"] .audit-input, html[data-theme="dark"] .audit-select { border-color: #334155; background: #0f172a; color: #f8fafc; }
        .audit-input:focus, .audit-select:focus { outline: none; border-color: var(--audit-primary); box-shadow: 0 0 0 3px rgba(20, 184, 166, .14); }
        .audit-actions { display: flex; align-items: end; gap: .42rem; }
        .audit-btn { min-height: 41px; padding: 0 .74rem; border: 1px solid var(--audit-border); border-radius: 9px; background: #fff; color: #172033; font: inherit; font-size: .82rem; font-weight: 850; text-decoration: none; white-space: nowrap; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: .4rem; }
        .audit-btn.primary { border-color: var(--audit-primary); background: var(--audit-primary); color: #fff; }
        .audit-btn.primary:hover { background: var(--audit-primary-dark); }
        html[data-theme="dark"] .audit-btn { border-color: #334155; background: #0f172a; color: #e5e7eb; }
        html[data-theme="dark"] .audit-btn.primary { border-color: #0f766e; background: #0f766e; color: #fff; }
        .audit-note { display: flex; align-items: flex-start; gap: .52rem; margin: 0; padding: .75rem .9rem; border-bottom: 1px solid var(--audit-border); background: var(--audit-card); color: var(--audit-muted); font-size: .8rem; line-height: 1.45; }
        .audit-note i { margin-top: .08rem; color: var(--audit-primary); }
        .audit-summary { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .75rem .9rem; border-bottom: 1px solid var(--audit-border); color: var(--audit-muted); font-size: .8rem; }
        .audit-summary strong { color: var(--audit-text); }
        .audit-table-wrap { overflow: auto; }
        .audit-table { width: 100%; min-width: 1460px; border-collapse: collapse; }
        .audit-table th, .audit-table td { padding: .72rem .68rem; border-bottom: 1px solid #edf2f7; text-align: left; vertical-align: top; font-size: .81rem; }
        html[data-theme="dark"] .audit-table th, html[data-theme="dark"] .audit-table td { border-bottom-color: #1f2937; }
        .audit-table th { position: sticky; top: 0; z-index: 2; background: var(--audit-header); color: var(--audit-muted); font-size: .68rem; font-weight: 900; letter-spacing: .045em; text-transform: uppercase; }
        .audit-table tbody tr:hover td { background: rgba(20, 184, 166, .035); }
        .audit-log-no { color: var(--audit-text); font-size: .9rem; font-weight: 900; text-align: center; }
        .audit-time strong { display: block; color: var(--audit-text); white-space: nowrap; }
        .audit-time span { display: block; margin-top: .17rem; color: var(--audit-muted); font-size: .72rem; }
        .audit-actor { display: flex; gap: .52rem; align-items: flex-start; min-width: 180px; }
        .audit-avatar { display: inline-flex; width: 28px; height: 28px; flex: 0 0 28px; align-items: center; justify-content: center; border-radius: 8px; background: var(--audit-soft); color: var(--audit-primary); }
        .audit-actor strong, .audit-process strong { display: block; color: var(--audit-text); }
        .audit-actor span, .audit-process span { display: block; margin-top: .14rem; color: var(--audit-muted); font-size: .71rem; line-height: 1.35; }
        .audit-source { display: inline-flex; align-items: center; gap: .35rem; min-width: 130px; color: var(--audit-text); font-weight: 800; }
        .audit-source i { color: var(--audit-primary); }
        .audit-chip, .audit-outcome, .audit-reference { display: inline-flex; align-items: center; border-radius: 999px; padding: .24rem .53rem; font-size: .69rem; font-weight: 850; white-space: nowrap; }
        .audit-chip { background: var(--audit-soft); color: var(--audit-primary-dark); }
        html[data-theme="dark"] .audit-chip { color: var(--audit-primary); }
        .audit-reference { max-width: 170px; overflow: hidden; border: 1px solid var(--audit-border); background: var(--audit-card); color: var(--audit-text); text-overflow: ellipsis; text-decoration: none; }
        .audit-reference:hover { border-color: var(--audit-primary); color: var(--audit-primary); }
        .audit-reference-empty { color: var(--audit-muted); font-size: .74rem; }
        .audit-outcome.success { background: var(--audit-success-bg); color: var(--audit-success); }
        .audit-outcome.warning { background: var(--audit-warning-bg); color: var(--audit-warning); }
        .audit-outcome.failed { background: var(--audit-danger-bg); color: var(--audit-danger); }
        .audit-outcome.info { background: var(--audit-info-bg); color: var(--audit-info); }
        .audit-detail-text { max-width: 360px; color: var(--audit-text); line-height: 1.43; }
        .audit-tech { margin-top: .38rem; }
        .audit-tech summary { color: var(--audit-primary); font-size: .7rem; font-weight: 850; cursor: pointer; }
        .audit-tech-grid { display: grid; gap: .32rem; margin-top: .45rem; padding: .55rem; border: 1px solid var(--audit-border); border-radius: 8px; background: var(--audit-header); color: var(--audit-muted); font-size: .68rem; line-height: 1.4; }
        .audit-tech-grid strong { color: var(--audit-text); }
        .audit-tech pre { max-width: 390px; max-height: 210px; margin: .15rem 0 0; padding: .5rem; overflow: auto; border-radius: 7px; background: #0f172a; color: #e2e8f0; font-size: .66rem; white-space: pre-wrap; word-break: break-word; }
        .audit-empty, .audit-error { padding: 2rem 1rem; text-align: center; color: var(--audit-muted); }
        .audit-error { color: var(--audit-danger); font-weight: 800; }
        .audit-pagination { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .78rem .9rem; }
        .audit-pages { display: flex; flex-wrap: wrap; gap: .35rem; }
        .audit-page { display: inline-flex; min-width: 34px; height: 34px; align-items: center; justify-content: center; border: 1px solid var(--audit-border); border-radius: 8px; background: var(--audit-card); color: var(--audit-text); font-size: .77rem; font-weight: 850; text-decoration: none; }
        .audit-page.active { border-color: var(--audit-primary); background: var(--audit-primary); color: #fff; }
        .audit-page.disabled { opacity: .45; pointer-events: none; }
        .lifecycle-card { overflow: hidden; border: 1px solid var(--audit-border); border-radius: 14px; background: var(--audit-card); box-shadow: var(--audit-shadow); }
        .lifecycle-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: 1rem; border-bottom: 1px solid var(--audit-border); background: linear-gradient(135deg, var(--audit-soft), transparent); }
        .lifecycle-head h2 { margin: 0; color: var(--audit-text); font-size: 1.08rem; }
        .lifecycle-head p { margin: .3rem 0 0; color: var(--audit-muted); font-size: .8rem; }
        .lifecycle-badges { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .38rem; }
        .lifecycle-body { display: grid; grid-template-columns: minmax(280px, .8fr) minmax(420px, 1.4fr); gap: 1rem; padding: 1rem; }
        .duration-panel h3, .timeline-panel h3 { margin: 0 0 .72rem; color: var(--audit-text); font-size: .86rem; }
        .duration-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .52rem; }
        .duration-item { padding: .68rem; border: 1px solid var(--audit-border); border-radius: 10px; background: var(--audit-header); }
        .duration-item span { display: block; color: var(--audit-muted); font-size: .67rem; line-height: 1.35; }
        .duration-item strong { display: block; margin-top: .25rem; color: var(--audit-text); font-size: .9rem; }
        .timeline { position: relative; display: grid; gap: .05rem; }
        .timeline::before { position: absolute; top: .5rem; bottom: .5rem; left: 12px; width: 2px; background: var(--audit-border); content: ""; }
        .timeline-item { position: relative; display: grid; grid-template-columns: 25px minmax(0, 1fr); gap: .65rem; padding: 0 0 .88rem; }
        .timeline-dot { position: relative; z-index: 1; display: inline-flex; width: 25px; height: 25px; align-items: center; justify-content: center; border: 3px solid var(--audit-card); border-radius: 50%; background: var(--audit-primary); color: #fff; font-size: .58rem; }
        .timeline-content { padding: .62rem .68rem; border: 1px solid var(--audit-border); border-radius: 10px; background: var(--audit-header); }
        .timeline-top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .timeline-top strong { color: var(--audit-text); font-size: .81rem; }
        .timeline-top time { color: var(--audit-muted); font-size: .69rem; white-space: nowrap; }
        .timeline-content p { margin: .35rem 0 0; color: var(--audit-muted); font-size: .73rem; line-height: 1.42; }
        .timeline-meta { display: flex; flex-wrap: wrap; gap: .32rem; margin-top: .42rem; color: var(--audit-muted); font-size: .67rem; }
        .lifecycle-missing { padding: 1rem; color: var(--audit-warning); font-size: .82rem; }
        @media (max-width: 1280px) {
            .audit-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .audit-toolbar { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .audit-toolbar-secondary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .audit-actions { grid-column: 1 / -1; }
        }
        @media (max-width: 900px) {
            .lifecycle-body { grid-template-columns: 1fr; }
            .audit-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 680px) {
            .main-content { padding: 1rem .68rem 2rem; }
            .audit-head, .lifecycle-head, .audit-summary, .audit-pagination { align-items: flex-start; flex-direction: column; }
            .audit-stats, .audit-toolbar, .audit-toolbar-secondary, .duration-grid { grid-template-columns: 1fr; }
            .audit-actions { flex-direction: column; width: 100%; }
            .audit-btn { width: 100%; }
            .lifecycle-badges { justify-content: flex-start; }
            .timeline-top { flex-direction: column; gap: .15rem; }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content">
        <div class="main-container audit-shell">
            <section class="audit-head">
                <div>
                    <h1>Operational Audit Trail</h1>
                    <p>Hi <?php echo audit_h($adminName); ?>. Review the accountable sequence of actions performed by responders, dispatchers, administrators, connected APIs, and automated system processes.</p>
                </div>
                <div class="audit-updated">
                    <i class="fas fa-clock"></i>
                    <span>Latest log: <?php echo audit_h(audit_format_date($stats['latest'])); ?></span>
                </div>
            </section>

            <section class="audit-stats" aria-label="Audit summary">
                <div class="audit-stat"><span>Total logs</span><strong><?php echo number_format($stats['total']); ?></strong></div>
                <div class="audit-stat"><span>Logged today</span><strong><?php echo number_format($stats['today']); ?></strong></div>
                <div class="audit-stat"><span>Responder actions</span><strong><?php echo number_format($stats['responder']); ?></strong></div>
                <div class="audit-stat"><span>Dispatcher actions</span><strong><?php echo number_format($stats['dispatcher']); ?></strong></div>
                <div class="audit-stat"><span>Warnings / failures</span><strong><?php echo number_format($stats['attention']); ?></strong></div>
            </section>

            <?php if ($lifecycle !== null): ?>
                <section class="lifecycle-card" aria-label="Incident lifecycle">
                    <div class="lifecycle-head">
                        <div>
                            <h2>Incident Lifecycle — <?php echo audit_h($referenceFilter); ?></h2>
                            <p>Canonical operational milestones and elapsed response times for the selected incident reference.</p>
                        </div>
                        <div class="lifecycle-badges">
                            <?php if (is_array($lifecycle['incident'] ?? null)): ?>
                                <span class="audit-chip"><?php echo audit_h(audit_label((string)($lifecycle['incident']['type'] ?? 'incident'))); ?></span>
                                <span class="audit-chip">Priority: <?php echo audit_h(ucfirst((string)($lifecycle['incident']['priority'] ?? 'Not set'))); ?></span>
                                <span class="audit-chip">Status: <?php echo audit_h(audit_label((string)($lifecycle['incident']['status'] ?? 'Unknown'))); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (empty($lifecycle['found'])): ?>
                        <div class="lifecycle-missing"><i class="fas fa-triangle-exclamation"></i> No incident, call, or audit lifecycle was found for this exact reference. Check the spelling or clear the reference filter.</div>
                    <?php else: ?>
                        <div class="lifecycle-body">
                            <div class="duration-panel">
                                <h3>Response-time intervals</h3>
                                <div class="duration-grid">
                                    <?php foreach (($lifecycle['durations'] ?? []) as $duration): ?>
                                        <div class="duration-item" title="<?php echo audit_h(audit_format_date($duration['start'] ?? null) . ' → ' . audit_format_date($duration['end'] ?? null)); ?>">
                                            <span><?php echo audit_h((string)$duration['label']); ?></span>
                                            <strong><?php echo audit_h((string)$duration['value']); ?></strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="timeline-panel">
                                <h3>Recorded process timeline</h3>
                                <?php if (empty($lifecycle['events'])): ?>
                                    <div class="audit-empty">No timestamped lifecycle events are available.</div>
                                <?php else: ?>
                                    <div class="timeline">
                                        <?php foreach ($lifecycle['events'] as $event): ?>
                                            <article class="timeline-item">
                                                <span class="timeline-dot"><i class="fas fa-check"></i></span>
                                                <div class="timeline-content">
                                                    <div class="timeline-top">
                                                        <strong><?php echo audit_h((string)$event['label']); ?></strong>
                                                        <time datetime="<?php echo audit_h(audit_iso_datetime((string)$event['timestamp'])); ?>"><?php echo audit_h(audit_format_date((string)$event['timestamp'])); ?></time>
                                                    </div>
                                                    <?php if (trim((string)($event['details'] ?? '')) !== ''): ?><p><?php echo audit_h((string)$event['details']); ?></p><?php endif; ?>
                                                    <div class="timeline-meta">
                                                        <span><i class="fas fa-user"></i> <?php echo audit_h((string)$event['actor']); ?></span>
                                                        <span>• <?php echo audit_h(audit_source_label((string)$event['source'])); ?></span>
                                                        <?php if (trim((string)($event['unit'] ?? '')) !== ''): ?><span>• Unit <?php echo audit_h((string)$event['unit']); ?></span><?php endif; ?>
                                                        <span>• <?php echo audit_h(ucfirst((string)$event['outcome'])); ?></span>
                                                    </div>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="audit-card">
                <div class="audit-card-title">
                    <div>
                        <h2>Search and organize logs</h2>
                        <p>Use the incident reference filter to open the complete call-to-review lifecycle above.</p>
                    </div>
                    <a class="audit-btn" href="<?php echo audit_h(audit_query_url(['export' => 'csv', 'page' => null])); ?>">
                        <i class="fas fa-file-csv"></i><span>Export CSV</span>
                    </a>
                </div>

                <form class="audit-toolbar" method="get" action="admin/audit.php">
                    <div class="audit-field">
                        <label for="auditSearch">Search</label>
                        <input id="auditSearch" class="audit-input" type="search" name="q" value="<?php echo audit_h($search); ?>" placeholder="Actor, action, details, request ID...">
                    </div>
                    <div class="audit-field">
                        <label for="auditReference">Incident reference</label>
                        <input id="auditReference" class="audit-input" type="text" name="reference" value="<?php echo audit_h($referenceFilter); ?>" placeholder="e.g. REF-2026...">
                    </div>
                    <div class="audit-field">
                        <label for="auditRole">Actor role</label>
                        <select id="auditRole" class="audit-select" name="role">
                            <option value="">All roles</option>
                            <?php foreach ($allowedRoles as $role): ?><option value="<?php echo audit_h($role); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>><?php echo audit_h(audit_label($role)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="audit-field">
                        <label for="auditSource">System source</label>
                        <select id="auditSource" class="audit-select" name="source">
                            <option value="">All sources</option>
                            <?php foreach ($allowedSources as $source): ?><option value="<?php echo audit_h($source); ?>" <?php echo $sourceFilter === $source ? 'selected' : ''; ?>><?php echo audit_h(audit_source_label($source)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="audit-field">
                        <label for="auditCategory">Process category</label>
                        <select id="auditCategory" class="audit-select" name="category">
                            <option value="">All processes</option>
                            <?php foreach ($allowedCategories as $category): ?><option value="<?php echo audit_h($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo audit_h(audit_category_label($category)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="audit-field">
                        <label for="auditOutcome">Outcome</label>
                        <select id="auditOutcome" class="audit-select" name="outcome">
                            <option value="">All outcomes</option>
                            <?php foreach ($allowedOutcomes as $outcome): ?><option value="<?php echo audit_h($outcome); ?>" <?php echo $outcomeFilter === $outcome ? 'selected' : ''; ?>><?php echo audit_h(ucfirst($outcome)); ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <div class="audit-toolbar-secondary">
                        <div class="audit-field">
                            <label for="auditDateFrom">From date</label>
                            <input id="auditDateFrom" class="audit-input" type="date" name="date_from" value="<?php echo audit_h($dateFrom); ?>">
                        </div>
                        <div class="audit-field">
                            <label for="auditDateTo">To date</label>
                            <input id="auditDateTo" class="audit-input" type="date" name="date_to" value="<?php echo audit_h($dateTo); ?>">
                        </div>
                        <div class="audit-field">
                            <label for="auditPageSize">Rows per page</label>
                            <select id="auditPageSize" class="audit-select" name="per_page">
                                <?php foreach ($allowedPageSizes as $size): ?><option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?> rows</option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="audit-actions">
                            <button class="audit-btn primary" type="submit"><i class="fas fa-filter"></i><span>Apply filters</span></button>
                            <a class="audit-btn" href="admin/audit.php"><i class="fas fa-rotate-left"></i><span>Reset</span></a>
                        </div>
                    </div>
                </form>

                <p class="audit-note"><i class="fas fa-circle-info"></i><span><strong>Log No.</strong> is the sequential position in the current filtered results (1, 2, 3, …). It is not a user ID, employee ID, responder ID, or database ID.</span></p>
                <div class="audit-summary">
                    <span>Showing <strong><?php echo number_format($showStart); ?>–<?php echo number_format($showEnd); ?></strong> of <strong><?php echo number_format($matchingCount); ?></strong> matching logs</span>
                    <span>Times shown in Asia/Manila</span>
                </div>

                <?php if ($loadError !== ''): ?>
                    <div class="audit-error">Unable to load the operational audit trail: <?php echo audit_h($loadError); ?></div>
                <?php elseif (!$auditRows): ?>
                    <div class="audit-empty"><i class="fas fa-magnifying-glass"></i><br>No logs matched the selected filters.</div>
                <?php else: ?>
                    <div class="audit-table-wrap">
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th title="Sequential position, not an account ID">Log No.</th>
                                    <th>Date &amp; Time</th>
                                    <th>Actor</th>
                                    <th>Source</th>
                                    <th>Process / Action</th>
                                    <th>Incident / Reference</th>
                                    <th>Outcome</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditRows as $index => $row): ?>
                                    <?php
                                    $logNo = $offset + $index + 1;
                                    $actorName = trim((string)($row['actor_name'] ?? '')) ?: 'System';
                                    $actorEmail = trim((string)($row['actor_email'] ?? ''));
                                    $actorRole = strtolower(trim((string)($row['actor_role'] ?? 'system')));
                                    $source = strtolower(trim((string)($row['source_channel'] ?? 'system')));
                                    $category = strtolower(trim((string)($row['event_category'] ?? 'system')));
                                    $outcome = strtolower(trim((string)($row['event_outcome'] ?? 'success')));
                                    if (!in_array($outcome, $allowedOutcomes, true)) {
                                        $outcome = 'info';
                                    }
                                    $reference = trim((string)($row['reference_no'] ?? ''));
                                    $metadataPretty = audit_json_pretty($row['metadata_json'] ?? null);
                                    $sourceIcon = $source === 'responder_app' ? 'fa-mobile-screen-button' : ($source === 'dispatcher_web' ? 'fa-headset' : ($source === 'admin_web' ? 'fa-user-shield' : ($source === 'external_api' ? 'fa-plug' : 'fa-server')));
                                    ?>
                                    <tr>
                                        <td class="audit-log-no"><?php echo number_format($logNo); ?></td>
                                        <td class="audit-time">
                                            <strong><?php echo audit_h(audit_format_date($row['created_at'] ?? null)); ?></strong>
                                            <span>Asia/Manila</span>
                                        </td>
                                        <td>
                                            <div class="audit-actor">
                                                <span class="audit-avatar"><i class="fas <?php echo $actorRole === 'system' ? 'fa-gears' : 'fa-user'; ?>"></i></span>
                                                <div>
                                                    <strong><?php echo audit_h($actorName); ?></strong>
                                                    <span><?php echo audit_h(audit_label($actorRole)); ?><?php echo $actorEmail !== '' ? (' · ' . audit_h($actorEmail)) : ''; ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="audit-source"><i class="fas <?php echo audit_h($sourceIcon); ?>"></i><?php echo audit_h(audit_source_label($source)); ?></span></td>
                                        <td>
                                            <div class="audit-process">
                                                <strong><?php echo audit_h(audit_label((string)($row['action'] ?? ''))); ?></strong>
                                                <span class="audit-chip"><?php echo audit_h(audit_category_label($category)); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($reference !== ''): ?>
                                                <a class="audit-reference" href="<?php echo audit_h(audit_query_url(['reference' => $reference, 'page' => 1])); ?>" title="Open lifecycle for <?php echo audit_h($reference); ?>"><?php echo audit_h($reference); ?></a>
                                            <?php else: ?>
                                                <span class="audit-reference-empty">No incident reference</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="audit-outcome <?php echo audit_h($outcome); ?>"><?php echo audit_h(ucfirst($outcome)); ?></span></td>
                                        <td>
                                            <div class="audit-detail-text"><?php echo audit_h(audit_details_summary($row['details'] ?? '')); ?></div>
                                            <details class="audit-tech">
                                                <summary>Technical context</summary>
                                                <div class="audit-tech-grid">
                                                    <div><strong>Entity:</strong> <?php echo audit_h(audit_label((string)($row['entity_type'] ?? 'system'))); ?><?php echo (int)($row['entity_id'] ?? 0) > 0 ? (' · record ' . number_format((int)$row['entity_id'])) : ''; ?></div>
                                                    <div><strong>Request ID:</strong> <?php echo audit_h(trim((string)($row['request_id'] ?? '')) ?: 'Legacy log / not recorded'); ?></div>
                                                    <div><strong>IP:</strong> <?php echo audit_h(trim((string)($row['ip_address'] ?? '')) ?: 'Not recorded'); ?></div>
                                                    <?php if ($metadataPretty !== ''): ?><div><strong>Structured metadata:</strong><pre><?php echo audit_h($metadataPretty); ?></pre></div><?php endif; ?>
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($pageCount > 1): ?>
                    <nav class="audit-pagination" aria-label="Audit log pages">
                        <span>Page <strong><?php echo number_format($page); ?></strong> of <strong><?php echo number_format($pageCount); ?></strong></span>
                        <div class="audit-pages">
                            <a class="audit-page <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo audit_h(audit_query_url(['page' => max(1, $page - 1)])); ?>" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a>
                            <?php
                            $pageStart = max(1, $page - 2);
                            $pageEnd = min($pageCount, $page + 2);
                            if ($pageStart > 1): ?><a class="audit-page" href="<?php echo audit_h(audit_query_url(['page' => 1])); ?>">1</a><?php if ($pageStart > 2): ?><span class="audit-page disabled">…</span><?php endif; ?><?php endif;
                            for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                                <a class="audit-page <?php echo $pageNumber === $page ? 'active' : ''; ?>" href="<?php echo audit_h(audit_query_url(['page' => $pageNumber])); ?>"><?php echo number_format($pageNumber); ?></a>
                            <?php endfor;
                            if ($pageEnd < $pageCount): ?><?php if ($pageEnd < $pageCount - 1): ?><span class="audit-page disabled">…</span><?php endif; ?><a class="audit-page" href="<?php echo audit_h(audit_query_url(['page' => $pageCount])); ?>"><?php echo number_format($pageCount); ?></a><?php endif; ?>
                            <a class="audit-page <?php echo $page >= $pageCount ? 'disabled' : ''; ?>" href="<?php echo audit_h(audit_query_url(['page' => min($pageCount, $page + 1)])); ?>" aria-label="Next page"><i class="fas fa-chevron-right"></i></a>
                        </div>
                    </nav>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>
</body>
</html>
