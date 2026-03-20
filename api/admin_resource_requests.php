<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed',
        'pending_count' => 0,
        'requests' => []
    ]);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit < 1) {
    $limit = 50;
}
if ($limit > 100) {
    $limit = 100;
}

try {
    $requests = [];
    $stmt = $pdo->query("SELECT id, requestor, resource_name, date_requested, status, details FROM resource_requests ORDER BY date_requested DESC LIMIT " . (int)$limit);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $details = json_decode((string)($row['details'] ?? ''), true);
        if (!is_array($details)) {
            continue;
        }
        if ((string)($row['status'] ?? '') !== 'pending') {
            continue;
        }
        if ((string)($details['request_kind'] ?? 'standard') === 'backup') {
            continue;
        }

        $requests[] = [
            'id' => (int)($row['id'] ?? 0),
            'requestor' => (string)($row['requestor'] ?? ''),
            'resource_name' => (string)($row['resource_name'] ?? ''),
            'date_requested' => (string)($row['date_requested'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'type' => (string)($details['type'] ?? ''),
            'quantity' => max(1, (int)($details['quantity'] ?? 1)),
            'priority' => (string)($details['priority'] ?? ''),
            'location' => (string)($details['location'] ?? ''),
            'notes' => (string)($details['notes'] ?? ''),
            'urgency' => (string)($details['urgency'] ?? ''),
            'request_kind' => (string)($details['request_kind'] ?? 'standard'),
            'incident_id' => (int)($details['incident_id'] ?? 0),
            'incident_code' => (string)($details['incident_code'] ?? ''),
            'incident_title' => (string)($details['incident_title'] ?? ''),
        ];
    }

    echo json_encode([
        'success' => true,
        'pending_count' => count($requests),
        'requests' => $requests
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'pending_count' => 0,
        'requests' => []
    ]);
}
