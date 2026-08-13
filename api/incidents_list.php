<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';

$requireApiRoles = static function (array $allowedRoles): array {
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $user = get_logged_in_user() ?? [];
    $role = canonical_role((string)($user['role'] ?? ''));
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $user['canonical_role'] = $role;
    return $user;
};
$requireApiRoles(['admin', 'dispatcher']);
unset($requireApiRoles);

require_once __DIR__ . '/../includes/db.php';

/** @return array<string,array<string,true>> */
function ers_incidents_schema(PDO $pdo): array
{
    $tables = [
        'incidents',
        'calls',
        'external_incident_links',
        'activity_log',
        'interagency_incident_cards',
        'dispatches',
        'units',
        'resource_records',
        'admin_resources',
        'incident_notes',
        'incident_surveys',
        'incident_admin_reviews',
        'api_sync_logs',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));

    try {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute($tables);
    } catch (Throwable $e) {
        error_log('incidents_list schema lookup failed: ' . $e->getMessage());
        return [];
    }

    $schema = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $schema[$table][$column] = true;
        }
    }

    return $schema;
}

/** @param array<string,array<string,true>> $schema */
function ers_incidents_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]);
}

/** @param array<string,array<string,true>> $schema */
function ers_incidents_has_column(array $schema, string $table, string $column): bool
{
    return isset($schema[$table][$column]);
}

function ers_incidents_valid_date(string $value, string $format): ?DateTimeImmutable
{
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false) {
        return null;
    }
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }
    if ($date->format($format) !== $value) {
        return null;
    }

    return $date;
}

/** @return array{0:?string,1:?string} */
function ers_incidents_resolve_range(): array
{
    $startRaw = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $endRaw = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($startRaw !== '' || $endRaw !== '') {
        $start = ers_incidents_valid_date($startRaw, 'Y-m-d');
        $end = ers_incidents_valid_date($endRaw, 'Y-m-d');
        if ($start !== null && $end !== null && $start <= $end) {
            return [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];
        }
        return [null, null];
    }

    $dayRaw = isset($_GET['day']) ? trim((string)$_GET['day']) : '';
    if ($dayRaw !== '') {
        $day = ers_incidents_valid_date($dayRaw, 'Y-m-d');
        return $day !== null
            ? [$day->format('Y-m-d 00:00:00'), $day->format('Y-m-d 23:59:59')]
            : [null, null];
    }

    $monthRaw = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
    if ($monthRaw !== '') {
        $month = ers_incidents_valid_date($monthRaw, 'Y-m');
        if ($month === null) {
            return [null, null];
        }
        $monthEnd = $month->modify('+1 month -1 day');
        return [$month->format('Y-m-d 00:00:00'), $monthEnd->format('Y-m-d 23:59:59')];
    }

    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : '';
    if ($period === '') {
        return [null, null];
    }

    $today = new DateTimeImmutable('today');
    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];

        case 'week':
            $rangeStart = $today->modify('monday this week');
            $rangeEnd = $rangeStart->modify('+6 days');
            break;

        case 'quarter':
            $monthNumber = (int)$today->format('n');
            $quarterStartMonth = intdiv($monthNumber - 1, 3) * 3 + 1;
            $rangeStart = new DateTimeImmutable(
                $today->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01'
            );
            $rangeEnd = $rangeStart->modify('+3 months -1 day');
            break;

        case 'year':
            $rangeStart = new DateTimeImmutable($today->format('Y-01-01'));
            $rangeEnd = new DateTimeImmutable($today->format('Y-12-31'));
            break;

        case 'month':
        default:
            $rangeStart = new DateTimeImmutable($today->format('Y-m-01'));
            $rangeEnd = $rangeStart->modify('+1 month -1 day');
            break;
    }

    return [$rangeStart->format('Y-m-d 00:00:00'), $rangeEnd->format('Y-m-d 23:59:59')];
}

/** @return list<string> */
function ers_incidents_normalized_type_values(string $typeFilter): array
{
    $typeFilter = strtolower(trim($typeFilter));
    if ($typeFilter === '') {
        return [];
    }
    if (in_array($typeFilter, ['traffic', 'accident'], true)) {
        return ['traffic', 'accident'];
    }
    if (in_array($typeFilter, ['police', 'crime'], true)) {
        return ['police', 'crime'];
    }
    return [$typeFilter];
}

/**
 * @param list<string> $where
 * @param array<string,mixed> $params
 * @param list<string> $typeValues
 */
function ers_incidents_append_type_filter(
    array &$where,
    array &$params,
    string $column,
    array $typeValues,
    string $prefix
): void {
    if ($typeValues === []) {
        return;
    }

    $clauses = [];
    foreach ($typeValues as $index => $value) {
        $base = $prefix . '_type_' . $index;
        $exact = ':' . $base . '_exact';
        $start = ':' . $base . '_start';
        $end = ':' . $base . '_end';
        $middle = ':' . $base . '_middle';
        $params[$base . '_exact'] = $value;
        $params[$base . '_start'] = $value . ',%';
        $params[$base . '_end'] = '%, ' . $value;
        $params[$base . '_middle'] = '%, ' . $value . ',%';
        $clauses[] = "(LOWER({$column}) = {$exact}
            OR LOWER({$column}) LIKE {$start}
            OR LOWER({$column}) LIKE {$end}
            OR LOWER({$column}) LIKE {$middle})";
    }

    $where[] = '(' . implode(' OR ', $clauses) . ')';
}

/** @param list<string> $expressions */
function ers_incidents_coalesce(array $expressions): string
{
    $expressions = array_values(array_filter(
        $expressions,
        static fn (string $expression): bool => $expression !== 'NULL'
    ));
    if ($expressions === []) {
        return 'NULL';
    }
    if (count($expressions) === 1) {
        return $expressions[0];
    }
    return 'COALESCE(' . implode(', ', $expressions) . ')';
}

