<?php
// API endpoint: /api/alerts_active.php
// Returns currently active alerts (e.g., high response time, resource utilization, weather)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$alerts = [];
$all = isset($_GET['all']) && $_GET['all'] == 1;

// High response time alerts (list all incidents with high response time in last hour if ?all=1, else just summary)
$rt_query = "SELECT id, created_at, responded_at, TIMESTAMPDIFF(MINUTE, created_at, responded_at) AS rt FROM incidents WHERE responded_at IS NOT NULL AND created_at >= NOW() - INTERVAL 1 HOUR AND TIMESTAMPDIFF(MINUTE, created_at, responded_at) > 10 ORDER BY created_at DESC";
if ($all) {
    $rt_rows = $pdo->query($rt_query)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rt_rows as $row) {
        $alerts[] = [
            'type' => 'critical',
            'title' => 'High Response Time',
            'details' => 'Incident #' . $row['id'] . ' response time: ' . $row['rt'] . ' min',
            'created_at' => $row['created_at'],
            'responded_at' => $row['responded_at']
        ];
    }
} else {
    $rt = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) AS avg_rt FROM incidents WHERE responded_at IS NOT NULL AND created_at >= NOW() - INTERVAL 1 HOUR")->fetch();
    if ($rt && $rt['avg_rt'] > 10) {
        $alerts[] = [
            'type' => 'critical',
            'title' => 'High Response Time',
            'details' => 'Average response time exceeds 10 minutes (last hour)'
        ];
    }
}

// Resource utilization (list all units if ?all=1, else just summary)
$amb = $pdo->query("SELECT id, identifier AS unit_name, status FROM units WHERE unit_type='ambulance'")->fetchAll(PDO::FETCH_ASSOC);
$total = count($amb);
$used = array_filter($amb, function($u){ return $u['status'] !== 'available'; });
if ($total > 0 && count($used) / $total > 0.8) {
    if ($all) {
        foreach ($used as $unit) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Resource Utilization',
                'details' => 'Ambulance ' . $unit['unit_name'] . ' is ' . $unit['status'],
                'unit_id' => $unit['id']
            ];
        }
    } else {
        $alerts[] = [
            'type' => 'warning',
            'title' => 'Resource Utilization',
            'details' => 'Ambulance fleet at over 80% capacity'
        ];
    }
}

// Weather alert (from dashboard, only show one even if all=1)
// Try to get weather condition from index.php via GET param or fallback
$condition = isset($_GET['condition']) ? $_GET['condition'] : null;
if (!$condition) {
    // Try to get from cache or fallback (not ideal, but for modal completeness)
    $condition = 'Unavailable';
}
if ($condition !== 'Unavailable' && stripos($condition, 'rain') !== false) {
    $alerts[] = [
        'type' => 'info',
        'title' => 'Weather Alert',
        'details' => $condition
    ];
}

echo json_encode(['ok' => true, 'data' => $alerts]);
