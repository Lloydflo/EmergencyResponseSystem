<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo 'Database connection unavailable'; exit; }

function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : '';
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start . ' 00:00:00', $end . ' 23:59:59', 'Custom'];
    }

    $today = new DateTime('today');
    if ($period === '') {
        $rangeStart = (clone $today)->modify('-29 days');
        $rangeEnd = $today;
        return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59', 'Last 30 Days'];
    }

    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d') . ' 00:00:00', $today->format('Y-m-d') . ' 23:59:59', 'Today'];
        case 'week':
            $rangeStart = (clone $today)->modify('monday this week');
            $rangeEnd = (clone $rangeStart)->modify('+6 days');
            $label = 'This Week';
            break;
        case 'quarter':
            $month = (int)$today->format('n');
            $quarterStartMonth = [1 => 1, 2 => 4, 3 => 7, 4 => 10][intdiv($month - 1, 3) + 1];
            $rangeStart = new DateTime($today->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $rangeEnd = (clone $rangeStart)->modify('+3 months -1 day');
            $label = 'This Quarter';
            break;
        case 'year':
            $rangeStart = new DateTime($today->format('Y-01-01'));
            $rangeEnd = new DateTime($today->format('Y-12-31'));
            $label = 'This Year';
            break;
        case 'month':
        default:
            $rangeStart = new DateTime($today->format('Y-m-01'));
            $rangeEnd = (clone $rangeStart)->modify('+1 month -1 day');
            $label = 'This Month';
            break;
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

function normalize_type_key(string $type): string {
    $type = strtolower(trim($type));
    if ($type === 'crime') {
        return 'police';
    }
    if ($type === 'accident') {
        return 'traffic';
    }
    return in_array($type, ['medical', 'fire', 'police', 'traffic'], true) ? $type : 'other';
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

$labels = [];
$dataTotal = [];
$types = ['medical','fire','police','traffic','other'];
$typeSeries = array_fill_keys($types, []);

$startDate = new DateTime(substr($startAt, 0, 10));
$endDate = new DateTime(substr($endAt, 0, 10));
$indexByDay = [];
$cursor = clone $startDate;
$index = 0;
while ($cursor <= $endDate) {
    $dayKey = $cursor->format('Y-m-d');
    $labels[] = $dayKey;
    $indexByDay[$dayKey] = $index;
    $dataTotal[$index] = 0;
    foreach ($types as $typeKey) {
        $typeSeries[$typeKey][$index] = 0;
    }
    $cursor->modify('+1 day');
    $index++;
}

$sql = "
    SELECT DATE(i.created_at) AS incident_day, LOWER(i.type) AS type_name, COUNT(*) AS total_count
    FROM incidents i
    WHERE i.created_at BETWEEN :start AND :end
";
$params = [
    ':start' => $startAt,
    ':end' => $endAt,
];
append_type_filter($sql, $params, 'i.type', $typeValues, 'trend');
if ($priorityFilter !== '') {
    $sql .= ' AND i.priority = :priority';
    $params[':priority'] = $priorityFilter;
}
$sql .= ' GROUP BY DATE(i.created_at), LOWER(i.type) ORDER BY DATE(i.created_at) ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
foreach ($stmt->fetchAll() as $row) {
    $dayKey = (string)($row['incident_day'] ?? '');
    if (!isset($indexByDay[$dayKey])) {
        continue;
    }
    $slot = $indexByDay[$dayKey];
    $count = (int)($row['total_count'] ?? 0);
    $typeKey = normalize_type_key((string)($row['type_name'] ?? 'other'));
    $dataTotal[$slot] += $count;
    $typeSeries[$typeKey][$slot] += $count;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trend Analysis Report</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; color: #111827; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .sub { color: #6b7280; margin-bottom: 16px; }
        .card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; background: #fff; margin-top: 12px; }
        .muted { color: #6b7280; }
        .btn { padding: 8px 12px; border: 1px solid #e5e7eb; background:#fff; border-radius:8px; cursor:pointer; }
        .toolbar { display:flex; gap:8px; margin: 8px 0 16px; }
        .chart-wrap { position: relative; width: 100%; height: 360px; }
        .chart-canvas { width: 100% !important; height: 100% !important; display: block; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef2f7; font-size: 14px; }
        th { background: #f9fafb; font-weight: 700; }
    </style>
</head>
<body>
    <h1>Trend Analysis Report</h1>
    <div class="sub"><?php echo htmlspecialchars($periodLabel); ?> trend from <?php echo htmlspecialchars(substr($startAt, 0, 10)); ?> to <?php echo htmlspecialchars(substr($endAt, 0, 10)); ?></div>
    <div class="toolbar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>

    <div class="card">
        <div class="muted">Total Incidents</div>
        <div class="chart-wrap"><canvas id="trendTotal" class="chart-canvas"></canvas></div>
    </div>

    <div class="card">
        <div class="muted">Incidents by Type</div>
        <div class="chart-wrap"><canvas id="trendByType" class="chart-canvas"></canvas></div>
    </div>

    <div class="card">
        <div class="muted">Tabular Summary</div>
        <table>
            <thead><tr><th>Date</th><th>Total</th><th>Medical</th><th>Fire</th><th>Police</th><th>Traffic</th><th>Other</th></tr></thead>
            <tbody>
                <?php for ($i = 0; $i < count($labels); $i++): ?>
                <tr>
                    <td><?php echo htmlspecialchars($labels[$i]); ?></td>
                    <td><?php echo (int)$dataTotal[$i]; ?></td>
                    <td><?php echo (int)$typeSeries['medical'][$i]; ?></td>
                    <td><?php echo (int)$typeSeries['fire'][$i]; ?></td>
                    <td><?php echo (int)$typeSeries['police'][$i]; ?></td>
                    <td><?php echo (int)$typeSeries['traffic'][$i]; ?></td>
                    <td><?php echo (int)$typeSeries['other'][$i]; ?></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <script>
        const labels = <?php echo json_encode($labels); ?>;
        const totalData = <?php echo json_encode($dataTotal); ?>;
        const typeSeries = <?php echo json_encode($typeSeries); ?>;
        document.addEventListener('DOMContentLoaded', () => {
            const c1 = document.getElementById('trendTotal');
            new Chart(c1, { type:'line', data:{ labels, datasets:[{ label:'Total Incidents', data: totalData, borderColor:'#111827', backgroundColor:'rgba(17,24,39,0.1)', tension:0.3, fill:true }] }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } } });
            const c2 = document.getElementById('trendByType');
            new Chart(c2, { type:'line', data:{ labels, datasets:[
                { label:'Medical', data:typeSeries.medical, borderColor:'#22c55e', fill:false, tension:0.3 },
                { label:'Fire', data:typeSeries.fire, borderColor:'#ef4444', fill:false, tension:0.3 },
                { label:'Police', data:typeSeries.police, borderColor:'#3b82f6', fill:false, tension:0.3 },
                { label:'Traffic', data:typeSeries.traffic, borderColor:'#f59e0b', fill:false, tension:0.3 },
                { label:'Other', data:typeSeries.other, borderColor:'#6b7280', fill:false, tension:0.3 },
            ] }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } } });
        });
    </script>
</body>
</html>