/**
 * Return a deterministic source classification for one incident signal set.
 * Kept pure so precedence remains easy to regression-test without a database.
 *
 * @return array{source:string,label:string,detection:string,inferred:bool}
 */
function ers_incidents_classify_intake_source(
    bool $hasTipOrigin,
    bool $hasExternalOrigin,
    bool $hasIncidentExternalSource,
    bool $hasSystemExternalCard,
    bool $hasAcceptedCall,
    bool $hasReportedCall,
    ?string $explicitSource = null
): array {
    if ($hasTipOrigin) {
        return [
            'source' => 'tip',
            'label' => 'Converted TIP',
            'detection' => 'anonymous_tip_link',
            'inferred' => false,
        ];
    }
    $explicitSource = strtolower(trim((string)$explicitSource));
    if ($explicitSource === 'tip') {
        return [
            'source' => 'tip',
            'label' => 'Converted TIP',
            'detection' => 'recorded_intake_source',
            'inferred' => false,
        ];
    }
    if ($explicitSource === 'call' || $hasAcceptedCall) {
        return [
            'source' => 'call',
            'label' => 'Emergency Call',
            'detection' => $explicitSource === 'call' ? 'recorded_intake_source' : 'accepted_call_audit',
            'inferred' => false,
        ];
    }
    if ($hasExternalOrigin) {
        return [
            'source' => 'interagency',
            'label' => 'Inter-agency',
            'detection' => 'external_incident_link',
            'inferred' => false,
        ];
    }
    if ($hasIncidentExternalSource) {
        return [
            'source' => 'interagency',
            'label' => 'Inter-agency',
            'detection' => 'incident_external_source',
            'inferred' => false,
        ];
    }
    if ($hasSystemExternalCard) {
        return [
            'source' => 'interagency',
            'label' => 'Inter-agency',
            'detection' => 'system_incident_card',
            'inferred' => false,
        ];
    }
    if ($explicitSource === 'interagency') {
        return [
            'source' => 'interagency',
            'label' => 'Inter-agency',
            'detection' => 'recorded_intake_source',
            'inferred' => false,
        ];
    }
    if ($explicitSource === 'manual') {
        return [
            'source' => $explicitSource,
            'label' => 'Manual Incident',
            'detection' => 'recorded_intake_source',
            'inferred' => false,
        ];
    }
    return [
        'source' => 'unverified',
        'label' => 'Source unverified',
        'detection' => $hasReportedCall ? 'legacy_linked_record' : 'legacy_direct_record',
        'inferred' => true,
    ];
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$schema = ers_incidents_schema($pdo);
if (
    !ers_incidents_has_table($schema, 'incidents')
    || !ers_incidents_has_column($schema, 'incidents', 'id')
) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Incidents table unavailable']);
    exit;
}

$priority = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
$status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
$adminReview = isset($_GET['admin_review']) ? strtolower(trim((string)$_GET['admin_review'])) : '';
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$includeIntakeSource = isset($_GET['include_intake_source'])
    && in_array(strtolower(trim((string)$_GET['include_intake_source'])), ['1', 'true', 'yes'], true);
$typeValues = ers_incidents_normalized_type_values($type);
[$rangeStart, $rangeEnd] = ers_incidents_resolve_range();

// Optional shared resource table.
$resourceRecordsTable = null;
foreach (['resource_records', 'admin_resources'] as $candidate) {
    if (
        ers_incidents_has_table($schema, $candidate)
        && ers_incidents_has_column($schema, $candidate, 'code')
    ) {
        $resourceRecordsTable = $candidate;
        break;
    }
}

$incidentExpr = static function (string $column, string $fallback = 'NULL') use ($schema): string {
    return ers_incidents_has_column($schema, 'incidents', $column) ? "i.`{$column}`" : $fallback;
};

$referenceNoExpr = $incidentExpr('reference_no', "''");
$typeExpr = $incidentExpr('type', "''");
$priorityExpr = $incidentExpr('priority', "''");
$statusExpr = $incidentExpr('status', "''");
$locationAddressExpr = $incidentExpr('location_address', "''");
$descriptionExpr = $incidentExpr('description', "''");
$createdAtExpr = $incidentExpr('created_at');
$updatedAtExpr = $incidentExpr('updated_at');
$resolvedAtExpr = $incidentExpr('resolved_at');
$titleExpr = $incidentExpr('title', "''");
$incidentLatExpr = $incidentExpr('latitude');
$incidentLngExpr = $incidentExpr('longitude');
$reportedByCallIdExpr = $incidentExpr('reported_by_call_id');
$incidentExternalSourceExpr = $incidentExpr('external_source');
$incidentExternalIdExpr = $incidentExpr('external_incident_id');
$incidentIntakeSourceExpr = $incidentExpr('intake_source');
$normalizedIncidentIntakeSourceExpr = $includeIntakeSource && $incidentIntakeSourceExpr !== 'NULL'
    ? "LOWER(TRIM(COALESCE({$incidentIntakeSourceExpr}, '')))"
    : "''";
$hasIncidentExternalSourceExpr = $includeIntakeSource && $incidentExternalSourceExpr !== 'NULL'
    ? "CASE WHEN NULLIF(TRIM(COALESCE({$incidentExternalSourceExpr}, '')), '') IS NULL THEN 0 ELSE 1 END"
    : '0';

// Call details and coordinate fallback.
$callJoin = '';
$callerNameExpr = 'NULL';
$callerPhoneExpr = 'NULL';
$callLatExpr = 'NULL';
$callLngExpr = 'NULL';
if (
    ers_incidents_has_column($schema, 'incidents', 'reported_by_call_id')
    && ers_incidents_has_table($schema, 'calls')
    && ers_incidents_has_column($schema, 'calls', 'id')
) {
    $callJoin = ' LEFT JOIN calls c ON c.id = i.reported_by_call_id';
    $callerNameExpr = ers_incidents_has_column($schema, 'calls', 'caller_name') ? 'c.caller_name' : 'NULL';
    $callerPhoneExpr = ers_incidents_has_column($schema, 'calls', 'caller_phone') ? 'c.caller_phone' : 'NULL';
    $callLatExpr = ers_incidents_has_column($schema, 'calls', 'latitude') ? 'c.latitude' : 'NULL';
    $callLngExpr = ers_incidents_has_column($schema, 'calls', 'longitude') ? 'c.longitude' : 'NULL';
}
$latitudeExpr = ers_incidents_coalesce([$incidentLatExpr, $callLatExpr]);
$longitudeExpr = ers_incidents_coalesce([$incidentLngExpr, $callLngExpr]);

