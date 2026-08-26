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

    // 1. Pending web resource requests
    $stmt = $pdo->query("SELECT id, requestor, resource_name, date_requested, status, details FROM resource_requests WHERE status = 'pending' ORDER BY date_requested DESC LIMIT " . (int)$limit);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $details = json_decode((string)($row['details'] ?? ''), true);
        if (!is_array($details)) {
            continue;
        }
        if ((string)($details['request_kind'] ?? 'standard') === 'backup') {
            continue;
        }

        $requests[] = [
            'id' => (int)($row['id'] ?? 0),
            'source' => 'resource_requests',
            'requestor' => (string)($row['requestor'] ?? 'Staff / Dispatch'),
            'resource_name' => (string)($row['resource_name'] ?? ''),
            'date_requested' => (string)($row['date_requested'] ?? ''),
            'status' => 'pending',
            'type' => (string)($details['type'] ?? 'vehicle'),
            'quantity' => max(1, (int)($details['quantity'] ?? 1)),
            'priority' => (string)($details['priority'] ?? 'high'),
            'location' => (string)($details['location'] ?? ''),
            'notes' => (string)($details['notes'] ?? ''),
            'urgency' => (string)($details['urgency'] ?? 'urgent'),
            'request_kind' => (string)($details['request_kind'] ?? 'standard'),
            'incident_id' => (int)($details['incident_id'] ?? 0),
            'incident_code' => (string)($details['incident_code'] ?? ''),
            'incident_title' => (string)($details['incident_title'] ?? ''),
        ];
    }

    // 2. Pending responder app resource requests
    try {
        $hasResponderRequests = $pdo->query("SHOW TABLES LIKE 'responder_resource_requests'")->fetchColumn();
        if ($hasResponderRequests) {
            $rStmt = $pdo->query("
                SELECT r.*,
                       i.reference_no AS incident_reference,
                       i.title AS incident_title_db,
                       i.priority AS incident_priority_db
                FROM responder_resource_requests r
                LEFT JOIN incidents i ON i.id = r.incident_id
                WHERE r.status = 'pending'
                ORDER BY r.id DESC
                LIMIT " . (int)$limit
            );
            while ($row = $rStmt->fetch(PDO::FETCH_ASSOC)) {
                $incId = (int)($row['incident_id'] ?? 0);
                $incCode = (string)($row['incident_reference'] ?? ($incId > 0 ? 'INC-' . $incId : ''));
                $incTitle = (string)($row['incident_title_db'] ?? '');
                $requests[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'source' => 'responder_resource_requests',
                    'requestor' => (string)($row['responder_name'] ?? 'On-Scene Responder'),
                    'resource_name' => (string)($row['resource_name'] ?? 'Backup Vehicle'),
                    'date_requested' => (string)($row['created_at'] ?? ''),
                    'status' => 'pending',
                    'type' => (string)($row['category'] ?? 'vehicle'),
                    'quantity' => max(1, (int)($row['quantity'] ?? 1)),
                    'priority' => (string)($row['incident_priority_db'] ?? ($row['urgency'] === 'critical' ? 'high' : 'medium')),
                    'location' => (string)($row['location'] ?? ''),
                    'notes' => (string)($row['notes'] ?? ''),
                    'urgency' => (string)($row['urgency'] ?? 'urgent'),
                    'request_kind' => 'standard',
                    'incident_id' => $incId,
                    'incident_code' => $incCode,
                    'incident_title' => $incTitle,
                ];
            }
        }
    } catch (Throwable $e) {}

    // Sort newest first
    usort($requests, static function (array $a, array $b): int {
        return strcmp((string)($b['date_requested'] ?? ''), (string)($a['date_requested'] ?? ''));
    });

    echo json_encode([
        'success' => true,
        'pending_count' => count($requests),
        'requests' => array_slice($requests, 0, $limit)
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
