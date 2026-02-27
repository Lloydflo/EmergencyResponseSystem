<?php
// API endpoint: api/report_call_duration.php
// Returns average call duration per incident type for the current month
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'DB unavailable']);
    exit;
}

// Get average call duration per incident type (in minutes)
$sql = "SELECT incident_type AS type, AVG(TIMESTAMPDIFF(SECOND, received_at, created_at))/60 AS avg_duration_min
        FROM calls
        WHERE received_at IS NOT NULL AND created_at IS NOT NULL
          AND YEAR(received_at) = YEAR(CURDATE()) AND MONTH(received_at) = MONTH(CURDATE())
        GROUP BY incident_type";
$stmt = $pdo->query($sql);
$data = [];
while ($row = $stmt->fetch()) {
    $data[$row['type']] = round((float)$row['avg_duration_min'], 2);
}

// Standardize type labels
$labels = ['medical' => 'Medical', 'fire' => 'Fire', 'police' => 'Police', 'traffic' => 'Traffic', 'other' => 'Other'];
$result = [];
foreach ($labels as $key => $label) {
    $result[] = [
        'type' => $label,
        'avg_duration' => isset($data[$key]) ? $data[$key] : 0
    ];
}

echo json_encode([
    'ok' => true,
    'data' => $result
]);
