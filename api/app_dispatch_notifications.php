<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DB connection unavailable',
        'notifications' => []
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$sinceId = isset($input['since_id']) ? (int)$input['since_id'] : (isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0);
$limit = isset($input['limit']) ? (int)$input['limit'] : (isset($_GET['limit']) ? (int)$_GET['limit'] : 20);
$department = isset($input['department']) ? trim((string)$input['department']) : (isset($_GET['department']) ? trim((string)$_GET['department']) : '');

if ($sinceId < 0) {
    $sinceId = 0;
}
if ($limit < 1) {
    $limit = 20;
}
if ($limit > 100) {
    $limit = 100;
}

$departmentMap = [
    'medical' => 'ambulance',
    'ambulance' => 'ambulance',
    'fire' => 'fire',
    'police' => 'police',
    'rescue' => 'rescue',
    'other' => 'other'
];
$departmentKey = strtolower($department);
$unitTypeFilter = isset($departmentMap[$departmentKey]) ? $departmentMap[$departmentKey] : '';

try {
    $sql = "
        SELECT
            a.id AS notification_id,
            a.action,
            a.entity_type,
            a.created_at AS notified_at,
            a.details,
            d.id AS dispatch_id,
            d.status AS dispatch_status,
            d.assigned_at,
            d.incident_id,
            d.unit_id,
            i.reference_no,
            i.type AS incident_type,
            i.priority,
            i.location_address,
            i.latitude,
            i.longitude,
            u.identifier AS unit_identifier,
            u.unit_type
        FROM activity_log a
        LEFT JOIN dispatches d
            ON d.id = a.entity_id
            AND a.entity_type = 'dispatch'
        LEFT JOIN incidents i ON i.id = CASE
            WHEN a.entity_type = 'incident' THEN a.entity_id
            ELSE d.incident_id
        END
        LEFT JOIN units u ON u.id = d.unit_id
        WHERE a.action IN ('dispatch_confirmed', 'incident_resolved')
          AND a.id > ?
    ";

    $params = [$sinceId];
    if ($unitTypeFilter !== '') {
        $sql .= " AND (
            u.unit_type = ?
            OR (
                a.action = 'incident_resolved'
                AND LOWER(COALESCE(i.type, '')) IN (?, ?)
            )
        ) ";
        $params[] = $unitTypeFilter;
        $params[] = $departmentKey === 'medical' ? 'medical' : $unitTypeFilter;
        $params[] = $departmentKey === 'police' ? 'crime' : $unitTypeFilter;
    }

    $sql .= " ORDER BY a.id ASC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($rows as $row) {
        $details = null;
        if (isset($row['details']) && is_string($row['details']) && $row['details'] !== '') {
            $decoded = json_decode($row['details'], true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }

        $notifications[] = [
            'notification_id' => (int)$row['notification_id'],
            'action' => $row['action'] ?? '',
            'notified_at' => $row['notified_at'],
            'dispatch' => [
                'dispatch_id' => isset($row['dispatch_id']) ? (int)$row['dispatch_id'] : null,
                'status' => $row['dispatch_status'] ?? null,
                'assigned_at' => $row['assigned_at'] ?? null,
                'incident_id' => isset($row['incident_id']) ? (int)$row['incident_id'] : null,
                'unit_id' => isset($row['unit_id']) ? (int)$row['unit_id'] : null,
                'reference_no' => $row['reference_no'] ?? null,
                'incident_type' => $row['incident_type'] ?? null,
                'priority' => $row['priority'] ?? null,
                'location_address' => $row['location_address'] ?? null,
                'latitude' => $row['latitude'] ?? null,
                'longitude' => $row['longitude'] ?? null,
                'unit_identifier' => $row['unit_identifier'] ?? null,
                'unit_type' => $row['unit_type'] ?? null
            ],
            'details' => $details
        ];
    }

    $lastNotificationId = $sinceId;
    if (!empty($notifications)) {
        $lastNotificationId = (int)$notifications[count($notifications) - 1]['notification_id'];
    }

    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'since_id' => $sinceId,
        'last_notification_id' => $lastNotificationId,
        'count' => count($notifications),
        'notifications' => $notifications
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch notifications',
        'notifications' => []
    ]);
}
