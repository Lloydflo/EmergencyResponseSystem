<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incident_admin_review.php';

if (current_session_role() !== 'admin') {
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
    if (!ers_ensure_incident_admin_reviews($pdo)) {
        echo json_encode(['ok' => true, 'notifications' => [], 'latest_id' => 0]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            iar.id AS notification_id,
            CONCAT('Incident ', COALESCE(NULLIF(i.reference_no, ''), CONCAT('#', i.id)), ' review was sent to admin.') AS details,
            iar.sent_at AS notified_at,
            iar.sent_by_name,
            i.id AS incident_id,
            i.reference_no,
            i.type,
            i.priority,
            i.location_address,
            i.resolved_at
        FROM incident_admin_reviews iar
        LEFT JOIN incidents i ON i.id = iar.incident_id
        WHERE i.id IS NOT NULL
        ORDER BY iar.id DESC
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
            'sent_by_name' => $row['sent_by_name'] ?? null,
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
