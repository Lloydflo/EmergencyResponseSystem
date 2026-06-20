<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

$assignment_id = intval($_POST["assignment_id"] ?? 0);
$responder_id  = intval($_POST["responder_id"] ?? 0);
$status        = trim($_POST["status"] ?? "");

$allowed = ["received", "en_route", "on_scene", "completed"];

if ($assignment_id <= 0 || $responder_id <= 0 || !in_array($status, $allowed)) {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

function app_assignment_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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
        $stmt->execute([$tableName, $columnName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function app_assignment_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName, $indexName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function app_assignment_ensure_incident_link(PDO $pdo): void
{
    if (!app_assignment_column_exists($pdo, 'dispatch_operator_records', 'incident_id')) {
        $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD COLUMN `incident_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `id`");
    }
    if (!app_assignment_index_exists($pdo, 'dispatch_operator_records', 'idx_dispatch_operator_records_incident_id')) {
        $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD KEY `idx_dispatch_operator_records_incident_id` (`incident_id`)");
    }
}

function app_assignment_find_dispatch(PDO $pdo, array $assignment): ?array
{
    $incidentId = (int)($assignment["incident_id"] ?? 0);
    if ($incidentId > 0) {
        $stmt = $pdo->prepare("
            SELECT d.id AS dispatch_id, d.incident_id, d.unit_id
            FROM dispatches d
            WHERE d.incident_id = ?
            ORDER BY CASE WHEN d.status IN ('assigned','acknowledged','enroute','on_scene') THEN 0 ELSE 1 END, d.id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        return ["dispatch_id" => null, "incident_id" => $incidentId, "unit_id" => null];
    }

    $unitCode = trim((string)($assignment["assigned_unit_code"] ?? ""));
    if ($unitCode === "") {
        $unitCode = trim((string)($assignment["responder_unit_code"] ?? ""));
    }

    if ($unitCode === "") {
        return null;
    }

    $assignedAt = trim((string)($assignment["assigned_at"] ?? ""));
    if ($assignedAt === "") {
        $assignedAt = trim((string)($assignment["created_at"] ?? ""));
    }

    if ($assignedAt !== "") {
        $stmt = $pdo->prepare("
            SELECT d.id AS dispatch_id, d.incident_id, d.unit_id
            FROM dispatches d
            INNER JOIN units u ON u.id = d.unit_id
            WHERE UPPER(TRIM(u.identifier)) = UPPER(TRIM(?))
              AND d.incident_id > 0
              AND ABS(TIMESTAMPDIFF(SECOND, d.assigned_at, ?)) <= 60
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, d.assigned_at, ?)) ASC, d.id DESC
            LIMIT 1
        ");
        $stmt->execute([$unitCode, $assignedAt, $assignedAt]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $stmt = $pdo->prepare("
        SELECT d.id AS dispatch_id, d.incident_id, d.unit_id
        FROM dispatches d
        INNER JOIN units u ON u.id = d.unit_id
        WHERE UPPER(TRIM(u.identifier)) = UPPER(TRIM(?))
          AND d.incident_id > 0
          AND d.status IN ('assigned','acknowledged','enroute','on_scene')
        ORDER BY d.assigned_at DESC, d.id DESC
        LIMIT 1
    ");
    $stmt->execute([$unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function app_assignment_resolve_incident(PDO $pdo, array $assignment): array
{
    $dispatch = app_assignment_find_dispatch($pdo, $assignment);
    $incidentId = $dispatch ? (int)($dispatch["incident_id"] ?? 0) : 0;
    if ($incidentId <= 0) {
        return ["resolved" => false, "incident_id" => null];
    }

    $assignmentId = (int)($assignment["id"] ?? 0);
    if ($assignmentId > 0 && app_assignment_column_exists($pdo, 'dispatch_operator_records', 'incident_id')) {
        $backfill = $pdo->prepare("UPDATE dispatch_operator_records SET incident_id = ? WHERE id = ? AND (incident_id IS NULL OR incident_id = 0)");
        $backfill->execute([$incidentId, $assignmentId]);
    }

    $unitIdsStmt = $pdo->prepare("SELECT unit_id FROM dispatches WHERE incident_id = ?");
    $unitIdsStmt->execute([$incidentId]);
    $unitIds = array_values(array_filter(array_map(static function ($row): int {
        return (int)($row["unit_id"] ?? 0);
    }, $unitIdsStmt->fetchAll(PDO::FETCH_ASSOC))));

    $dispatchUpdate = $pdo->prepare("
        UPDATE dispatches
        SET status = 'cleared', cleared_at = COALESCE(cleared_at, CURRENT_TIMESTAMP)
        WHERE incident_id = ?
          AND status IN ('assigned','acknowledged','enroute','on_scene')
    ");
    $dispatchUpdate->execute([$incidentId]);

    if ($unitIds !== []) {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $unitUpdate = $pdo->prepare("
            UPDATE units
            SET status = 'available',
                current_incident_id = NULL,
                last_status_at = CURRENT_TIMESTAMP
            WHERE id IN ($placeholders)
        ");
        $unitUpdate->execute($unitIds);
    }

    $incidentUpdate = $pdo->prepare("
        UPDATE incidents
        SET status = 'resolved',
            resolved_at = COALESCE(resolved_at, CURRENT_TIMESTAMP),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $incidentUpdate->execute([$incidentId]);

    app_assignment_log_resolved_notification($pdo, $incidentId);

    return ["resolved" => true, "incident_id" => $incidentId];
}

function app_assignment_log_resolved_notification(PDO $pdo, int $incidentId): void
{
    if ($incidentId <= 0) {
        return;
    }

    try {
        $existsStmt = $pdo->prepare("
            SELECT 1
            FROM activity_log
            WHERE action = 'incident_resolved'
              AND entity_type = 'incident'
              AND entity_id = ?
            LIMIT 1
        ");
        $existsStmt->execute([$incidentId]);
        if ($existsStmt->fetchColumn()) {
            return;
        }

        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log")->fetchColumn();
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details, created_at)
            SELECT
                ?,
                NULL,
                'incident_resolved',
                'incident',
                i.id,
                CONCAT('Incident ', COALESCE(NULLIF(i.reference_no, ''), CONCAT('#', i.id)), ' has been resolved.'),
                CURRENT_TIMESTAMP
            FROM incidents i
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$nextId, $incidentId]);
    } catch (Throwable $e) {
        error_log('Incident resolved notification skipped: ' . $e->getMessage());
    }
}

try {
    $pdo = db();
    app_assignment_ensure_incident_link($pdo);

    $pdo->beginTransaction();

    $assignmentStmt = $pdo->prepare("
        SELECT
            d.id,
            d.incident_id,
            d.assigned_to,
            d.assigned_unit_code,
            d.assigned_at,
            d.created_at,
            u.unit_code AS responder_unit_code
        FROM dispatch_operator_records d
        LEFT JOIN users u ON u.id = d.assigned_to
        WHERE d.id = ?
          AND d.assigned_to = ?
        LIMIT 1
        FOR UPDATE
    ");
    $assignmentStmt->execute([$assignment_id, $responder_id]);
    $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Assignment not found"]);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE dispatch_operator_records
        SET status = ?
        WHERE id = ?
        AND assigned_to = ?
    ");
    $stmt->execute([$status, $assignment_id, $responder_id]);

    $unit_status = match ($status) {
        "received"  => "assigned",
        "en_route"  => "en_route",
        "on_scene"  => "on_scene",
        "completed" => "available",
        default     => "assigned"
    };

    $unit = $pdo->prepare("
        UPDATE users
        SET unit_status = ?
        WHERE id = ?
    ");
    $unit->execute([$unit_status, $responder_id]);

    $incidentSync = ["resolved" => false, "incident_id" => null];
    if ($status === "completed") {
        $incidentSync = app_assignment_resolve_incident($pdo, $assignment);
    }

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "assignment_status" => $status,
        "unit_status" => $unit_status,
        "incident_resolved" => (bool)$incidentSync["resolved"],
        "incident_id" => $incidentSync["incident_id"]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage()
    ]);
}
