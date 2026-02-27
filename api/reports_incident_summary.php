<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo 'Database connection unavailable';
    exit;
}

function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : 'month';
    $start = isset($_GET['start']) ? $_GET['start'] : '';
    $end = isset($_GET['end']) ? $_GET['end'] : '';

    if ($start && $end) {
        return [$start . ' 00:00:00', $end . ' 23:59:59', 'Custom'];
    }
    $today = new DateTime('today');
    switch ($period) {
        case 'today':
            $s = $today->format('Y-m-d') . ' 00:00:00';
            $e = $today->format('Y-m-d') . ' 23:59:59';
            return [$s, $e, 'Today'];
        case 'week':
            $start = (clone $today)->modify('monday this week');
            $end = (clone $start)->modify('+6 days');
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'This Week'];
        case 'quarter':
            $m = (int)$today->format('n');
            $q = intdiv($m-1, 3) + 1;
            $qm = [1=>1,2=>4,3=>7,4=>10][$q];
            $start = new DateTime($today->format('Y') . '-' . str_pad((string)$qm,2,'0',STR_PAD_LEFT) . '-01');
            $end = (clone $start)->modify('+3 months -1 day');
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'This Quarter'];
        case 'year':
            $start = new DateTime($today->format('Y-01-01'));
            $end = new DateTime($today->format('Y-12-31'));
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'This Year'];
        case 'month':
        default:
            $start = new DateTime($today->format('Y-m-01'));
            $end = (clone $start)->modify('+1 month -1 day');
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'This Month'];
    }
}

[$startAt, $endAt, $periodLabel] = period_to_range();

// Fetch incidents within range
$sql = "SELECT id, reference_no, type, priority, status, location_address, created_at, resolved_at
        FROM incidents WHERE created_at BETWEEN :s AND :e ORDER BY created_at DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute([':s'=>$startAt, ':e'=>$endAt]);
$incidents = $stmt->fetchAll();

// Counts by type/priority/status
$types = ['medical'=>0,'fire'=>0,'police'=>0,'traffic'=>0,'other'=>0];
$priorities = ['high'=>0,'medium'=>0,'low'=>0];
$statuses = ['pending'=>0,'dispatched'=>0,'resolved'=>0,'cancelled'=>0];
$total = 0;
foreach ($incidents as $r) {
    $total++;
    $t = $r['type']; $p = $r['priority']; $s = $r['status'];
    $types[$t] = ($types[$t] ?? 0) + 1;
    $p = $p === 'critical' ? 'low' : $p;
    $priorities[$p] = ($priorities[$p] ?? 0) + 1;
    $statuses[$s] = ($statuses[$s] ?? 0) + 1;
}

// Avg response time from dispatches in period
$avgResponse = 0.0;
$rt = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at)) AS avg_min
                     FROM dispatches WHERE assigned_at BETWEEN :s AND :e AND on_scene_at IS NOT NULL");
$rt->execute([':s'=>$startAt, ':e'=>$endAt]);
$row = $rt->fetch();
if ($row && $row['avg_min'] !== null) { $avgResponse = round((float)$row['avg_min'], 1); }

// Resolution rate
$resolved = (int)$statuses['resolved'];
$resolutionRate = $total > 0 ? round(($resolved/$total)*100, 1) : 0.0;

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Summary Report - <?php echo htmlspecialchars($periodLabel); ?></title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; color: #111827; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .sub { color: #6b7280; margin-bottom: 16px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 12px; margin: 16px 0 20px; }
        .card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; background: #fff; }
        .kpi { font-size: 24px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef2f7; font-size: 14px; }
        th { background: #f9fafb; font-weight: 700; }
        .muted { color: #6b7280; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
        .prio-critical { background:#fee2e2; color:#991b1b; }
        .prio-high { background:#ffedd5; color:#9a3412; }
        .prio-medium { background:#fef3c7; color:#92400e; }
        .prio-low { background:#dcfce7; color:#065f46; }
        .status-pending { background:#fef3c7; color:#92400e; }
        .status-dispatched { background:#e0e7ff; color:#3730a3; }
        .status-resolved { background:#dcfce7; color:#166534; }
        .status-cancelled { background:#f3f4f6; color:#374151; }
        .toolbar { display:flex; gap:8px; margin: 8px 0 16px; }
        .btn { padding: 8px 12px; border: 1px solid #e5e7eb; background:#fff; border-radius:8px; cursor:pointer; }
    </style>
</head>
<body>
    <h1>Incident Summary Report</h1>
    <div class="sub">Period: <?php echo htmlspecialchars($periodLabel); ?> (<?php echo htmlspecialchars(substr($startAt,0,10)); ?> to <?php echo htmlspecialchars(substr($endAt,0,10)); ?>)</div>

    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="grid">
        <div class="card"><div class="muted">Total Incidents</div><div class="kpi"><?php echo (int)$total; ?></div></div>
        <div class="card"><div class="muted">Resolved</div><div class="kpi"><?php echo (int)$resolved; ?></div></div>
        <div class="card"><div class="muted">Resolution Rate</div><div class="kpi"><?php echo number_format($resolutionRate,1); ?>%</div></div>
        <div class="card"><div class="muted">Avg Response Time</div><div class="kpi"><?php echo number_format($avgResponse,1); ?> min</div></div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="muted">By Type</div>
            <table>
                <tbody>
                <?php foreach ($types as $k=>$v): ?>
                    <tr><td><?php echo htmlspecialchars(ucfirst($k)); ?></td><td><?php echo (int)$v; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card">
            <div class="muted">By Priority</div>
            <table>
                <tbody>
                <?php foreach ($priorities as $k=>$v): ?>
                    <tr><td><?php echo htmlspecialchars(ucfirst($k)); ?></td><td><?php echo (int)$v; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card">
            <div class="muted">By Status</div>
            <table>
                <tbody>
                <?php foreach ($statuses as $k=>$v): ?>
                    <tr><td><?php echo htmlspecialchars(ucfirst($k)); ?></td><td><?php echo (int)$v; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <div class="muted" style="margin-bottom:6px;">Incidents (latest first)</div>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Type</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Location</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$incidents): ?>
                <tr><td colspan="6" class="muted">No incidents in this period.</td></tr>
            <?php else: foreach ($incidents as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['reference_no']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($r['type'])); ?></td>
                    <td><span class="badge prio-<?php echo htmlspecialchars($r['priority']); ?>"><?php echo htmlspecialchars(ucfirst($r['priority'])); ?></span></td>
                    <td><span class="badge status-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span></td>
                    <td class="muted"><?php echo htmlspecialchars($r['location_address'] ?: ''); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($r['created_at']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
