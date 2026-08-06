<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(false);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/report_analytics.php';

function perf_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function perf_value($value, string $suffix = '', int $decimals = 1): string
{
    return $value === null ? '—' : number_format((float)$value, $decimals) . $suffix;
}

function perf_delta($current, $previous, bool $lowerIsBetter = false): array
{
    if ($current === null || $previous === null) {
        return ['label' => 'No comparable baseline', 'tone' => 'neutral'];
    }
    $delta = (float)$current - (float)$previous;
    if (abs($delta) < 0.05) {
        return ['label' => 'No material change', 'tone' => 'neutral'];
    }
    $improved = $lowerIsBetter ? $delta < 0 : $delta > 0;
    return [
        'label' => ($delta > 0 ? '+' : '') . number_format($delta, 1) . ($lowerIsBetter ? ' min' : ' pp'),
        'tone' => $improved ? 'good' : 'bad',
    ];
}

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $scope = ers_report_scope($_GET);
    $report = ers_report_fetch_metrics($pdo, $scope);
    $metrics = $report['metrics'];
    $currentDispatch = ers_report_fetch_dispatch_metrics($pdo, $scope, false);
    $previousDispatch = ers_report_fetch_dispatch_metrics($pdo, $scope, true);
    $responseDelta = perf_delta($currentDispatch['avg_on_scene_minutes'], $previousDispatch['avg_on_scene_minutes'], true);
    $resolutionDelta = perf_delta($metrics['resolution_rate'], $metrics['previous_resolution_rate'], false);
    $slaDelta = perf_delta($currentDispatch['response_sla_compliance_rate'], $previousDispatch['response_sla_compliance_rate'], false);
    $ackDelta = perf_delta($currentDispatch['acknowledgement_rate'], $previousDispatch['acknowledgement_rate'], false);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo perf_h($e->getMessage());
    exit;
} catch (Throwable $e) {
    error_log('reports_performance.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Unable to generate the performance report.';
    exit;
}

$rows = [
    [
        'metric' => 'Dispatch-to-scene average',
        'current' => perf_value($currentDispatch['avg_on_scene_minutes'], ' min'),
        'previous' => perf_value($previousDispatch['avg_on_scene_minutes'], ' min'),
        'samples' => $currentDispatch['on_scene_sample_count'] . ' / ' . $previousDispatch['on_scene_sample_count'],
        'target' => '≤ ' . ERS_REPORT_RESPONSE_SLA_MINUTES . ' min',
        'delta' => $responseDelta,
    ],
    [
        'metric' => 'Arrival SLA compliance',
        'current' => perf_value($currentDispatch['response_sla_compliance_rate'], '%'),
        'previous' => perf_value($previousDispatch['response_sla_compliance_rate'], '%'),
        'samples' => $currentDispatch['on_scene_sample_count'] . ' / ' . $previousDispatch['on_scene_sample_count'],
        'target' => '≥ ' . number_format(ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT, 0) . '%',
        'delta' => $slaDelta,
    ],
    [
        'metric' => 'Incident resolution rate',
        'current' => perf_value($metrics['resolution_rate'], '%'),
        'previous' => perf_value($metrics['previous_resolution_rate'], '%'),
        'samples' => $metrics['total_incidents'] . ' / ' . $metrics['previous_total_incidents'],
        'target' => '≥ ' . number_format(ERS_REPORT_RESOLUTION_TARGET_PERCENT, 0) . '%',
        'delta' => $resolutionDelta,
    ],
    [
        'metric' => 'Dispatch acknowledgement rate',
        'current' => perf_value($currentDispatch['acknowledgement_rate'], '%'),
        'previous' => perf_value($previousDispatch['acknowledgement_rate'], '%'),
        'samples' => $currentDispatch['total_dispatches'] . ' / ' . $previousDispatch['total_dispatches'],
        'target' => '≥ ' . number_format(ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT, 0) . '%',
        'delta' => $ackDelta,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Performance Review Report</title><link rel="icon" href="../images/favicon.ico">
<style>
*{box-sizing:border-box}body{margin:0;padding:28px;background:#f4f7f9;color:#172126;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial,sans-serif}.report{max-width:1120px;margin:auto}.head,.card{background:#fff;border:1px solid #dbe3e7;border-radius:14px}.head{display:flex;justify-content:space-between;gap:20px;padding:20px}h1{margin:0;font-size:24px}h2{margin:0 0 12px;font-size:16px}.sub,.muted{color:#60717a;font-size:13px;line-height:1.5}button{padding:9px 13px;border:1px solid #cbd5db;border-radius:8px;background:#fff;font-weight:700;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px}.card{padding:16px}.label{color:#60717a;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.value{font-size:25px;font-weight:800;margin-top:6px}.definition{margin-top:12px;padding:13px 15px;border-left:4px solid #3f7f7d;background:#eef6f5;color:#3e555d;font-size:12px;line-height:1.6}.table-card{margin-top:12px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e7edef;text-align:left;font-size:12px}th{background:#f7f9fa;color:#54656e;text-transform:uppercase;font-size:10px;letter-spacing:.04em}.trend{display:inline-flex;padding:4px 8px;border-radius:999px;font-weight:800}.trend.good{background:#e8f5ee;color:#157347}.trend.bad{background:#fdecea;color:#b42318}.trend.neutral{background:#eef2f5;color:#5b6b73}.foot{margin-top:12px;color:#60717a;font-size:12px}.live{color:#946200;font-weight:800}@media(max-width:850px){.grid{grid-template-columns:repeat(2,1fr)}.head{flex-direction:column}}@media print{body{padding:0;background:#fff}button{display:none}.head,.card{break-inside:avoid}}
</style>
</head>
<body><div class="report">
<header class="head"><div><h1>Administrative Performance Review</h1><div class="sub"><?php echo perf_h($scope['period_label'] . ': ' . $scope['start_date'] . ' to ' . $scope['end_date']); ?> · Asia/Manila</div><div class="sub">Compared with <?php echo perf_h($scope['previous_start_date'] . ' to ' . $scope['previous_end_date']); ?></div></div><button onclick="window.print()">Print / Save as PDF</button></header>
<section class="grid">
<article class="card"><div class="label">Dispatch-to-scene</div><div class="value"><?php echo perf_value($currentDispatch['avg_on_scene_minutes'],' min'); ?></div><div class="muted"><?php echo number_format((int)$currentDispatch['on_scene_sample_count']); ?> valid arrival(s)</div></article>
<article class="card"><div class="label">Arrival SLA compliance</div><div class="value"><?php echo perf_value($currentDispatch['response_sla_compliance_rate'],'%'); ?></div><div class="muted">Within <?php echo ERS_REPORT_RESPONSE_SLA_MINUTES; ?> minutes</div></article>
<article class="card"><div class="label">Resolution rate</div><div class="value"><?php echo perf_value($metrics['resolution_rate'],'%'); ?></div><div class="muted"><?php echo number_format((int)$metrics['resolved_incidents']); ?> of <?php echo number_format((int)$metrics['total_incidents']); ?> incident(s)</div></article>
<article class="card"><div class="label">Current unit utilization</div><div class="value"><?php echo perf_value($metrics['resource_utilization'],'%'); ?></div><div class="muted"><span class="live">Live snapshot</span>, not historical</div></article>
</section>
<div class="definition"><strong>Definitions:</strong> Response performance uses only dispatches with a valid <code>on_scene_at</code> at or after assignment. Resolution rate uses incidents created during the selected range and resolved by that range’s end. Missing timestamps are reported as unavailable rather than converted to zero. Percentage comparisons are percentage-point changes.</div>
<section class="card table-card"><h2>Current versus previous equal-duration period</h2><table><thead><tr><th>Metric</th><th>Current</th><th>Previous</th><th>Samples current / previous</th><th>Target</th><th>Change</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><strong><?php echo perf_h($row['metric']); ?></strong></td><td><?php echo perf_h($row['current']); ?></td><td><?php echo perf_h($row['previous']); ?></td><td><?php echo perf_h($row['samples']); ?></td><td><?php echo perf_h($row['target']); ?></td><td><span class="trend <?php echo perf_h($row['delta']['tone']); ?>"><?php echo perf_h($row['delta']['label']); ?></span></td></tr><?php endforeach; ?></tbody></table></section>
<div class="foot">Generated <?php echo perf_h($scope['generated_at']); ?>. Filters: type <?php echo perf_h($scope['type'] ?: 'all'); ?>; priority <?php echo perf_h($scope['priority'] ?: 'all'); ?>.</div>
</div></body></html>
