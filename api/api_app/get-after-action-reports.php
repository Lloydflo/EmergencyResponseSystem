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
op_require_active_reviewer($pdo, $reviewerId);
if (!in_array(
    $status,
    ['all', 'pending', 'draft', 'submitted', 'approved', 'verified', 'returned', 'revision_required'],
    true
)) {
    op_error('Invalid report status filter.', 422);
}

/**
 * Include the original incident record and completion evidence so the admin can
 * validate that the incident and the submitted responder report are legitimate
 * before approving it. Missing legacy columns are projected as NULL.
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

$sql =
    'SELECT aar.*, ' . implode(', ', $incidentProjection) . ', '
    . 'UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms '
    . 'FROM responder_after_action_reports aar '
    . 'INNER JOIN incidents i ON i.id = aar.incident_id ';
$params = [];
if ($status === 'pending') {
    // Responder-facing Pending includes editable drafts and admin returns.
    $sql .= "WHERE aar.status IN ('draft', 'returned') ";
} elseif (in_array($status, ['approved', 'verified'], true)) {
    $sql .= 'WHERE aar.status = ? ';
    $params[] = 'verified';
} elseif (in_array($status, ['revision_required', 'returned'], true)) {
    $sql .= 'WHERE aar.status = ? ';
    $params[] = 'returned';
} elseif ($status !== 'all') {
    $sql .= 'WHERE aar.status = ? ';
    $params[] = $status;
}
$sql .= 'ORDER BY aar.updated_at DESC, aar.id DESC LIMIT ' . $limit;
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

        // Evidence bundle for incident/report legitimacy review.
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
        ],
    ];
}, $rows);

op_success([
    'reports' => $reports,
    'count' => count($reports),
    'status_filter' => $status,
]);
