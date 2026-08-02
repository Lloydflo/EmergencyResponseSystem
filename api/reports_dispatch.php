<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/dispatch_attempt_log.php';

header('Content-Type: application/json');
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

[$startAt, $endAt] = period_to_range();

try {
    ers_dispatch_attempt_ensure_table($pdo);

    $typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
    $typeValues = normalized_type_values($typeFilter);
    $failedStaleThresholdMinutes = 15;

    $dispatchJoin = '';
    $dispatchWhere = 'd.assigned_at BETWEEN :s AND :e';
    $dispatchParams = [':s' => $startAt, ':e' => $endAt];
    if ($typeFilter !== '' || $priorityFilter !== '') {
        $dispatchJoin = ' INNER JOIN incidents i ON i.id = d.incident_id';
        append_type_filter($dispatchWhere, $dispatchParams, 'i.type', $typeValues, 'dispatch');
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
    append_type_filter($loadWhere, $loadParams, 'i_load.type', $typeValues, 'load');
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

    $failedLogWhere = 'dal.created_at BETWEEN :fail_s AND :fail_e';
    $failedLogParams = [':fail_s' => $startAt, ':fail_e' => $endAt];
    append_type_filter($failedLogWhere, $failedLogParams, 'i_fail.type', $typeValues, 'failed_log');
    if ($priorityFilter !== '') {
        $failedLogWhere .= ' AND i_fail.priority = :failed_log_prio';
        $failedLogParams[':failed_log_prio'] = $priorityFilter;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM dispatch_attempt_logs dal
        LEFT JOIN incidents i_fail ON i_fail.id = dal.incident_id
        WHERE {$failedLogWhere}
          AND LOWER(COALESCE(dal.status, 'failed')) = 'failed'
    ");
    $stmt->execute($failedLogParams);
    $recordedFailures = (int)($stmt->fetch()['c'] ?? 0);

    $cancelledWhere = "d.status = 'cancelled' AND d.assigned_at BETWEEN :cancel_s AND :cancel_e";
    $cancelledParams = [':cancel_s' => $startAt, ':cancel_e' => $endAt];
    append_type_filter($cancelledWhere, $cancelledParams, 'i_cancel.type', $typeValues, 'cancelled');
    if ($priorityFilter !== '') {
        $cancelledWhere .= ' AND i_cancel.priority = :cancelled_prio';
        $cancelledParams[':cancelled_prio'] = $priorityFilter;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM dispatches d
        LEFT JOIN incidents i_cancel ON i_cancel.id = d.incident_id
        WHERE {$cancelledWhere}
    ");
    $stmt->execute($cancelledParams);
    $cancelledDispatches = (int)($stmt->fetch()['c'] ?? 0);

    $staleWhere = "
        d.status = 'assigned'
        AND d.acknowledged_at IS NULL
        AND d.assigned_at BETWEEN :stale_s AND :stale_e
        AND TIMESTAMPDIFF(MINUTE, d.assigned_at, CURRENT_TIMESTAMP) >= :stale_threshold
    ";
    $staleParams = [
        ':stale_s' => $startAt,
        ':stale_e' => $endAt,
        ':stale_threshold' => $failedStaleThresholdMinutes,
    ];
    append_type_filter($staleWhere, $staleParams, 'i_stale.type', $typeValues, 'stale');
    if ($priorityFilter !== '') {
        $staleWhere .= ' AND i_stale.priority = :stale_prio';
        $staleParams[':stale_prio'] = $priorityFilter;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM dispatches d
        LEFT JOIN incidents i_stale ON i_stale.id = d.incident_id
        WHERE {$staleWhere}
    ");
    $stmt->execute($staleParams);
    $staleUnacknowledged = (int)($stmt->fetch()['c'] ?? 0);

    $failedAttempts = [];
    $stmt = $pdo->prepare("
        SELECT
            dal.id,
            dal.incident_id,
            i_fail.reference_no,
            i_fail.type AS incident_type,
            i_fail.priority,
            i_fail.location_address,
            dal.unit_id,
            COALESCE(NULLIF(dal.unit_identifier, ''), u_fail.identifier, '') AS unit_identifier,
            COALESCE(u_fail.unit_type, '') AS unit_type,
            dal.source,
            dal.failure_reason,
            dal.recovery_status,
            dal.recovery_action,
            dal.recovered_dispatch_id,
            dal.recovered_at,
            dal.created_at AS attempted_at,
            'recorded_failure' AS failure_kind
        FROM dispatch_attempt_logs dal
        LEFT JOIN incidents i_fail ON i_fail.id = dal.incident_id
        LEFT JOIN units u_fail ON u_fail.id = dal.unit_id
        WHERE {$failedLogWhere}
          AND LOWER(COALESCE(dal.status, 'failed')) = 'failed'
        ORDER BY dal.created_at DESC, dal.id DESC
        LIMIT 40
    ");
    $stmt->execute($failedLogParams);
    foreach ($stmt->fetchAll() as $row) {
        $recoveryStatus = strtolower(trim((string)($row['recovery_status'] ?? 'open')));
        if ($recoveryStatus === '') {
            $recoveryStatus = 'open';
        }
        $recoveryActions = [];
        if ($recoveryStatus === 'open') {
            if ((int)($row['incident_id'] ?? 0) > 0) {
                $recoveryActions[] = 'retry_same_unit';
            }
            $recoveryActions[] = 'close_failure';
        }
        $failedAttempts[] = [
            'id' => (int)$row['id'],
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'incident_type' => (string)($row['incident_type'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
            'location' => (string)($row['location_address'] ?? ''),
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'unit_identifier' => (string)($row['unit_identifier'] ?? ''),
            'unit_type' => (string)($row['unit_type'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'failure_reason' => (string)($row['failure_reason'] ?? ''),
            'attempted_at' => (string)($row['attempted_at'] ?? ''),
            'failure_kind' => (string)($row['failure_kind'] ?? 'recorded_failure'),
            'recovery_status' => $recoveryStatus,
            'recovery_action' => (string)($row['recovery_action'] ?? ''),
            'recovered_dispatch_id' => (int)($row['recovered_dispatch_id'] ?? 0),
            'recovered_at' => (string)($row['recovered_at'] ?? ''),
            'recovery_actions' => $recoveryActions,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.incident_id,
            i_stale.reference_no,
            i_stale.type AS incident_type,
            i_stale.priority,
            i_stale.location_address,
            d.unit_id,
            u_stale.identifier AS unit_identifier,
            u_stale.unit_type,
            d.assigned_at AS attempted_at,
            CASE WHEN d.status = 'cancelled'
                THEN 'Dispatch was cancelled before completion'
                ELSE CONCAT('No acknowledgement after ', :label_threshold, ' minutes')
            END AS failure_reason,
            CASE WHEN d.status = 'cancelled' THEN 'cancelled_dispatch' ELSE 'stale_unacknowledged' END AS failure_kind
        FROM dispatches d
        LEFT JOIN incidents i_cancel ON i_cancel.id = d.incident_id
        LEFT JOIN incidents i_stale ON i_stale.id = d.incident_id
        LEFT JOIN units u_stale ON u_stale.id = d.unit_id
        WHERE ({$cancelledWhere}) OR ({$staleWhere})
        ORDER BY d.assigned_at DESC, d.id DESC
        LIMIT 40
    ");
    $derivedParams = $cancelledParams + $staleParams;
    $derivedParams[':label_threshold'] = $failedStaleThresholdMinutes;
    $stmt->execute($derivedParams);
    foreach ($stmt->fetchAll() as $row) {
        $failedAttempts[] = [
            'id' => (int)$row['id'],
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'incident_type' => (string)($row['incident_type'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
            'location' => (string)($row['location_address'] ?? ''),
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'unit_identifier' => (string)($row['unit_identifier'] ?? ''),
            'unit_type' => (string)($row['unit_type'] ?? ''),
            'source' => 'dispatches',
            'failure_reason' => (string)($row['failure_reason'] ?? ''),
            'attempted_at' => (string)($row['attempted_at'] ?? ''),
            'failure_kind' => (string)($row['failure_kind'] ?? ''),
            'recovery_status' => (string)($row['failure_kind'] ?? '') === 'stale_unacknowledged' ? 'open' : 'closed',
            'recovery_action' => '',
            'recovered_dispatch_id' => 0,
            'recovered_at' => '',
            'recovery_actions' => (string)($row['failure_kind'] ?? '') === 'stale_unacknowledged' ? ['cancel_dispatch'] : [],
        ];
    }

    usort($failedAttempts, static function (array $a, array $b): int {
        return strcmp((string)($b['attempted_at'] ?? ''), (string)($a['attempted_at'] ?? ''));
    });
    $failedAttempts = array_slice($failedAttempts, 0, 50);
    $failedAttemptTotal = $recordedFailures + $cancelledDispatches + $staleUnacknowledged;
    $attemptDenominator = $totalDispatches + $recordedFailures;
    $failureRate = $attemptDenominator > 0
        ? round(($failedAttemptTotal / $attemptDenominator) * 100, 1)
        : 0.0;

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
            'failed_attempts_total' => $failedAttemptTotal,
            'failed_attempt_rate' => $failureRate,
            'recorded_failed_attempts' => $recordedFailures,
            'cancelled_dispatches' => $cancelledDispatches,
            'stale_unacknowledged_dispatches' => $staleUnacknowledged,
            'stale_threshold_min' => $failedStaleThresholdMinutes,
        ],
        'summary_by_service' => $serviceSummary,
        'top_units' => $topUnits,
        'all_units' => $allUnits,
        'failed_attempts' => $failedAttempts,
        'daily' => ['labels' => $labels, 'data' => $data]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
