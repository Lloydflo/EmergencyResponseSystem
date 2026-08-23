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

    $hasIncidentColumn = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'interagency_event_profiles' AND COLUMN_NAME = 'incident_id' LIMIT 1");
    $hasIncidentColumn->execute();
    if (!(bool)$hasIncidentColumn->fetchColumn()) {
        $pdo->exec('ALTER TABLE interagency_event_profiles ADD COLUMN incident_id BIGINT UNSIGNED DEFAULT NULL, ADD KEY idx_event_incident_id (incident_id)');
    }
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

function event_dispatch_incident(PDO $pdo, int $eventId): int
{
    $eventStmt = $pdo->prepare("SELECT id, incident_id, coordination_id, event_profile, event_location, event_schedule, on_site_safety_hazard_level,
                                       required_standby_responders, emergency_contact_persons, source_system
                                FROM interagency_event_profiles WHERE id = ? FOR UPDATE");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }
    $existingId = (int)($event['incident_id'] ?? 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $priority = strtolower((string)($event['on_site_safety_hazard_level'] ?? 'medium'));
    if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) $priority = 'medium';
    $reference = 'EVT-' . date('YmdHis') . '-' . $eventId;
    $title = 'Event Standby: ' . trim((string)($event['event_profile'] ?? 'Event Coordination'));
    $description = 'Event coordination ID: ' . (string)($event['coordination_id'] ?? '')
        . "\nLocation: " . (string)($event['event_location'] ?? 'Not provided')
        . "\nSchedule: " . (string)($event['event_schedule'] ?? 'Not provided')
        . "\nRequired standby responders: " . (string)($event['required_standby_responders'] ?? 0)
        . "\nEmergency contacts: " . (string)($event['emergency_contact_persons'] ?? 'Not provided')
        . "\nSource system: " . (string)($event['source_system'] ?? 'ERS');
    $insert = $pdo->prepare("INSERT INTO incidents
        (reference_no, type, priority, status, title, description, location_address, latitude, longitude, reported_by_call_id, created_at)
        VALUES (?, 'other', ?, 'dispatched', ?, ?, ?, NULL, NULL, NULL, NOW())");
    $insert->execute([$reference, $priority, $title, $description, (string)($event['event_location'] ?? '') ?: 'Event location not provided']);
    $incidentId = (int)$pdo->lastInsertId();
    $link = $pdo->prepare('UPDATE interagency_event_profiles SET incident_id = ? WHERE id = ?');
    $link->execute([$incidentId, $eventId]);
    return $incidentId;
}

function event_dispatch_units_have_incident_column(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'units' AND COLUMN_NAME = 'current_incident_id' LIMIT 1");
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
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
        $incidentId = event_dispatch_incident($pdo, $eventId);
        $operatorId = max(0, (int)($_SESSION['user_id'] ?? 0)) ?: null;
        $assignment = $pdo->prepare("INSERT INTO event_unit_dispatches (event_id, unit_id, status, assigned_by, assigned_at, released_at)
                                     VALUES (?, ?, 'assigned', ?, NOW(), NULL)
                                     ON DUPLICATE KEY UPDATE status = 'assigned', assigned_by = VALUES(assigned_by), assigned_at = NOW(), released_at = NULL");
        foreach ($unitIds as $unitId) {
            $assignment->execute([$eventId, $unitId, $operatorId]);
        }
        if (event_dispatch_units_have_incident_column($pdo)) {
            $unitUpdate = $pdo->prepare("UPDATE units SET status = 'assigned', current_incident_id = ? WHERE id IN ($placeholders)");
            $unitUpdate->execute(array_merge([$incidentId], $unitIds));
        } else {
            $unitUpdate = $pdo->prepare("UPDATE units SET status = 'assigned' WHERE id IN ($placeholders)");
            $unitUpdate->execute($unitIds);
        }
    }
    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => $action === 'release' ? 'Units released from the event.' : 'Responder units assigned to the event.', 'incident_id' => $incidentId ?? null, 'assignments' => event_dispatch_assignments($pdo, $eventId)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
