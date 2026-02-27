<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

try {
    $vehiclesTotal = (int)$pdo->query("SELECT COUNT(*) AS c FROM units")->fetch()['c'];
    $vehiclesAvailable = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status='available'")->fetch()['c'];
    $vehiclesInUse = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','acknowledged','enroute','on_scene')")->fetch()['c'];
    $vehiclesOffline = max(0, $vehiclesTotal - $vehiclesAvailable - $vehiclesInUse);

    $personnelTotal = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff")->fetch()['c'];
    $personnelAvailable = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];
    $personnelOffline = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('off_duty','leave')")->fetch()['c'];
    $personnelInUse = max(0, $personnelTotal - $personnelAvailable - $personnelOffline);

    $equipmentTotal = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type='equipment'")->fetch()['c'];
    $equipmentAvailable = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type='equipment' AND status IN ('available','ready')")->fetch()['c'];
    $equipmentInUse = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type='equipment' AND status IN ('deployed','in_use','assigned')")->fetch()['c'];
    $equipmentOffline = max(0, $equipmentTotal - $equipmentAvailable - $equipmentInUse);

    $activeIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('pending','dispatched','active','in_progress')")->fetch()['c'];

    $pendingRows = [];
    try {
        $pendingRows = $pdo->query("SELECT resource_name, details FROM resource_requests WHERE status='pending' ORDER BY date_requested DESC LIMIT 100")->fetchAll();
    } catch (Throwable $e) {
        $pendingRows = [];
    }

    $byType = ['vehicle' => 0, 'personnel' => 0, 'equipment' => 0, 'other' => 0];
    foreach ($pendingRows as $row) {
        $details = json_decode((string)($row['details'] ?? ''), true);
        $type = strtolower((string)($details['type'] ?? 'other'));
        $qty = (int)($details['quantity'] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }
        if (!isset($byType[$type])) {
            $type = 'other';
        }
        $byType[$type] += $qty;
    }

    $pendingSummary = 'vehicle=' . $byType['vehicle']
        . ', personnel=' . $byType['personnel']
        . ', equipment=' . $byType['equipment']
        . ', other=' . $byType['other'];

    $resourceData = [
        'vehicles_total' => $vehiclesTotal,
        'vehicles_available' => $vehiclesAvailable,
        'vehicles_inuse' => $vehiclesInUse,
        'vehicles_offline' => $vehiclesOffline,
        'personnel_total' => $personnelTotal,
        'personnel_available' => $personnelAvailable,
        'personnel_inuse' => $personnelInUse,
        'personnel_offline' => $personnelOffline,
        'equipment_total' => $equipmentTotal,
        'equipment_available' => $equipmentAvailable,
        'equipment_inuse' => $equipmentInUse,
        'equipment_offline' => $equipmentOffline,
        'active_incidents' => $activeIncidents,
        'pending_request_summary' => $pendingSummary,
    ];

    $text = getResourceGapRecommendations($resourceData);
    if ($text) {
        echo json_encode([
            'ok' => true,
            'text' => $text,
            'snapshot' => $resourceData,
        ]);
        exit;
    }

    $error = function_exists('getGeminiLastError') ? trim((string)getGeminiLastError()) : '';
    echo json_encode(['ok' => false, 'error' => $error !== '' ? $error : 'AI unavailable']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}

