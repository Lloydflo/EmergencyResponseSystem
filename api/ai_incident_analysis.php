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
    $sql = "SELECT id, reference_no, type, priority, status, location_address, description
            FROM incidents
            WHERE status IN ('pending','dispatched','active','in_progress')
            ORDER BY CASE LOWER(priority)
                         WHEN 'critical' THEN 1
                         WHEN 'high' THEN 2
                         WHEN 'urgent' THEN 3
                         WHEN 'moderate' THEN 4
                         WHEN 'medium' THEN 4
                         WHEN 'low' THEN 5
                         ELSE 6
                     END,
                     created_at DESC
            LIMIT 1";
    $incident = $pdo->query($sql)->fetch();

    if (!$incident) {
        $incident = $pdo->query("SELECT id, reference_no, type, priority, status, location_address, description
                                 FROM incidents
                                 ORDER BY created_at DESC
                                 LIMIT 1")->fetch();
    }

    if (!$incident) {
        echo json_encode(['ok' => false, 'error' => 'No incidents found for AI analysis']);
        exit;
    }

    $incidentData = [
        'type' => (string)($incident['type'] ?? 'Unknown'),
        'location' => (string)($incident['location_address'] ?? 'Unknown'),
        'description' => (string)($incident['description'] ?? 'No description'),
        'severity' => strtoupper((string)($incident['priority'] ?? 'Unknown')),
    ];

    $text = analyzeIncident($incidentData);
    if ($text) {
        echo json_encode([
            'ok' => true,
            'text' => $text,
            'incident_ref' => (string)($incident['reference_no'] ?? ''),
            'incident_id' => (int)($incident['id'] ?? 0),
        ]);
        exit;
    }

    $error = function_exists('getGeminiLastError') ? trim((string)getGeminiLastError()) : '';
    echo json_encode(['ok' => false, 'error' => $error !== '' ? $error : 'AI unavailable']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