// Intake-source signals are returned as read-only metadata for the Dispatcher
// queue. The existing incident workflow and status filters remain unchanged.
// TIP takes precedence over other external links; accepted call-session audit
// events distinguish live calls from manually entered local incidents.
$externalOriginJoin = '';
$hasTipOriginExpr = '0';
$hasExternalOriginExpr = '0';
$tipSourceSystemExpr = 'NULL';
$externalSourceSystemExpr = 'NULL';
$tipExternalIdExpr = 'NULL';
$externalLinkExternalIdExpr = 'NULL';
if (
    $includeIntakeSource
    && ers_incidents_has_table($schema, 'external_incident_links')
    && ers_incidents_has_column($schema, 'external_incident_links', 'incident_id')
    && ers_incidents_has_column($schema, 'external_incident_links', 'source_system')
) {
    $payloadExpr = ers_incidents_has_column($schema, 'external_incident_links', 'payload_json')
        ? "LOWER(COALESCE(payload_json, ''))"
        : "''";
    $tipSignal = "(LOWER(TRIM(COALESCE(source_system, ''))) = 'anonymous tip inbox'
        OR {$payloadExpr} LIKE '%\"source\":\"anonymous_tip\"%')";
    $tipExternalIdSelect = ers_incidents_has_column($schema, 'external_incident_links', 'external_incident_id')
        ? "MAX(CASE WHEN {$tipSignal} THEN NULLIF(TRIM(external_incident_id), '') ELSE NULL END)"
        : 'NULL';
    $externalExternalIdSelect = ers_incidents_has_column($schema, 'external_incident_links', 'external_incident_id')
        ? "MAX(CASE WHEN TRIM(COALESCE(source_system, '')) <> '' AND NOT {$tipSignal}
            THEN NULLIF(TRIM(external_incident_id), '') ELSE NULL END)"
        : 'NULL';
    $externalOriginJoin = " LEFT JOIN (
        SELECT
            incident_id,
            MAX(CASE WHEN {$tipSignal} THEN 1 ELSE 0 END) AS has_tip_origin,
            MAX(CASE WHEN TRIM(COALESCE(source_system, '')) <> '' AND NOT {$tipSignal}
                THEN 1 ELSE 0 END) AS has_external_origin,
            MAX(CASE WHEN {$tipSignal}
                THEN NULLIF(TRIM(source_system), '') ELSE NULL END) AS tip_source_system,
            MAX(CASE WHEN TRIM(COALESCE(source_system, '')) <> '' AND NOT {$tipSignal}
                THEN NULLIF(TRIM(source_system), '') ELSE NULL END) AS external_source_system,
            {$tipExternalIdSelect} AS tip_external_id,
            {$externalExternalIdSelect} AS external_external_id
        FROM external_incident_links
        GROUP BY incident_id
    ) intake_links ON intake_links.incident_id = i.id";
    $hasTipOriginExpr = 'COALESCE(intake_links.has_tip_origin, 0)';
    $hasExternalOriginExpr = 'COALESCE(intake_links.has_external_origin, 0)';
    $tipSourceSystemExpr = 'intake_links.tip_source_system';
    $externalSourceSystemExpr = 'intake_links.external_source_system';
    $tipExternalIdExpr = 'intake_links.tip_external_id';
    $externalLinkExternalIdExpr = 'intake_links.external_external_id';
}

$acceptedCallJoin = '';
$hasAcceptedCallExpr = '0';
if (
    $includeIntakeSource
    && $reportedByCallIdExpr !== 'NULL'
    && ers_incidents_has_table($schema, 'activity_log')
    && ers_incidents_has_column($schema, 'activity_log', 'entity_type')
    && ers_incidents_has_column($schema, 'activity_log', 'entity_id')
    && (
        ers_incidents_has_column($schema, 'activity_log', 'action')
        || ers_incidents_has_column($schema, 'activity_log', 'event_key')
    )
) {
    $acceptedEventFilter = ers_incidents_has_column($schema, 'activity_log', 'action')
        ? "action = 'call_accepted'"
        : "(event_key LIKE 'call_session:%:accepted' OR event_key LIKE 'call:%:accepted')";
    $acceptedCallJoin = " LEFT JOIN (
        SELECT entity_id AS call_id, 1 AS has_accepted_call
        FROM activity_log
        WHERE entity_type = 'call'
          AND {$acceptedEventFilter}
        GROUP BY entity_id
    ) accepted_calls ON accepted_calls.call_id = i.reported_by_call_id";
    $hasAcceptedCallExpr = 'COALESCE(accepted_calls.has_accepted_call, 0)';
}

