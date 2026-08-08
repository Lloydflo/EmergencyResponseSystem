<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

op_require_method('GET');

$responderId = op_query_int('responder_id');
op_require_positive($responderId, 'responder_id');

$limit = op_query_int('limit', 200);
$limit = max(1, min($limit, 500));

try {
    $pdo = db();
    op_require_active_responder($pdo, $responderId);
    op_require_tables($pdo, ['incidents']);
    op_require_columns($pdo, 'incidents', ['id', 'type', 'completed_by_responder_id']);

    $select = ['`id`', '`type`'];
    foreach ([
        'reference_no',
        'priority',
        'title',
        'description',
        'location_address',
        'completion_notes',
        'completion_image_path',
        'completed_at',
    ] as $column) {
        $select[] = op_column_exists($pdo, 'incidents', $column)
            ? '`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }

    $where = ['`completed_by_responder_id` = ?'];
    if (op_column_exists($pdo, 'incidents', 'completed_at')) {
        $where[] = '`completed_at` IS NOT NULL';
    } elseif (op_column_exists($pdo, 'incidents', 'status')) {
        $where[] = "LOWER(TRIM(`status`)) IN ('resolved', 'closed', 'completed')";
    }

    $orderBy = op_column_exists($pdo, 'incidents', 'completed_at')
        ? '`completed_at` DESC, `id` DESC'
        : '`id` DESC';

    $statement = $pdo->prepare(
        'SELECT ' . implode(', ', $select)
        . ' FROM `incidents` WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $orderBy
        . ' LIMIT ?'
    );
    $statement->bindValue(1, $responderId, PDO::PARAM_INT);
    $statement->bindValue(2, $limit, PDO::PARAM_INT);
    $statement->execute();

    $baseUrl = op_base_url();
    $incidents = array_map(
        static function (array $row) use ($baseUrl): array {
            $imagePath = trim((string)($row['completion_image_path'] ?? ''));
            if (
                $imagePath !== ''
                && !str_starts_with($imagePath, 'http://')
                && !str_starts_with($imagePath, 'https://')
                && $baseUrl !== ''
            ) {
                $imagePath = $baseUrl . '/' . ltrim($imagePath, '/');
            }

            return [
                'id' => (int)$row['id'],
                'reference_no' => (string)($row['reference_no'] ?? ''),
                'type' => (string)($row['type'] ?? ''),
                'priority' => (string)($row['priority'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'location_address' => (string)($row['location_address'] ?? ''),
                'completion_notes' => trim((string)($row['completion_notes'] ?? '')),
                'completion_image_path' => $imagePath,
                'completed_at' => $row['completed_at'] ?? null,
            ];
        },
        op_fetch_all($statement)
    );

    op_success([
        'incidents' => $incidents,
        'count' => count($incidents),
    ]);
} catch (Throwable $error) {
    error_log('[get-completed-incidents] ' . $error->getMessage());
    op_error('Unable to load completed incidents.', 500);
}
