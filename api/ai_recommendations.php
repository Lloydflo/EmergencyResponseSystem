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
    $activeIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('pending','dispatched')")->fetch()['c'];
    $availableUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status='available'")->fetch()['c'];
    $pendingCalls = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status='pending'")->fetch()['c'];
    $currentIncident = 'No active incident context';
    $topIncident = $pdo->query("SELECT reference_no, type, location_address, priority
                                FROM incidents
                                WHERE status IN ('pending','dispatched','active','in_progress')
                                ORDER BY CASE LOWER(priority)
                                    WHEN 'critical' THEN 1
                                    WHEN 'high' THEN 2
                                    WHEN 'urgent' THEN 2
                                    WHEN 'medium' THEN 3
                                    WHEN 'moderate' THEN 3
                                    WHEN 'low' THEN 4
                                    ELSE 6
                                END, created_at DESC
                                LIMIT 1")->fetch();
    if ($topIncident) {
        $currentIncident = trim(
            (string)($topIncident['reference_no'] ?? '') . ' ' .
            (string)($topIncident['type'] ?? '') . ' ' .
            (string)($topIncident['location_address'] ?? '') . ' ' .
            strtoupper((string)($topIncident['priority'] ?? ''))
        );
    }

    $dispatchData = [
        'active_incidents' => $activeIncidents,
        'available_units' => $availableUnits,
        'pending_calls' => $pendingCalls,
        'current_incident' => $currentIncident
    ];
    $text = getDispatchRecommendations($dispatchData);
    if ($text) {
        echo json_encode(['ok' => true, 'text' => $text]);
    } else {
        $error = function_exists('getGeminiLastError') ? trim((string) getGeminiLastError()) : '';
        echo json_encode(['ok' => false, 'error' => $error !== '' ? $error : 'AI unavailable']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
