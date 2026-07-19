<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";
require_once __DIR__ . "/../../includes/vehicle_resource_units.php";
require_once __DIR__ . "/../../includes/activity_log.php";

$assignment_id = intval($_POST["assignment_id"] ?? 0);
$responder_id  = intval($_POST["responder_id"] ?? 0);
$notes         = trim($_POST["notes"] ?? "");

if ($assignment_id <= 0 || $responder_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

if (!isset($_FILES["proof_image"])) {
    echo json_encode(["success" => false, "message" => "No proof_image file uploaded"]);
    exit;
}

$file = $_FILES["proof_image"];
if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(["success" => false, "message" => "Upload failed with error code: " . (int)$file["error"]]);
    exit;
}

$maxSizeBytes = 5 * 1024 * 1024;
if (($file["size"] ?? 0) <= 0 || $file["size"] > $maxSizeBytes) {
    echo json_encode(["success" => false, "message" => "File must be greater than 0 bytes and up to 5 MB"]);
    exit;
}

$allowedMimeToExt = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp"
];

$detectedMime = mime_content_type($file["tmp_name"]);
if (!isset($allowedMimeToExt[$detectedMime])) {
    echo json_encode(["success" => false, "message" => "Unsupported image type"]);
    exit;
}

$ext = $allowedMimeToExt[$detectedMime];
$uploadDir = __DIR__ . "/../../uploads/completion_proof/";
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
    echo json_encode(["success" => false, "message" => "Failed to create upload directory"]);
    exit;
}

$newName = "proof_" . $assignment_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
$targetPath = $uploadDir . $newName;
$relativePath = "/uploads/completion_proof/" . $newName;