// Some legacy inter-agency senders created a system-authored incident card
// without an external_incident_links row. Only null-user system cards qualify;
// locally shared incident cards must not be reclassified as incoming cases.
$systemCardJoin = '';
$hasSystemExternalCardExpr = '0';
$systemExternalSourceExpr = 'NULL';
$systemExternalIdExpr = 'NULL';
if (
    $includeIntakeSource
    && ers_incidents_has_table($schema, 'interagency_incident_cards')
    && ers_incidents_has_column($schema, 'interagency_incident_cards', 'incident_id')
    && ers_incidents_has_column($schema, 'interagency_incident_cards', 'message_id')
    && ers_incidents_has_table($schema, 'activity_log')
    && ers_incidents_has_column($schema, 'activity_log', 'id')
    && ers_incidents_has_column($schema, 'activity_log', 'user_id')
    && ers_incidents_has_column($schema, 'activity_log', 'details')
) {
    $safeDetailsExpr = "CASE WHEN JSON_VALID(a.details) THEN a.details ELSE '{}' END";
    $systemCardFilters = [
        'a.user_id IS NULL',
        'JSON_VALID(a.details)',
        "JSON_EXTRACT({$safeDetailsExpr}, '$.incident_card') IS NOT NULL",
    ];
    if (ers_incidents_has_column($schema, 'activity_log', 'action')) {
        $systemCardFilters[] = "LOWER(TRIM(COALESCE(a.action, ''))) = 'chat'";
    }
    if (ers_incidents_has_column($schema, 'activity_log', 'entity_type')) {
        $systemCardFilters[] = "LOWER(TRIM(COALESCE(a.entity_type, ''))) = 'agency_user_chat'";
    }
    $systemCardWhere = implode(' AND ', $systemCardFilters);
    $detailsSourceExpr = "MAX(COALESCE(
        NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$safeDetailsExpr}, '$.incident_card.source_system'))), ''),
        NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT({$safeDetailsExpr}, '$.external_sender_name'))), '')
    ))";
    $detailsExternalIdExpr = "MAX(NULLIF(TRIM(JSON_UNQUOTE(
        JSON_EXTRACT({$safeDetailsExpr}, '$.incident_card.external_incident_id')
    )), ''))";
    $systemCardJoin = " LEFT JOIN (
        SELECT
            c.incident_id,
            1 AS has_system_external_card,
            {$detailsSourceExpr} AS system_external_source,
            {$detailsExternalIdExpr} AS system_external_id
        FROM interagency_incident_cards c
        INNER JOIN activity_log a ON a.id = c.message_id
        WHERE {$systemCardWhere}
        GROUP BY c.incident_id
    ) system_cards ON system_cards.incident_id = i.id";
    $hasSystemExternalCardExpr = 'COALESCE(system_cards.has_system_external_card, 0)';
    $systemExternalSourceExpr = 'system_cards.system_external_source';
    $systemExternalIdExpr = 'system_cards.system_external_id';
}

$intakeSourceExpr = "CASE
    WHEN {$hasTipOriginExpr} = 1
      OR {$normalizedIncidentIntakeSourceExpr} = 'tip' THEN 'tip'
    WHEN {$normalizedIncidentIntakeSourceExpr} = 'call'
      OR {$hasAcceptedCallExpr} = 1 THEN 'call'
    WHEN {$hasExternalOriginExpr} = 1
      OR {$hasSystemExternalCardExpr} = 1
      OR {$hasIncidentExternalSourceExpr} = 1
      OR {$normalizedIncidentIntakeSourceExpr} = 'interagency' THEN 'interagency'
    WHEN {$normalizedIncidentIntakeSourceExpr} = 'manual' THEN 'manual'
    ELSE 'unverified'
END";
$intakeSourceLabelExpr = "CASE
    WHEN {$hasTipOriginExpr} = 1
      OR {$normalizedIncidentIntakeSourceExpr} = 'tip' THEN 'Converted TIP'
    WHEN {$normalizedIncidentIntakeSourceExpr} = 'call'
      OR {$hasAcceptedCallExpr} = 1 THEN 'Emergency Call'
    WHEN {$hasExternalOriginExpr} = 1
      OR {$hasSystemExternalCardExpr} = 1
      OR {$hasIncidentExternalSourceExpr} = 1
      OR {$normalizedIncidentIntakeSourceExpr} = 'interagency'
      THEN COALESCE(
          NULLIF({$externalSourceSystemExpr}, ''),
          NULLIF({$systemExternalSourceExpr}, ''),
          NULLIF(TRIM({$incidentExternalSourceExpr}), ''),
          'Inter-agency'
      )
    WHEN {$normalizedIncidentIntakeSourceExpr} = 'manual' THEN 'Manual Incident'
    ELSE 'Source unverified'
END";
$intakeSourceSystemExpr = "CASE
    WHEN {$hasTipOriginExpr} = 1
      THEN COALESCE(NULLIF({$tipSourceSystemExpr}, ''), 'Anonymous Tip Inbox')
    WHEN {$hasExternalOriginExpr} = 1
      THEN NULLIF({$externalSourceSystemExpr}, '')
    WHEN {$hasIncidentExternalSourceExpr} = 1
      THEN NULLIF(TRIM({$incidentExternalSourceExpr}), '')
    WHEN {$hasSystemExternalCardExpr} = 1
      THEN NULLIF({$systemExternalSourceExpr}, '')
    ELSE NULL
END";
$intakeExternalIdExpr = "CASE
    WHEN {$hasTipOriginExpr} = 1 THEN {$tipExternalIdExpr}
    WHEN {$hasExternalOriginExpr} = 1 THEN {$externalLinkExternalIdExpr}
    WHEN {$hasIncidentExternalSourceExpr} = 1 THEN {$incidentExternalIdExpr}
    WHEN {$hasSystemExternalCardExpr} = 1 THEN {$systemExternalIdExpr}
    ELSE NULL
END";
$intakeDetectionExpr = "CASE
    WHEN {$hasTipOriginExpr} = 1 THEN 'anonymous_tip_link'
    WHEN {$normalizedIncidentIntakeSourceExpr} = 'tip' THEN 'recorded_intake_source'
    WHEN {$normalizedIncidentIntakeSourceExpr} = 'call' THEN 'recorded_intake_source'
    WHEN {$hasAcceptedCallExpr} = 1 THEN 'accepted_call_audit'
    WHEN {$hasExternalOriginExpr} = 1 THEN 'external_incident_link'
    WHEN {$hasIncidentExternalSourceExpr} = 1 THEN 'incident_external_source'
    WHEN {$hasSystemExternalCardExpr} = 1 THEN 'system_incident_card'
    WHEN {$normalizedIncidentIntakeSourceExpr} IN ('manual', 'interagency')
      THEN 'recorded_intake_source'
    WHEN {$reportedByCallIdExpr} IS NOT NULL THEN 'legacy_linked_record'
    ELSE 'legacy_direct_record'
