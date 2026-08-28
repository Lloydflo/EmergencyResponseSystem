<?php
declare(strict_types=1);

// Surfaces "responder acknowledged this broadcast" events (from the mobile
// app's Acknowledge/Notify Received button) in the admin/dispatcher header
// notification bell. Follows the same pattern as resolved_incident_notifications.php.
//
// Data source: activity_log rows written by
// api/api_app/acknowledge-broadcast.php (action = 'broadcast_acknowledged').

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$role = current_session_role();
if (!in_array($role, ['admin', 'dispatcher'], true)) {
    echo json_encode(['ok' => true, 'notifications' => [], 'latest_id' => 0]);
    exit;
}

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
            i.location_address
        FROM activity_log a
        LEFT JOIN interagency_incident_broadcasts b ON b.id = a.entity_id
        LEFT JOIN incidents i ON i.id = b.incident_id
        WHERE a.action = 'broadcast_acknowledged'
          AND a.entity_type = 'interagency_broadcast'
        ORDER BY a.id DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute();

    $notifications = array_map(static function (array $row): array {
        $decoded = json_decode((string)($row['details'] ?? ''), true);
        $responderName = is_array($decoded) ? trim((string)($decoded['responder_name'] ?? '')) : '';
        if ($responderName === '') {
            $responderName = 'A responder';
        }

        $incidentLabel = trim((string)($row['reference_no'] ?? ''));
        if ($incidentLabel === '' && !empty($row['incident_id'])) {
            $incidentLabel = '#' . (string)$row['incident_id'];
        }

        $details = $incidentLabel !== ''
            ? "$responderName acknowledged the broadcast for incident $incidentLabel."
            : "$responderName acknowledged a broadcast.";

        return [
            'notification_id' => (int)($row['notification_id'] ?? 0),
            'notified_at' => $row['notified_at'] ?? null,
            'details' => $details,
            'responder_name' => $responderName,
            'incident' => [
                'id' => isset($row['incident_id']) ? (int)$row['incident_id'] : null,
                'label' => $incidentLabel,
                'reference_no' => $row['reference_no'] ?? null,
                'type' => $row['type'] ?? null,
                'priority' => $row['priority'] ?? null,
                'location_address' => $row['location_address'] ?? null,
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
