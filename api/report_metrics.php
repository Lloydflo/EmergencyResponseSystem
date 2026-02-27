<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

// Determine period range (supports period or custom start/end)
function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : 'month';
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start . ' 00:00:00', $end . ' 23:59:59', 'custom'];
    }
    $today = new DateTime('today');
    switch ($period) {
        case 'today': {
            $s = $today->format('Y-m-d') . ' 00:00:00';
            $e = $today->format('Y-m-d') . ' 23:59:59';
            return [$s, $e, 'today'];
        }
        case 'week': {
            $start = (clone $today)->modify('monday this week');
            $end = (clone $start)->modify('+6 days');
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'week'];
        }
        case 'quarter': {
            $m = (int)$today->format('n'); $q = intdiv($m - 1, 3) + 1; $qm = [1=>1,2=>4,3=>7,4=>10][$q];
            $start = new DateTime($today->format('Y') . '-' . str_pad((string)$qm, 2, '0', STR_PAD_LEFT) . '-01');
            $end = (clone $start)->modify('+3 months -1 day');
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'quarter'];
        }
        case 'year': {
            $start = new DateTime($today->format('Y-01-01'));
            $end = new DateTime($today->format('Y-12-31'));
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'year'];
        }
        case 'month': default: {
            $start = new DateTime($today->format('Y-m-01'));
            $end = (clone $start)->modify('+1 month -1 day');
            return [$start->format('Y-m-d') . ' 00:00:00', $end->format('Y-m-d') . ' 23:59:59', 'month'];
        }
    }
}

function previous_range(string $kind, string $startAt, string $endAt): array {
    $s = new DateTime($startAt); $e = new DateTime($endAt);
    switch ($kind) {
        case 'today':
            $ps = (clone $s)->modify('-1 day'); $pe = (clone $e)->modify('-1 day'); break;
        case 'week':
            $ps = (clone $s)->modify('-7 days'); $pe = (clone $e)->modify('-7 days'); break;
        case 'quarter':
            $ps = (clone $s)->modify('-3 months'); $pe = (clone $e)->modify('-3 months'); break;
        case 'year':
            $ps = (clone $s)->modify('-1 year'); $pe = (clone $e)->modify('-1 year'); break;
        case 'month':
            $ps = (clone $s)->modify('-1 month'); $pe = (clone $e)->modify('-1 month'); break;
        case 'custom': default:
            $diff = $e->getTimestamp() - $s->getTimestamp();
            $ps = (clone $s)->modify('-' . max(1, (int)ceil($diff / 86400)) . ' days');
            $pe = (clone $s)->modify('-1 day');
            break;
    }
    return [$ps->format('Y-m-d H:i:s'), $pe->format('Y-m-d H:i:s')];
}

