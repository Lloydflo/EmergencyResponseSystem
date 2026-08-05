<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');
$responderId = op_query_int('responder_id');
$limit = max(1, min(100, op_query_int('limit', 50)));
op_require_positive($responderId, 'responder_id');

try {
    $pdo = db();
    op_require_active_responder($pdo, $responderId);

    $statement = $pdo->prepare(
        'SELECT b.id, b.incident_id, b.priority, b.message, b.created_by, b.created_at, '
        . 'i.reference_no AS incident_reference, i.type AS incident_type, '
        . 'i.location_address AS location, creator.name AS created_by_name, '
        . 'ack.acknowledged_at '
        . 'FROM interagency_incident_broadcasts b '
        . 'INNER JOIN incidents i ON i.id = b.incident_id '
        . 'LEFT JOIN users creator ON creator.id = b.created_by '
        . 'LEFT JOIN interagency_incident_broadcast_acks ack '
        . 'ON ack.broadcast_id = b.id AND ack.user_id = ? '
        . 'ORDER BY b.created_at DESC, b.id DESC LIMIT ' . $limit
    );
    $statement->execute([$responderId]);

    $broadcasts = [];
    foreach (op_fetch_all($statement) as $row) {
        $createdAt = (string)($row['created_at'] ?? '');
        $broadcasts[] = [
            'id' => (int)$row['id'],
            'incident_id' => (int)$row['incident_id'],
            'incident_reference' => (string)($row['incident_reference'] ?? ''),
            'incident_type' => (string)($row['incident_type'] ?? ''),
            'location' => (string)($row['location'] ?? ''),
            'priority' => (string)($row['priority'] ?? 'routine'),
            'message' => (string)($row['message'] ?? ''),
            'created_by_name' => (string)($row['created_by_name'] ?? 'Dispatch'),
            'created_at' => $createdAt,
            'created_at_ms' => $createdAt !== '' ? strtotime($createdAt) * 1000 : 0,
            'acknowledged' => !empty($row['acknowledged_at']),
            'acknowledged_at' => (string)($row['acknowledged_at'] ?? ''),
        ];
    }

    op_success([
        'broadcasts' => $broadcasts,
        'unread_count' => count(array_filter($broadcasts, static fn(array $item): bool => !$item['acknowledged'])),
    ]);
} catch (Throwable $error) {
    error_log('get-responder-broadcasts: ' . $error->getMessage());
    op_error('Unable to load emergency broadcasts.', 500);
}
