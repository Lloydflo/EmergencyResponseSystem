<?php
// Returns interagency chat messages, optionally filtered by department
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

// Map department label to entity_id for activity_log
function dept_to_entity_id($dept) {
    switch (strtolower($dept)) {
        case 'police': return 1;
        case 'fire': return 2;
        case 'medical': return 3;
        case 'coordinator': return 4;
        default: return null;
    }
}

$dept = isset($_GET['department']) ? trim((string)$_GET['department']) : '';
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;

try {
    if ($dept !== '' && strtolower($dept) !== 'all') {
        $eid = dept_to_entity_id($dept);
        if ($eid === null) {
            echo json_encode(['ok' => true, 'items' => []]);
            exit;
        }
        if ($sinceId > 0) {
            $stmt = $pdo->prepare("SELECT id, entity_id, details, created_at FROM activity_log WHERE entity_type='agency_chat' AND entity_id=? AND id>? ORDER BY id ASC LIMIT ?");
            $stmt->execute([$eid, $sinceId, $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT id, entity_id, details, created_at FROM activity_log WHERE entity_type='agency_chat' AND entity_id=? ORDER BY id DESC LIMIT ?");
            $stmt->execute([$eid, $limit]);
        }
    } else {
        if ($sinceId > 0) {
            $stmt = $pdo->prepare("SELECT id, entity_id, details, created_at FROM activity_log WHERE entity_type='agency_chat' AND id>? ORDER BY id ASC LIMIT ?");
            $stmt->execute([$sinceId, $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT id, entity_id, details, created_at FROM activity_log WHERE entity_type='agency_chat' ORDER BY id DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Map entity_id back to department string
    $map = [1=>'police', 2=>'fire', 3=>'medical', 4=>'coordinator'];
    $items = array_map(function($r) use ($map) {
        $dept = $map[$r['entity_id']] ?? 'system';
        return [
            'id' => (int)$r['id'],
            'department' => $dept,
            'text' => (string)$r['details'],
            'created_at' => (string)$r['created_at']
        ];
    }, $rows);
    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