try {
    [$startAt, $endAt, $kind] = period_to_range();
    [$prevStartAt, $prevEndAt] = previous_range($kind, $startAt, $endAt);

    // Optional filters
    $typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';

    // Totals for period and previous period
    $sqlIncBase = 'FROM incidents WHERE created_at BETWEEN :s AND :e';
    $paramsInc = [':s' => $startAt, ':e' => $endAt];
    if ($typeFilter !== '') { $sqlIncBase .= ' AND type = :type'; $paramsInc[':type'] = $typeFilter; }
    if ($priorityFilter !== '') { $sqlIncBase .= ' AND priority = :prio'; $paramsInc[':prio'] = $priorityFilter; }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS c ' . $sqlIncBase);
    $stmt->execute($paramsInc);
    $total_incidents_month = (int)($stmt->fetch()['c'] ?? 0);

    $paramsPrev = [':s' => $prevStartAt, ':e' => $prevEndAt];
    $sqlPrev = 'FROM incidents WHERE created_at BETWEEN :s AND :e';
    if ($typeFilter !== '') { $sqlPrev .= ' AND type = :type'; $paramsPrev[':type'] = $typeFilter; }
    if ($priorityFilter !== '') { $sqlPrev .= ' AND priority = :prio'; $paramsPrev[':prio'] = $priorityFilter; }
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c ' . $sqlPrev);
    $stmt->execute($paramsPrev);
    $total_incidents_last_month = (int)($stmt->fetch()['c'] ?? 0);

    // Calls in period
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM calls WHERE received_at BETWEEN :s AND :e');
    $stmt->execute([':s' => $startAt, ':e' => $endAt]);
    $total_calls_today = (int)($stmt->fetch()['c'] ?? 0);

    // Success rate: resolved / total within period
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN status = "resolved" THEN 1 ELSE 0 END) AS resolved ' . $sqlIncBase);
    $stmt->execute($paramsInc);
    $r = $stmt->fetch();
    $total_incidents = (int)($r['total'] ?? 0);
    $resolved_count = (int)($r['resolved'] ?? 0);
    $success_rate = $total_incidents > 0 ? round(($resolved_count / $total_incidents) * 100, 1) : 0.0;

    // Resource utilization: current snapshot (units busy / total)
    $total_units = (int)$pdo->query('SELECT COUNT(*) AS c FROM units')->fetch()['c'];
    $busy_units = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','acknowledged','enroute','on_scene')")
        ->fetch()['c'];
    $resource_utilization = $total_units > 0 ? round(($busy_units / $total_units) * 100, 1) : 0.0;

    // Avg response time (minutes): assigned -> on_scene within period
    $avg_response_time = 0.0;
    $sqlDisp = 'FROM dispatches WHERE assigned_at IS NOT NULL AND on_scene_at IS NOT NULL AND assigned_at BETWEEN :s AND :e';
    if ($typeFilter !== '') {
        // Limit by incident type via join
        $stmt = $pdo->prepare('SELECT AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, d.on_scene_at)) AS avg_min 
            FROM dispatches d INNER JOIN incidents i ON i.id = d.incident_id 
            WHERE d.assigned_at BETWEEN :s AND :e AND d.on_scene_at IS NOT NULL AND i.type = :type');
        $stmt->execute([':s' => $startAt, ':e' => $endAt, ':type' => $typeFilter]);
    } else {
        $stmt = $pdo->prepare('SELECT AVG(TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at)) AS avg_min ' . $sqlDisp);
        $stmt->execute([':s' => $startAt, ':e' => $endAt]);
    }
    $row = $stmt->fetch();
    if ($row && $row['avg_min'] !== null) {
        $avg_response_time = round((float)$row['avg_min'], 1);
    }

    // Incidents by priority within period
    $priorityCounts = [ 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 ];
    $sqlP = 'SELECT priority, COUNT(*) AS c ' . $sqlIncBase . ' GROUP BY priority';
    $stmt = $pdo->prepare($sqlP);
    $stmt->execute($paramsInc);
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower((string)$r['priority']);
        if (isset($priorityCounts[$p])) {
            $priorityCounts[$p] = (int)$r['c'];
        }
    }

    // Incidents by type within period (normalize accident->traffic, crime->police)
    $typeCounts = [ 'medical' => 0, 'fire' => 0, 'police' => 0, 'traffic' => 0, 'other' => 0 ];
    $sqlT = 'SELECT type, COUNT(*) AS c ' . $sqlIncBase . ' GROUP BY type';
    $stmt = $pdo->prepare($sqlT);
    $stmt->execute($paramsInc);
    foreach ($stmt->fetchAll() as $r) {
        $t = strtolower((string)$r['type']);
        if ($t === 'accident') $t = 'traffic';
        if ($t === 'crime') $t = 'police';
        if (isset($typeCounts[$t])) { $typeCounts[$t] += (int)$r['c']; }
        else { $typeCounts['other'] += (int)$r['c']; }
    }

    echo json_encode([
        'ok' => true,
        'metrics' => [
            'total_calls_today' => $total_calls_today,
            'total_incidents_month' => $total_incidents_month,
            'total_incidents_last_month' => $total_incidents_last_month,
            'success_rate' => $success_rate,
            'resource_utilization' => $resource_utilization,
            'avg_response_time_min' => $avg_response_time,
            'incidents_by_priority' => $priorityCounts,
            'incidents_by_type' => $typeCounts,
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
