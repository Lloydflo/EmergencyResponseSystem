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

function period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : '';
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start . ' 00:00:00', $end . ' 23:59:59'];
    }

    $today = new DateTime('today');
    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d') . ' 00:00:00', $today->format('Y-m-d') . ' 23:59:59'];
        case 'week':
            $rangeStart = (clone $today)->modify('monday this week');
            $rangeEnd = (clone $rangeStart)->modify('+6 days');
            break;
        case 'quarter':
            $month = (int)$today->format('n');
            $quarterStartMonth = [1 => 1, 2 => 4, 3 => 7, 4 => 10][intdiv($month - 1, 3) + 1];
            $rangeStart = new DateTime($today->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $rangeEnd = (clone $rangeStart)->modify('+3 months -1 day');
            break;
        case 'year':
            $rangeStart = new DateTime($today->format('Y-01-01'));
            $rangeEnd = new DateTime($today->format('Y-12-31'));
            break;
        case 'month':
        default:
            $rangeStart = new DateTime($today->format('Y-m-01'));
            $rangeEnd = (clone $rangeStart)->modify('+1 month -1 day');
            break;
    }

    return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59'];
}

[$startAt, $endAt] = period_to_range();

try {
    $typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
    if ($typeFilter === 'accident') { $typeFilter = 'traffic'; }
    if ($typeFilter === 'crime') { $typeFilter = 'police'; }

    $dispatchJoin = '';
    $dispatchWhere = 'd.assigned_at BETWEEN :s AND :e';
    $dispatchParams = [':s' => $startAt, ':e' => $endAt];
    if ($typeFilter !== '' || $priorityFilter !== '') {
        $dispatchJoin = ' INNER JOIN incidents i ON i.id = d.incident_id';
        if ($typeFilter !== '') {
            $dispatchWhere .= ' AND i.type = :type';
            $dispatchParams[':type'] = $typeFilter;
        }
        if ($priorityFilter !== '') {
            $dispatchWhere .= ' AND i.priority = :prio';
            $dispatchParams[':prio'] = $priorityFilter;
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM dispatches d{$dispatchJoin} WHERE {$dispatchWhere}");
    $stmt->execute($dispatchParams);
    $totalDispatches = (int)($stmt->fetch()['c'] ?? 0);

    $avgAck = 0.0;
    $avgEnroute = 0.0;
    $avgOnScene = 0.0;
    $avgClear = 0.0;
    $row = $pdo->prepare("
        SELECT
            AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, d.acknowledged_at)) AS ack,
            AVG(TIMESTAMPDIFF(MINUTE, d.acknowledged_at, d.enroute_at)) AS enr,
            AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))) AS scene,
            AVG(TIMESTAMPDIFF(MINUTE, COALESCE(d.on_scene_at, d.assigned_at), d.cleared_at)) AS clr
        FROM dispatches d{$dispatchJoin}
        WHERE {$dispatchWhere}
    ");
    $row->execute($dispatchParams);
    $metricsRow = $row->fetch();
    if ($metricsRow) {
        $avgAck = isset($metricsRow['ack']) && $metricsRow['ack'] !== null ? round((float)$metricsRow['ack'], 1) : 0.0;
        $avgEnroute = isset($metricsRow['enr']) && $metricsRow['enr'] !== null ? round((float)$metricsRow['enr'], 1) : 0.0;
        $avgOnScene = isset($metricsRow['scene']) && $metricsRow['scene'] !== null ? round((float)$metricsRow['scene'], 1) : 0.0;
        $avgClear = isset($metricsRow['clr']) && $metricsRow['clr'] !== null ? round((float)$metricsRow['clr'], 1) : 0.0;
    }

    $slaThreshold = 15;
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at)) > :t THEN 1 ELSE 0 END) AS breaches,
            COUNT(*) AS total
        FROM dispatches d{$dispatchJoin}
        WHERE {$dispatchWhere} AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
    ");
    $breachParams = $dispatchParams;
    $breachParams[':t'] = $slaThreshold;
    $stmt->execute($breachParams);
    $breachRow = $stmt->fetch();
    $breaches = (int)($breachRow['breaches'] ?? 0);
    $breachRate = ($breachRow && (int)($breachRow['total'] ?? 0) > 0)
        ? round(($breaches / (int)$breachRow['total']) * 100, 1)
        : 0.0;

    // The dispatch load chart should reflect units that are currently carrying
    // active work, even if those dispatches were assigned before the selected
    // report window. This keeps the chart aligned with the dispatcher's live
    // queue of pending/dispatched incidents.
    $loadWhere = '
        (
            d.assigned_at BETWEEN :load_s AND :load_e
            OR d.status IN ("assigned", "acknowledged", "enroute", "on_scene")
            OR i_load.status IN ("pending", "dispatched", "active", "in_progress")
        )
    ';
    $loadParams = [
        ':load_s' => $startAt,
        ':load_e' => $endAt,
    ];
    if ($typeFilter !== '') {
        $loadWhere .= ' AND i_load.type = :type';
        $loadParams[':type'] = $typeFilter;
    }
    if ($priorityFilter !== '') {
        $loadWhere .= ' AND i_load.priority = :prio';
        $loadParams[':prio'] = $priorityFilter;
    }

    $types = ['ambulance' => 0, 'fire' => 0, 'police' => 0, 'rescue' => 0, 'other' => 0];
    $stmt = $pdo->prepare("
        SELECT u.unit_type, COUNT(*) AS c
        FROM dispatches d
        INNER JOIN units u ON u.id = d.unit_id
        INNER JOIN incidents i_load ON i_load.id = d.incident_id
        WHERE {$loadWhere}
        GROUP BY u.unit_type
    ");
    $stmt->execute($loadParams);
    foreach ($stmt->fetchAll() as $row) {
        $unitType = (string)($row['unit_type'] ?? 'other');
        if (!isset($types[$unitType])) {
            $unitType = 'other';
        }
        $types[$unitType] = (int)$row['c'];
    }

    $topUnits = [];
    $stmt = $pdo->prepare("
        SELECT u.identifier, u.unit_type, COUNT(*) AS c
        FROM dispatches d
        INNER JOIN units u ON u.id = d.unit_id
        INNER JOIN incidents i_load ON i_load.id = d.incident_id
        WHERE {$loadWhere}
        GROUP BY u.id, u.identifier, u.unit_type
        ORDER BY c DESC, u.identifier ASC
        LIMIT 10
    ");
    $stmt->execute($loadParams);
    foreach ($stmt->fetchAll() as $row) {
        $topUnits[] = [
            'identifier' => (string)$row['identifier'],
            'unit_type' => (string)$row['unit_type'],
            'count' => (int)$row['c'],
        ];
    }

    $serviceSummary = [
        'ambulance' => 0,
        'fire' => 0,
        'police' => 0,
        'traffic' => 0,
    ];
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN i_load.type = 'medical' THEN 'ambulance'
                WHEN i_load.type = 'fire' THEN 'fire'
                WHEN i_load.type IN ('police', 'crime') THEN 'police'
                WHEN i_load.type IN ('traffic', 'accident') THEN 'traffic'
                ELSE 'other'
            END AS service_key,
            COUNT(*) AS c
        FROM dispatches d
        INNER JOIN incidents i_load ON i_load.id = d.incident_id
        WHERE {$loadWhere}
        GROUP BY service_key
    ");
    $stmt->execute($loadParams);
    foreach ($stmt->fetchAll() as $row) {
        $serviceKey = (string)($row['service_key'] ?? 'other');
        if (isset($serviceSummary[$serviceKey])) {
            $serviceSummary[$serviceKey] = (int)$row['c'];
        }
    }

    $allUnits = [];
    $unitSummarySql = "
        SELECT
            u.identifier,
            u.unit_type,
            COALESCE(dispatch_counts.c, 0) AS c
        FROM units u
        LEFT JOIN (
            SELECT d.unit_id, COUNT(*) AS c
            FROM dispatches d
            INNER JOIN incidents i_load ON i_load.id = d.incident_id
            WHERE {$loadWhere}
            GROUP BY d.unit_id
        ) dispatch_counts ON dispatch_counts.unit_id = u.id
        ORDER BY c DESC, u.identifier ASC
    ";
    $stmt = $pdo->prepare($unitSummarySql);
    $stmt->execute($loadParams);
    foreach ($stmt->fetchAll() as $row) {
        $allUnits[] = [
            'identifier' => (string)$row['identifier'],
            'unit_type' => (string)$row['unit_type'],
            'count' => (int)$row['c'],
        ];
    }

    $labels = [];
    $data = [];
    $stmt = $pdo->prepare("
        SELECT DATE(d.assigned_at) AS d, COUNT(*) AS c
        FROM dispatches d{$dispatchJoin}
        WHERE {$dispatchWhere}
        GROUP BY DATE(d.assigned_at)
        ORDER BY DATE(d.assigned_at)
    ");
    $stmt->execute($dispatchParams);
    foreach ($stmt->fetchAll() as $row) {
        $labels[] = (string)$row['d'];
        $data[] = (int)$row['c'];
    }

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
        'summary_by_service' => $serviceSummary,
        'top_units' => $topUnits,
        'all_units' => $allUnits,
        'daily' => ['labels' => $labels, 'data' => $data]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
