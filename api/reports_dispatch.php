<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB connection unavailable']); exit; }

function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : '';
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start.' 00:00:00', $end.' 23:59:59'];
    }
    $today = new DateTime('today');
    switch ($period) {
        case 'today': {
            $s=$today->format('Y-m-d').' 00:00:00'; $e=$today->format('Y-m-d').' 23:59:59'; return [$s,$e];
        }
        case 'week': {
            $start=(clone $today)->modify('monday this week'); $end=(clone $start)->modify('+6 days'); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59'];
        }
        case 'quarter': {
            $m=(int)$today->format('n'); $q=intdiv($m-1,3)+1; $qm=[1=>1,2=>4,3=>7,4=>10][$q]; $start=new DateTime($today->format('Y').'-'.str_pad((string)$qm,2,'0',STR_PAD_LEFT).'-01'); $end=(clone $start)->modify('+3 months -1 day'); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59'];
        }
        case 'year': {
            $start=new DateTime($today->format('Y-01-01')); $end=new DateTime($today->format('Y-12-31')); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59'];
        }
        case 'month': default: {
            $start=new DateTime($today->format('Y-m-01')); $end=(clone $start)->modify('+1 month -1 day'); return [$start->format('Y-m-d').' 00:00:00',$end->format('Y-m-d').' 23:59:59'];
        }
    }
}
[$startAt,$endAt] = period_to_range();

try {
    // Total dispatches in period
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM dispatches WHERE assigned_at BETWEEN :s AND :e");
    $stmt->execute([':s'=>$startAt, ':e'=>$endAt]);
    $totalDispatches = (int)($stmt->fetch()['c'] ?? 0);

    // Averages for each stage (in minutes)
    $avgAck = 0.0; $avgEnroute = 0.0; $avgOnScene = 0.0; $avgClear = 0.0;
    $row = $pdo->prepare("SELECT 
        AVG(TIMESTAMPDIFF(MINUTE, assigned_at, acknowledged_at)) AS ack,
        AVG(TIMESTAMPDIFF(MINUTE, acknowledged_at, enroute_at)) AS enr,
        AVG(TIMESTAMPDIFF(MINUTE, enroute_at, on_scene_at)) AS scene,
        AVG(TIMESTAMPDIFF(MINUTE, on_scene_at, cleared_at)) AS clr
        FROM dispatches WHERE assigned_at BETWEEN :s AND :e");
    $row->execute([':s'=>$startAt, ':e'=>$endAt]);
    $r = $row->fetch();
    if ($r) {
        $avgAck = isset($r['ack']) && $r['ack'] !== null ? round((float)$r['ack'], 1) : 0.0;
        $avgEnroute = isset($r['enr']) && $r['enr'] !== null ? round((float)$r['enr'], 1) : 0.0;
        $avgOnScene = isset($r['scene']) && $r['scene'] !== null ? round((float)$r['scene'], 1) : 0.0;
        $avgClear = isset($r['clr']) && $r['clr'] !== null ? round((float)$r['clr'], 1) : 0.0;
    }

    // SLA breaches: on_scene took longer than 15 minutes from assigned
    $slaThreshold = 15; // minutes
    $stmt = $pdo->prepare("SELECT 
        SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at) > :t THEN 1 ELSE 0 END) AS breaches,
        COUNT(*) AS total
        FROM dispatches WHERE assigned_at BETWEEN :s AND :e AND on_scene_at IS NOT NULL");
    $stmt->execute([':s'=>$startAt, ':e'=>$endAt, ':t'=>$slaThreshold]);
    $rr = $stmt->fetch();
    $breaches = (int)($rr['breaches'] ?? 0);
    $breachRate = ($rr && ($rr['total'] ?? 0) > 0) ? round(($breaches / (int)$rr['total']) * 100, 1) : 0.0;

    // Dispatches by unit type
    $types = ['ambulance'=>0,'fire'=>0,'police'=>0,'rescue'=>0,'other'=>0];
    $stmt = $pdo->prepare("SELECT u.unit_type, COUNT(*) AS c FROM dispatches d INNER JOIN units u ON u.id = d.unit_id WHERE d.assigned_at BETWEEN :s AND :e GROUP BY u.unit_type");
    $stmt->execute([':s'=>$startAt, ':e'=>$endAt]);
    foreach ($stmt->fetchAll() as $row) {
        $ut = $row['unit_type'] ?? 'other';
        if (!isset($types[$ut])) $ut = 'other';
        $types[$ut] = (int)$row['c'];
    }

    // Top units by dispatch count
    $topUnits = [];
    $stmt = $pdo->prepare("SELECT u.identifier, u.unit_type, COUNT(*) AS c FROM dispatches d INNER JOIN units u ON u.id = d.unit_id WHERE d.assigned_at BETWEEN :s AND :e GROUP BY u.id, u.identifier, u.unit_type ORDER BY c DESC LIMIT 10");
    $stmt->execute([':s'=>$startAt, ':e'=>$endAt]);
    foreach ($stmt->fetchAll() as $row) {
        $topUnits[] = [ 'identifier' => (string)$row['identifier'], 'unit_type' => (string)$row['unit_type'], 'count' => (int)$row['c'] ];
    }

    // Daily volume (labels + counts)
    $labels = []; $data = [];
    $stmt = $pdo->prepare("SELECT DATE(assigned_at) AS d, COUNT(*) AS c FROM dispatches WHERE assigned_at BETWEEN :s AND :e GROUP BY DATE(assigned_at) ORDER BY DATE(assigned_at)");
    $stmt->execute([':s'=>$startAt, ':e'=>$endAt]);
    foreach ($stmt->fetchAll() as $row) { $labels[] = (string)$row['d']; $data[] = (int)$row['c']; }

    echo json_encode([
        'ok' => true,
        'metrics' => [
            'total_dispatches' => $totalDispatches,
            'avg_ack_min' => $avgAck,
            'avg_enroute_min' => $avgEnroute,
            'avg_on_scene_min' => $avgOnScene,
            'avg_clear_min' => $avgClear,
            'sla_breach_count' => $breaches,
            'sla_breach_rate' => $breachRate,
            'by_unit_type' => $types,
        ],
        'top_units' => $topUnits,
        'daily' => [ 'labels' => $labels, 'data' => $data ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Query failed']);
}
