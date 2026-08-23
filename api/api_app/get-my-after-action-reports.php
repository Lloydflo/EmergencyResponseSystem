<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_after_action_schema.php';

op_require_method('GET');
$pdo = db();
op_require_after_action_schema($pdo);
op_require_tables($pdo, ['incidents']);

$responderId = op_query_int('responder_id');
op_require_positive($responderId, 'responder_id');
op_require_active_responder($pdo, $responderId);

/**
 * Include enough source-incident evidence for old monthly history records to
 * remain viewable even when they fall outside the separate completed-incident
 * endpoint's rolling limit.
 */
$incidentProjection = [];
foreach ([
    'reference_no',
    'type',
    'completion_notes',
    'completion_image_path',
    'completed_at',
] as $column) {
    $alias = 'source_incident_' . $column;
    $incidentProjection[] = op_column_exists($pdo, 'incidents', $column)
        ? 'i.`' . $column . '` AS `' . $alias . '`'
        : 'NULL AS `' . $alias . '`';
}
$incidentProjection[] = op_column_exists($pdo, 'incidents', 'completed_at')
    ? 'UNIX_TIMESTAMP(i.`completed_at`) * 1000 AS `source_incident_completed_at_ms`'
    : '0 AS `source_incident_completed_at_ms`';

$statement = $pdo->prepare(
    'SELECT aar.*, ' . implode(', ', $incidentProjection) . ', '
    . 'UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms, '
    . 'UNIX_TIMESTAMP(aar.reviewed_at) * 1000 AS reviewed_at_ms '
    . 'FROM responder_after_action_reports aar '
    . 'LEFT JOIN incidents i ON i.id = aar.incident_id '
    . 'WHERE aar.responder_id = ? '
    . 'ORDER BY aar.updated_at DESC, aar.id DESC'
);
$statement->execute([$responderId]);
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
        'incident' => [
            'id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['source_incident_reference_no'] ?? ''),
            'type' => (string)($row['source_incident_type'] ?? $row['incident_type'] ?? ''),
            'completion_notes' => (string)($row['source_incident_completion_notes'] ?? ''),
            'completion_image_path' => $completionImage,
            'completed_at' => $row['source_incident_completed_at'] ?? null,
            'completed_at_ms' => (int)($row['source_incident_completed_at_ms'] ?? 0),
        ],
    ];
}, $rows);

$counts = [
    'pending' => 0,
    'submitted' => 0,
    'approved_recent' => 0,
    'history' => 0,
];
$historyMonthCounts = [];
foreach ($reports as $report) {
    $workflowStatus = (string)($report['workflow_status'] ?? 'pending');
    if ($workflowStatus === 'submitted') {
        $counts['submitted']++;
        continue;
    }
    if ($workflowStatus === 'approved') {
        if (!empty($report['is_history'])) {
            $counts['history']++;
            $monthKey = (string)($report['history_month'] ?? '');
            if ($monthKey !== '') {
                $historyMonthCounts[$monthKey] = ($historyMonthCounts[$monthKey] ?? 0) + 1;
            }
        } else {
            $counts['approved_recent']++;
        }
        continue;
    }
    $counts['pending']++;
}

krsort($historyMonthCounts);
$historyMonths = [];
foreach ($historyMonthCounts as $key => $count) {
    $date = DateTimeImmutable::createFromFormat('!Y-m', $key);
    $historyMonths[] = [
        'key' => $key,
        'label' => $date !== false ? $date->format('F Y') : $key,
        'count' => $count,
    ];
}

op_success([
    'reports' => $reports,
    'count' => count($reports),
    'counts' => $counts,
    'history_months' => $historyMonths,
    'history_policy' => op_after_action_history_policy(),
]);
