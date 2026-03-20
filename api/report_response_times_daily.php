<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : 'month';
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start . ' 00:00:00', $end . ' 23:59:59'];
    }

    $today = new DateTime('today');
    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d') . ' 00:00:00', $today->format('Y-m-d') . ' 23:59:59'];
        case 'week':
            $rangeStart = (clone $today)->modify('monday this week');
            $rangeEnd = (clone $rangeStart)->modify('+6 days');
            break;
        case 'quarter':
            $month = (int)$today->format('n');
            $quarterStartMonth = [1 => 1, 2 => 4, 3 => 7, 4 => 10][intdiv($month - 1, 3) + 1];
            $rangeStart = new DateTime($today->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $rangeEnd = (clone $rangeStart)->modify('+3 months -1 day');
            break;
        case 'year':
            $rangeStart = new DateTime($today->format('Y-01-01'));
            $rangeEnd = new DateTime($today->format('Y-12-31'));
            break;
        case 'month':
        default:
            $rangeStart = new DateTime($today->format('Y-m-01'));
            $rangeEnd = (clone $rangeStart)->modify('+1 month -1 day');
            break;
    }

    return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59'];
}

try {
    [$startAt, $endAt] = period_to_range();
    $typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
    if ($typeFilter === 'accident') { $typeFilter = 'traffic'; }
    if ($typeFilter === 'crime') { $typeFilter = 'police'; }

    $sql = "
        SELECT DATE(d.assigned_at) AS d,
               ROUND(AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))), 1) AS avg_min
        FROM dispatches d
        INNER JOIN incidents i ON i.id = d.incident_id
        WHERE d.assigned_at BETWEEN :start AND :end
          AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
    ";
    $params = [
        ':start' => $startAt,
        ':end' => $endAt,
    ];
    if ($typeFilter !== '') {
        $sql .= ' AND i.type = :type';
        $params[':type'] = $typeFilter;
    }
    if ($priorityFilter !== '') {
        $sql .= ' AND i.priority = :priority';
        $params[':priority'] = $priorityFilter;
    }
    $sql .= ' GROUP BY DATE(d.assigned_at) ORDER BY DATE(d.assigned_at) ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $indexed = [];
    foreach ($rows as $row) {
        $indexed[(string)$row['d']] = $row['avg_min'] !== null ? (float)$row['avg_min'] : 0.0;
    }

    $labels = [];
    $data = [];
    $cursor = new DateTime(substr($startAt, 0, 10));
    $limit = new DateTime(substr($endAt, 0, 10));
    while ($cursor <= $limit) {
        $dateKey = $cursor->format('Y-m-d');
        $labels[] = $dateKey;
        $data[] = $indexed[$dateKey] ?? 0.0;
        $cursor->modify('+1 day');
    }

    echo json_encode(['ok' => true, 'labels' => $labels, 'data' => $data]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
