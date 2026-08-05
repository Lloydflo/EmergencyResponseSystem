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

$table_exists = function (string $table) use ($pdo): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
};

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

// Low resource stock / availability alerts
if ($table_exists('shared_resources')) {
    $stockRows = $pdo->query(
        "SELECT id, name, resource_type, quantity_total, quantity_available, status
         FROM shared_resources
         WHERE quantity_total > 0
           AND (
               (quantity_available < quantity_total AND quantity_available <= 0)
               OR (quantity_available < quantity_total AND quantity_available <= GREATEST(1, FLOOR(quantity_total * 0.2)))
               OR status IN ('unavailable', 'maintenance')
           )
         ORDER BY quantity_available ASC, updated_at DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($stockRows)) {
        if ($all) {
            foreach ($stockRows as $row) {
                $availableQty = (int)($row['quantity_available'] ?? 0);
                $totalQty = (int)($row['quantity_total'] ?? 0);
                $status = strtolower((string)($row['status'] ?? 'available'));
                $isCritical = ($availableQty <= 0 || $status === 'unavailable');

                $alerts[] = [
                    'type' => $isCritical ? 'critical' : 'warning',
                    'title' => 'Low Resource Stock',
                    'details' => trim((string)($row['name'] ?? 'Resource')) . ' has only ' . $availableQty . ' of ' . $totalQty . ' item(s) available',
                    'resource_id' => (int)($row['id'] ?? 0)
                ];
            }
        } else {
            $criticalCount = 0;
            foreach ($stockRows as $row) {
                $availableQty = (int)($row['quantity_available'] ?? 0);
                $status = strtolower((string)($row['status'] ?? 'available'));
                if ($availableQty <= 0 || $status === 'unavailable') {
                    $criticalCount++;
                }
            }

            $alerts[] = [
                'type' => $criticalCount > 0 ? 'critical' : 'warning',
                'title' => 'Low Resource Stock',
                'details' => count($stockRows) . ' resource stock item(s) need replenishment'
            ];
        }
    }
}

$resourceRecordsTable = null;
if ($table_exists('resource_records')) {
    $resourceRecordsTable = 'resource_records';
} elseif ($table_exists('admin_resources')) {
    $resourceRecordsTable = 'admin_resources';
}

if ($resourceRecordsTable !== null) {
    $equipmentSummary = $pdo->query(
        "SELECT
            COUNT(*) AS total_equipment,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_equipment
         FROM `" . $resourceRecordsTable . "`
         WHERE category = 'equipment'"
    )->fetch(PDO::FETCH_ASSOC);

    $totalEquipment = (int)($equipmentSummary['total_equipment'] ?? 0);
    $availableEquipment = (int)($equipmentSummary['available_equipment'] ?? 0);
    $equipmentThreshold = $totalEquipment > 0 ? max(1, (int)floor($totalEquipment * 0.2)) : 0;

    if ($totalEquipment > 0 && $availableEquipment < $totalEquipment && $availableEquipment <= $equipmentThreshold) {
        if ($all) {
            $equipmentRows = $pdo->query(
                "SELECT id, code, name, status, location
                 FROM `" . $resourceRecordsTable . "`
                 WHERE category = 'equipment'
                   AND status <> 'available'
                 ORDER BY updated_at DESC, id DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($equipmentRows as $row) {
                $alerts[] = [
                    'type' => 'warning',
                    'title' => 'Low Equipment Availability',
                    'details' => trim((string)($row['code'] ?? 'EQ')) . ' - ' . trim((string)($row['name'] ?? 'Equipment')) . ' is currently ' . strtolower((string)($row['status'] ?? 'unavailable')),
                    'resource_id' => (int)($row['id'] ?? 0)
                ];
            }
        } else {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Low Equipment Availability',
                'details' => 'Only ' . $availableEquipment . ' of ' . $totalEquipment . ' equipment resource(s) are currently available'
            ];
        }
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
