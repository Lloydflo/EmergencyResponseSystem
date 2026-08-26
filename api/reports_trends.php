<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(false);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/report_analytics.php';

function trends_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $scope = ers_report_scope($_GET);
    $labels = [];
    $series = [
        'medical' => [],
        'fire' => [],
        'police' => [],
        'traffic' => [],
        'other' => [],
    ];
    $daily = [];
    $cursor = ers_report_parse_date((string)$scope['start_date'], 'start');
    $end = ers_report_parse_date((string)$scope['end_date'], 'end');
    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $labels[] = $key;
        $daily[$key] = ['medical' => 0, 'fire' => 0, 'police' => 0, 'traffic' => 0, 'other' => 0];
        $cursor = $cursor->modify('+1 day');
    }

    $parts = ers_report_incident_where($scope, 'i', 'trend', false);
    $stmt = $pdo->prepare("SELECT DATE(i.created_at) AS date_key, i.type, COUNT(*) AS count_value FROM incidents i WHERE {$parts['where']} GROUP BY DATE(i.created_at), i.type ORDER BY DATE(i.created_at)");
    $stmt->execute($parts['params']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dateKey = (string)($row['date_key'] ?? '');
        if (!isset($daily[$dateKey])) {
            continue;
        }
        $typeKey = ers_report_incident_type_key((string)($row['type'] ?? ''));
        $daily[$dateKey][$typeKey] += (int)($row['count_value'] ?? 0);
    }

    $totals = [];
    foreach ($labels as $dateKey) {
        $totals[] = array_sum($daily[$dateKey]);
        foreach ($series as $typeKey => $_) {
            $series[$typeKey][] = $daily[$dateKey][$typeKey];
        }
    }
    $grandTotal = array_sum($totals);
    $peakValue = $totals ? max($totals) : 0;
    $peakIndex = $peakValue > 0 ? array_search($peakValue, $totals, true) : false;
    $peakDate = $peakIndex === false ? null : $labels[(int)$peakIndex];
    $activeDays = count(array_filter($totals, static fn(int $value): bool => $value > 0));
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo trends_h($e->getMessage());
    exit;
} catch (Throwable $e) {
    error_log('reports_trends.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Unable to generate the trends report.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Trend Monitoring Report</title><link rel="icon" href="../images/favicon.ico"><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
*{box-sizing:border-box}body{margin:0;padding:28px;background:#f4f7f9;color:#172126;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial,sans-serif}.report{max-width:1180px;margin:auto}.head,.card{background:#fff;border:1px solid #dbe3e7;border-radius:14px}.head{display:flex;justify-content:space-between;gap:20px;padding:20px}h1{margin:0;font-size:16px}h2{margin:0 0 12px;font-size:16px}.sub,.muted{color:#60717a;font-size:13px;line-height:1.5}button{padding:9px 13px;border:1px solid #cbd5db;border-radius:8px;background:#fff;font-weight:700;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px}.card{padding:16px}.label{color:#60717a;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.value{font-size:16px;font-weight:800;margin-top:6px}.charts{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.chart{height:340px;position:relative}.table-card{margin-top:12px}.scroll{max-height:560px;overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:9px 10px;border-bottom:1px solid #e7edef;text-align:left;font-size:12px}th{position:sticky;top:0;background:#f7f9fa;color:#54656e;text-transform:uppercase;font-size:10px;letter-spacing:.04em}@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.charts{grid-template-columns:1fr}.head{flex-direction:column}}@media print{body{padding:0;background:#fff}button{display:none}.charts{grid-template-columns:1fr 1fr;gap:12px}.chart{height:420px}.scroll{max-height:none;overflow:visible}.head,.card{break-inside:avoid}.card{page-break-inside:avoid}.report{max-width:none}}
</style></head><body><div class="report">
<header class="head"><div><h1>Trend Monitoring Report</h1><div class="sub"><?php echo trends_h($scope['period_label'] . ': ' . $scope['start_date'] . ' to ' . $scope['end_date']); ?> · Asia/Manila</div><div class="sub">Counts use incident creation date only.</div></div><button onclick="window.print()">Print / Save as PDF</button></header>
<section class="grid"><article class="card"><div class="label">Incidents created</div><div class="value"><?php echo number_format($grandTotal); ?></div></article><article class="card"><div class="label">Days in range</div><div class="value"><?php echo number_format((int)$scope['range_days']); ?></div></article><article class="card"><div class="label">Days with incidents</div><div class="value"><?php echo number_format($activeDays); ?></div></article><article class="card"><div class="label">Peak day</div><div class="value" style="font-size:18px"><?php echo trends_h($peakDate ?: '—'); ?></div><div class="muted"><?php echo $peakValue > 0 ? number_format($peakValue) . ' incident(s)' : 'No incidents'; ?></div></article></section>
<section class="charts"><article class="card"><h2>Total incidents by day</h2><div class="chart"><canvas id="totalTrend"></canvas></div></article><article class="card"><h2>Incidents by type</h2><div class="chart"><canvas id="typeTrend"></canvas></div></article></section>
<section class="card table-card"><h2>Daily source data</h2><div class="scroll"><table><thead><tr><th>Date</th><th>Total</th><th>Medical</th><th>Fire</th><th>Police</th><th>Traffic</th><th>Other</th></tr></thead><tbody><?php foreach($labels as $index=>$date): ?><tr><td><?php echo trends_h($date); ?></td><td><?php echo number_format((int)$totals[$index]); ?></td><?php foreach(['medical','fire','police','traffic','other'] as $type): ?><td><?php echo number_format((int)$series[$type][$index]); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></section>
</div>
<script>
const labels=<?php echo json_encode($labels, JSON_UNESCAPED_SLASHES); ?>;
const totals=<?php echo json_encode($totals); ?>;
const series=<?php echo json_encode($series); ?>;
new Chart(document.getElementById('totalTrend'),{type:'line',data:{labels,datasets:[{label:'Incidents created',data:totals,borderColor:'#3f7f7d',backgroundColor:'rgba(63,127,125,.15)',fill:true,tension:.25,spanGaps:false}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
new Chart(document.getElementById('typeTrend'),{type:'line',data:{labels,datasets:[{label:'Medical',data:series.medical,borderColor:'#22c55e',tension:.25},{label:'Fire',data:series.fire,borderColor:'#ef4444',tension:.25},{label:'Police',data:series.police,borderColor:'#3b82f6',tension:.25},{label:'Traffic',data:series.traffic,borderColor:'#f59e0b',tension:.25},{label:'Other',data:series.other,borderColor:'#64748b',tension:.25}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
</script></body></html>
