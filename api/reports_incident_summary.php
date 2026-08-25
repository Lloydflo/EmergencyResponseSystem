<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(false);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/report_analytics.php';

function report_html($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function report_number_or_dash($value, int $decimals = 1, string $suffix = ''): string
{
    return $value === null ? '—' : number_format((float)$value, $decimals) . $suffix;
}

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $scope = ers_report_scope($_GET);
    $report = ers_report_fetch_metrics($pdo, $scope);
    $metrics = $report['metrics'];
    $items = ers_report_fetch_incidents($pdo, $scope, 500);
    $statusCounts = array_merge(
        ['resolved' => 0, 'cancelled' => 0, 'open' => 0],
        (array)($metrics['incident_status_counts'] ?? [])
    );
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo report_html($e->getMessage());
    exit;
} catch (Throwable $e) {
    error_log('reports_incident_summary.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Unable to generate the incident report.';
    exit;
}

$typeCounts = (array)($metrics['incidents_by_type'] ?? []);
$priorityCounts = (array)($metrics['incidents_by_priority'] ?? []);
$rangeLabel = $scope['period_label'] . ': ' . $scope['start_date'] . ' to ' . $scope['end_date'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Summary Report</title>
    <link rel="icon" type="image/x-icon" href="../images/favicon.ico">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7f9; color: #172126; font-family: Inter, system-ui, -apple-system, Segoe UI, Arial, sans-serif; }
        .report { max-width: 1180px; margin: auto; }
        .head, .card { border: 1px solid #dbe3e7; border-radius: 14px; background: #fff; }
        .head { display:flex; justify-content:space-between; gap:20px; padding:20px; }
        h1 { margin:0; font-size:16px; } h2 { margin:0 0 12px; font-size:16px; }
        .sub, .muted { color:#60717a; font-size:13px; line-height:1.5; }
        .toolbar { display:flex; align-items:flex-start; gap:8px; }
        button { padding:9px 13px; border:1px solid #cbd5db; border-radius:8px; background:#fff; cursor:pointer; font-weight:700; }
        .grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:12px; }
        .card { padding:16px; }
        .metric-label { color:#60717a; font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
        .metric { margin-top:6px; font-size:16px; font-weight:800; }
        .two { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px; }
        table { width:100%; border-collapse:collapse; } th,td { padding:9px 10px; border-bottom:1px solid #e7edef; text-align:left; font-size:12px; vertical-align:top; }
        th { background:#f7f9fa; color:#54656e; font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
        .badge { display:inline-flex; padding:3px 7px; border-radius:999px; background:#eef6f5; color:#326a68; font-size:10px; font-weight:800; }
        .scroll { max-height:560px; overflow:auto; }
        @media (max-width:850px){ .grid{grid-template-columns:repeat(2,1fr)} .two{grid-template-columns:1fr} .head{flex-direction:column} }
        @media print { body{padding:0;background:#fff} .toolbar{display:none}.head,.card{box-shadow:none;break-inside:avoid}.scroll{max-height:none;overflow:visible} }
    </style>
</head>
<body>
<div class="report">
    <header class="head">
        <div>
            <h1>Incident Summary Report</h1>
            <div class="sub"><?php echo report_html($rangeLabel); ?> · Asia/Manila</div>
            <div class="sub">Generated <?php echo report_html($scope['generated_at']); ?></div>
        </div>
        <div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
    </header>

    <section class="grid">
        <article class="card"><div class="metric-label">Incidents created</div><div class="metric"><?php echo number_format((int)$metrics['total_incidents']); ?></div><div class="muted">Created within selected range</div></article>
        <article class="card"><div class="metric-label">Resolved by period end</div><div class="metric"><?php echo number_format((int)$metrics['resolved_incidents']); ?></div><div class="muted">From the selected incident cohort</div></article>
        <article class="card"><div class="metric-label">Resolution rate</div><div class="metric"><?php echo report_number_or_dash($metrics['resolution_rate'], 1, '%'); ?></div><div class="muted">Resolved ÷ incidents created</div></article>
        <article class="card"><div class="metric-label">Dispatch-to-scene average</div><div class="metric"><?php echo report_number_or_dash($metrics['avg_response_time_min'], 1, ' min'); ?></div><div class="muted"><?php echo number_format((int)$metrics['avg_response_sample_count']); ?> valid on-scene sample(s)</div></article>
    </section>

    <section class="two">
        <article class="card"><h2>Incidents by type</h2><table><tbody><?php foreach (['medical','fire','police','traffic','other'] as $key): ?><tr><td><?php echo report_html(ucfirst($key)); ?></td><td><?php echo number_format((int)($typeCounts[$key] ?? 0)); ?></td></tr><?php endforeach; ?></tbody></table></article>
        <article class="card"><h2>Incidents by priority</h2><table><tbody><?php foreach (['critical','high','medium','low','other'] as $key): ?><tr><td><?php echo report_html(ucfirst($key)); ?></td><td><?php echo number_format((int)($priorityCounts[$key] ?? 0)); ?></td></tr><?php endforeach; ?></tbody></table></article>
        <article class="card"><h2>Current cohort status</h2><table><tbody><?php foreach ($statusCounts as $key => $value): ?><tr><td><?php echo report_html(ucfirst($key)); ?></td><td><?php echo number_format($value); ?></td></tr><?php endforeach; ?></tbody></table></article>
        <article class="card"><h2>Applied filters</h2><table><tbody><tr><td>Type</td><td><?php echo report_html($scope['type'] ?: 'All'); ?></td></tr><tr><td>Priority</td><td><?php echo report_html($scope['priority'] ?: 'All'); ?></td></tr><tr><td>Previous comparison</td><td><?php echo report_html($scope['previous_start_date'] . ' to ' . $scope['previous_end_date']); ?></td></tr></tbody></table></article>
    </section>

    <section class="card" style="margin-top:12px">
        <h2>Incidents created in selected range</h2>
        <div class="muted" style="margin:-5px 0 10px">Showing up to 500 newest records; aggregate totals above include the full filtered cohort.</div>
        <div class="scroll"><table><thead><tr><th>Reference</th><th>Created</th><th>Type</th><th>Priority</th><th>Status</th><th>Location</th><th>Unit</th><th>Response</th></tr></thead><tbody>
        <?php if (!$items): ?><tr><td colspan="8" class="muted">No incidents found.</td></tr><?php endif; ?>
        <?php foreach ($items as $item): ?><tr>
            <td><strong><?php echo report_html($item['incident_code']); ?></strong></td>
            <td><?php echo report_html($item['created_at']); ?></td>
            <td><?php echo report_html(ucfirst((string)$item['type'])); ?></td>
            <td><span class="badge"><?php echo report_html(strtoupper((string)$item['priority'])); ?></span></td>
            <td><?php echo report_html(ucwords(str_replace('_',' ',(string)$item['status']))); ?></td>
            <td><?php echo report_html($item['location']); ?></td>
            <td><?php echo report_html($item['unit_identifier'] ?: '—'); ?></td>
            <td><?php echo $item['response_time_min'] === null ? '—' : report_html(number_format((float)$item['response_time_min'],1) . ' min'); ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
</body>
</html>
