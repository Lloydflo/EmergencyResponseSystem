<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

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
    if (function_exists('ers_reconcile_all_dispatch_and_unit_statuses')) {
        ers_reconcile_all_dispatch_and_unit_statuses($pdo);
    }

    $requests = [];

    // 1. Pending web resource requests
    if (ers_vehicle_resource_table_exists($pdo, 'resource_requests')) {
        $stmt = $pdo->query("SELECT id, requestor, resource_name, date_requested, status, details FROM resource_requests WHERE status = 'pending' ORDER BY date_requested DESC LIMIT " . (int)$limit);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            if (!is_array($details)) {
                continue;
            }
            if ((string)($details['request_kind'] ?? 'standard') === 'backup') {
                continue;
            }

            $incId = (int)($details['incident_id'] ?? 0);
            if ($incId > 0 && ers_vehicle_resource_table_exists($pdo, 'incidents')) {
                $chk = $pdo->prepare("SELECT status FROM incidents WHERE id = ? LIMIT 1");
                $chk->execute([$incId]);
                $incStatus = strtolower(trim((string)$chk->fetchColumn()));
                if (in_array($incStatus, ['resolved', 'closed', 'cancelled', 'completed'], true)) {
                    continue;
                }
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
                'incident_id' => $incId,
                'incident_code' => (string)($details['incident_code'] ?? ''),
                'incident_title' => (string)($details['incident_title'] ?? ''),
            ];
        }
    }

    // 2. Pending responder app resource requests
    if (ers_vehicle_resource_table_exists($pdo, 'responder_resource_requests')) {
        $rStmt = $pdo->query("
            SELECT r.*,
                   i.reference_no AS incident_reference,
                   i.title AS incident_title_db,
                   i.priority AS incident_priority_db,
                   i.status AS incident_status_db
            FROM responder_resource_requests r
            LEFT JOIN incidents i ON (
                (r.incident_id REGEXP '^[0-9]+$' AND i.id = CAST(r.incident_id AS UNSIGNED))
                OR (i.reference_no COLLATE utf8mb4_unicode_ci = r.incident_id COLLATE utf8mb4_unicode_ci)
            )
            WHERE r.status = 'pending'
              AND (
                  r.incident_id IS NULL 
                  OR r.incident_id = '' 
                  OR r.incident_id = '0' 
                  OR i.id IS NULL 
                  OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved', 'closed', 'cancelled', 'completed')
              )
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

    // 3. Pending responder backup requests
    if (ers_vehicle_resource_table_exists($pdo, 'responder_backup_requests')) {
        $bStmt = $pdo->query("
            SELECT b.*,
                   i.reference_no AS incident_reference,
                   i.title AS incident_title_db,
                   i.priority AS incident_priority_db,
                   i.location_address AS incident_location_db,
                   i.status AS incident_status_db
            FROM responder_backup_requests b
            LEFT JOIN incidents i ON (
                (b.incident_id REGEXP '^[0-9]+$' AND i.id = CAST(b.incident_id AS UNSIGNED))
                OR (i.reference_no COLLATE utf8mb4_unicode_ci = b.incident_id COLLATE utf8mb4_unicode_ci)
            )
            WHERE b.status = 'pending'
              AND (
                  b.incident_id IS NULL 
                  OR b.incident_id = '' 
                  OR b.incident_id = '0' 
                  OR i.id IS NULL 
                  OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved', 'closed', 'cancelled', 'completed')
              )
            ORDER BY b.id DESC
            LIMIT " . (int)$limit
        );
        while ($row = $bStmt->fetch(PDO::FETCH_ASSOC)) {
            $incId = (int)($row['incident_id'] ?? 0);
            $incCode = (string)($row['incident_reference'] ?? ($incId > 0 ? 'INC-' . $incId : (string)($row['incident_id'] ?? '')));
            $incTitle = (string)($row['incident_title_db'] ?? '');
            $dept = trim((string)($row['requested_department'] ?? ''));
            $res = trim((string)($row['resources'] ?? ''));
            $isFull = !empty($row['is_full_backup']);
            $resLabel = $res !== '' ? $res : ($isFull ? 'Full Backup Team' : 'Additional Backup');

            $requests[] = [
                'id' => (int)($row['id'] ?? 0),
                'source' => 'responder_backup_requests',
                'requestor' => (string)($row['responder_name'] ?? 'On-Scene Responder'),
                'resource_name' => $resLabel,
                'date_requested' => (string)($row['created_at'] ?? ''),
                'status' => 'pending',
                'type' => $dept !== '' ? $dept : 'backup',
                'quantity' => 1,
                'priority' => (string)($row['incident_priority_db'] ?? 'high'),
                'location' => (string)($row['incident_location_db'] ?? ''),
                'notes' => 'Requested ' . ($dept !== '' ? $dept . ' backup' : 'backup') . ' (' . $resLabel . ')',
                'urgency' => 'urgent',
                'request_kind' => 'backup',
                'incident_id' => $incId,
                'incident_code' => $incCode,
                'incident_title' => $incTitle,
            ];
        }
    }

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
