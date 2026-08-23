<?php
// Resolve an incident and release any assigned units
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';
require_once __DIR__ . '/../includes/emergency_com_status_sync.php';
require_once __DIR__ . '/../includes/anonymous_tip_status_sync.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$hasIncidentId = is_array($input)
    && array_key_exists('incident_id', $input)
    && $input['incident_id'] !== ''
    && is_numeric((string)$input['incident_id']);
$incidentId = $hasIncidentId ? (int)$input['incident_id'] : null;
$incidentCode = '';
if (is_array($input)) {
    if (array_key_exists('incident_code', $input)) {
        $incidentCode = trim((string)$input['incident_code']);
    } elseif (array_key_exists('reference_no', $input)) {
        $incidentCode = trim((string)$input['reference_no']);
    }
}
$note = is_array($input) && isset($input['note']) ? trim((string)$input['note']) : '';

if ($incidentId === null && $incidentCode === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing incident identifier']);
    exit;
}

function incident_resolve_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function incident_resolve_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function incident_resolve_log_notification(PDO $pdo, int $incidentId, string $note = ''): void
{
    if ($incidentId <= 0 || !incident_resolve_table_exists($pdo, 'activity_log')) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT reference_no, status, resolved_at FROM incidents WHERE id = ? LIMIT 1');
        $stmt->execute([$incidentId]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $reference = trim((string)($incident['reference_no'] ?? ''));
        $userId = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0
            ? (int)$_SESSION['user_id']
            : null;
        $role = strtolower(trim((string)($_SESSION['login_role'] ?? $_SESSION['user_role'] ?? '')));
        if (!in_array($role, ['admin', 'dispatcher'], true)) {
            $role = $userId ? 'dispatcher' : 'system';
        }
        $source = $role === 'admin' ? 'admin_web' : ($role === 'dispatcher' ? 'dispatcher_web' : 'server_api');
        $occurredAt = trim((string)($incident['resolved_at'] ?? '')) ?: null;

        record_operational_audit_event(
            $pdo,
            $userId,
            'incident_resolved',
            'incident',
            $incidentId,
            'Incident ' . ($reference !== '' ? $reference : ('#' . $incidentId)) . ' was resolved from the operations website.',
            [
                'actor_role' => $role,
                'source_channel' => $source,
                'event_category' => 'completion',
                'event_outcome' => 'success',
                'reference_no' => $reference,
                'incident_id' => $incidentId,
                'occurred_at' => $occurredAt,
                'event_key' => 'incident:' . $incidentId . ':resolved:' . $source,
                'metadata' => [
                    'incident_status' => (string)($incident['status'] ?? 'resolved'),
                    'resolution_note_recorded' => trim($note) !== '',
                    'units_released' => true,
                ],
            ]
        );
    } catch (Throwable $notificationError) {
        error_log('Incident resolve audit skipped: ' . $notificationError->getMessage());
    }
}

