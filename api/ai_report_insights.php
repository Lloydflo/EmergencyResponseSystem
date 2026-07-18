<?php
require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

header('Content-Type: application/json');
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function ai_report_period_to_range(): array {
    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : 'month';
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start . ' 00:00:00', $end . ' 23:59:59', 'Custom'];
    }

    $today = new DateTime('today');
    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d') . ' 00:00:00', $today->format('Y-m-d') . ' 23:59:59', 'Today'];
        case 'week':
            $rangeStart = (clone $today)->modify('monday this week');
            $rangeEnd = (clone $rangeStart)->modify('+6 days');
            return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59', 'This Week'];
        case 'quarter':
            $month = (int)$today->format('n');
            $quarterStartMonth = [1 => 1, 2 => 4, 3 => 7, 4 => 10][intdiv($month - 1, 3) + 1];
            $rangeStart = new DateTime($today->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $rangeEnd = (clone $rangeStart)->modify('+3 months -1 day');
            return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59', 'This Quarter'];
        case 'year':
            $rangeStart = new DateTime($today->format('Y-01-01'));
            $rangeEnd = new DateTime($today->format('Y-12-31'));
            return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59', 'This Year'];
        case 'month':
        default:
            $rangeStart = new DateTime($today->format('Y-m-01'));
            $rangeEnd = (clone $rangeStart)->modify('+1 month -1 day');
            return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59', 'This Month'];
    }
}

function ai_report_normalized_type_values(string $typeFilter): array {
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

function ai_report_append_type_filter(string &$sql, array &$params, string $column, array $typeValues, string $prefix): void {
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
    [$startAt, $endAt, $periodLabel] = ai_report_period_to_range();
    $typeFilter = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
    $typeValues = ai_report_normalized_type_values($typeFilter);

    $incidentActivityWhere = "
        (
            i.created_at BETWEEN :start AND :end
            OR (i.updated_at IS NOT NULL AND i.updated_at BETWEEN :updated_start AND :updated_end)
            OR EXISTS (
                SELECT 1
                FROM dispatches d_window
                WHERE d_window.incident_id = i.id
                  AND d_window.assigned_at BETWEEN :dispatch_start AND :dispatch_end
            )
        )
    ";
    $incidentSql = "FROM incidents i WHERE {$incidentActivityWhere}";
    $incidentParams = [
        ':start' => $startAt,
        ':end' => $endAt,
        ':updated_start' => $startAt,
        ':updated_end' => $endAt,
        ':dispatch_start' => $startAt,
        ':dispatch_end' => $endAt,
    ];
    ai_report_append_type_filter($incidentSql, $incidentParams, 'i.type', $typeValues, 'incident');
    if ($priorityFilter !== '') {
        $incidentSql .= ' AND i.priority = :priority';
        $incidentParams[':priority'] = $priorityFilter;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN i.status = "resolved" THEN 1 ELSE 0 END) AS resolved ' . $incidentSql);
    $stmt->execute($incidentParams);
    $incidentRow = $stmt->fetch() ?: [];
    $totalIncidentsMonth = (int)($incidentRow['total'] ?? 0);
    $resolvedCountMonth = (int)($incidentRow['resolved'] ?? 0);

    $totalUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units")->fetch()['c'];
    $busyUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','enroute','on_scene','acknowledged')")->fetch()['c'];
    $activeResponders = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];

    $successRate = $totalIncidentsMonth > 0 ? round(($resolvedCountMonth / $totalIncidentsMonth) * 100, 1) : 0.0;
    $resourceUtilization = $totalUnits > 0 ? round(($busyUnits / $totalUnits) * 100, 1) : 0.0;

    $avgResponseTime = 0.0;
    $responseSql = "
        SELECT AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))) AS avg_min
        FROM dispatches d
        INNER JOIN incidents i ON i.id = d.incident_id
        WHERE d.assigned_at BETWEEN :start AND :end
          AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
    ";
    $responseParams = [
        ':start' => $startAt,
        ':end' => $endAt,
    ];
    ai_report_append_type_filter($responseSql, $responseParams, 'i.type', $typeValues, 'response');
    if ($priorityFilter !== '') {
        $responseSql .= ' AND i.priority = :priority';
        $responseParams[':priority'] = $priorityFilter;
    }
    $stmt = $pdo->prepare($responseSql);
    $stmt->execute($responseParams);
    $row = $stmt->fetch();
    if ($row && $row['avg_min'] !== null) {
        $avgResponseTime = round((float)$row['avg_min'], 1);
    }

    $reportData = [
        'period' => $periodLabel . ' (' . substr($startAt, 0, 10) . ' to ' . substr($endAt, 0, 10) . ')',
        'filters' => trim(($typeFilter !== '' ? 'Type: ' . $typeFilter . '; ' : '') . ($priorityFilter !== '' ? 'Priority: ' . $priorityFilter : '')) ?: 'All categories and priorities',
        'total_incidents' => $totalIncidentsMonth,
        'avg_response_time' => $avgResponseTime . ' minutes',
        'resource_utilization' => $resourceUtilization . '%',
        'active_responders' => $activeResponders,
        'resolved_incidents' => $resolvedCountMonth,
        'success_rate' => $successRate . '%',
    ];

    $text = generateReportInsights($reportData);
    if ($text) {
        echo json_encode(['ok' => true, 'text' => $text]);
        exit;
    }

    $error = function_exists('getGeminiLastError') ? trim((string)getGeminiLastError()) : '';
    echo json_encode(['ok' => false, 'error' => $error !== '' ? $error : 'AI unavailable']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
