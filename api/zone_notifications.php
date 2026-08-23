<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in() || current_session_role() !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required', 'notifications' => []]);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable', 'notifications' => []]);
    exit;
}

$limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

function zone_notification_details(?string $raw): array
{
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : ['message' => trim((string)$raw)];
}

try {
    $stmt = $pdo->query(
        "SELECT
            a.id AS notification_id,
            a.user_id,
            a.action,
            a.entity_id,
            a.details,
            a.created_at AS notified_at,
            u.name AS responder_name
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity_type = 'responder_zone'
           AND a.action IN ('responder_zone_entered', 'responder_zone_left')
         ORDER BY a.id DESC
         LIMIT " . (int)$limit
    );

    $notifications = [];
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $details = zone_notification_details((string)($row['details'] ?? ''));
        $transition = (string)($row['action'] ?? '') === 'responder_zone_left' ? 'left' : 'entered';
        $unitCode = trim((string)($details['unit_code'] ?? ''));
        $zoneName = trim((string)($details['zone_name'] ?? ''));
        $message = trim((string)($details['message'] ?? ''));
        if ($message === '') {
            $message = ($unitCode !== '' ? $unitCode : 'Responder')
                . ' ' . $transition . ' '
                . ($zoneName !== '' ? $zoneName : 'a zone') . '.';
        }

        $notifications[] = [
            'notification_id' => (int)($row['notification_id'] ?? 0),
            'action' => (string)($row['action'] ?? ''),
            'transition' => $transition,
            'notified_at' => (string)($row['notified_at'] ?? ''),
            'message' => $message,
            'responder_id' => (int)($details['responder_id'] ?? $row['user_id'] ?? 0),
            'responder_name' => (string)($row['responder_name'] ?? ''),
            'unit_id' => (int)($details['unit_id'] ?? $row['entity_id'] ?? 0),
            'unit_code' => $unitCode,
            'unit_type' => (string)($details['unit_type'] ?? ''),
            'zone_key' => (string)($details['zone_key'] ?? ''),
            'zone_name' => $zoneName,
            'zone_type' => (string)($details['zone_type'] ?? ''),
            'latitude' => isset($details['latitude']) ? (float)$details['latitude'] : null,
            'longitude' => isset($details['longitude']) ? (float)$details['longitude'] : null,
        ];
    }

    echo json_encode(['ok' => true, 'notifications' => $notifications], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('zone_notifications query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed', 'notifications' => []]);
}
?>