function app_complete_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->execute([$tableName, $columnName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function app_complete_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
        );
        $stmt->execute([$tableName, $indexName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function app_complete_ensure_incident_link(PDO $pdo): void
{
    if (!app_complete_column_exists($pdo, 'dispatch_operator_records', 'incident_id')) {
        $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD COLUMN `incident_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `id`");
    }
    if (!app_complete_index_exists($pdo, 'dispatch_operator_records', 'idx_dispatch_operator_records_incident_id')) {
        $pdo->exec("ALTER TABLE `dispatch_operator_records` ADD KEY `idx_dispatch_operator_records_incident_id` (`incident_id`)");
    }
}

function app_complete_find_dispatch(PDO $pdo, array $assignment): ?array
{
    $activeDispatchStatuses = "'assigned','acknowledged','enroute','en_route','on_scene','busy','in_use'";
    $incidentId = (int)($assignment["incident_id"] ?? 0);
    if ($incidentId > 0) {
        $stmt = $pdo->prepare("
            SELECT d.id AS dispatch_id, d.incident_id, d.unit_id
            FROM dispatches d
            WHERE d.incident_id = ?
            ORDER BY CASE WHEN d.status IN ({$activeDispatchStatuses}) THEN 0 ELSE 1 END, d.id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        return ["dispatch_id" => null, "incident_id" => $incidentId, "unit_id" => null];
    }

    $unitCode = trim((string)($assignment["assigned_unit_code"] ?? ""));
    if ($unitCode === "") {
        $unitCode = trim((string)($assignment["responder_unit_code"] ?? ""));
    }
    if ($unitCode === "") return null;

    $stmt = $pdo->prepare("
        SELECT d.id AS dispatch_id, d.incident_id, d.unit_id
        FROM dispatches d
        INNER JOIN units u ON u.id = d.unit_id
        WHERE UPPER(TRIM(u.identifier)) = UPPER(TRIM(?))
          AND d.incident_id > 0
          AND d.status IN ({$activeDispatchStatuses})
        ORDER BY d.assigned_at DESC, d.id DESC
        LIMIT 1
    ");
    $stmt->execute([$unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function app_complete_resolve_incident(PDO $pdo, array $assignment, int $responderId): array
{
    $dispatch = app_complete_find_dispatch($pdo, $assignment);
    $incidentId = $dispatch ? (int)($dispatch["incident_id"] ?? 0) : 0;
    if ($incidentId <= 0) {
        return ["resolved" => false, "incident_id" => null];
    }

    $assignmentId = (int)($assignment["id"] ?? 0);
    if ($assignmentId > 0 && app_complete_column_exists($pdo, 'dispatch_operator_records', 'incident_id')) {
        $backfill = $pdo->prepare("UPDATE dispatch_operator_records SET incident_id = ? WHERE id = ? AND (incident_id IS NULL OR incident_id = 0)");
        $backfill->execute([$incidentId, $assignmentId]);
    }

    $unitIdsStmt = $pdo->prepare("SELECT unit_id FROM dispatches WHERE incident_id = ?");
    $unitIdsStmt->execute([$incidentId]);
    $unitIds = array_values(array_filter(array_map(static function ($row): int {
        return (int)($row["unit_id"] ?? 0);
    }, $unitIdsStmt->fetchAll(PDO::FETCH_ASSOC))));
    $dispatchUnitId = $dispatch ? (int)($dispatch["unit_id"] ?? 0) : 0;
    if ($dispatchUnitId > 0) {
        $unitIds[] = $dispatchUnitId;
    }

    $unitCode = trim((string)($assignment["assigned_unit_code"] ?? ""));
    if ($unitCode === "") {
        $unitCode = trim((string)($assignment["responder_unit_code"] ?? ""));
    }
    if ($unitCode !== "") {
        $unitLookup = $pdo->prepare("SELECT id FROM units WHERE UPPER(TRIM(identifier)) = UPPER(TRIM(?)) LIMIT 1");
        $unitLookup->execute([$unitCode]);
        $unitLookupId = (int)$unitLookup->fetchColumn();
        if ($unitLookupId > 0) {
            $unitIds[] = $unitLookupId;
        }
    }
    $unitIds = array_values(array_unique(array_filter($unitIds)));

    $dispatchUpdate = $pdo->prepare("
        UPDATE dispatches
        SET status = 'cleared', cleared_at = COALESCE(cleared_at, CURRENT_TIMESTAMP)
        WHERE incident_id = ? AND status IN ('assigned','acknowledged','enroute','en_route','on_scene','busy','in_use')
    ");
    $dispatchUpdate->execute([$incidentId]);

    if ($unitIds !== []) {
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $unitUpdate = $pdo->prepare("
            UPDATE units SET status = 'available', current_incident_id = NULL, last_status_at = CURRENT_TIMESTAMP
            WHERE id IN ($placeholders)
        ");
        $unitUpdate->execute($unitIds);
        ers_sync_vehicle_resource_status_by_unit_ids($pdo, $unitIds, 'available');
    } elseif ($unitCode !== "") {
        ers_update_vehicle_resource_status_by_identifier($pdo, $unitCode, 'available');
    }

    global $notes, $relativePath;

    $incidentUpdate = $pdo->prepare("
        UPDATE incidents
        SET status = 'resolved',
            resolved_at = COALESCE(resolved_at, CURRENT_TIMESTAMP),
            updated_at = CURRENT_TIMESTAMP,
            completion_notes = ?,
            completion_image_path = ?,
            review_status = 'pending_review',
            completed_by_responder_id = ?,
            completed_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $incidentUpdate->execute([$notes, $relativePath, $responderId, $incidentId]);

    return ["resolved" => true, "incident_id" => $incidentId];
}

function app_complete_log_incident_resolved(int $incidentId, int $responderId): void
{
    if ($incidentId < 1) {
        return;
    }
    $details = 'Incident #' . $incidentId . ' was completed by responder #' . $responderId . '.';
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT reference_no FROM incidents WHERE id = ? LIMIT 1");
        $stmt->execute([$incidentId]);
        $reference = trim((string)$stmt->fetchColumn());
        if ($reference !== '') {
            $details = 'Incident ' . $reference . ' was completed by responder #' . $responderId . '.';
        }
    } catch (Throwable $e) {
        // Best-effort audit detail only.
    }
    log_activity_event($responderId, 'incident_resolved', 'incident', $incidentId, $details);
}

try {
    $pdo = db();
    app_complete_ensure_incident_link($pdo);

    $pdo->beginTransaction();

    $assignmentStmt = $pdo->prepare("
        SELECT d.id, d.incident_id, d.assigned_to, d.assigned_unit_code, d.assigned_at, d.created_at,
               u.unit_code AS responder_unit_code
        FROM dispatch_operator_records d
        LEFT JOIN users u ON u.id = d.assigned_to
        WHERE d.id = ? AND d.assigned_to = ?
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

    if (!move_uploaded_file($file["tmp_name"], $targetPath)) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Failed to store uploaded file"]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE dispatch_operator_records SET status = 'completed' WHERE id = ? AND assigned_to = ?");
    $stmt->execute([$assignment_id, $responder_id]);

    $unit = $pdo->prepare("UPDATE users SET unit_status = 'available' WHERE id = ?");
    $unit->execute([$responder_id]);

    $incidentSync = app_complete_resolve_incident($pdo, $assignment, $responder_id);

    if (!$incidentSync["resolved"]) {
        @unlink($targetPath);
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Could not resolve linked incident"]);
        exit;
    }

    $pdo->commit();
    app_complete_log_incident_resolved((int)$incidentSync["incident_id"], $responder_id);

    echo json_encode([
        "success" => true,
        "incident_id" => $incidentSync["incident_id"],
        "completion_image_path" => $relativePath,
        "review_status" => "pending_review"
    ]);

} catch (Throwable $e) {
    if (isset($targetPath) && file_exists($targetPath)) {
        @unlink($targetPath);
    }
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}
