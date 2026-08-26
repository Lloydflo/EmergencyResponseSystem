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
    require_once __DIR__ . '/../includes/vehicle_resource_units.php';
    if (function_exists('ers_reconcile_all_dispatch_and_unit_statuses')) {
        ers_reconcile_all_dispatch_and_unit_statuses($pdo);
    }

    $hasIncidents = ers_vehicle_resource_table_exists($pdo, 'incidents');
    $requests = [];

    // 1. Web resource requests marked as backup
    if (ers_vehicle_resource_table_exists($pdo, 'resource_requests')) {
        $requestStmt = $pdo->query("SELECT id, requestor, resource_name, date_requested, status, details FROM resource_requests WHERE status = 'pending' ORDER BY date_requested DESC LIMIT " . (int)$limit);
        while ($row = $requestStmt->fetch(PDO::FETCH_ASSOC)) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            if (!is_array($details)) {
                continue;
            }
            if ((string)($details['request_kind'] ?? '') !== 'backup') {
                continue;
            }

            $incidentId = (int)($details['incident_id'] ?? 0);
            if ($incidentId <= 0) {
                continue;
            }

            if ($hasIncidents) {
                $chk = $pdo->prepare("SELECT status FROM incidents WHERE id = ? LIMIT 1");
                $chk->execute([$incidentId]);
                $incStatus = strtolower(trim((string)$chk->fetchColumn()));
                if (in_array($incStatus, ['resolved', 'closed', 'cancelled', 'completed'], true)) {
                    continue;
                }
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
    }

    // 2. Responder app backup requests
    if (ers_vehicle_resource_table_exists($pdo, 'responder_backup_requests')) {
        $incJoin = $hasIncidents ? "LEFT JOIN incidents i ON (i.id = b.incident_id OR i.reference_no = b.incident_id)" : "";
        $incFilter = $hasIncidents ? "AND (b.incident_id IS NULL OR b.incident_id = '' OR b.incident_id = '0' OR i.id IS NULL OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved', 'closed', 'cancelled', 'completed'))" : "";
        $bStmt = $pdo->query("
            SELECT b.*,
                   i.reference_no AS incident_reference,
                   i.title AS incident_title_db,
                   i.priority AS incident_priority_db,
                   i.location_address AS incident_location_db
            FROM responder_backup_requests b
            {$incJoin}
            WHERE b.status = 'pending'
              {$incFilter}
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT " . (int)$limit
        );
        if ($bStmt) {
            while ($bRow = $bStmt->fetch(PDO::FETCH_ASSOC)) {
                $bIncId = (int)($bRow['incident_id'] ?? 0);
                $bIncCode = (string)($bRow['incident_reference'] ?? ($bIncId > 0 ? 'INC-' . $bIncId : (string)($bRow['incident_id'] ?? '')));
                $dept = trim((string)($bRow['requested_department'] ?? ''));
                $res = trim((string)($bRow['resources'] ?? ''));
                $isFull = !empty($bRow['is_full_backup']);
                $resLabel = $res !== '' ? $res : ($isFull ? 'Full Backup Team' : 'Additional Backup');

                $requests[] = [
                    'id' => (int)($bRow['id'] ?? 0),
                    'requestor' => (string)($bRow['responder_name'] ?? 'On-Scene Responder'),
                    'resource_name' => $resLabel,
                    'date_requested' => (string)($bRow['created_at'] ?? ''),
                    'status' => 'pending',
                    'type' => $dept !== '' ? $dept : 'backup',
                    'quantity' => 1,
                    'priority' => (string)($bRow['incident_priority_db'] ?? 'high'),
                    'location' => (string)($bRow['incident_location_db'] ?? ''),
                    'notes' => 'Requested ' . ($dept !== '' ? $dept . ' backup' : 'backup') . ' (' . $resLabel . ')',
                    'urgency' => 'urgent',
                    'request_kind' => 'backup',
                    'incident_id' => $bIncId,
                    'incident_code' => $bIncCode,
                    'incident_title' => (string)($bRow['incident_title_db'] ?? ''),
                    'decision_reason' => '',
                    'selected_resources' => [],
                    'dispatched_units' => [],
                    'dispatcher_name' => '',
                    'dispatched_at' => '',
                ];
            }
        }
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
