<?php
// api/trend_data.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();

$start = isset($_GET['start']) ? $_GET['start'] : null;
$end = isset($_GET['end']) ? $_GET['end'] : null;

$where = '';
$params = [];
if ($start && $end) {
    $where = 'WHERE DATE(created_at) BETWEEN :start AND :end';
    $params = [':start' => $start, ':end' => $end];
}

$sql = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as day, COUNT(*) as count
        FROM incidents $where
        GROUP BY day
        ORDER BY day ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fill missing days with zero
if ($start && $end) {
    $period = new DatePeriod(
        new DateTime($start),
        new DateInterval('P1D'),
        (new DateTime($end))->modify('+1 day')
    );
    $allDays = [];
    foreach ($period as $dt) {
        $allDays[$dt->format('Y-m-d')] = 0;
    }
    foreach ($data as $row) {
        $allDays[$row['day']] = (int)$row['count'];
    }
    $labels = array_keys($allDays);
    $values = array_values($allDays);
} else {
    $labels = array_column($data, 'day');
    $values = array_column($data, 'count');
}

echo json_encode([
    'ok' => true,
    'labels' => $labels,
    'values' => $values
]);
