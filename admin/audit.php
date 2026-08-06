<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/audit.php');
require_once $rootDir . '/includes/db.php';

date_default_timezone_set('Asia/Manila');

// This page changes frequently during audit UI review. Prevent a stale HTML
// response and force browsers to request the current grouped-view assets.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$auditUiBuild = '20260806-grouped-v3';
if (!headers_sent()) {
    header('X-Audit-UI-Build: ' . $auditUiBuild);
}
$auditCssModified = is_file($rootDir . '/css/audit.css') ? (string)filemtime($rootDir . '/css/audit.css') : '0';
$auditJsModified = is_file($rootDir . '/js/audit.js') ? (string)filemtime($rootDir . '/js/audit.js') : '0';
$auditCssVersion = rawurlencode($auditUiBuild . '-' . $auditCssModified);
$auditJsVersion = rawurlencode($auditUiBuild . '-' . $auditJsModified);

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

function audit_personnel_role_label(string $role, string $source = ''): string
{
    $role = strtolower(trim($role));
    $source = strtolower(trim($source));

    if ($role === 'operator') {
        return 'Dispatcher';
    }
    if ($role === '' && $source === 'dispatcher_web') {
        return 'Dispatcher';
    }
    if ($role === '' && $source === 'responder_app') {
        return 'Responder';
    }
    return audit_label($role !== '' ? $role : 'user');
}

function audit_role_bucket(string $role, string $source): string
{
    $role = strtolower(trim($role));
    $source = strtolower(trim($source));

    if ($role === 'responder' || $source === 'responder_app') {
        return 'responders';
    }
    if (in_array($role, ['dispatcher', 'operator'], true) || $source === 'dispatcher_web') {
        return 'dispatchers';
    }
    return 'others';
}

function audit_role_icon(string $role, string $source): string
{
    switch (audit_role_bucket($role, $source)) {
        case 'responders':
            return 'fa-helmet-safety';
        case 'dispatchers':
            return 'fa-headset';
        default:
            if (strtolower(trim($role)) === 'system' || strtolower(trim($source)) === 'system') {
                return 'fa-gears';
            }
            if (strtolower(trim($role)) === 'admin' || strtolower(trim($source)) === 'admin_web') {
                return 'fa-user-shield';
            }
            return 'fa-user';
    }
}

function audit_role_tone(string $role, string $source): string
{
    switch (audit_role_bucket($role, $source)) {
        case 'responders':
            return 'responder';
        case 'dispatchers':
            return 'dispatcher';
        default:
            if (strtolower(trim($role)) === 'system' || strtolower(trim($source)) === 'system') {
                return 'system';
            }
            return 'other';
    }
}

function audit_date_only_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Unknown date';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Manila')))->format('M d, Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function audit_time_only_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Unknown time';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Manila')))->format('h:i:s A');
    } catch (Throwable $e) {
        return $value;
    }
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
            'Log No.', 'Date and Time (Asia/Manila)', 'Personnel', 'Personnel Role', 'Source',
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

$activeFilters = [];
$advancedFilterCount = 0;

if ($search !== '') {
    $activeFilters[] = [
        'label' => 'Search: “' . $search . '”',
        'url' => audit_query_url(['q' => null, 'page' => 1]),
    ];
}
if ($referenceFilter !== '') {
    $activeFilters[] = [
        'label' => 'Reference: ' . $referenceFilter,
        'url' => audit_query_url(['reference' => null, 'page' => 1]),
    ];
}
if ($roleFilter !== '') {
    $advancedFilterCount++;
    $activeFilters[] = [
        'label' => 'Role: ' . audit_label($roleFilter),
        'url' => audit_query_url(['role' => null, 'page' => 1]),
    ];
}
if ($sourceFilter !== '') {
    $advancedFilterCount++;
    $activeFilters[] = [
        'label' => 'Source: ' . audit_source_label($sourceFilter),
        'url' => audit_query_url(['source' => null, 'page' => 1]),
    ];
}
if ($categoryFilter !== '') {
    $advancedFilterCount++;
    $activeFilters[] = [
        'label' => 'Process: ' . audit_category_label($categoryFilter),
        'url' => audit_query_url(['category' => null, 'page' => 1]),
    ];
}
if ($outcomeFilter !== '') {
    $advancedFilterCount++;
    $activeFilters[] = [
        'label' => 'Outcome: ' . ucfirst($outcomeFilter),
        'url' => audit_query_url(['outcome' => null, 'page' => 1]),
    ];
}
if ($dateFrom !== '' || $dateTo !== '') {
    $advancedFilterCount++;
    $dateFromLabel = $dateFrom !== '' ? date('M j, Y', strtotime($dateFrom)) : 'Any date';
    $dateToLabel = $dateTo !== '' ? date('M j, Y', strtotime($dateTo)) : 'Present';
    $activeFilters[] = [
        'label' => 'Date: ' . $dateFromLabel . ' – ' . $dateToLabel,
        'url' => audit_query_url(['date_from' => null, 'date_to' => null, 'page' => 1]),
    ];
}

