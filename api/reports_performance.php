<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo 'Database connection unavailable'; exit; }

function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : 'month';
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start . ' 00:00:00', $end . ' 23:59:59', 'Custom'];
    }

    $today = new DateTime('today');
    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d') . ' 00:00:00', $today->format('Y-m-d') . ' 23:59:59', 'Today'];
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

    $label = 'This Month';
    if ($period === 'week') {
        $label = 'This Week';
    } elseif ($period === 'quarter') {
        $label = 'This Quarter';
    } elseif ($period === 'year') {
        $label = 'This Year';
    }

    return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59', $label];
}

function normalized_type_values(string $typeFilter): array {
    $typeFilter = strtolower(trim($typeFilter));
    if ($typeFilter === '') {
        return [];
    }
    if ($typeFilter === 'traffic' || $typeFilter === 'accident') {
        return ['traffic', 'accident'];
    }
    if ($typeFilter === 'police' || $typeFilter === 'crime') {
        return ['police', 'crime'];
    }
    return [$typeFilter];
}

function append_type_filter(string &$sql, array &$params, string $column, array $typeValues, string $prefix): void {
    if (!$typeValues) {
        return;
    }
    $placeholders = [];
    foreach ($typeValues as $index => $value) {
        $placeholder = ':' . $prefix . '_type_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }
    $sql .= ' AND LOWER(' . $column . ') IN (' . implode(', ', $placeholders) . ')';
}

[$startAt, $endAt, $periodLabel] = period_to_range();
$typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
$typeValues = normalized_type_values($typeFilter);

$avgResponse = 0.0;
$resolutionRate = 0.0;
$avgResolveTime = 0.0;

$avgResponseSql = "
    SELECT AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))) AS avg_min
    FROM dispatches d
    INNER JOIN incidents i ON i.id = d.incident_id
    WHERE d.assigned_at BETWEEN :start AND :end
      AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
";
$avgResponseParams = [
    ':start' => $startAt,
    ':end' => $endAt,
];
append_type_filter($avgResponseSql, $avgResponseParams, 'i.type', $typeValues, 'avg');
if ($priorityFilter !== '') {
    $avgResponseSql .= ' AND i.priority = :priority';
    $avgResponseParams[':priority'] = $priorityFilter;
}
$stmt = $pdo->prepare($avgResponseSql);
$stmt->execute($avgResponseParams);
$row = $stmt->fetch();
if ($row && $row['avg_min'] !== null) {
    $avgResponse = round((float)$row['avg_min'], 1);
}

$incidentActivityWhere = "
    (
        i.created_at BETWEEN :start AND :end
        OR (i.updated_at IS NOT NULL AND i.updated_at BETWEEN :updated_start AND :updated_end)
        OR EXISTS (
            SELECT 1
            FROM dispatches d_window
            WHERE d_window.incident_id = i.id
              AND d_window.assigned_at BETWEEN :dispatch_start AND :dispatch_end
        )
    )
";
$incidentStatsSql = "
    SELECT COUNT(*) AS total,
        SUM(CASE WHEN i.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
        AVG(CASE WHEN i.status = 'resolved' AND i.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, i.created_at, i.resolved_at) END) AS avg_resolve
    FROM incidents i
    WHERE {$incidentActivityWhere}
";
$incidentStatsParams = [
    ':start' => $startAt,
    ':end' => $endAt,
    ':updated_start' => $startAt,
    ':updated_end' => $endAt,
    ':dispatch_start' => $startAt,
    ':dispatch_end' => $endAt,
];
append_type_filter($incidentStatsSql, $incidentStatsParams, 'i.type', $typeValues, 'stats');
if ($priorityFilter !== '') {
    $incidentStatsSql .= ' AND i.priority = :priority';
    $incidentStatsParams[':priority'] = $priorityFilter;
}
$stmt = $pdo->prepare($incidentStatsSql);
$stmt->execute($incidentStatsParams);
$stats = $stmt->fetch() ?: [];
$total = (int)($stats['total'] ?? 0);
$resolved = (int)($stats['resolved'] ?? 0);
if ($total > 0) {
    $resolutionRate = round(($resolved / $total) * 100, 1);
}
if (isset($stats['avg_resolve']) && $stats['avg_resolve'] !== null) {
    $avgResolveTime = round((float)$stats['avg_resolve'], 1);
}

