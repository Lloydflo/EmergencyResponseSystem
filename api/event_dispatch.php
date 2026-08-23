<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in() || !in_array(canonical_role((string)((get_logged_in_user() ?? [])['role'] ?? '')), ['admin', 'dispatcher'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Dispatcher or admin access is required.']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection unavailable.']);
    exit;
}

function event_dispatch_ensure_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_unit_dispatches (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT UNSIGNED NOT NULL,
        unit_id BIGINT UNSIGNED NOT NULL,
        status ENUM('assigned','released') NOT NULL DEFAULT 'assigned',
        assigned_by BIGINT UNSIGNED DEFAULT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        released_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_event_unit (event_id, unit_id),
        KEY idx_event_unit_status (event_id, status),
        KEY idx_event_dispatch_unit (unit_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function event_dispatch_event_exists(PDO $pdo, int $eventId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM interagency_event_profiles WHERE id = ? LIMIT 1');
    $stmt->execute([$eventId]);
    return (bool)$stmt->fetchColumn();
}

function event_dispatch_assignments(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("SELECT ed.id, ed.unit_id, ed.status, ed.assigned_at, ed.released_at,
                                  u.identifier, u.unit_type
                           FROM event_unit_dispatches ed
                           LEFT JOIN units u ON u.id = ed.unit_id
                           WHERE ed.event_id = ?
                           ORDER BY ed.assigned_at DESC, ed.id DESC");
    $stmt->execute([$eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

event_dispatch_ensure_table($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $eventId = max(0, (int)($_GET['event_id'] ?? 0));
    if ($eventId <= 0 || !event_dispatch_event_exists($pdo, $eventId)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found.']);
        exit;
    }
    echo json_encode(['ok' => true, 'assignments' => event_dispatch_assignments($pdo, $eventId)]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET or POST method required.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$eventId = max(0, (int)($input['event_id'] ?? 0));
$action = strtolower(trim((string)($input['action'] ?? 'assign')));
$unitIds = array_values(array_unique(array_filter(array_map('intval', (array)($input['unit_ids'] ?? [])), static fn(int $id): bool => $id > 0)));

if ($eventId <= 0 || !event_dispatch_event_exists($pdo, $eventId)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Event not found.']);
    exit;
}
if ($unitIds === []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Select at least one responder unit.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $unitsStmt = $pdo->prepare("SELECT id, identifier, status FROM units WHERE id IN ($placeholders) FOR UPDATE");
    $unitsStmt->execute($unitIds);
    $units = $unitsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($units) !== count($unitIds)) {
        throw new RuntimeException('One or more selected units were not found.');
    }

    if ($action === 'release') {
        $release = $pdo->prepare("UPDATE event_unit_dispatches
                                  SET status = 'released', released_at = NOW()
                                  WHERE event_id = ? AND unit_id IN ($placeholders) AND status = 'assigned'");
        $release->execute(array_merge([$eventId], $unitIds));
        $unitUpdate = $pdo->prepare("UPDATE units SET status = 'available' WHERE id IN ($placeholders)");
        $unitUpdate->execute($unitIds);
    } else {
        foreach ($units as $unit) {
            if (strtolower((string)$unit['status']) !== 'available') {
                throw new RuntimeException('Unit ' . ($unit['identifier'] ?: $unit['id']) . ' is no longer available.');
            }
        }
        $operatorId = max(0, (int)($_SESSION['user_id'] ?? 0)) ?: null;
        $assignment = $pdo->prepare("INSERT INTO event_unit_dispatches (event_id, unit_id, status, assigned_by, assigned_at, released_at)
                                     VALUES (?, ?, 'assigned', ?, NOW(), NULL)
                                     ON DUPLICATE KEY UPDATE status = 'assigned', assigned_by = VALUES(assigned_by), assigned_at = NOW(), released_at = NULL");
        foreach ($unitIds as $unitId) {
            $assignment->execute([$eventId, $unitId, $operatorId]);
        }
        $unitUpdate = $pdo->prepare("UPDATE units SET status = 'assigned' WHERE id IN ($placeholders)");
        $unitUpdate->execute($unitIds);
    }
    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => $action === 'release' ? 'Units released from the event.' : 'Responder units assigned to the event.', 'assignments' => event_dispatch_assignments($pdo, $eventId)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
