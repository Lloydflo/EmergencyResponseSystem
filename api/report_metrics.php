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

function normalized_type_values(string $typeFilter): array {
    $typeFilter = strtolower(trim($typeFilter));
    if ($typeFilter === '') {
        return [];
    }
    if ($typeFilter === 'traffic' || $typeFilter === 'accident') {
        return ['traffic', 'accident'];
    }
    if ($typeFilter === 'police' || $typeFilter === 'crime') {
        return ['police', 'crime'];
    }
    return [$typeFilter];
}

function append_type_filter(string &$sql, array &$params, string $column, array $typeValues, string $prefix): void {
    if (!$typeValues) {
        return;
    }
    $placeholders = [];
    foreach ($typeValues as $index => $value) {
        $placeholder = ':' . $prefix . '_type_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }
    $sql .= ' AND LOWER(' . $column . ') IN (' . implode(', ', $placeholders) . ')';
}

try {
    [$startAt, $endAt, $kind] = period_to_range();
    [$prevStartAt, $prevEndAt] = previous_range($kind, $startAt, $endAt);

    // Optional filters
    $typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
    $typeValues = normalized_type_values($typeFilter);

    // Totals for period and previous period.
    // Treat an incident as part of the selected window if it was created,
    // updated, or dispatched during that period so incident charts stay in
    // sync with the dispatch-based charts.
    $incidentActivityWhere = "
        (
            i.created_at BETWEEN :s AND :e
            OR (i.updated_at IS NOT NULL AND i.updated_at BETWEEN :us AND :ue)
            OR EXISTS (
                SELECT 1
                FROM dispatches d_window
                WHERE d_window.incident_id = i.id
                  AND d_window.assigned_at BETWEEN :ds AND :de
            )
        )
    ";
    $sqlIncBase = 'FROM incidents i WHERE ' . $incidentActivityWhere;
    $paramsInc = [
        ':s' => $startAt,
        ':e' => $endAt,
        ':us' => $startAt,
        ':ue' => $endAt,
        ':ds' => $startAt,
        ':de' => $endAt,
    ];
    append_type_filter($sqlIncBase, $paramsInc, 'i.type', $typeValues, 'inc');
    if ($priorityFilter !== '') { $sqlIncBase .= ' AND i.priority = :prio'; $paramsInc[':prio'] = $priorityFilter; }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS c ' . $sqlIncBase);
    $stmt->execute($paramsInc);
    $total_incidents_month = (int)($stmt->fetch()['c'] ?? 0);

    $incidentActivityWherePrev = "
        (
            i.created_at BETWEEN :s AND :e
            OR (i.updated_at IS NOT NULL AND i.updated_at BETWEEN :us AND :ue)
            OR EXISTS (
                SELECT 1
                FROM dispatches d_window
                WHERE d_window.incident_id = i.id
                  AND d_window.assigned_at BETWEEN :ds AND :de
            )
        )
    ";
    $paramsPrev = [
        ':s' => $prevStartAt,
        ':e' => $prevEndAt,
        ':us' => $prevStartAt,
        ':ue' => $prevEndAt,
        ':ds' => $prevStartAt,
        ':de' => $prevEndAt,
    ];
    $sqlPrev = 'FROM incidents i WHERE ' . $incidentActivityWherePrev;
    append_type_filter($sqlPrev, $paramsPrev, 'i.type', $typeValues, 'prev');
    if ($priorityFilter !== '') { $sqlPrev .= ' AND i.priority = :prio'; $paramsPrev[':prio'] = $priorityFilter; }
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

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN status = "resolved" THEN 1 ELSE 0 END) AS resolved ' . $sqlPrev);
    $stmt->execute($paramsPrev);
    $prevSuccessRow = $stmt->fetch();
    $prev_total_incidents = (int)($prevSuccessRow['total'] ?? 0);
    $prev_resolved_count = (int)($prevSuccessRow['resolved'] ?? 0);
    $previous_success_rate = $prev_total_incidents > 0 ? round(($prev_resolved_count / $prev_total_incidents) * 100, 1) : null;

    // Resource utilization: current snapshot (units busy / total)
    $total_units = (int)$pdo->query('SELECT COUNT(*) AS c FROM units')->fetch()['c'];
    $busy_units = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','acknowledged','enroute','on_scene')")
        ->fetch()['c'];
    $resource_utilization = $total_units > 0 ? round(($busy_units / $total_units) * 100, 1) : 0.0;

    // Avg response time (minutes): dispatch assigned -> on-scene/cleared.
    $avg_response_time = 0.0;
    $sqlResp = '
        SELECT
            AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))) AS avg_min,
            COUNT(*) AS sample_count
        FROM dispatches d
        INNER JOIN incidents i ON i.id = d.incident_id
        WHERE d.assigned_at BETWEEN :s AND :e
          AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
    ';
    $paramsResp = [':s' => $startAt, ':e' => $endAt];
    append_type_filter($sqlResp, $paramsResp, 'i.type', $typeValues, 'resp');
    if ($priorityFilter !== '') {
        $sqlResp .= ' AND i.priority = :prio';
        $paramsResp[':prio'] = $priorityFilter;
    }
    $stmt = $pdo->prepare($sqlResp);
    $stmt->execute($paramsResp);
    $row = $stmt->fetch();
    $avg_response_sample_count = (int)($row['sample_count'] ?? 0);
    if ($row && $row['avg_min'] !== null) {
        $avg_response_time = round((float)$row['avg_min'], 1);
    }

    $previous_avg_response_time = null;
    $sqlPrevResp = '
        SELECT
            AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))) AS avg_min,
            COUNT(*) AS sample_count
        FROM dispatches d
        INNER JOIN incidents i ON i.id = d.incident_id
        WHERE d.assigned_at BETWEEN :s AND :e
          AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
    ';
    $paramsPrevResp = [':s' => $prevStartAt, ':e' => $prevEndAt];
    append_type_filter($sqlPrevResp, $paramsPrevResp, 'i.type', $typeValues, 'prev_resp');
    if ($priorityFilter !== '') {
        $sqlPrevResp .= ' AND i.priority = :prio';
        $paramsPrevResp[':prio'] = $priorityFilter;
    }
    $stmt = $pdo->prepare($sqlPrevResp);
    $stmt->execute($paramsPrevResp);
    $prevRespRow = $stmt->fetch();
    $previous_avg_response_sample_count = (int)($prevRespRow['sample_count'] ?? 0);
    if ($prevRespRow && $prevRespRow['avg_min'] !== null) {
        $previous_avg_response_time = round((float)$prevRespRow['avg_min'], 1);
    }

    // Chart counts should also include incidents that are still open right now,
    // even if they were created before the selected date range. This keeps the
    // report workload charts aligned with the dispatcher's active incident view.
    $chartIncidentWhere = '
        (
            ' . $incidentActivityWhere . '
            OR i.status IN ("pending", "dispatched", "active", "in_progress")
        )
    ';
    $sqlChartBase = 'FROM incidents i WHERE ' . $chartIncidentWhere;
    append_type_filter($sqlChartBase, $paramsInc, 'i.type', $typeValues, 'chart');
    if ($priorityFilter !== '') {
        $sqlChartBase .= ' AND i.priority = :prio';
    }

    // Incidents by priority within the report scope plus current open incidents.
    $priorityCounts = [ 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 ];
    $sqlP = 'SELECT i.priority, COUNT(*) AS c ' . $sqlChartBase . ' GROUP BY i.priority';
    $stmt = $pdo->prepare($sqlP);
    $stmt->execute($paramsInc);
    foreach ($stmt->fetchAll() as $r) {
        $p = strtolower((string)$r['priority']);
        if (isset($priorityCounts[$p])) {
            $priorityCounts[$p] = (int)$r['c'];
        }
    }

    // Incidents by type within the report scope plus current open incidents
    // (normalize accident->traffic, crime->police).
    $typeCounts = [ 'medical' => 0, 'fire' => 0, 'police' => 0, 'traffic' => 0, 'other' => 0 ];
    $sqlT = 'SELECT i.type, COUNT(*) AS c ' . $sqlChartBase . ' GROUP BY i.type';
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
            'previous_success_rate' => $previous_success_rate,
            'resource_utilization' => $resource_utilization,
            'avg_response_time_min' => $avg_response_time,
            'previous_avg_response_time_min' => $previous_avg_response_time,
            'avg_response_sample_count' => $avg_response_sample_count,
            'previous_avg_response_sample_count' => $previous_avg_response_sample_count,
            'incidents_by_priority' => $priorityCounts,
            'incidents_by_type' => $typeCounts,
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