function incident_resolve_complete_operator_records(PDO $pdo, int $incidentId): void
{
    if (
        $incidentId <= 0
        || !incident_resolve_table_exists($pdo, 'dispatch_operator_records')
        || !incident_resolve_column_exists($pdo, 'dispatch_operator_records', 'status')
    ) {
        return;
    }

    try {
        $updates = 0;
        if (incident_resolve_column_exists($pdo, 'dispatch_operator_records', 'incident_id')) {
            $stmt = $pdo->prepare("
                UPDATE dispatch_operator_records
                SET status = 'completed'
                WHERE incident_id = :iid
                  AND LOWER(COALESCE(status, '')) NOT IN ('completed', 'resolved', 'cancelled')
            ");
            $stmt->execute([':iid' => $incidentId]);
            $updates += (int)$stmt->rowCount();
        }

        if (
            $updates === 0
            && incident_resolve_table_exists($pdo, 'dispatches')
            && incident_resolve_table_exists($pdo, 'units')
            && incident_resolve_column_exists($pdo, 'dispatch_operator_records', 'assigned_unit_code')
            && incident_resolve_column_exists($pdo, 'dispatch_operator_records', 'assigned_at')
        ) {
            $stmt = $pdo->prepare("
                UPDATE dispatch_operator_records dor
                INNER JOIN units u
                    ON UPPER(TRIM(u.identifier)) = UPPER(TRIM(dor.assigned_unit_code))
                INNER JOIN dispatches d
                    ON d.unit_id = u.id
                   AND d.incident_id = :iid
                   AND ABS(TIMESTAMPDIFF(SECOND, d.assigned_at, COALESCE(dor.assigned_at, dor.created_at))) <= 60
                SET dor.status = 'completed'
                WHERE LOWER(COALESCE(dor.status, '')) NOT IN ('completed', 'resolved', 'cancelled')
            ");
            $stmt->execute([':iid' => $incidentId]);
        }
    } catch (Throwable $operatorError) {
        error_log('Incident resolve operator-record completion skipped: ' . $operatorError->getMessage());
    }
}

try {
    if ($incidentId === null && $incidentCode !== '') {
        $lookup = $pdo->prepare('SELECT id FROM incidents WHERE reference_no = :ref LIMIT 1');
        $lookup->execute([':ref' => $incidentCode]);
        $resolvedId = $lookup->fetchColumn();
        $incidentId = $resolvedId ? (int)$resolvedId : null;
    }
    if ($incidentId === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Incident not found']);
        exit;
    }

    $pdo->beginTransaction();

    $hasUnitsTable = incident_resolve_table_exists($pdo, 'units');
    $hasUnitCurrentIncidentId = $hasUnitsTable && incident_resolve_column_exists($pdo, 'units', 'current_incident_id');
    $hasUnitLastStatusAt = $hasUnitsTable && incident_resolve_column_exists($pdo, 'units', 'last_status_at');
    $unitIds = [];

    if ($hasUnitCurrentIncidentId) {
        $unitIdStmt = $pdo->prepare("SELECT id FROM units WHERE current_incident_id = :iid");
        $unitIdStmt->execute([':iid' => $incidentId]);
        $unitIds = array_map(static function ($row): int {
            return (int)($row['id'] ?? 0);
        }, $unitIdStmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Update all dispatches for this incident to 'cleared' (will trigger unit availability via DB trigger)
    if (incident_resolve_table_exists($pdo, 'dispatches')) {
        try {
            $dispatchFields = ["status='cleared'"];
            if (incident_resolve_column_exists($pdo, 'dispatches', 'cleared_at')) {
                $dispatchFields[] = 'cleared_at = CURRENT_TIMESTAMP';
            }
            $stmt = $pdo->prepare(
                'UPDATE dispatches SET ' . implode(', ', $dispatchFields) .
                " WHERE incident_id = :iid AND status IN ('assigned','acknowledged','enroute','on_scene')"
            );
            $stmt->execute([':iid' => $incidentId]);
        } catch (Throwable $dispatchError) {
            error_log('Incident resolve dispatch clear skipped: ' . $dispatchError->getMessage());
        }
    }

    // Explicitly set units available for any units linked directly (safety net)
    if ($hasUnitCurrentIncidentId) {
        try {
            $unitFields = ['current_incident_id=NULL'];
            if (incident_resolve_column_exists($pdo, 'units', 'status')) {
                $unitFields[] = "status='available'";
            }
            if ($hasUnitLastStatusAt) {
                $unitFields[] = 'last_status_at=CURRENT_TIMESTAMP';
            }
            $stmt2 = $pdo->prepare('UPDATE units SET ' . implode(', ', $unitFields) . ' WHERE current_incident_id = :iid');
            $stmt2->execute([':iid' => $incidentId]);
        } catch (Throwable $unitError) {
            error_log('Incident resolve unit release skipped: ' . $unitError->getMessage());
        }
    }

    try {
        ers_sync_vehicle_resource_status_by_unit_ids($pdo, $unitIds, 'available');
    } catch (Throwable $syncError) {
        error_log('Incident resolve resource sync skipped: ' . $syncError->getMessage());
    }

    incident_resolve_complete_operator_records($pdo, $incidentId);

    // Mark incident resolved
    $incidentFields = ["status='resolved'"];
    if (incident_resolve_column_exists($pdo, 'incidents', 'resolved_at')) {
        $incidentFields[] = 'resolved_at=CURRENT_TIMESTAMP';
    }
    if (incident_resolve_column_exists($pdo, 'incidents', 'updated_at')) {
        $incidentFields[] = 'updated_at=CURRENT_TIMESTAMP';
    }
    $stmt3 = $pdo->prepare('UPDATE incidents SET ' . implode(', ', $incidentFields) . ' WHERE id = :iid');
    $stmt3->execute([':iid' => $incidentId]);

    incident_resolve_log_notification($pdo, $incidentId, $note);

    // Optional: add note to incident_notes
    if (
        $note !== ''
        && incident_resolve_table_exists($pdo, 'incident_notes')
        && incident_resolve_column_exists($pdo, 'incident_notes', 'incident_id')
        && incident_resolve_column_exists($pdo, 'incident_notes', 'author_name')
        && incident_resolve_column_exists($pdo, 'incident_notes', 'note')
    ) {
        try {
            $stmt4 = $pdo->prepare("INSERT INTO incident_notes (incident_id, author_name, note) VALUES (:iid, 'System', :note)");
            $stmt4->execute([':iid' => $incidentId, ':note' => $note]);
        } catch (Throwable $noteError) {
            error_log('Incident resolve note insert skipped: ' . $noteError->getMessage());
        }
    }

    $pdo->commit();
    ers_notify_emergency_com_status($pdo, $incidentId, $note);
    ers_notify_anonymous_tip_status($pdo, $incidentId, 'resolved', $note);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    error_log('Incident resolve failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Resolve failed: ' . $e->getMessage()]);
}
