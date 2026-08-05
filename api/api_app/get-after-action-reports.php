<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_after_action_schema.php';

op_require_method('GET');
$pdo = db();
op_require_after_action_schema($pdo);
op_require_tables($pdo, ['incidents']);

$reviewerId = op_query_int('reviewer_id');
$status = strtolower(op_query_string('status', 'submitted', 24));
$limit = max(1, min(200, op_query_int('limit', 100)));
$year = op_query_int('year', 0);
$month = op_query_int('month', 0);
op_require_active_reviewer($pdo, $reviewerId);

$allowedStatuses = [
    'all',
    'pending',
    'draft',
    'submitted',
    'approved',
    'verified',
    'active_approved',
    'history',
    'returned',
    'revision_required',
];
if (!in_array($status, $allowedStatuses, true)) {
    op_error('Invalid report status filter.', 422);
}
if ($year !== 0 && ($year < 2000 || $year > 2100)) {
    op_error('Invalid history year.', 422);
}
if ($month !== 0 && ($month < 1 || $month > 12)) {
    op_error('Invalid history month.', 422);
}
if ($month !== 0 && $year === 0) {
    op_error('The year parameter is required when month is supplied.', 422);
}

/**
 * Include the original incident record and completion evidence so the admin can
 * validate that the incident and submitted responder report are legitimate.
 * Missing legacy incident columns are projected as NULL.
 */
$incidentProjection = [];
$incidentColumns = [
    'reference_no',
    'type',
    'title',
    'description',
    'priority',
    'status',
    'location_address',
    'completion_notes',
    'completion_image_path',
    'completed_at',
];
foreach ($incidentColumns as $column) {
    $alias = 'source_incident_' . $column;
    $incidentProjection[] = op_column_exists($pdo, 'incidents', $column)
        ? 'i.`' . $column . '` AS `' . $alias . '`'
        : 'NULL AS `' . $alias . '`';
}
$incidentProjection[] = op_column_exists($pdo, 'incidents', 'completed_at')
    ? 'UNIX_TIMESTAMP(i.`completed_at`) * 1000 AS `source_incident_completed_at_ms`'
    : '0 AS `source_incident_completed_at_ms`';

$where = [];
$params = [];
switch ($status) {
    case 'pending':
        // Responder-facing Pending includes editable drafts and admin returns.
        $where[] = "aar.status IN ('draft', 'returned')";
        break;
    case 'approved':
        // Include the current legacy value (`verified`) and any older rows that
        // were stored literally as `approved`.
        $where[] = "aar.status IN ('verified', 'approved')";
        break;
    case 'verified':
        $where[] = "aar.status = 'verified'";
        break;
    case 'active_approved':
        $where[] = "aar.status IN ('verified', 'approved')";
        $where[] = 'COALESCE(aar.reviewed_at, aar.updated_at) > '
            . 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . op_after_action_history_hours() . ' HOUR)';
        break;
    case 'history':
        $where[] = "aar.status IN ('verified', 'approved')";
        $where[] = 'COALESCE(aar.reviewed_at, aar.updated_at) <= '
            . 'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . op_after_action_history_hours() . ' HOUR)';
        break;
    case 'revision_required':
    case 'returned':
        $where[] = "aar.status = 'returned'";
        break;
    case 'all':
        break;
    default:
        $where[] = 'aar.status = ?';
        $params[] = $status;
        break;
}

if ($year !== 0) {
    $where[] = 'YEAR(COALESCE(aar.reviewed_at, aar.updated_at)) = ?';
    $params[] = $year;
}
if ($month !== 0) {
    $where[] = 'MONTH(COALESCE(aar.reviewed_at, aar.updated_at)) = ?';
    $params[] = $month;
}

$sql =
    'SELECT aar.*, ' . implode(', ', $incidentProjection) . ', '
    . 'UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms, '
    . 'UNIX_TIMESTAMP(aar.reviewed_at) * 1000 AS reviewed_at_ms '
    . 'FROM responder_after_action_reports aar '
    . 'LEFT JOIN incidents i ON i.id = aar.incident_id ';
if ($where !== []) {
    $sql .= 'WHERE ' . implode(' AND ', $where) . ' ';
}
$sql .= in_array($status, ['approved', 'verified', 'active_approved', 'history'], true)
    ? 'ORDER BY COALESCE(aar.reviewed_at, aar.updated_at) DESC, aar.id DESC '
    : 'ORDER BY aar.updated_at DESC, aar.id DESC ';
$sql .= 'LIMIT ' . $limit;

$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = op_fetch_all($statement);

$baseUrl = op_base_url();
$reports = array_map(static function (array $row) use ($baseUrl): array {
    $completionImage = trim((string)($row['source_incident_completion_image_path'] ?? ''));
    if (
        $completionImage !== ''
        && !str_starts_with($completionImage, 'http://')
        && !str_starts_with($completionImage, 'https://')
        && $baseUrl !== ''
    ) {
        $completionImage = $baseUrl . '/' . ltrim($completionImage, '/');
    }

    return op_after_action_response($row) + [
        // Backward-compatible aliases used by existing admin clients.
        'reference_no' => (string)($row['source_incident_reference_no'] ?? ''),
        'location_address' => (string)($row['source_incident_location_address'] ?? ''),

        // Evidence bundle for incident/report legitimacy review and history.
        'incident' => [
            'id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['source_incident_reference_no'] ?? ''),
            'type' => (string)($row['source_incident_type'] ?? ''),
            'title' => (string)($row['source_incident_title'] ?? ''),
            'description' => (string)($row['source_incident_description'] ?? ''),
            'priority' => (string)($row['source_incident_priority'] ?? ''),
            'status' => (string)($row['source_incident_status'] ?? ''),
            'location_address' => (string)($row['source_incident_location_address'] ?? ''),
            'completion_notes' => (string)($row['source_incident_completion_notes'] ?? ''),
            'completion_image_path' => $completionImage,
            'completed_at' => $row['source_incident_completed_at'] ?? null,
            'completed_at_ms' => (int)($row['source_incident_completed_at_ms'] ?? 0),
        ],
    ];
}, $rows);

op_success([
    'reports' => $reports,
    'count' => count($reports),
    'status_filter' => $status,
    'year_filter' => $year !== 0 ? $year : null,
    'month_filter' => $month !== 0 ? $month : null,
    'history_policy' => op_after_action_history_policy(),
]);
