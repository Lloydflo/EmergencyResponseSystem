<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo 'Database connection unavailable'; exit; }


$unitStatuses = ['available'=>0,'assigned'=>0,'enroute'=>0,'on_scene'=>0,'unavailable'=>0,'maintenance'=>0];
$resStatuses = ['available'=>0,'deployed'=>0,'maintenance'=>0,'out_of_service'=>0];
$staffStatuses = ['available'=>0,'on_duty'=>0,'off_duty'=>0,'leave'=>0];

// KPIs matching dashboard logic
$totalVehicles = 0;
$activePersonnel = 0;
$equipmentItems = 0;
try {
    // Total vehicles (units not in maintenance)
    $qv = $pdo->query("SELECT COUNT(*) AS c FROM units WHERE status != 'maintenance'");
    $totalVehicles = (int)($qv->fetch()['c'] ?? 0);
    // Active personnel (staff on duty or available)
    $qp = $pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')");
    $activePersonnel = (int)($qp->fetch()['c'] ?? 0);
    // Equipment items (resources of type equipment and not in maintenance)
    $qe = $pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type = 'equipment' AND status != 'maintenance'");
    $equipmentItems = (int)($qe->fetch()['c'] ?? 0);
} catch (Throwable $e) {}

try {
    $q1 = $pdo->query("SELECT status, COUNT(*) c FROM units GROUP BY status");
    foreach ($q1->fetchAll() as $r) { $s=$r['status']; if (isset($unitStatuses[$s])) $unitStatuses[$s]=(int)$r['c']; }
} catch (Throwable $e) {}
try {
    $q2 = $pdo->query("SELECT status, COUNT(*) c FROM resources GROUP BY status");
    foreach ($q2->fetchAll() as $r) { $s=$r['status']; if (isset($resStatuses[$s])) $resStatuses[$s]=(int)$r['c']; }
} catch (Throwable $e) {}
try {
    $q3 = $pdo->query("SELECT status, COUNT(*) c FROM staff GROUP BY status");
    foreach ($q3->fetchAll() as $r) { $s=$r['status']; if (isset($staffStatuses[$s])) $staffStatuses[$s]=(int)$r['c']; }
} catch (Throwable $e) {}

$totalUnits = array_sum($unitStatuses);
$busyUnits = ($unitStatuses['assigned'] ?? 0) + ($unitStatuses['enroute'] ?? 0) + ($unitStatuses['on_scene'] ?? 0);
$util = $totalUnits>0 ? round(($busyUnits/$totalUnits)*100,1) : 0.0;

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Utilization Report</title>
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
        .bar-inner { background:#22c55e; height: 100%; }
        .btn { padding: 8px 12px; border: 1px solid #e5e7eb; background:#fff; border-radius:8px; cursor:pointer; }
        .toolbar { display:flex; gap:8px; margin: 8px 0 16px; }
    </style>
</head>
<body>
    <h1>Resource Utilization Report</h1>
    <div class="sub">Live snapshot of units, resources, and staff</div>
    <div class="toolbar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>


    <div class="grid">
        <div class="card"><div class="muted">Total Vehicles</div><div class="kpi"><?php echo (int)$totalVehicles; ?></div></div>
        <div class="card"><div class="muted">Active Personnel</div><div class="kpi"><?php echo (int)$activePersonnel; ?></div></div>
        <div class="card"><div class="muted">Equipment Items</div><div class="kpi"><?php echo (int)$equipmentItems; ?></div></div>
        <div class="card"><div class="muted">Utilization (Units Busy)</div><div class="kpi"><?php echo number_format($util,1); ?>%</div></div>
        <div class="card"><div class="muted">Total Units</div><div class="kpi"><?php echo (int)$totalUnits; ?></div></div>
        <div class="card"><div class="muted">Busy Units</div><div class="kpi"><?php echo (int)$busyUnits; ?></div></div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="muted" style="margin-bottom:6px;">Units by Status</div>
            <table>
                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach ($unitStatuses as $k=>$v): ?>
                        <tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$k))); ?></td><td><?php echo (int)$v; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card">
            <div class="muted" style="margin-bottom:6px;">Resources by Status</div>
            <table>
                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach ($resStatuses as $k=>$v): ?>
                        <tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$k))); ?></td><td><?php echo (int)$v; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card">
            <div class="muted" style="margin-bottom:6px;">Staff by Status</div>
            <table>
                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                <tbody>
                    <?php foreach ($staffStatuses as $k=>$v): ?>
                        <tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$k))); ?></td><td><?php echo (int)$v; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
