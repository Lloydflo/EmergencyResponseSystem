<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo 'Database connection unavailable'; exit; }

function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : 'month';
    $today = new DateTime('today');
    switch ($period) {
        case 'today': $s=$today->format('Y-m-d').' 00:00:00'; $e=$today->format('Y-m-d').' 23:59:59'; return [$s,$e,'Today'];
        case 'week': $start=(clone $today)->modify('monday this week'); $end=(clone $start)->modify('+6 days'); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59','This Week'];
        case 'quarter': $m=(int)$today->format('n'); $q=intdiv($m-1,3)+1; $qm=[1=>1,2=>4,3=>7,4=>10][$q]; $start=new DateTime($today->format('Y').'-'.str_pad((string)$qm,2,'0',STR_PAD_LEFT).'-01'); $end=(clone $start)->modify('+3 months -1 day'); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59','This Quarter'];
        case 'year': $start=new DateTime($today->format('Y-01-01')); $end=new DateTime($today->format('Y-12-31')); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59','This Year'];
        case 'month': default: $start=new DateTime($today->format('Y-m-01')); $end=(clone $start)->modify('+1 month -1 day'); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59','This Month'];
    }
}
[$startAt,$endAt,$periodLabel] = period_to_range();

// KPIs
$avgResponse = 0.0; $resolutionRate = 0.0; $avgResolveTime = 0.0;

// Average response (assigned->on_scene)
$row = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at)) AS a FROM dispatches WHERE assigned_at BETWEEN :s AND :e AND on_scene_at IS NOT NULL");
$row->execute([':s'=>$startAt, ':e'=>$endAt]);
$r = $row->fetch(); if ($r && $r['a'] !== null) { $avgResponse = round((float)$r['a'], 1); }

// Resolution rate and avg resolution time
$q = $pdo->prepare("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) AS resolved,
    AVG(CASE WHEN status='resolved' THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) AS avg_resolve
    FROM incidents WHERE created_at BETWEEN :s AND :e");
$q->execute([':s'=>$startAt, ':e'=>$endAt]);
$x = $q->fetch();
$total = (int)($x['total'] ?? 0);
$resolved = (int)($x['resolved'] ?? 0);
if ($total > 0) { $resolutionRate = round(($resolved/$total)*100, 1); }
if ($x && $x['avg_resolve'] !== null) { $avgResolveTime = round((float)$x['avg_resolve'], 1); }

// Dispatch stages (pipeline)
$pipes = [ 'assigned'=>0,'acknowledged'=>0,'enroute'=>0,'on_scene'=>0,'cleared'=>0,'cancelled'=>0 ];
$pd = $pdo->prepare("SELECT status, COUNT(*) c FROM dispatches WHERE assigned_at BETWEEN :s AND :e GROUP BY status");
$pd->execute([':s'=>$startAt, ':e'=>$endAt]);
foreach ($pd->fetchAll() as $row) { $st = $row['status']; if (isset($pipes[$st])) { $pipes[$st] = (int)$row['c']; } }

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
