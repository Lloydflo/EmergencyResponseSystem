<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) {
    header('Location: ../login.php?redirect=' . urlencode('dispatcher/resources.php'));
    exit;
}

$currentRole = current_session_role();
if ($currentRole !== 'admin' && $currentRole !== 'dispatcher') {
    http_response_code(403);
    echo 'Access denied';
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/report_analytics.php';

function resources_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function resources_value($value, string $suffix = '', int $decimals = 1): string
{
    return $value === null ? '—' : number_format((float)$value, $decimals) . $suffix;
}

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $scope = ers_report_scope($_GET);
    $summary = ers_report_fetch_dispatch_summary($pdo, $scope);
    $snapshot = $summary['unit_snapshot'];
    $dispatchMetrics = $summary['metrics'];
    $topUnits = $summary['all_units'];
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo resources_h($e->getMessage());
    exit;
} catch (Throwable $e) {
    error_log('reports_resources.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Unable to generate the resource report.';
    exit;
}

$typeKeys = ['ambulance','fire','police','rescue','other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Resource Audit Report</title><link rel="icon" href="../images/favicon.ico">
<style>
*{box-sizing:border-box}body{margin:0;padding:28px;background:#f4f7f9;color:#172126;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial,sans-serif}.report{max-width:1120px;margin:auto}.head,.card{background:#fff;border:1px solid #dbe3e7;border-radius:14px}.head{display:flex;justify-content:space-between;gap:20px;padding:20px}h1{margin:0;font-size:24px}h2{margin:0 0 12px;font-size:16px}.sub,.muted{color:#60717a;font-size:13px;line-height:1.5}button{padding:9px 13px;border:1px solid #cbd5db;border-radius:8px;background:#fff;font-weight:700;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-top:12px}.card{padding:16px}.label{color:#60717a;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.value{font-size:24px;font-weight:800;margin-top:6px}.two{display:grid;grid-template-columns:1fr 1.35fr;gap:12px;margin-top:12px}.note{margin-top:12px;padding:13px 15px;border-left:4px solid #946200;background:#fff4d6;color:#66511b;font-size:12px;line-height:1.6}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e7edef;text-align:left;font-size:12px}th{background:#f7f9fa;color:#54656e;text-transform:uppercase;font-size:10px;letter-spacing:.04em}.scroll{max-height:520px;overflow:auto}@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.two{grid-template-columns:1fr}.head{flex-direction:column}}@media print{body{padding:0;background:#fff}button{display:none}.scroll{max-height:none;overflow:visible}.card,.head{break-inside:avoid}}
</style></head>
<body><div class="report">
<header class="head"><div><h1>Resource Audit Report</h1><div class="sub">Live fleet snapshot plus dispatch activity for <?php echo resources_h($scope['start_date'] . ' to ' . $scope['end_date']); ?> · Asia/Manila</div><div class="sub">Generated <?php echo resources_h($scope['generated_at']); ?></div></div><button onclick="window.print()">Print / Save as PDF</button></header>
<section class="grid">
<article class="card"><div class="label">Total units</div><div class="value"><?php echo number_format((int)$snapshot['total_units']); ?></div><div class="muted">Live snapshot</div></article>
<article class="card"><div class="label">Available</div><div class="value"><?php echo number_format((int)$snapshot['available_units']); ?></div><div class="muted">Ready now</div></article>
<article class="card"><div class="label">In use</div><div class="value"><?php echo number_format((int)$snapshot['in_use_units']); ?></div><div class="muted">Assigned / en route / on scene</div></article>
<article class="card"><div class="label">Maintenance / unavailable</div><div class="value"><?php echo number_format((int)$snapshot['maintenance_units'] + (int)$snapshot['unavailable_units']); ?></div><div class="muted">Not operational</div></article>
<article class="card"><div class="label">Operational utilization</div><div class="value"><?php echo resources_value($snapshot['utilization_rate'],'%'); ?></div><div class="muted">In use ÷ operational units</div></article>
</section>
<div class="note"><strong>Important:</strong> Unit status is a current live snapshot and cannot be reconstructed historically from the present <code>units.status</code> field. Dispatch counts below are historical and are filtered by the selected assignment date, incident type, and priority.</div>
<section class="two">
<article class="card"><h2>Dispatch activity by unit type</h2><table><thead><tr><th>Unit type</th><th>Dispatches in range</th><th>Units in live inventory</th></tr></thead><tbody><?php foreach($typeKeys as $key): ?><tr><td><?php echo resources_h(ucfirst($key)); ?></td><td><?php echo number_format((int)($dispatchMetrics['by_unit_type'][$key] ?? 0)); ?></td><td><?php echo number_format((int)($snapshot['by_type'][$key] ?? 0)); ?></td></tr><?php endforeach; ?></tbody></table></article>
<article class="card"><h2>Unit dispatch counts for selected range</h2><div class="scroll"><table><thead><tr><th>Unit</th><th>Type</th><th>Dispatches</th></tr></thead><tbody><?php if(!$topUnits): ?><tr><td colspan="3" class="muted">No dispatches found.</td></tr><?php endif; ?><?php foreach($topUnits as $unit): ?><tr><td><strong><?php echo resources_h($unit['identifier']); ?></strong></td><td><?php echo resources_h(ucfirst((string)$unit['unit_type'])); ?></td><td><?php echo number_format((int)$unit['count']); ?></td></tr><?php endforeach; ?></tbody></table></div></article>
</section>
<section class="card" style="margin-top:12px"><h2>Dispatch milestone quality</h2><table><tbody><tr><td>Dispatches assigned</td><td><?php echo number_format((int)$dispatchMetrics['total_dispatches']); ?></td></tr><tr><td>Acknowledgement rate</td><td><?php echo resources_value($dispatchMetrics['acknowledgement_rate'],'%'); ?></td></tr><tr><td>Valid on-scene samples</td><td><?php echo number_format((int)$dispatchMetrics['on_scene_sample_count']); ?></td></tr><tr><td>Average assignment-to-scene</td><td><?php echo resources_value($dispatchMetrics['avg_on_scene_minutes'],' min'); ?></td></tr></tbody></table></section>
</div></body></html>
