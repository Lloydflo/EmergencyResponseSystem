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
        'requests' => [],
        'units' => []
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
    $requestStmt = $pdo->query("SELECT id, requestor, resource_name, date_requested, status, details FROM resource_requests ORDER BY date_requested DESC LIMIT " . (int)$limit);
    while ($row = $requestStmt->fetch(PDO::FETCH_ASSOC)) {
        $details = json_decode((string)($row['details'] ?? ''), true);
        if (!is_array($details)) {
            continue;
        }
        if ((string)($details['request_kind'] ?? '') !== 'backup') {
            continue;
        }
        if ((string)($row['status'] ?? '') !== 'pending') {
            continue;
        }

        $incidentId = (int)($details['incident_id'] ?? 0);
        if ($incidentId <= 0) {
            continue;
        }

        $selectedResources = [];
        if (!empty($details['selected_resources']) && is_array($details['selected_resources'])) {
            foreach ($details['selected_resources'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $selectedResources[] = [
                    'id' => (int)($item['id'] ?? 0),
                    'code' => (string)($item['code'] ?? ''),
                    'name' => (string)($item['name'] ?? ''),
                    'category' => (string)($item['category'] ?? ''),
                    'location' => (string)($item['location'] ?? ''),
                    'assignment' => (string)($item['assignment'] ?? ''),
                    'notes' => (string)($item['notes'] ?? ''),
                ];
            }
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
            'request_kind' => 'backup',
            'incident_id' => $incidentId,
            'incident_code' => (string)($details['incident_code'] ?? ''),
            'incident_title' => (string)($details['incident_title'] ?? ''),
            'decision_reason' => (string)($details['decision_reason'] ?? ''),
            'selected_resources' => $selectedResources,
            'dispatched_units' => is_array($details['dispatched_units'] ?? null) ? $details['dispatched_units'] : [],
            'dispatcher_name' => (string)($details['dispatcher_name'] ?? ''),
            'dispatched_at' => (string)($details['dispatched_at'] ?? ''),
        ];
    }

    $units = [];
    $unitStmt = $pdo->query("SELECT id, identifier, unit_type, status, current_incident_id, last_status_at FROM units WHERE status = 'available' ORDER BY unit_type, identifier");
    while ($row = $unitStmt->fetch(PDO::FETCH_ASSOC)) {
        $units[] = [
            'id' => (int)($row['id'] ?? 0),
            'identifier' => (string)($row['identifier'] ?? ''),
            'unit_type' => (string)($row['unit_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'current_incident_id' => isset($row['current_incident_id']) ? (int)$row['current_incident_id'] : null,
            'last_status_at' => (string)($row['last_status_at'] ?? ''),
        ];
    }

    echo json_encode([
        'success' => true,
        'pending_count' => count($requests),
        'requests' => $requests,
        'units' => $units,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'pending_count' => 0,
        'requests' => [],
        'units' => []
    ]);
}
