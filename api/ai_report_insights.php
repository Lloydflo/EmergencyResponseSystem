<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

try {
    $totalIncidentsMonth = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch()['c'];
    $resolvedCountMonth = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status='resolved' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch()['c'];
    $totalUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units")->fetch()['c'];
    $busyUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','enroute','on_scene','acknowledged')")->fetch()['c'];
    $activeResponders = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];

    $successRate = $totalIncidentsMonth > 0 ? round(($resolvedCountMonth / $totalIncidentsMonth) * 100, 1) : 0.0;
    $resourceUtilization = $totalUnits > 0 ? round(($busyUnits / $totalUnits) * 100, 1) : 0.0;

    $avgResponseTime = 0.0;
    $row = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at)) AS avg_min
                        FROM dispatches
                        WHERE assigned_at IS NOT NULL
                          AND on_scene_at IS NOT NULL
                          AND YEAR(assigned_at)=YEAR(CURDATE())
                          AND MONTH(assigned_at)=MONTH(CURDATE())")->fetch();
    if ($row && $row['avg_min'] !== null) {
        $avgResponseTime = round((float)$row['avg_min'], 1);
    }

    $reportData = [
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

