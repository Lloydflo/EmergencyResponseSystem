<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');
$pdo = db();

$responderId = op_query_int('responder_id');
$limit = max(1, min(200, op_query_int('limit', 100)));
op_require_positive($responderId, 'responder_id');
op_require_active_responder($pdo, $responderId);

$summaryStatement = $pdo->prepare(
    'SELECT COUNT(*) AS review_count, '
    . 'COALESCE(AVG(response_rating), 0) AS average_response_rating, '
    . 'COALESCE(AVG(communication_rating), 0) AS average_communication_rating, '
    . 'COALESCE(AVG(professionalism_rating), 0) AS average_professionalism_rating, '
    . 'COALESCE(AVG((response_rating + communication_rating + professionalism_rating) / 3.0), 0) '
    . 'AS average_overall_rating '
    . 'FROM incident_reviews WHERE responder_id = ?'
);
$summaryStatement->execute([$responderId]);
$summary = op_fetch_one($summaryStatement) ?? [];

$sql =
    'SELECT ir.id, ir.incident_id, ir.responder_id, ir.response_rating, '
    . 'ir.communication_rating, ir.professionalism_rating, ir.outcome, ir.review_text, '
    . 'i.reference_no, i.type AS incident_type, i.priority, i.location_address, i.review_status, '
    . 'UNIX_TIMESTAMP(ir.created_at) * 1000 AS created_at_ms '
    . 'FROM incident_reviews ir '
    . 'INNER JOIN incidents i ON i.id = ir.incident_id '
    . 'WHERE ir.responder_id = ? '
    . 'ORDER BY ir.created_at DESC, ir.id DESC LIMIT ' . $limit;
$statement = $pdo->prepare($sql);
$statement->execute([$responderId]);
$rows = op_fetch_all($statement);

$reviews = array_map(static function (array $row): array {
    return [
        'id' => (int)$row['id'],
        'incident_id' => (int)$row['incident_id'],
        'responder_id' => (int)$row['responder_id'],
        'reference_no' => (string)($row['reference_no'] ?? ''),
        'incident_type' => (string)($row['incident_type'] ?? ''),
        'priority' => (string)($row['priority'] ?? ''),
        'location_address' => (string)($row['location_address'] ?? ''),
        'review_status' => (string)($row['review_status'] ?? ''),
        'response_rating' => (int)$row['response_rating'],
        'communication_rating' => (int)$row['communication_rating'],
        'professionalism_rating' => (int)$row['professionalism_rating'],
        'outcome' => (string)($row['outcome'] ?? ''),
        'review_text' => (string)($row['review_text'] ?? ''),
        'created_at_ms' => (int)($row['created_at_ms'] ?? 0),
    ];
}, $rows);

op_success([
    'summary' => [
        'review_count' => (int)($summary['review_count'] ?? 0),
        'average_response_rating' => round((float)($summary['average_response_rating'] ?? 0), 2),
        'average_communication_rating' => round((float)($summary['average_communication_rating'] ?? 0), 2),
        'average_professionalism_rating' => round((float)($summary['average_professionalism_rating'] ?? 0), 2),
        'average_overall_rating' => round((float)($summary['average_overall_rating'] ?? 0), 2),
    ],
    'reviews' => $reviews,
    'count' => count($reviews),
]);
