<?php
// Deploy resource: legacy tables keep their existing status flow, admin-managed resources move to in_use.
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

if (!defined('RESOURCE_RECORDS_TABLE')) {
    define('RESOURCE_RECORDS_TABLE', 'resource_records');
}
if (!defined('LEGACY_ADMIN_RESOURCES_TABLE')) {
    define('LEGACY_ADMIN_RESOURCES_TABLE', 'admin_resources');
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : 0;
$type = isset($input['type']) ? strtolower(trim((string)$input['type'])) : '';
$source = isset($input['source']) ? strtolower(trim((string)$input['source'])) : '';
$location = isset($input['location']) ? trim((string)$input['location']) : '';

if (!$id || $type === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id/type']);
    exit;
}

try {
    $resourceTable = null;
    if ($source === RESOURCE_RECORDS_TABLE) {
        $resourceTable = RESOURCE_RECORDS_TABLE;
    } elseif ($source === LEGACY_ADMIN_RESOURCES_TABLE) {
        $resourceTable = LEGACY_ADMIN_RESOURCES_TABLE;
    }

    if ($resourceTable !== null) {
        $resourceStmt = $pdo->prepare("SELECT code, category FROM `" . $resourceTable . "` WHERE id = ? LIMIT 1");
        $resourceStmt->execute([$id]);
        $resourceRow = $resourceStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "UPDATE `" . $resourceTable . "`
             SET status = 'in_use',
                 location = CASE WHEN ? <> '' THEN ? ELSE location END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND category = ?"
        );
        $stmt->execute([$location, $location, $id, $type]);
        if ($stmt->rowCount() === 0) {
            $checkStmt = $pdo->prepare("SELECT id FROM `" . $resourceTable . "` WHERE id = ? AND category = ? LIMIT 1");
            $checkStmt->execute([$id, $type]);
            if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Resource record not found']);
                exit;
            }
        }

        if (
            is_array($resourceRow)
            && strtolower(trim((string)($resourceRow['category'] ?? ''))) === 'vehicles'
            && trim((string)($resourceRow['code'] ?? '')) !== ''
        ) {
            ers_update_unit_status_by_identifier($pdo, (string)$resourceRow['code'], 'assigned');
            if (function_exists('ers_update_vehicle_resource_status_by_identifier')) {
                ers_update_vehicle_resource_status_by_identifier($pdo, (string)$resourceRow['code'], 'in_use');
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($type === 'vehicles') {
        $stmt = $pdo->prepare("UPDATE units SET status = 'assigned', last_status_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
        ers_sync_vehicle_resource_status_by_unit_id($pdo, $id, 'in_use');
    } elseif ($type === 'personnel') {
        $stmt = $pdo->prepare("UPDATE staff SET status = 'on_duty', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($type === 'equipment') {
        $stmt = $pdo->prepare("UPDATE resources SET status = 'deployed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unsupported type']);
        exit;
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
