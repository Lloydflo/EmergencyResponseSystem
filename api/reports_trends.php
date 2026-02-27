<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo 'Database connection unavailable'; exit; }

// Last 30 days incidents by day and by type
$labels = [];
$dataTotal = [];
$types = ['medical','fire','police','traffic','other'];
$typeSeries = array_fill_keys($types, array());

$start = (new DateTime('today'))->modify('-29 days');
$end = new DateTime('today');

// Build label days
$idxMap = [];
for ($i=0;$i<30;$i++) {
    $d = (clone $start)->modify("+$i day")->format('Y-m-d');
    $labels[] = $d;
    $idxMap[$d] = $i;
    $dataTotal[$i] = 0;
    foreach ($types as $t) { $typeSeries[$t][$i] = 0; }
}

// Query counts
$stmt = $pdo->prepare("SELECT DATE(created_at) d, type, COUNT(*) c FROM incidents WHERE created_at >= :s GROUP BY DATE(created_at), type");
$stmt->execute([':s' => $start->format('Y-m-d') . ' 00:00:00']);
foreach ($stmt->fetchAll() as $r) {
    $d = $r['d']; $t = $r['type']; $c = (int)$r['c'];
    if (!isset($idxMap[$d])) continue;
    $i = $idxMap[$d];
    $dataTotal[$i] += $c;
    if (!isset($typeSeries[$t])) { $t = 'other'; }
    $typeSeries[$t][$i] += $c;
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
    <div class="sub">Incidents across last 30 days</div>
    <div class="toolbar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>

    <div class="card">
        <div class="muted">Total Incidents (30-day trend)</div>
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
                <?php for ($i=0;$i<count($labels);$i++): ?>
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
