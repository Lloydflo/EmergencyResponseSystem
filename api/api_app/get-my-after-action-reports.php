<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_after_action_schema.php';

op_require_method('GET');
$pdo = db();
op_require_after_action_schema($pdo);

$responderId = op_query_int('responder_id');
op_require_positive($responderId, 'responder_id');
op_require_active_responder($pdo, $responderId);

$statement = $pdo->prepare(
    'SELECT aar.*, '
    . 'UNIX_TIMESTAMP(aar.created_at) * 1000 AS created_at_ms, '
    . 'UNIX_TIMESTAMP(aar.updated_at) * 1000 AS updated_at_ms '
    . 'FROM responder_after_action_reports aar '
    . 'WHERE aar.responder_id = ? '
    . 'ORDER BY aar.updated_at DESC, aar.id DESC'
);
$statement->execute([$responderId]);
$rows = op_fetch_all($statement);

op_success([
    'reports' => array_map('op_after_action_response', $rows),
    'count' => count($rows),
]);
