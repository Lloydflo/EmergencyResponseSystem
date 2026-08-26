<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized', 'requests' => [], 'resource_additions' => []]);
    exit;
}

$actor = get_logged_in_user();
$role = canonical_role((string)($actor['role'] ?? ''));
if (!in_array($role, ['admin', 'dispatcher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden', 'requests' => [], 'resource_additions' => []]);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed', 'requests' => [], 'resource_additions' => []]);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit < 1) {
    $limit = 50;
}
if ($limit > 100) {
    $limit = 100;
}

function resource_notification_table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function resource_notification_decode_details(?string $raw): array {
    $details = json_decode((string)$raw, true);
    return is_array($details) ? $details : [];
}

function resource_notification_request_item(array $row, string $source = 'resource_requests', int $offset = 0): ?array {
    $details = resource_notification_decode_details((string)($row['details'] ?? ''));
    if ((string)($row['status'] ?? '') !== 'pending') {
        return null;
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

    return [
        'id' => (int)($row['id'] ?? 0),
        'notification_id' => $offset + (int)($row['id'] ?? 0),
        'source' => $source,
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
        'selected_resources' => $selectedResources,
        'dispatcher_name' => (string)($details['dispatcher_name'] ?? ''),
        'dispatched_at' => (string)($details['dispatched_at'] ?? ''),
    ];
}

function resource_notification_app_resource_request_item(array $row): ?array {
    if ((string)($row['status'] ?? '') !== 'pending') {
        return null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'notification_id' => 100000000 + (int)($row['id'] ?? 0),
        'source' => 'responder_resource_requests',
        'requestor' => (string)($row['responder_name'] ?? ''),
        'resource_name' => (string)($row['resource_name'] ?? ''),
        'date_requested' => (string)($row['created_at'] ?? ''),
        'status' => (string)($row['status'] ?? 'pending'),
        'type' => (string)($row['category'] ?? ''),
        'quantity' => max(1, (int)($row['quantity'] ?? 1)),
        'priority' => '',
        'location' => (string)($row['location'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
        'urgency' => (string)($row['urgency'] ?? ''),
        'request_kind' => 'standard',
        'incident_id' => (int)($row['incident_id'] ?? 0),
        'incident_code' => '',
        'incident_title' => '',
        'selected_resources' => [],
        'dispatcher_name' => '',
        'dispatched_at' => '',
    ];
}

function resource_notification_app_backup_request_item(array $row): ?array {
    if ((string)($row['status'] ?? 'pending') !== 'pending') {
        return null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'notification_id' => 200000000 + (int)($row['id'] ?? 0),
        'source' => 'responder_backup_requests',
        'requestor' => (string)($row['responder_name'] ?? ''),
        'resource_name' => (string)($row['resources'] ?? ''),
        'date_requested' => (string)($row['created_at'] ?? ''),
        'status' => (string)($row['status'] ?? 'pending'),
        'type' => 'backup',
        'quantity' => 1,
        'priority' => '',
        'location' => '',
        'notes' => '',
        'urgency' => '',
        'request_kind' => 'backup',
        'incident_id' => (int)($row['incident_id'] ?? 0),
        'incident_code' => '',
        'incident_title' => '',
        'selected_resources' => [],
        'dispatcher_name' => '',
        'dispatched_at' => '',
        'requested_department' => (string)($row['requested_department'] ?? ''),
    ];
}

function resource_notification_addition_item(array $row): array {
    $details = resource_notification_decode_details((string)($row['details'] ?? ''));
    return [
        'notification_id' => (int)($row['notification_id'] ?? 0),
        'resource_id' => (int)($row['entity_id'] ?? 0),
        'resource_code' => (string)($details['code'] ?? ''),
        'resource_name' => (string)($details['name'] ?? ''),
        'category' => (string)($details['category'] ?? ''),
        'quantity' => max(1, (int)($details['quantity'] ?? 1)),
        'added_by' => (string)($details['added_by'] ?? ''),
        'details' => (string)($details['message'] ?? $row['details'] ?? ''),
        'notified_at' => (string)($row['notified_at'] ?? ''),
    ];
}

try {
    require_once __DIR__ . '/../includes/vehicle_resource_units.php';
    if (function_exists('ers_reconcile_all_dispatch_and_unit_statuses')) {
        ers_reconcile_all_dispatch_and_unit_statuses($pdo);
    }

    $hasIncidents = resource_notification_table_exists($pdo, 'incidents');
    $requests = [];

    if (resource_notification_table_exists($pdo, 'resource_requests')) {
        $stmt = $pdo->query(
            "SELECT id, requestor, resource_name, date_requested, status, details
             FROM resource_requests
             WHERE status = 'pending'
             ORDER BY date_requested DESC, id DESC
             LIMIT " . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            $incId = is_array($details) ? (int)($details['incident_id'] ?? 0) : 0;
            if ($incId > 0 && $hasIncidents) {
                $chk = $pdo->prepare("SELECT status FROM incidents WHERE id = ? LIMIT 1");
                $chk->execute([$incId]);
                $incStatus = strtolower(trim((string)$chk->fetchColumn()));
                if (in_array($incStatus, ['resolved', 'closed', 'cancelled', 'completed'], true)) {
                    continue;
                }
            }

            $item = resource_notification_request_item($row);
            if ($item !== null) {
                $requests[] = $item;
            }
        }
    }

    if (resource_notification_table_exists($pdo, 'responder_resource_requests')) {
        $incJoin = $hasIncidents ? "LEFT JOIN incidents i ON (
            (r.incident_id REGEXP '^[0-9]+$' AND i.id = CAST(r.incident_id AS UNSIGNED))
            OR (i.reference_no COLLATE utf8mb4_unicode_ci = r.incident_id COLLATE utf8mb4_unicode_ci)
        )" : "";
        $incFilter = $hasIncidents ? "AND (r.incident_id IS NULL OR r.incident_id = '' OR r.incident_id = '0' OR i.id IS NULL OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved', 'closed', 'cancelled', 'completed'))" : "";
        $stmt = $pdo->query(
            "SELECT r.id, r.responder_name, r.category, r.resource_name, r.quantity, r.urgency, r.incident_id, r.location, r.notes, r.status, r.created_at, r.updated_at
             FROM responder_resource_requests r
             {$incJoin}
             WHERE r.status = 'pending'
               {$incFilter}
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT " . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = resource_notification_app_resource_request_item($row);
            if ($item !== null) {
                $requests[] = $item;
            }
        }
    }

    if (resource_notification_table_exists($pdo, 'responder_backup_requests')) {
        $incJoin = $hasIncidents ? "LEFT JOIN incidents i ON (
            (b.incident_id REGEXP '^[0-9]+$' AND i.id = CAST(b.incident_id AS UNSIGNED))
            OR (i.reference_no COLLATE utf8mb4_unicode_ci = b.incident_id COLLATE utf8mb4_unicode_ci)
        )" : "";
        $incFilter = $hasIncidents ? "AND (b.incident_id IS NULL OR b.incident_id = '' OR b.incident_id = '0' OR i.id IS NULL OR LOWER(COALESCE(i.status, '')) NOT IN ('resolved', 'closed', 'cancelled', 'completed'))" : "";
        $stmt = $pdo->query(
            "SELECT b.id, b.responder_name, b.requested_department, b.resources, b.is_full_backup, b.incident_id, b.status, b.created_at, b.updated_at
             FROM responder_backup_requests b
             {$incJoin}
             WHERE b.status = 'pending'
               {$incFilter}
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT " . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = resource_notification_app_backup_request_item($row);
            if ($item !== null) {
                $requests[] = $item;
            }
        }
    }

    usort($requests, static function (array $a, array $b): int {
        $timeA = strtotime((string)($a['date_requested'] ?? '')) ?: 0;
        $timeB = strtotime((string)($b['date_requested'] ?? '')) ?: 0;
        if ($timeA !== $timeB) {
            return $timeB <=> $timeA;
        }
        return ((int)($b['notification_id'] ?? 0)) <=> ((int)($a['notification_id'] ?? 0));
    });
    $requests = array_slice($requests, 0, $limit);

    $resourceAdditions = [];
    if (resource_notification_table_exists($pdo, 'activity_log')) {
        $stmt = $pdo->query(
            "SELECT id AS notification_id, entity_id, details, created_at AS notified_at
             FROM activity_log
             WHERE action = 'resource_added'
               AND entity_type IN ('resource', 'resource_request')
             ORDER BY id DESC
             LIMIT " . (int)$limit
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $resourceAdditions[] = resource_notification_addition_item($row);
        }
    }

    echo json_encode([
        'success' => true,
        'pending_count' => count($requests),
        'requests' => $requests,
        'resource_additions' => $resourceAdditions,
        'latest_resource_addition_id' => $resourceAdditions[0]['notification_id'] ?? 0,
        'latest_request_id' => $requests ? max(array_map(static fn(array $item): int => (int)($item['notification_id'] ?? $item['id'] ?? 0), $requests)) : 0,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'pending_count' => 0,
        'requests' => [],
        'resource_additions' => [],
    ]);
}
