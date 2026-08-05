<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');
$responderId = op_query_int('responder_id');
if ($responderId <= 0) {
    op_json([], 422);
}

try {
    $pdo = db();
    if (op_active_responder($pdo, $responderId) === null) {
        op_json([], 403);
    }
    op_require_tables($pdo, ['dispatch_operator_records']);
    op_require_columns($pdo, 'dispatch_operator_records', ['id', 'assigned_to', 'status']);

    $wanted = [
        'id', 'incident_id', 'name', 'vehicle', 'location', 'latitude', 'longitude',
        'priority', 'description', 'status', 'assigned_to', 'assigned_responder_name',
        'assigned_unit_code', 'assigned_unit_type', 'assigned_at', 'created_at',
    ];
    $select = [];
    foreach ($wanted as $column) {
        $select[] = op_column_exists($pdo, 'dispatch_operator_records', $column)
            ? 'd.`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }

    $userJoin = '';
    $unitStatusSelect = 'NULL AS unit_status';
    if (
        op_table_exists($pdo, 'users')
        && op_column_exists($pdo, 'users', 'id')
        && op_column_exists($pdo, 'users', 'unit_status')
    ) {
        $userJoin = ' LEFT JOIN users u ON u.id = d.assigned_to';
        $unitStatusSelect = 'u.unit_status AS unit_status';
    }

    $order = [];
    if (op_column_exists($pdo, 'dispatch_operator_records', 'assigned_at')) {
        $order[] = 'd.assigned_at DESC';
    } elseif (op_column_exists($pdo, 'dispatch_operator_records', 'created_at')) {
        $order[] = 'd.created_at DESC';
    }
    $order[] = 'd.id DESC';

    $sql = 'SELECT ' . implode(', ', $select) . ', ' . $unitStatusSelect
        . ' FROM dispatch_operator_records d' . $userJoin
        . " WHERE d.assigned_to = ? AND LOWER(d.status) IN "
        . "('pending','assigned','received','accepted','acknowledged','enroute','en_route','on_scene')"
        . ' ORDER BY ' . implode(', ', $order) . ' LIMIT 200';
    $statement = $pdo->prepare($sql);
    $statement->execute([$responderId]);

    $rows = [];
    foreach (op_fetch_all($statement) as $row) {
        $vehicle = strtolower((string)($row['vehicle'] ?? ''));
        $type = str_contains($vehicle, 'fire')
            ? 'fire'
            : (str_contains($vehicle, 'ambulance')
                ? 'medical'
                : (str_contains($vehicle, 'police') ? 'crime' : 'fire'));
        $rows[] = [
            'assignment_id' => (string)$row['id'],
            'id' => (string)$row['id'],
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'type' => $type,
            'priority' => (string)($row['priority'] ?? 'medium'),
            'location' => (string)($row['location'] ?? ''),
            'status' => (string)($row['status'] ?? 'assigned'),
            'description' => (string)($row['description'] ?? ''),
            'assignedTo' => (string)($row['assigned_responder_name'] ?? ''),
            'latitude' => isset($row['latitude']) && $row['latitude'] !== null
                ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) && $row['longitude'] !== null
                ? (float)$row['longitude'] : null,
            'unit_code' => (string)($row['assigned_unit_code'] ?? ''),
            'unit_type' => (string)($row['assigned_unit_type'] ?? ''),
            'unit_status' => (string)($row['unit_status'] ?? ''),
            'assigned_at' => (string)($row['assigned_at'] ?? ''),
        ];
    }

    op_json($rows);
} catch (Throwable $error) {
    error_log('[get-assigned-incidents] ' . $error->getMessage());
    op_json([], 500);
}
