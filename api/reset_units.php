<?php
// Reset dispatched units back to available
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$unitIds = [];
$count = 2;

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['unit_ids']) && is_array($input['unit_ids'])) {
        $unitIds = array_values(array_filter(array_map('intval', $input['unit_ids'])));
    }
    if (isset($input['count'])) {
        $count = max(1, (int)$input['count']);
    }
} else {
    if (isset($_GET['count'])) {
        $count = max(1, (int)$_GET['count']);
    }
}

try {
    $pdo->beginTransaction();

    // If specific unit IDs not provided, pick the latest non-available units
    if (empty($unitIds)) {
        $allowed = ["assigned","enroute","on_scene","unavailable"];
        $place = implode(',', array_fill(0, count($allowed), '?'));
        $sql = "SELECT id FROM units WHERE status IN ($place) ORDER BY last_status_at DESC LIMIT " . $count;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($allowed);
        $unitIds = array_map(function ($r) { return (int)$r['id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    $updated = [];
    if (!empty($unitIds)) {
        $stmt = $pdo->prepare('UPDATE units SET status = \"available\", current_incident_id = NULL, last_status_at = CURRENT_TIMESTAMP WHERE id = ?');
        foreach ($unitIds as $uid) {
            $stmt->execute([$uid]);
            // Return identifier for UI context
            $s2 = $pdo->prepare('SELECT id, identifier, status FROM units WHERE id = ?');
            $s2->execute([$uid]);
            if ($row = $s2->fetch(PDO::FETCH_ASSOC)) {
                $updated[] = $row;
            }
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'reset_count' => count($updated), 'units' => $updated]);
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Reset failed']);
}