END";
$intakeSourceInferredExpr = "CASE
    WHEN {$hasTipOriginExpr} = 1
      OR {$hasExternalOriginExpr} = 1
      OR {$hasIncidentExternalSourceExpr} = 1
      OR {$hasSystemExternalCardExpr} = 1
      OR {$normalizedIncidentIntakeSourceExpr} IN ('call', 'manual', 'tip', 'interagency')
      OR {$hasAcceptedCallExpr} = 1 THEN 0
    ELSE 1
END";

// Latest dispatch is computed once per incident.
$dispatchJoin = '';
$unitJoin = '';
$hasLatestDispatch = ers_incidents_has_table($schema, 'dispatches')
    && ers_incidents_has_column($schema, 'dispatches', 'id')
    && ers_incidents_has_column($schema, 'dispatches', 'incident_id');
$dispatchColumns = [
    'unit_id',
    'status',
    'assigned_at',
    'acknowledged_at',
    'enroute_at',
    'on_scene_at',
    'cleared_at',
];
$dispatchSelectParts = ['d1.id', 'd1.incident_id'];
foreach ($dispatchColumns as $column) {
    $dispatchSelectParts[] = ers_incidents_has_column($schema, 'dispatches', $column)
        ? "d1.`{$column}`"
        : "NULL AS `{$column}`";
}

if ($hasLatestDispatch) {
    $dispatchJoin = " LEFT JOIN (
        SELECT " . implode(', ', $dispatchSelectParts) . "
        FROM dispatches d1
        INNER JOIN (
            SELECT incident_id, MAX(id) AS max_id
            FROM dispatches
            GROUP BY incident_id
        ) latest_dispatch ON latest_dispatch.max_id = d1.id
    ) ld ON ld.incident_id = i.id";
}

$unitIdentifierExpr = 'NULL';
$unitTypeExpr = 'NULL';
if (
    $hasLatestDispatch
    && ers_incidents_has_column($schema, 'dispatches', 'unit_id')
    && ers_incidents_has_table($schema, 'units')
    && ers_incidents_has_column($schema, 'units', 'id')
) {
    $unitJoin = ' LEFT JOIN units u ON u.id = ld.unit_id';
    $unitIdentifierExpr = ers_incidents_has_column($schema, 'units', 'identifier') ? 'u.identifier' : 'NULL';
    $unitTypeExpr = ers_incidents_has_column($schema, 'units', 'unit_type') ? 'u.unit_type' : 'NULL';
}

// Vehicle metadata.
$resourceJoin = '';
$vehicleNameExpr = 'NULL';
$driverNameExpr = 'NULL';
$plateNumberExpr = 'NULL';
if ($resourceRecordsTable !== null && $unitJoin !== '' && $unitIdentifierExpr !== 'NULL') {
    $resourceJoin = " LEFT JOIN `{$resourceRecordsTable}` ar ON ar.code = u.identifier";
    $vehicleNameExpr = ers_incidents_has_column($schema, $resourceRecordsTable, 'name') ? 'ar.name' : 'NULL';
    $driverNameExpr = ers_incidents_has_column($schema, $resourceRecordsTable, 'driver_name') ? 'ar.driver_name' : 'NULL';
    $plateNumberExpr = ers_incidents_has_column($schema, $resourceRecordsTable, 'plate_number') ? 'ar.plate_number' : 'NULL';
}

// Aggregate feedback once per incident instead of running multiple correlated
// COUNT/SUM subqueries for each of up to 200 result rows.
$noteAggregateJoin = '';
$noteFeedbackExpr = '0';
$noteRatingCountExpr = '0';
$noteRatingSumExpr = '0';
if (
    ers_incidents_has_table($schema, 'incident_notes')
    && ers_incidents_has_column($schema, 'incident_notes', 'incident_id')
    && ers_incidents_has_column($schema, 'incident_notes', 'note')
) {
    $hasNoteRating = ers_incidents_has_column($schema, 'incident_notes', 'rating');
    $noteRatingCountSelect = $hasNoteRating
        ? 'SUM(CASE WHEN rating IS NOT NULL THEN 1 ELSE 0 END)'
        : '0';
    $noteRatingSumSelect = $hasNoteRating
        ? 'COALESCE(SUM(CASE WHEN rating IS NOT NULL THEN rating ELSE 0 END), 0)'
        : '0';
    $noteAggregateJoin = " LEFT JOIN (
        SELECT
            incident_id,
            COUNT(*) AS feedback_count,
            {$noteRatingCountSelect} AS rating_count,
            {$noteRatingSumSelect} AS rating_sum
        FROM incident_notes
        WHERE note NOT LIKE 'Resolution proof uploaded:%'
        GROUP BY incident_id
    ) note_stats ON note_stats.incident_id = i.id";
    $noteFeedbackExpr = 'COALESCE(note_stats.feedback_count, 0)';
    $noteRatingCountExpr = 'COALESCE(note_stats.rating_count, 0)';
    $noteRatingSumExpr = 'COALESCE(note_stats.rating_sum, 0)';
}

$surveyAggregateJoin = '';
$surveyFeedbackExpr = '0';
$surveyRatingCountExpr = '0';
$surveyRatingSumExpr = '0';
if (
    ers_incidents_has_table($schema, 'incident_surveys')
    && ers_incidents_has_column($schema, 'incident_surveys', 'incident_id')
    && ers_incidents_has_column($schema, 'incident_surveys', 'response_rating')
) {
    $surveyAggregateJoin = " LEFT JOIN (
        SELECT
            incident_id,
            COUNT(*) AS feedback_count,
            SUM(CASE WHEN response_rating IS NOT NULL THEN 1 ELSE 0 END) AS rating_count,
            COALESCE(SUM(CASE WHEN response_rating IS NOT NULL THEN response_rating ELSE 0 END), 0) AS rating_sum
        FROM incident_surveys
        GROUP BY incident_id
    ) survey_stats ON survey_stats.incident_id = i.id";
    $surveyFeedbackExpr = 'COALESCE(survey_stats.feedback_count, 0)';
    $surveyRatingCountExpr = 'COALESCE(survey_stats.rating_count, 0)';
    $surveyRatingSumExpr = 'COALESCE(survey_stats.rating_sum, 0)';
}