$clearAdvancedUrl = audit_query_url([
    'role' => null,
    'source' => null,
    'category' => null,
    'outcome' => null,
    'date_from' => null,
    'date_to' => null,
    'page' => 1,
]);


$groupedTabs = [
    'responders' => [
        'label' => 'Responders',
        'description' => 'Responder App activities grouped by person and day.',
        'groups' => [],
        'entry_count' => 0,
    ],
    'dispatchers' => [
        'label' => 'Dispatchers',
        'description' => 'Dispatcher Website activities grouped by person and day.',
        'groups' => [],
        'entry_count' => 0,
    ],
    'others' => [
        'label' => 'Other / System',
        'description' => 'Admin, system, and other uncategorized activities.',
        'groups' => [],
        'entry_count' => 0,
    ],
];

foreach ($auditRows as $index => $row) {
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
    $detailsSummary = audit_details_summary($row['details'] ?? '');
    $rowIdentity = (int)($row['id'] ?? 0) > 0 ? (string)(int)$row['id'] : (string)$logNo;
    $dialogId = 'audit-detail-' . $rowIdentity . '-' . $index;
    $dialogTitleId = $dialogId . '-title';
    $entityText = audit_label((string)($row['entity_type'] ?? 'system'));
    if ((int)($row['entity_id'] ?? 0) > 0) {
        $entityText .= ' · record ' . number_format((int)$row['entity_id']);
    }

    $groupDateKey = '';
    try {
        $groupDateKey = (new DateTimeImmutable((string)($row['created_at'] ?? 'now'), new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    } catch (Throwable $e) {
        $groupDateKey = substr((string)($row['created_at'] ?? ''), 0, 10);
    }
    $tabKey = audit_role_bucket($actorRole, $source);
    if (!isset($groupedTabs[$tabKey])) {
        $tabKey = 'others';
    }
    $groupActorNameKey = function_exists('mb_strtolower') ? mb_strtolower($actorName) : strtolower($actorName);
    $groupActorEmailKey = function_exists('mb_strtolower') ? mb_strtolower($actorEmail) : strtolower($actorEmail);
    $groupKey = implode('|', [$groupDateKey, $groupActorNameKey, $groupActorEmailKey, $source, $actorRole]);

    if (!isset($groupedTabs[$tabKey]['groups'][$groupKey])) {
        $groupedTabs[$tabKey]['groups'][$groupKey] = [
            'group_key' => $groupKey,
            'date_key' => $groupDateKey,
            'date_label' => audit_date_only_label((string)($row['created_at'] ?? '')),
            'actor_name' => $actorName,
            'actor_email' => $actorEmail,
            'actor_role' => $actorRole,
            'role_label' => audit_personnel_role_label($actorRole, $source),
            'role_icon' => audit_role_icon($actorRole, $source),
            'role_tone' => audit_role_tone($actorRole, $source),
            'source' => $source,
            'source_label' => audit_source_label($source),
            'latest_time' => (string)($row['created_at'] ?? ''),
            'latest_time_label' => audit_time_only_label((string)($row['created_at'] ?? '')),
            'entries' => [],
            'success_count' => 0,
            'warning_count' => 0,
            'failed_count' => 0,
        ];
    }

    $entry = [
        'log_no' => $logNo,
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_at_iso' => audit_iso_datetime((string)($row['created_at'] ?? '')),
        'created_time_label' => audit_time_only_label((string)($row['created_at'] ?? '')),
        'created_full_label' => audit_format_date($row['created_at'] ?? null),
        'actor_name' => $actorName,
        'actor_email' => $actorEmail,
        'actor_role' => $actorRole,
        'role_label' => audit_personnel_role_label($actorRole, $source),
        'role_icon' => audit_role_icon($actorRole, $source),
        'role_tone' => audit_role_tone($actorRole, $source),
        'source' => $source,
        'source_label' => audit_source_label($source),
        'category' => $category,
        'category_label' => audit_category_label($category),
        'outcome' => $outcome,
        'reference' => $reference,
        'details_summary' => $detailsSummary,
        'metadata_pretty' => $metadataPretty,
        'dialog_id' => $dialogId,
        'dialog_title_id' => $dialogTitleId,
        'action_label' => audit_label((string)($row['action'] ?? '')),
        'entity_text' => $entityText,
        'request_id' => trim((string)($row['request_id'] ?? '')),
        'ip_address' => trim((string)($row['ip_address'] ?? '')),
        'user_agent' => trim((string)($row['user_agent'] ?? '')),
    ];

    $groupedTabs[$tabKey]['groups'][$groupKey]['entries'][] = $entry;
    $groupedTabs[$tabKey]['entry_count']++;

    if ($outcome === 'success') {
        $groupedTabs[$tabKey]['groups'][$groupKey]['success_count']++;
    } elseif ($outcome === 'warning') {
        $groupedTabs[$tabKey]['groups'][$groupKey]['warning_count']++;
    } elseif ($outcome === 'failed') {
        $groupedTabs[$tabKey]['groups'][$groupKey]['failed_count']++;
    }

    if (audit_datetime_key((string)($row['created_at'] ?? '')) > audit_datetime_key($groupedTabs[$tabKey]['groups'][$groupKey]['latest_time'])) {
        $groupedTabs[$tabKey]['groups'][$groupKey]['latest_time'] = (string)($row['created_at'] ?? '');
        $groupedTabs[$tabKey]['groups'][$groupKey]['latest_time_label'] = audit_time_only_label((string)($row['created_at'] ?? ''));
    }
}

foreach ($groupedTabs as $tabKey => &$tabConfig) {
    foreach ($tabConfig['groups'] as &$group) {
        usort($group['entries'], static function (array $left, array $right): int {
            return (audit_datetime_key((string)$right['created_at']) ?? 0) <=> (audit_datetime_key((string)$left['created_at']) ?? 0);
        });
        $group['entry_total'] = count($group['entries']);
    }
    unset($group);
    $tabConfig['groups'] = array_values($tabConfig['groups']);
    usort($tabConfig['groups'], static function (array $left, array $right): int {
        $timeCompare = (audit_datetime_key((string)$right['latest_time']) ?? 0) <=> (audit_datetime_key((string)$left['latest_time']) ?? 0);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        return strcmp((string)$left['actor_name'], (string)$right['actor_name']);
    });
}
unset($tabConfig);

$visibleTabKeys = [];
foreach ($groupedTabs as $tabKey => $tabConfig) {
    if ($tabConfig['entry_count'] > 0 || $tabKey !== 'others') {
        $visibleTabKeys[] = $tabKey;
    }
}
if ($visibleTabKeys === []) {
    $visibleTabKeys = ['responders', 'dispatchers'];
}

$preferredTab = 'responders';
if ($sourceFilter === 'dispatcher_web' || in_array($roleFilter, ['dispatcher', 'operator'], true)) {
    $preferredTab = 'dispatchers';
} elseif ($sourceFilter === 'responder_app' || $roleFilter === 'responder') {
    $preferredTab = 'responders';
} elseif (($roleFilter !== '' || $sourceFilter !== '') && !in_array($preferredTab, $visibleTabKeys, true)) {
    $preferredTab = 'others';
}
if (!in_array($preferredTab, $visibleTabKeys, true)) {
    $preferredTab = $visibleTabKeys[0];
}
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
    <link rel="stylesheet" href="css/audit.css?v=<?php echo audit_h($auditCssVersion); ?>">
    <script src="js/audit.js?v=<?php echo audit_h($auditJsVersion); ?>" defer></script>
</head>
<body data-audit-ui-build="<?php echo audit_h($auditUiBuild); ?>">
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content audit-main">
        <div class="main-container audit-page">
            <header class="audit-page-header">
                <div class="audit-title-block">
                    <div class="audit-eyebrow-row"><span class="audit-eyebrow"><i class="fas fa-shield-halved" aria-hidden="true"></i> Operational audit trail</span><span class="audit-ui-build"><i class="fas fa-layer-group" aria-hidden="true"></i> Grouped by personnel &amp; day</span></div>
                    <h1>Audit Logs</h1>
                    <p>Review emergency-response activity, trace accountability, and inspect technical context without changing the underlying records.</p>
                </div>
                <div class="audit-header-meta" aria-label="Audit record status">
                    <span class="audit-record-state"><span class="audit-status-dot" aria-hidden="true"></span> Read-only record</span>
                    <span class="audit-latest"><i class="far fa-clock" aria-hidden="true"></i> Latest: <?php echo audit_h(audit_format_date($stats['latest'])); ?></span>
                </div>
            </header>

            <section class="audit-metrics" aria-label="Audit summary">
                <article class="audit-metric">
                    <span class="audit-metric-icon"><i class="fas fa-list-check" aria-hidden="true"></i></span>
                    <div><span>Total logs</span><strong><?php echo number_format($stats['total']); ?></strong></div>
                </article>
                <article class="audit-metric">
                    <span class="audit-metric-icon"><i class="fas fa-calendar-day" aria-hidden="true"></i></span>
                    <div><span>Logged today</span><strong><?php echo number_format($stats['today']); ?></strong></div>
                </article>
                <article class="audit-metric">
                    <span class="audit-metric-icon"><i class="fas fa-truck-medical" aria-hidden="true"></i></span>
                    <div><span>Responder actions</span><strong><?php echo number_format($stats['responder']); ?></strong></div>
                </article>
                <article class="audit-metric">
                    <span class="audit-metric-icon"><i class="fas fa-headset" aria-hidden="true"></i></span>
                    <div><span>Dispatcher actions</span><strong><?php echo number_format($stats['dispatcher']); ?></strong></div>
                </article>
                <article class="audit-metric audit-metric-attention">
                    <span class="audit-metric-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                    <div><span>Needs attention</span><strong><?php echo number_format($stats['attention']); ?></strong></div>
                </article>
            </section>

            <?php if ($lifecycle !== null): ?>
                <section class="lifecycle-card" aria-label="Incident lifecycle">
                    <div class="lifecycle-head">
                        <div>
                            <span class="audit-section-kicker">Selected incident</span>
                            <h2>Lifecycle for <?php echo audit_h($referenceFilter); ?></h2>
                            <p>Canonical milestones and elapsed response times for the exact reference.</p>
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
                        <div class="lifecycle-missing">
                            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                            <div><strong>No lifecycle found.</strong><span>Check the reference spelling or remove the reference filter.</span></div>
                        </div>
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
                                    <div class="audit-empty-inline">No timestamped lifecycle events are available.</div>
                                <?php else: ?>
                                    <div class="timeline">
                                        <?php foreach ($lifecycle['events'] as $event): ?>
                                            <article class="timeline-item">
                                                <span class="timeline-dot"><i class="fas fa-check" aria-hidden="true"></i></span>
                                                <div class="timeline-content">
                                                    <div class="timeline-top">
                                                        <strong><?php echo audit_h((string)$event['label']); ?></strong>
                                                        <time datetime="<?php echo audit_h(audit_iso_datetime((string)$event['timestamp'])); ?>"><?php echo audit_h(audit_format_date((string)$event['timestamp'])); ?></time>
                                                    </div>
                                                    <?php if (trim((string)($event['details'] ?? '')) !== ''): ?><p><?php echo audit_h((string)$event['details']); ?></p><?php endif; ?>
                                                    <div class="timeline-meta">
                                                        <span><i class="fas fa-user" aria-hidden="true"></i> <?php echo audit_h((string)$event['actor']); ?></span>
                                                        <span><?php echo audit_h(audit_source_label((string)$event['source'])); ?></span>
                                                        <?php if (trim((string)($event['unit'] ?? '')) !== ''): ?><span>Unit <?php echo audit_h((string)$event['unit']); ?></span><?php endif; ?>
                                                        <span><?php echo audit_h(ucfirst((string)$event['outcome'])); ?></span>
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

            <section class="audit-panel" aria-labelledby="auditRecordsTitle">
                <header class="audit-panel-header">
                    <div>
                        <span class="audit-section-kicker">System activity</span>
                        <h2 id="auditRecordsTitle">Log records</h2>
                        <p>Search key fields first, then review grouped daily actions per responder or dispatcher.</p>
                    </div>
                    <div class="audit-panel-actions">
                        <?php if ($activeFilters): ?>
                            <a class="audit-btn audit-btn-secondary" href="admin/audit.php"><i class="fas fa-rotate-left" aria-hidden="true"></i><span>Reset</span></a>
                        <?php endif; ?>
                        <a class="audit-btn audit-btn-secondary" href="<?php echo audit_h(audit_query_url(['export' => 'csv', 'page' => null])); ?>">
                            <i class="fas fa-file-arrow-down" aria-hidden="true"></i><span>Export CSV</span>
                        </a>
                    </div>
                </header>

                <form class="audit-filter-form" method="get" action="admin/audit.php">
                    <div class="audit-primary-filters">
                        <div class="audit-field audit-field-search">
                            <label for="auditSearch">Search logs</label>
                            <div class="audit-input-with-icon">
                                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                                <input id="auditSearch" class="audit-input" type="search" name="q" value="<?php echo audit_h($search); ?>" placeholder="Responder name, dispatcher name, action, details...">
                            </div>
                        </div>
                        <div class="audit-field audit-field-reference">
                            <label for="auditReference">Incident reference</label>
                            <div class="audit-input-with-icon">
                                <i class="fas fa-link" aria-hidden="true"></i>
                                <input id="auditReference" class="audit-input" type="text" name="reference" value="<?php echo audit_h($referenceFilter); ?>" placeholder="e.g. REF-2026...">
                            </div>
                        </div>
                        <button class="audit-btn audit-btn-primary audit-primary-submit" type="submit">
                            <i class="fas fa-filter" aria-hidden="true"></i><span>Apply</span>
                        </button>
                    </div>

                    <details class="audit-advanced-filters" <?php echo $advancedFilterCount > 0 ? 'open' : ''; ?>>
                        <summary>
                            <span class="audit-advanced-title"><i class="fas fa-sliders" aria-hidden="true"></i> Advanced filters</span>
                            <span class="audit-advanced-description">Role, source, process, outcome, date, and page size</span>
                            <?php if ($advancedFilterCount > 0): ?><span class="audit-filter-count"><?php echo number_format($advancedFilterCount); ?> active</span><?php endif; ?>
                            <i class="fas fa-chevron-down audit-advanced-chevron" aria-hidden="true"></i>
                        </summary>
                        <div class="audit-advanced-content">
                            <div class="audit-advanced-grid">
                                <div class="audit-field">
                                    <label for="auditRole">Personnel role</label>
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
                            </div>
                            <div class="audit-advanced-footer">
                                <span>Filters apply together and results remain sorted from newest to oldest.</span>
                                <div>
                                    <?php if ($advancedFilterCount > 0): ?><a class="audit-btn audit-btn-ghost" href="<?php echo audit_h($clearAdvancedUrl); ?>">Clear advanced</a><?php endif; ?>
                                    <button class="audit-btn audit-btn-primary" type="submit"><i class="fas fa-check" aria-hidden="true"></i><span>Apply filters</span></button>
                                </div>
                            </div>
                        </div>
                    </details>
                </form>

                <?php if ($activeFilters): ?>
                    <div class="audit-active-filters" aria-label="Active filters">
                        <span class="audit-active-label"><i class="fas fa-filter" aria-hidden="true"></i> Active filters</span>
                        <div class="audit-filter-chips">
                            <?php foreach ($activeFilters as $filter): ?>
                                <a class="audit-filter-chip" href="<?php echo audit_h((string)$filter['url']); ?>" title="Remove this filter">
                                    <span><?php echo audit_h((string)$filter['label']); ?></span><i class="fas fa-xmark" aria-hidden="true"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <a class="audit-clear-all" href="admin/audit.php">Clear all</a>
                    </div>
                <?php endif; ?>

                <div class="audit-results-bar">
                    <div>
                        <strong><?php echo number_format($matchingCount); ?></strong> matching <?php echo $matchingCount === 1 ? 'activity' : 'activities'; ?>
                        <span>Showing <?php echo number_format($showStart); ?>–<?php echo number_format($showEnd); ?>, grouped by personnel and day</span>
                    </div>
                    <div class="audit-results-meta">
                        <span><i class="fas fa-arrow-down-wide-short" aria-hidden="true"></i> Newest first</span>
                        <span><i class="far fa-clock" aria-hidden="true"></i> Asia/Manila</span>
                        <span class="audit-help" title="The number shown beside each timestamp is its sequence in the current filtered results—not a user or database ID."><i class="far fa-circle-question" aria-hidden="true"></i><span class="audit-visually-hidden">About log sequence numbers</span></span>
                    </div>
                </div>

                <?php if ($loadError !== ''): ?>
                    <div class="audit-state audit-state-error" role="alert">
                        <span class="audit-state-icon"><i class="fas fa-circle-exclamation" aria-hidden="true"></i></span>
                        <div><h3>Audit logs could not be loaded</h3><p><?php echo audit_h($loadError); ?></p></div>
                    </div>
                <?php elseif (!$auditRows): ?>
                    <div class="audit-state">
                        <span class="audit-state-icon"><i class="fas fa-magnifying-glass" aria-hidden="true"></i></span>
                        <div><h3>No matching logs</h3><p>Adjust the search or remove one or more filters to see additional records.</p></div>
                        <?php if ($activeFilters): ?><a class="audit-btn audit-btn-secondary" href="admin/audit.php">Clear filters</a><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="audit-role-tabs" role="tablist" aria-label="Audit log groups by personnel type">
                        <?php foreach ($visibleTabKeys as $tabKey): ?>
                            <?php $tabConfig = $groupedTabs[$tabKey]; $isActiveTab = $preferredTab === $tabKey; ?>
                            <button
                                class="audit-role-tab <?php echo $isActiveTab ? 'active' : ''; ?>"
                                type="button"
                                role="tab"
                                id="<?php echo audit_h('audit-tab-btn-' . $tabKey); ?>"
                                aria-controls="<?php echo audit_h('audit-tab-panel-' . $tabKey); ?>"
                                aria-selected="<?php echo $isActiveTab ? 'true' : 'false'; ?>"
                                data-audit-tab-target="<?php echo audit_h($tabKey); ?>"
                            >
                                <span><?php echo audit_h((string)$tabConfig['label']); ?></span>
                                <strong><?php echo number_format((int)$tabConfig['entry_count']); ?></strong>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="audit-tab-panels">
                        <?php foreach ($visibleTabKeys as $tabKey): ?>
                            <?php $tabConfig = $groupedTabs[$tabKey]; $isActiveTab = $preferredTab === $tabKey; ?>
                            <section
                                class="audit-tab-panel <?php echo $isActiveTab ? 'active' : ''; ?>"
                                id="<?php echo audit_h('audit-tab-panel-' . $tabKey); ?>"
                                role="tabpanel"
                                aria-labelledby="<?php echo audit_h('audit-tab-btn-' . $tabKey); ?>"
                                <?php echo $isActiveTab ? '' : 'hidden'; ?>
                            >
                                <div class="audit-tab-intro">
                                    <div>
                                        <h3><?php echo audit_h((string)$tabConfig['label']); ?></h3>
                                        <p><?php echo audit_h((string)$tabConfig['description']); ?></p>
                                    </div>
                                    <span class="audit-tab-total"><?php echo number_format((int)$tabConfig['entry_count']); ?> <?php echo (int)$tabConfig['entry_count'] === 1 ? 'action' : 'actions'; ?></span>
                                </div>

                                <?php if (empty($tabConfig['groups'])): ?>
                                    <div class="audit-empty-inline">No grouped entries are available for this tab.</div>
                                <?php else: ?>
                                    <div class="audit-group-list">
                                        <?php foreach ($tabConfig['groups'] as $groupIndex => $group): ?>
                                            <details class="audit-group-card" <?php echo ($isActiveTab && $groupIndex === 0) ? 'open' : ''; ?>>
                                                <summary>
                                                    <div class="audit-group-summary-main">
                                                        <span class="audit-person-badge <?php echo audit_h((string)$group['role_tone']); ?>"><i class="fas <?php echo audit_h((string)$group['role_icon']); ?>" aria-hidden="true"></i></span>
                                                        <div class="audit-group-summary-text">
                                                            <strong><?php echo audit_h((string)$group['actor_name']); ?></strong>
                                                            <div class="audit-group-summary-meta">
                                                                <span class="audit-mini-chip"><?php echo audit_h((string)$group['role_label']); ?></span>
                                                                <span class="audit-mini-chip"><?php echo audit_h((string)$group['source_label']); ?></span>
                                                                <?php if (trim((string)$group['actor_email']) !== ''): ?><span class="audit-group-email"><?php echo audit_h((string)$group['actor_email']); ?></span><?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="audit-group-summary-side">
                                                        <span class="audit-group-date"><i class="far fa-calendar" aria-hidden="true"></i><?php echo audit_h((string)$group['date_label']); ?></span>
                                                        <span class="audit-group-count"><?php echo number_format((int)$group['entry_total']); ?> <?php echo (int)$group['entry_total'] === 1 ? 'action' : 'actions'; ?></span>
                                                        <span class="audit-group-latest"><i class="far fa-clock" aria-hidden="true"></i>Latest <?php echo audit_h((string)$group['latest_time_label']); ?></span>
                                                        <span class="audit-group-health">
                                                            <?php if ((int)$group['success_count'] > 0): ?><span class="audit-mini-stat success"><?php echo number_format((int)$group['success_count']); ?> success</span><?php endif; ?>
                                                            <?php if ((int)$group['warning_count'] > 0): ?><span class="audit-mini-stat warning"><?php echo number_format((int)$group['warning_count']); ?> warning</span><?php endif; ?>
                                                            <?php if ((int)$group['failed_count'] > 0): ?><span class="audit-mini-stat failed"><?php echo number_format((int)$group['failed_count']); ?> failed</span><?php endif; ?>
                                                        </span>
                                                        <i class="fas fa-chevron-down audit-group-chevron" aria-hidden="true"></i>
                                                    </div>
                                                </summary>

                                                <div class="audit-group-body">
                                                    <ol class="audit-entry-list">
                                                        <?php foreach ($group['entries'] as $entry): ?>
                                                            <li class="audit-entry-card">
                                                                <div class="audit-entry-top">
                                                                    <div class="audit-entry-time">
                                                                        <span class="audit-sequence" title="Sequence in current filtered results">#<?php echo number_format((int)$entry['log_no']); ?></span>
                                                                        <time datetime="<?php echo audit_h((string)$entry['created_at_iso']); ?>">
                                                                            <strong><?php echo audit_h((string)$entry['created_time_label']); ?></strong>
                                                                            <span><?php echo audit_h((string)$entry['created_full_label']); ?></span>
                                                                        </time>
                                                                    </div>

                                                                    <div class="audit-entry-main">
                                                                        <div class="audit-entry-heading">
                                                                            <strong><?php echo audit_h((string)$entry['action_label']); ?></strong>
                                                                            <span class="audit-chip"><?php echo audit_h((string)$entry['category_label']); ?></span>
                                                                            <span class="audit-outcome <?php echo audit_h((string)$entry['outcome']); ?>"><span aria-hidden="true"></span><?php echo audit_h(ucfirst((string)$entry['outcome'])); ?></span>
                                                                        </div>
                                                                        <p><?php echo audit_h((string)($entry['details_summary'] !== '' ? $entry['details_summary'] : 'No description recorded.')); ?></p>
                                                                        <div class="audit-entry-meta">
                                                                            <span><i class="fas fa-link" aria-hidden="true"></i><?php echo audit_h((string)$entry['source_label']); ?></span>
                                                                            <?php if (trim((string)$entry['reference']) !== ''): ?>
                                                                                <a class="audit-reference" href="<?php echo audit_h(audit_query_url(['reference' => (string)$entry['reference'], 'page' => 1])); ?>" title="Open lifecycle for <?php echo audit_h((string)$entry['reference']); ?>"><?php echo audit_h((string)$entry['reference']); ?></a>
                                                                            <?php else: ?>
                                                                                <span class="audit-reference-empty">No incident reference</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>

                                                                    <div class="audit-entry-actions">
                                                                        <button class="audit-row-action" type="button" data-audit-dialog="<?php echo audit_h((string)$entry['dialog_id']); ?>" aria-haspopup="dialog">
                                                                            <i class="far fa-eye" aria-hidden="true"></i><span>View details</span>
                                                                        </button>
                                                                    </div>
                                                                </div>

                                                                <dialog class="audit-detail-dialog" id="<?php echo audit_h((string)$entry['dialog_id']); ?>" aria-labelledby="<?php echo audit_h((string)$entry['dialog_title_id']); ?>">
                                                                    <div class="audit-dialog-shell">
                                                                        <header class="audit-dialog-header">
                                                                            <div>
                                                                                <span>Log #<?php echo number_format((int)$entry['log_no']); ?> · <?php echo audit_h((string)$entry['created_full_label']); ?></span>
                                                                                <h2 id="<?php echo audit_h((string)$entry['dialog_title_id']); ?>"><?php echo audit_h((string)$entry['action_label']); ?></h2>
                                                                                <p><?php echo audit_h((string)$entry['category_label']); ?> activity from <?php echo audit_h((string)$entry['source_label']); ?></p>
                                                                            </div>
                                                                            <button class="audit-dialog-close" type="button" data-audit-dialog-close aria-label="Close details"><i class="fas fa-xmark" aria-hidden="true"></i></button>
                                                                        </header>
                                                                        <div class="audit-dialog-body">
                                                                            <section class="audit-dialog-summary" aria-label="Log summary">
                                                                                <div>
                                                                                    <span class="audit-outcome <?php echo audit_h((string)$entry['outcome']); ?>"><span aria-hidden="true"></span><?php echo audit_h(ucfirst((string)$entry['outcome'])); ?></span>
                                                                                    <?php if (trim((string)$entry['reference']) !== ''): ?><span class="audit-reference audit-reference-static"><?php echo audit_h((string)$entry['reference']); ?></span><?php endif; ?>
                                                                                </div>
                                                                                <p><?php echo audit_h((string)($entry['details_summary'] !== '' ? $entry['details_summary'] : 'No description was recorded for this event.')); ?></p>
                                                                            </section>
                                                                            <dl class="audit-detail-grid">
                                                                                <div><dt>Personnel</dt><dd><?php echo audit_h((string)$entry['actor_name']); ?></dd></div>
                                                                                <div><dt>Personnel role</dt><dd><?php echo audit_h((string)$entry['role_label']); ?></dd></div>
                                                                                <div><dt>Source</dt><dd><?php echo audit_h((string)$entry['source_label']); ?></dd></div>
                                                                                <div><dt>Category</dt><dd><?php echo audit_h((string)$entry['category_label']); ?></dd></div>
                                                                                <div><dt>Date and time</dt><dd><?php echo audit_h((string)$entry['created_full_label']); ?></dd></div>
                                                                                <div><dt>Entity</dt><dd><?php echo audit_h((string)$entry['entity_text']); ?></dd></div>
                                                                                <div><dt>Request ID</dt><dd class="audit-mono"><?php echo audit_h((string)($entry['request_id'] !== '' ? $entry['request_id'] : 'Legacy log / not recorded')); ?></dd></div>
                                                                                <div><dt>IP address</dt><dd class="audit-mono"><?php echo audit_h((string)($entry['ip_address'] !== '' ? $entry['ip_address'] : 'Not recorded')); ?></dd></div>
                                                                                <div class="audit-detail-wide"><dt>Personnel email</dt><dd><?php echo audit_h((string)($entry['actor_email'] !== '' ? $entry['actor_email'] : 'Not recorded')); ?></dd></div>
                                                                                <div class="audit-detail-wide"><dt>User agent</dt><dd class="audit-breakable"><?php echo audit_h((string)($entry['user_agent'] !== '' ? $entry['user_agent'] : 'Not recorded')); ?></dd></div>
                                                                            </dl>
                                                                            <?php if (trim((string)$entry['metadata_pretty']) !== ''): ?>
                                                                                <section class="audit-metadata">
                                                                                    <div><h3>Structured metadata</h3><span>Raw context captured with this event</span></div>
                                                                                    <pre><?php echo audit_h((string)$entry['metadata_pretty']); ?></pre>
                                                                                </section>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <footer class="audit-dialog-footer">
                                                                            <button class="audit-btn audit-btn-secondary" type="button" data-audit-dialog-close>Close</button>
                                                                        </footer>
                                                                    </div>
                                                                </dialog>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ol>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($pageCount > 1): ?>
                    <nav class="audit-pagination" aria-label="Audit log pages">
                        <span>Page <strong><?php echo number_format($page); ?></strong> of <strong><?php echo number_format($pageCount); ?></strong></span>
                        <div class="audit-pages">
                            <a class="audit-page-link audit-page-nav <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo audit_h(audit_query_url(['page' => max(1, $page - 1)])); ?>" aria-label="Previous page" <?php echo $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : ''; ?>><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                            <?php
                            $pageStart = max(1, $page - 2);
                            $pageEnd = min($pageCount, $page + 2);
                            if ($pageStart > 1): ?><a class="audit-page-link" href="<?php echo audit_h(audit_query_url(['page' => 1])); ?>">1</a><?php if ($pageStart > 2): ?><span class="audit-page-link disabled" aria-hidden="true">…</span><?php endif; ?><?php endif;
                            for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                                <a class="audit-page-link <?php echo $pageNumber === $page ? 'active' : ''; ?>" href="<?php echo audit_h(audit_query_url(['page' => $pageNumber])); ?>" <?php echo $pageNumber === $page ? 'aria-current="page"' : ''; ?>><?php echo number_format($pageNumber); ?></a>
                            <?php endfor;
                            if ($pageEnd < $pageCount): ?><?php if ($pageEnd < $pageCount - 1): ?><span class="audit-page-link disabled" aria-hidden="true">…</span><?php endif; ?><a class="audit-page-link" href="<?php echo audit_h(audit_query_url(['page' => $pageCount])); ?>"><?php echo number_format($pageCount); ?></a><?php endif; ?>
                            <a class="audit-page-link audit-page-nav <?php echo $page >= $pageCount ? 'disabled' : ''; ?>" href="<?php echo audit_h(audit_query_url(['page' => min($pageCount, $page + 1)])); ?>" aria-label="Next page" <?php echo $page >= $pageCount ? 'aria-disabled="true" tabindex="-1"' : ''; ?>><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                        </div>
                    </nav>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>
</body>
</html>
