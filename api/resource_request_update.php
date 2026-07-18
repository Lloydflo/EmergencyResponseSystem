<?php
// api/resource_request_update.php
require_once '../includes/db.php';
require_once '../includes/activity_log.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status = $_POST['status'] ?? '';
$reason = $_POST['reason'] ?? '';

if ($id <= 0 || !in_array($status, ['approved','rejected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid id or status']);
    exit;
}

function infer_resource_bucket(string $rawType, string $resourceName): string {
    $type = strtolower(trim($rawType));
    if (in_array($type, ['vehicle', 'vehicles'], true)) {
        return 'vehicle';
    }
    if (in_array($type, ['personnel', 'staff', 'responder'], true)) {
        return 'personnel';
    }
    if (in_array($type, ['equipment', 'equipments', 'gear', 'tool'], true)) {
        return 'equipment';
    }

    $haystack = strtolower(trim($resourceName . ' ' . $rawType));
    if (preg_match('/ambulance|fire|police|patrol|truck|vehicle|unit|rescue/', $haystack)) {
        return 'vehicle';
    }
    if (preg_match('/personnel|staff|responder|paramedic|nurse|doctor|emt|firefighter|officer/', $haystack)) {
        return 'personnel';
    }
    return 'equipment';
}

function map_unit_type(string $resourceName): string {
    $name = strtolower($resourceName);
    if (strpos($name, 'ambulance') !== false || strpos($name, 'medic') !== false) return 'ambulance';
    if (strpos($name, 'fire') !== false) return 'fire';
    if (strpos($name, 'police') !== false || strpos($name, 'patrol') !== false) return 'police';
    if (strpos($name, 'rescue') !== false) return 'rescue';
    return 'other';
}

function slug_prefix(string $value): string {
    $v = preg_replace('/[^A-Za-z0-9]+/', '', strtoupper($value));
    if (!$v) return 'REQ';
    return substr($v, 0, 3);
}

function provision_vehicle_units(PDO $pdo, string $resourceName, int $quantity, array &$created): void {
    $unitType = map_unit_type($resourceName);
    $prefix = slug_prefix($resourceName);
    $stmt = $pdo->prepare("INSERT INTO units (identifier, unit_type, status, current_incident_id, latitude, longitude, last_status_at, created_at, updated_at) VALUES (?, ?, 'available', NULL, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL)");
    for ($i = 1; $i <= $quantity; $i++) {
        $identifier = sprintf('%s-REQ-%s-%02d', $prefix, date('YmdHis'), $i);
        $stmt->execute([$identifier, $unitType]);
        $created['units'][] = (int)$pdo->lastInsertId();
    }
}

function provision_personnel(PDO $pdo, string $resourceName, int $quantity, array &$created): void {
    $stmt = $pdo->prepare("INSERT INTO staff (name, role, phone, email, status, assigned_resource_id, created_at, updated_at) VALUES (?, 'Responder', NULL, NULL, 'available', NULL, CURRENT_TIMESTAMP, NULL)");
    for ($i = 1; $i <= $quantity; $i++) {
        $name = $quantity > 1 ? ($resourceName . ' #' . $i) : $resourceName;
        $stmt->execute([$name]);
        $created['staff'][] = (int)$pdo->lastInsertId();
    }
}

function provision_equipment(PDO $pdo, string $resourceName, int $quantity, array &$created): void {
    $stmt = $pdo->prepare("INSERT INTO resources (type, name, code, status, location, notes, created_at, updated_at) VALUES ('equipment', ?, ?, 'available', NULL, 'Provisioned from approved resource request', CURRENT_TIMESTAMP, NULL)");
    $prefix = slug_prefix($resourceName);
    for ($i = 1; $i <= $quantity; $i++) {
        $name = $quantity > 1 ? ($resourceName . ' #' . $i) : $resourceName;
        $code = sprintf('EQ-%s-%s-%02d', $prefix, date('YmdHis'), $i);
        $stmt->execute([$name, $code]);
        $created['resources'][] = (int)$pdo->lastInsertId();
    }
}

try {
    $pdo->beginTransaction();

    // Fetch existing request and details JSON
    $stmt = $pdo->prepare('SELECT resource_name, status, details FROM resource_requests WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        exit;
    }

    $resourceName = (string)($row['resource_name'] ?? 'Resource');
    $details = json_decode((string)($row['details'] ?? '{}'), true);
    if (!is_array($details)) { $details = []; }
    $details['decision_reason'] = $reason;
    $details['decision_at'] = date('Y-m-d H:i:s');

    $provisionedNow = false;
    if ($status === 'approved' && empty($details['provisioned_at'])) {
        $quantity = isset($details['quantity']) ? (int)$details['quantity'] : 1;
        if ($quantity < 1) $quantity = 1;
        $bucket = infer_resource_bucket((string)($details['type'] ?? ''), $resourceName);
        $created = ['units' => [], 'staff' => [], 'resources' => []];

        if ($bucket === 'vehicle') {
            provision_vehicle_units($pdo, $resourceName, $quantity, $created);
        } elseif ($bucket === 'personnel') {
            provision_personnel($pdo, $resourceName, $quantity, $created);
        } else {
            provision_equipment($pdo, $resourceName, $quantity, $created);
        }

        $details['provisioned_at'] = date('Y-m-d H:i:s');
        $details['provisioned_bucket'] = $bucket;
        $details['provisioned_items'] = $created;
        $provisionedNow = true;
    }

    // Update status and details
    $upd = $pdo->prepare('UPDATE resource_requests SET status = ?, details = ? WHERE id = ?');
    $upd->execute([$status, json_encode($details, JSON_UNESCAPED_UNICODE), $id]);

    $pdo->commit();
    if ($provisionedNow) {
        $quantity = isset($details['quantity']) ? max(1, (int)$details['quantity']) : 1;
        log_activity_event(
            null,
            'resource_added',
            'resource_request',
            $id,
            json_encode([
                'code' => 'REQ-' . $id,
                'name' => $resourceName,
                'category' => (string)($details['provisioned_bucket'] ?? 'resource'),
                'quantity' => $quantity,
                'added_by' => 'Approved request',
                'message' => $quantity . ' ' . $resourceName . ' provisioned from approved request',
            ], JSON_UNESCAPED_UNICODE)
        );
    }
    echo json_encode(['success' => true, 'provisioned' => $provisionedNow]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