$feedbackCountExpr = "({$noteFeedbackExpr} + {$surveyFeedbackExpr})";
$ratingCountExpr = "({$noteRatingCountExpr} + {$surveyRatingCountExpr})";
$ratingSumExpr = "({$noteRatingSumExpr} + {$surveyRatingSumExpr})";
$avgRatingExpr = "CASE
    WHEN {$ratingCountExpr} > 0 THEN ROUND({$ratingSumExpr} / {$ratingCountExpr}, 1)
    ELSE NULL
END";

// Read-only table detection: do not call ers_ensure_incident_admin_reviews(),
// which executes CREATE TABLE on every list request.
$adminReviewJoin = '';
$adminReviewSentAtExpr = 'NULL';
$adminReviewSentByNameExpr = 'NULL';
$adminReviewSentByUserIdExpr = 'NULL';
$hasAdminReviewTable = ers_incidents_has_table($schema, 'incident_admin_reviews')
    && ers_incidents_has_column($schema, 'incident_admin_reviews', 'incident_id');
if ($hasAdminReviewTable) {
    $adminReviewJoin = ' LEFT JOIN incident_admin_reviews iar ON iar.incident_id = i.id';
    $adminReviewSentAtExpr = ers_incidents_has_column($schema, 'incident_admin_reviews', 'sent_at') ? 'iar.sent_at' : 'NULL';
    $adminReviewSentByNameExpr = ers_incidents_has_column($schema, 'incident_admin_reviews', 'sent_by_name') ? 'iar.sent_by_name' : 'NULL';
    $adminReviewSentByUserIdExpr = ers_incidents_has_column($schema, 'incident_admin_reviews', 'sent_by_user_id') ? 'iar.sent_by_user_id' : 'NULL';
}

$crimeAnalyticsJoin = '';
$crimeAnalyticsStatusExpr = 'NULL';
$crimeAnalyticsSyncedAtExpr = 'NULL';
$hasCrimeAnalyticsLogTable = ers_incidents_has_table($schema, 'api_sync_logs')
    && ers_incidents_has_column($schema, 'api_sync_logs', 'id')
    && ers_incidents_has_column($schema, 'api_sync_logs', 'entity_id')
    && ers_incidents_has_column($schema, 'api_sync_logs', 'entity_type')
    && ers_incidents_has_column($schema, 'api_sync_logs', 'endpoint_name')
    && ers_incidents_has_column($schema, 'api_sync_logs', 'status');
if ($hasCrimeAnalyticsLogTable) {
    $crimeAnalyticsWhere = [
        "asl.entity_type = 'incident'",
        "asl.endpoint_name = 'send_crime_analytics'",
    ];
    if (ers_incidents_has_column($schema, 'api_sync_logs', 'direction')) {
        $crimeAnalyticsWhere[] = "asl.direction = 'outgoing'";
    }
    if (ers_incidents_has_column($schema, 'api_sync_logs', 'target_group')) {
        $crimeAnalyticsWhere[] = "asl.target_group = 'Crime Analytics'";
    }
    $crimeAnalyticsUpdatedAtSelect = ers_incidents_has_column($schema, 'api_sync_logs', 'updated_at') ? 'asl.updated_at' : 'NULL AS updated_at';
    $crimeAnalyticsJoin = " LEFT JOIN (
        SELECT asl.entity_id, asl.status, {$crimeAnalyticsUpdatedAtSelect}
        FROM api_sync_logs asl
        INNER JOIN (
            SELECT entity_id, MAX(id) AS max_id
            FROM api_sync_logs
            WHERE entity_type = 'incident'
              AND endpoint_name = 'send_crime_analytics'
            GROUP BY entity_id
        ) latest_crime_sync ON latest_crime_sync.max_id = asl.id
        WHERE " . implode(' AND ', $crimeAnalyticsWhere) . "
    ) crime_sync ON crime_sync.entity_id = i.id";
    $crimeAnalyticsStatusExpr = 'crime_sync.status';
    $crimeAnalyticsSyncedAtExpr = ers_incidents_has_column($schema, 'api_sync_logs', 'updated_at') ? 'crime_sync.updated_at' : 'NULL';
}

$assignedAtExpr = $hasLatestDispatch ? 'ld.assigned_at' : 'NULL';
$acknowledgedAtExpr = $hasLatestDispatch ? 'ld.acknowledged_at' : 'NULL';
$enrouteAtExpr = $hasLatestDispatch ? 'ld.enroute_at' : 'NULL';
$onSceneAtExpr = $hasLatestDispatch ? 'ld.on_scene_at' : 'NULL';
$clearedAtExpr = $hasLatestDispatch ? 'ld.cleared_at' : 'NULL';
$latestDispatchStatusExpr = $hasLatestDispatch ? 'ld.status' : 'NULL';
$responseTimeExpr = $hasLatestDispatch
    ? "CASE
        WHEN ld.assigned_at IS NOT NULL AND ld.on_scene_at IS NOT NULL
        THEN TIMESTAMPDIFF(MINUTE, ld.assigned_at, ld.on_scene_at)
        ELSE NULL
      END"
    : 'NULL';
$resolutionEndExpr = ers_incidents_coalesce([$resolvedAtExpr, $clearedAtExpr]);
$resolutionTimeExpr = $hasLatestDispatch && $resolutionEndExpr !== 'NULL'
    ? "CASE
        WHEN ld.assigned_at IS NOT NULL AND {$resolutionEndExpr} IS NOT NULL
        THEN TIMESTAMPDIFF(MINUTE, ld.assigned_at, {$resolutionEndExpr})
        ELSE NULL
      END"
    : 'NULL';