$pipes = [ 'assigned'=>0,'acknowledged'=>0,'enroute'=>0,'on_scene'=>0,'cleared'=>0,'cancelled'=>0 ];
$pipelineSql = "
    SELECT d.status, COUNT(*) c
    FROM dispatches d
    INNER JOIN incidents i ON i.id = d.incident_id
    WHERE d.assigned_at BETWEEN :start AND :end
";
$pipelineParams = [
    ':start' => $startAt,
    ':end' => $endAt,
];
append_type_filter($pipelineSql, $pipelineParams, 'i.type', $typeValues, 'pipeline');
if ($priorityFilter !== '') {
    $pipelineSql .= ' AND i.priority = :priority';
    $pipelineParams[':priority'] = $priorityFilter;
}
$pipelineSql .= ' GROUP BY d.status';
$stmt = $pdo->prepare($pipelineSql);
$stmt->execute($pipelineParams);
foreach ($stmt->fetchAll() as $row) {
    $statusKey = (string)($row['status'] ?? '');
    if (isset($pipes[$statusKey])) {
        $pipes[$statusKey] = (int)$row['c'];
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Analytics - <?php echo htmlspecialchars($periodLabel); ?></title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; color: #111827; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .sub { color: #6b7280; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap: 12px; margin: 16px 0 20px; }
        .card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; background: #fff; }
        .kpi { font-size: 24px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef2f7; font-size: 14px; }
        th { background: #f9fafb; font-weight: 700; }
        .muted { color: #6b7280; }
        .bar { height: 10px; background:#e5e7eb; border-radius: 999px; overflow: hidden; }
        .bar-inner { background:#3b82f6; height: 100%; }
        .btn { padding: 8px 12px; border: 1px solid #e5e7eb; background:#fff; border-radius:8px; cursor:pointer; }
        .toolbar { display:flex; gap:8px; margin: 8px 0 16px; }
    </style>
</head>
<body>
    <h1>Performance Analytics</h1>
    <div class="sub">Period: <?php echo htmlspecialchars($periodLabel); ?> (<?php echo htmlspecialchars(substr($startAt,0,10)); ?> to <?php echo htmlspecialchars(substr($endAt,0,10)); ?>)</div>
    <div class="toolbar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>

    <div class="grid">
        <div class="card"><div class="muted">Avg Response Time</div><div class="kpi"><?php echo number_format($avgResponse,1); ?> min</div></div>
        <div class="card"><div class="muted">Resolution Rate</div><div class="kpi"><?php echo number_format($resolutionRate,1); ?>%</div></div>
        <div class="card"><div class="muted">Avg Time to Resolve</div><div class="kpi"><?php echo number_format($avgResolveTime,1); ?> min</div></div>
    </div>

    <div class="card">
        <div class="muted" style="margin-bottom:6px;">Dispatch Pipeline (count per status)</div>
        <table>
            <thead><tr><th>Status</th><th>Count</th><th style="width:60%">Share</th></tr></thead>
            <tbody>
                <?php $sum = array_sum($pipes); foreach ($pipes as $st=>$c): $pct = $sum>0? round(($c/$sum)*100,1):0; ?>
                <tr>
                    <td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$st))); ?></td>
                    <td><?php echo (int)$c; ?></td>
                    <td>
                        <div class="bar"><div class="bar-inner" style="width: <?php echo $pct; ?>%"></div></div>
                        <div class="muted" style="font-size:12px; margin-top:4px;"><?php echo $pct; ?>%</div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
