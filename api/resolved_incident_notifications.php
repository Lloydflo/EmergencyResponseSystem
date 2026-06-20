<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable', 'notifications' => []]);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1) {
    $limit = 10;
}
if ($limit > 50) {
    $limit = 50;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            a.id AS notification_id,
            a.details,
            a.created_at AS notified_at,
            i.id AS incident_id,
            i.reference_no,
            i.type,
            i.priority,
            i.location_address,
            i.resolved_at
        FROM activity_log a
        LEFT JOIN incidents i ON i.id = a.entity_id
        WHERE a.action = 'incident_resolved'
          AND a.entity_type = 'incident'
        ORDER BY a.id DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute();

    $notifications = array_map(static function (array $row): array {
        $incidentLabel = trim((string)($row['reference_no'] ?? ''));
        if ($incidentLabel === '' && !empty($row['incident_id'])) {
            $incidentLabel = '#' . (string)$row['incident_id'];
        }

        return [
            'notification_id' => (int)($row['notification_id'] ?? 0),
            'notified_at' => $row['notified_at'] ?? null,
            'details' => (string)($row['details'] ?? ''),
            'incident' => [
                'id' => isset($row['incident_id']) ? (int)$row['incident_id'] : null,
                'label' => $incidentLabel,
                'reference_no' => $row['reference_no'] ?? null,
                'type' => $row['type'] ?? null,
                'priority' => $row['priority'] ?? null,
                'location_address' => $row['location_address'] ?? null,
                'resolved_at' => $row['resolved_at'] ?? null,
            ],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'ok' => true,
        'notifications' => $notifications,
        'latest_id' => $notifications[0]['notification_id'] ?? 0,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed', 'notifications' => []]);
}