$sql = "SELECT
        i.id,
        {$referenceNoExpr} AS reference_no,
        {$typeExpr} AS type,
        {$priorityExpr} AS priority,
        {$statusExpr} AS status,
        {$locationAddressExpr} AS location_address,
        {$descriptionExpr} AS description,
        {$createdAtExpr} AS created_at,
        {$updatedAtExpr} AS updated_at,
        {$resolvedAtExpr} AS resolved_at,
        {$latitudeExpr} AS latitude,
        {$longitudeExpr} AS longitude,
        {$titleExpr} AS title,
        {$callerNameExpr} AS caller_name,
        {$callerPhoneExpr} AS caller_phone,
        {$reportedByCallIdExpr} AS reported_by_call_id,
        {$intakeSourceExpr} AS intake_source,
        {$intakeSourceLabelExpr} AS intake_source_label,
        {$intakeSourceSystemExpr} AS intake_source_system,
        {$intakeExternalIdExpr} AS external_incident_id,
        {$intakeDetectionExpr} AS intake_source_detection,
        {$intakeSourceInferredExpr} AS intake_source_inferred,
        {$assignedAtExpr} AS assigned_at,
        {$acknowledgedAtExpr} AS acknowledged_at,
        {$enrouteAtExpr} AS enroute_at,
        {$onSceneAtExpr} AS on_scene_at,
        {$clearedAtExpr} AS cleared_at,
        {$latestDispatchStatusExpr} AS latest_dispatch_status,
        {$unitIdentifierExpr} AS unit_identifier,
        {$unitTypeExpr} AS unit_type,
        {$vehicleNameExpr} AS vehicle_name,
        {$driverNameExpr} AS driver_name,
        {$plateNumberExpr} AS plate_number,
        {$feedbackCountExpr} AS feedback_count,
        {$avgRatingExpr} AS avg_rating,
        {$ratingCountExpr} AS rating_count,
        {$adminReviewSentAtExpr} AS admin_review_sent_at,
        {$adminReviewSentByNameExpr} AS admin_review_sent_by_name,
        {$adminReviewSentByUserIdExpr} AS admin_review_sent_by_user_id,
        {$crimeAnalyticsStatusExpr} AS crime_analytics_status,
        {$crimeAnalyticsSyncedAtExpr} AS crime_analytics_synced_at,
        {$responseTimeExpr} AS response_time_min,
        {$resolutionTimeExpr} AS resolution_time_min
    FROM incidents i
    {$dispatchJoin}
    {$unitJoin}
    {$resourceJoin}
    {$callJoin}
    {$externalOriginJoin}
    {$acceptedCallJoin}
    {$systemCardJoin}
    {$noteAggregateJoin}
    {$surveyAggregateJoin}
    {$adminReviewJoin}
    {$crimeAnalyticsJoin}";

$where = [];
$params = [];

if ($priority !== '' && ers_incidents_has_column($schema, 'incidents', 'priority')) {
    $where[] = 'i.priority = :priority';
    $params['priority'] = $priority;
}

if ($status !== '' && ers_incidents_has_column($schema, 'incidents', 'status')) {
    if ($status === 'pending') {
        $where[] = "i.status = 'pending'";
        if (
            $hasLatestDispatch
            && ers_incidents_has_column($schema, 'dispatches', 'status')
        ) {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM dispatches d_pending
                WHERE d_pending.incident_id = i.id
                  AND d_pending.status IN ('assigned','acknowledged','enroute','on_scene')
            )";
        }
    } elseif ($status === 'active') {
        $where[] = "i.status IN ('pending', 'dispatched')";
    } elseif ($status === 'dispatched') {
        $where[] = "i.status = 'dispatched'";
    } elseif (in_array($status, ['resolved', 'closed'], true)) {
        $where[] = "i.status IN ('resolved', 'cancelled')";
    } elseif ($status === 'resolved_only') {
        $where[] = "i.status = 'resolved'";
    } elseif ($status === 'cancelled') {
        $where[] = "i.status = 'cancelled'";
    }
}

if (ers_incidents_has_column($schema, 'incidents', 'type')) {
    ers_incidents_append_type_filter($where, $params, 'i.type', $typeValues, 'incident');
}

if ($adminReview === 'sent') {
    $where[] = $hasAdminReviewTable ? 'iar.incident_id IS NOT NULL' : '1 = 0';
} elseif ($adminReview === 'unsent') {
    $where[] = $hasAdminReviewTable ? 'iar.incident_id IS NULL' : '1 = 1';
}

if ($search !== '') {
    $searchExpressions = ['CAST(i.id AS CHAR)'];
    foreach ([
        'reference_no' => $referenceNoExpr,
        'title' => $titleExpr,
        'type' => $typeExpr,
        'location' => $locationAddressExpr,
        'description' => $descriptionExpr,
        'caller_name' => $callerNameExpr,
        'caller_phone' => $callerPhoneExpr,
        'unit_identifier' => $unitIdentifierExpr,
        'unit_type' => $unitTypeExpr,
        'vehicle_name' => $vehicleNameExpr,
        'driver_name' => $driverNameExpr,
        'plate_number' => $plateNumberExpr,
    ] as $expression) {
        if ($expression !== 'NULL' && $expression !== "''") {
            $searchExpressions[] = $expression;
        }
    }

    $searchClauses = [];
    foreach ($searchExpressions as $index => $expression) {
        $key = 'search_' . $index;
        $searchClauses[] = "{$expression} LIKE :{$key}";
        $params[$key] = '%' . $search . '%';
    }
    $where[] = '(' . implode(' OR ', $searchClauses) . ')';
}

