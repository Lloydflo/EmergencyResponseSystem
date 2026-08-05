<?php
// API endpoint: /api/incident_update.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/incident_priority.php';
require_once __DIR__ . '/../includes/emergency_com_status_sync.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request payload']);
    exit;
}

$id = isset($input['id']) ? (int)$input['id'] : 0;
$incidentCode = '';
if (array_key_exists('incident_code', $input)) {
    $incidentCode = trim((string)$input['incident_code']);
} elseif (array_key_exists('reference_no', $input)) {
    $incidentCode = trim((string)$input['reference_no']);
}

if ($id <= 0 && $incidentCode === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing incident identifier']);
    exit;
}
// Accept optional fields; update only provided values
$type = isset($input['type']) ? trim((string)$input['type']) : null;
$priority = isset($input['priority']) ? trim((string)$input['priority']) : null;
$description = isset($input['description']) ? trim((string)$input['description']) : null;
// Map either 'location' or 'location_address' to DB column 'location_address'
$location_address = null;
if (array_key_exists('location_address', $input)) {
    $location_address = trim((string)$input['location_address']);
} elseif (array_key_exists('location', $input)) {
    $location_address = trim((string)$input['location']);
}
$status = isset($input['status']) ? trim((string)$input['status']) : null;

try {
    $pdo = get_db_connection();
    if ($id <= 0 && $incidentCode !== '') {
        $lookupStmt = $pdo->prepare('SELECT id FROM incidents WHERE reference_no = :ref LIMIT 1');
        $lookupStmt->execute([':ref' => $incidentCode]);
        $resolvedId = $lookupStmt->fetchColumn();
        $id = $resolvedId ? (int)$resolvedId : 0;
    }
    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Incident not found']);
        exit;
    }

    $existsStmt = $pdo->prepare('SELECT id, reference_no, type, priority, status, location_address, description FROM incidents WHERE id = :id LIMIT 1');
    $existsStmt->execute([':id' => $id]);
    $originalIncident = $existsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$originalIncident) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Incident not found']);
        exit;
    }

    $fields = [];
    $params = [':id' => $id];
    // Validate enums if provided
    if ($priority !== null) {
        $p = ers_normalize_priority_value($priority);
        if (!in_array($p, ['critical','high','medium','low'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid priority']);
            exit;
        }
        $fields[] = 'priority = :priority';
        $params[':priority'] = $p;
    }
    if ($type !== null) {
        $fields[] = 'type = :type';
        $params[':type'] = $type;
    }
    if ($description !== null) {
        $fields[] = 'description = :description';
        $params[':description'] = $description;
    }
    if ($location_address !== null) {
        $fields[] = 'location_address = :location_address';
        $params[':location_address'] = $location_address;
    }
    if ($status !== null) {
        $s = strtolower($status);
        if (!in_array($s, ['pending','received','dispatching','dispatched','ongoing_dispatch','in_progress','resolved','completed','cancelled'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid status']);
            exit;
        }
        $fields[] = 'status = :status';
        $params[':status'] = $s;
    }
    if (!$fields) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No fields to update']);
        exit;
    }
    $sql = 'UPDATE incidents SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($status !== null) {
        ers_notify_emergency_com_status($pdo, $id);
    }

    $updatedStmt = $pdo->prepare('SELECT reference_no, type, priority, status, location_address, description, updated_at FROM incidents WHERE id = ? LIMIT 1');
    $updatedStmt->execute([$id]);
    $updatedIncident = $updatedStmt->fetch(PDO::FETCH_ASSOC) ?: $originalIncident;
    $changedFields = [];
    $auditableChanges = [];
    foreach (['type', 'priority', 'status'] as $field) {
        $before = (string)($originalIncident[$field] ?? '');
        $after = (string)($updatedIncident[$field] ?? '');
        if ($before !== $after) {
            $changedFields[] = $field;
            $auditableChanges[$field] = ['from' => $before, 'to' => $after];
        }
    }
    foreach (['description', 'location_address'] as $field) {
        $before = (string)($originalIncident[$field] ?? '');
        $after = (string)($updatedIncident[$field] ?? '');
        if ($before !== $after) {
            $changedFields[] = $field;
            $auditableChanges[$field] = ['changed' => true];
        }
    }
    $userId = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0 ? (int)$_SESSION['user_id'] : null;
    $role = strtolower(trim((string)($_SESSION['login_role'] ?? $_SESSION['user_role'] ?? '')));
    if (!in_array($role, ['admin', 'dispatcher'], true)) {
        $role = $userId ? 'dispatcher' : 'system';
    }
    $source = $role === 'admin' ? 'admin_web' : ($role === 'dispatcher' ? 'dispatcher_web' : 'server_api');
    $referenceNo = trim((string)($updatedIncident['reference_no'] ?? ''));
    record_operational_audit_event(
        $pdo,
        $userId,
        'incident_updated',
        'incident',
        $id,
        'Incident ' . ($referenceNo !== '' ? $referenceNo : ('#' . $id)) . ' was updated: ' . implode(', ', $changedFields) . '.',
        [
            'actor_role' => $role,
            'source_channel' => $source,
            'event_category' => 'incident',
            'event_outcome' => 'success',
            'reference_no' => $referenceNo,
            'incident_id' => $id,
            'occurred_at' => $updatedIncident['updated_at'] ?? null,
            'metadata' => [
                'changed_fields' => $changedFields,
                'changes' => $auditableChanges,
            ],
        ]
    );

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
