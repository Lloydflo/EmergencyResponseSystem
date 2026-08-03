<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_after_action_schema.php';

op_require_method('GET');
$pdo = db();
op_require_after_action_schema($pdo);

$reviewerId = op_query_int('reviewer_id');
$status = strtolower(op_query_string('status', 'submitted', 16));
$limit = max(1, min(200, op_query_int('limit', 100)));
op_require_active_reviewer($pdo, $reviewerId);
if (!in_array($status, ['all', 'draft', 'submitted', 'verified', 'returned'], true)) {
    op_error('Invalid report status filter.', 422);
}

$sql =
    'SELECT aar.*, i.reference_no, i.location_address, '
    . 'UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms '
    . 'FROM responder_after_action_reports aar '
    . 'INNER JOIN incidents i ON i.id = aar.incident_id ';
$params = [];
if ($status !== 'all') {
    $sql .= 'WHERE aar.status = ? ';
    $params[] = $status;
}
$sql .= 'ORDER BY aar.updated_at DESC, aar.id DESC LIMIT ' . $limit;
$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = op_fetch_all($statement);

$reports = array_map(static function (array $row): array {
    return op_after_action_response($row) + [
        'reference_no' => (string)($row['reference_no'] ?? ''),
        'location_address' => (string)($row['location_address'] ?? ''),
    ];
}, $rows);

op_success(['reports' => $reports, 'count' => count($reports)]);