if ($rangeStart !== null && $rangeEnd !== null) {
    $rangeClauses = [];
    if ($createdAtExpr !== 'NULL') {
        $rangeClauses[] = 'i.created_at BETWEEN :range_created_start AND :range_created_end';
        $params['range_created_start'] = $rangeStart;
        $params['range_created_end'] = $rangeEnd;
    }
    if ($updatedAtExpr !== 'NULL') {
        $rangeClauses[] = 'i.updated_at BETWEEN :range_updated_start AND :range_updated_end';
        $params['range_updated_start'] = $rangeStart;
        $params['range_updated_end'] = $rangeEnd;
    }
    if (
        $hasLatestDispatch
        && ers_incidents_has_column($schema, 'dispatches', 'assigned_at')
    ) {
        $rangeClauses[] = "EXISTS (
            SELECT 1
            FROM dispatches d_window
            WHERE d_window.incident_id = i.id
              AND d_window.assigned_at BETWEEN :range_dispatch_start AND :range_dispatch_end
        )";
        $params['range_dispatch_start'] = $rangeStart;
        $params['range_dispatch_end'] = $rangeEnd;
    }
    if ($rangeClauses !== []) {
        $where[] = '(' . implode(' OR ', $rangeClauses) . ')';
    }
}

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$orderParts = [];
if (ers_incidents_has_column($schema, 'incidents', 'priority')) {
    $orderParts[] = "CASE LOWER(i.priority)
        WHEN 'critical' THEN 1
        WHEN 'high' THEN 2
        WHEN 'urgent' THEN 2
        WHEN 'medium' THEN 3
        WHEN 'moderate' THEN 3
        WHEN 'low' THEN 4
        ELSE 6
    END";
}
$sortDateExpressions = [];
foreach ([$resolvedAtExpr, $clearedAtExpr, $updatedAtExpr, $createdAtExpr] as $expression) {
    if ($expression !== 'NULL' && !in_array($expression, $sortDateExpressions, true)) {
        $sortDateExpressions[] = $expression;
    }
}
if ($sortDateExpressions !== []) {
    $dateSortExpr = count($sortDateExpressions) === 1
        ? $sortDateExpressions[0]
        : 'COALESCE(' . implode(', ', $sortDateExpressions) . ')';
    $orderParts[] = $dateSortExpr . ' DESC';
}
$orderParts[] = 'i.id DESC';
$sql .= ' ORDER BY ' . implode(', ', $orderParts) . ' LIMIT 200';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(static function (array $row): array {
        $intakeSource = strtolower(trim((string)($row['intake_source'] ?? 'unverified')));
        if (!in_array($intakeSource, ['call', 'manual', 'tip', 'interagency', 'unverified'], true)) {
            $intakeSource = 'unverified';
        }
        $intakeLabels = [
            'call' => 'Emergency Call',
            'manual' => 'Manual Incident',
            'tip' => 'Converted TIP',
            'interagency' => 'Inter-agency',
            'unverified' => 'Source unverified',
        ];
        return [
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'incident_code' => $row['reference_no'] ?? '',
            'type' => $row['type'] ?? '',
            'title' => $row['title'] ?? '',
            'location' => $row['location_address'] ?? '',
            'description' => $row['description'] ?? '',
            'priority' => $row['priority'] ?? '',
            'status' => $row['status'] ?? '',
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'resolved_at' => $row['resolved_at'] ?? null,
            'latitude' => isset($row['latitude']) && $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) && $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'assigned_unit' => $row['unit_identifier'] ?? null,
            'assigned_unit_type' => $row['unit_type'] ?? null,
            'vehicle_name' => $row['vehicle_name'] ?? null,
            'driver_name' => $row['driver_name'] ?? null,
            'plate_number' => $row['plate_number'] ?? null,
            'caller_name' => $row['caller_name'] ?? null,
            'caller_phone' => $row['caller_phone'] ?? null,
            'reported_by_call_id' => isset($row['reported_by_call_id']) && $row['reported_by_call_id'] !== null
                ? (int)$row['reported_by_call_id']
                : null,
            'intake_source' => $intakeSource,
            'intake_source_label' => $intakeLabels[$intakeSource],
            'intake_source_system' => $row['intake_source_system'] ?? null,
            'external_incident_id' => $row['external_incident_id'] ?? null,
            'intake_source_detection' => $row['intake_source_detection'] ?? 'legacy_direct_record',
            'intake_source_inferred' => ((int)($row['intake_source_inferred'] ?? 1)) === 1,
            'assigned_at' => $row['assigned_at'] ?? null,
            'acknowledged_at' => $row['acknowledged_at'] ?? null,
            'enroute_at' => $row['enroute_at'] ?? null,
            'on_scene_at' => $row['on_scene_at'] ?? null,
            'cleared_at' => $row['cleared_at'] ?? null,
            'latest_dispatch_status' => $row['latest_dispatch_status'] ?? null,
            'response_time_min' => isset($row['response_time_min']) && $row['response_time_min'] !== null ? (int)$row['response_time_min'] : null,
            'resolution_time_min' => isset($row['resolution_time_min']) && $row['resolution_time_min'] !== null ? (int)$row['resolution_time_min'] : null,
            'feedback_count' => isset($row['feedback_count']) ? (int)$row['feedback_count'] : 0,
            'avg_rating' => isset($row['avg_rating']) && $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
            'rating_count' => isset($row['rating_count']) ? (int)$row['rating_count'] : 0,
            'submitted_to_admin' => !empty($row['admin_review_sent_at']),
            'admin_review_sent_at' => $row['admin_review_sent_at'] ?? null,
            'admin_review_sent_by_name' => $row['admin_review_sent_by_name'] ?? null,
            'admin_review_sent_by_user_id' => isset($row['admin_review_sent_by_user_id']) && $row['admin_review_sent_by_user_id'] !== null
                ? (int)$row['admin_review_sent_by_user_id']
                : null,
            'crime_analytics_status' => $row['crime_analytics_status'] ?? null,
            'crime_analytics_synced_at' => $row['crime_analytics_synced_at'] ?? null,
        ];
    }, $rows);

    echo json_encode(
        ['ok' => true, 'items' => $items],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('incidents_list query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
