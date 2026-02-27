<?php
// API: List resolution proofs for an incident
// GET: incident_id
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
$incidentId = isset($_GET['incident_id']) ? (int)$_GET['incident_id'] : 0;
if ($incidentId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing incident_id']);
    exit;
}

try {
    $items = [];
    $tableExists = false;
    try {
        $chk = $pdo->query("SHOW TABLES LIKE 'incident_proofs'");
        $tableExists = (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        $tableExists = false;
    }
    if ($tableExists) {
        $stmt = $pdo->prepare('SELECT id, file_path, created_at FROM incident_proofs WHERE incident_id = ? ORDER BY created_at DESC');
        $stmt->execute([$incidentId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $items[] = [
                'url' => $r['file_path'],
                'created_at' => $r['created_at'] ?? null,
            ];
        }
    } else {
        // Fallback: parse incident_notes entries that contain the marker
        $stmt = $pdo->prepare("SELECT note, created_at FROM incident_notes WHERE incident_id = ? AND note LIKE 'Resolution proof uploaded:%' ORDER BY created_at DESC");
        $stmt->execute([$incidentId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            // Extract URL after colon and trim
            $note = (string)$r['note'];
            $pos = strpos($note, ':');
            $url = $pos !== false ? trim(substr($note, $pos + 1)) : '';
            if ($url !== '') {
                $items[] = [
                    'url' => $url,
                    'created_at' => $r['created_at'] ?? null,
                ];
            }
        }
    }
    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    error_log('Proof list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load proofs']);
}
