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

try {
    // Last 14 days average response time (assigned -> on_scene) per day
    $stmt = $pdo->query(
        "SELECT DATE(assigned_at) AS d, ROUND(AVG(TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at)), 1) AS avg_min
         FROM dispatches
         WHERE assigned_at IS NOT NULL AND on_scene_at IS NOT NULL
           AND assigned_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
         GROUP BY DATE(assigned_at)
         ORDER BY DATE(assigned_at) ASC"
    );
    $rows = $stmt->fetchAll();

    // Build contiguous date series for last 14 days
    $labels = [];
    $data = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = (new DateTime())->sub(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
        $labels[] = $d;
        $found = null;
        foreach ($rows as $r) {
            if ($r['d'] === $d) { $found = $r['avg_min']; break; }
        }
        $data[] = $found !== null ? (float)$found : 0.0;
    }

    echo json_encode(['ok' => true, 'labels' => $labels, 'data' => $data]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
