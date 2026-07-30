<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

function ers_table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

try {
    $vehicleResourceTable = ers_vehicle_resource_units_table($pdo);

    $pendingIncidents = (int)($pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status = 'pending'")->fetch()['c'] ?? 0);
    $activeDispatches = (int)($pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('dispatched', 'active', 'in_progress')")->fetch()['c'] ?? 0);
    $availableUnits = ers_count_available_vehicle_resource_units($pdo, $vehicleResourceTable ?? null);
    $unitsInField = (int)($pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned', 'enroute', 'on_scene')")->fetch()['c'] ?? 0);

    $callDateExpr = 'received_at';
    if (ers_table_exists($pdo, 'calls')) {
        if (ers_column_exists($pdo, 'calls', 'created_at')) {
            $callDateExpr = 'created_at';
        } elseif (!ers_column_exists($pdo, 'calls', 'received_at')) {
            $callDateExpr = '';
        }
    } else {
        $callDateExpr = '';
    }
    $todayCalls = 0;
    if ($callDateExpr !== '') {
        $todayCalls = (int)($pdo->query("SELECT COUNT(*) AS c FROM calls WHERE DATE({$callDateExpr}) = CURDATE()")->fetch()['c'] ?? 0);
    }

    $avgResponseMin = 0.0;
    $avgStmt = $pdo->query("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))) AS avg_rt
        FROM dispatches d
        WHERE d.assigned_at >= NOW() - INTERVAL 7 DAY
          AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
    ");
    if ($avgStmt) {
        $avgResponseMin = (float)(($avgStmt->fetch()['avg_rt'] ?? 0) ?: 0);
    }

    $hasPriorityScore = ers_column_exists($pdo, 'incidents', 'priority_score');
    $priorityScoreSelect = $hasPriorityScore ? 'i.priority_score,' : 'NULL AS priority_score,';
    $priorityScoreOrder = $hasPriorityScore ? 'COALESCE(i.priority_score, 0) DESC,' : '';

    $queueStmt = $pdo->query("
        SELECT
            i.id,
            i.reference_no,
            i.title,
            i.type,
            i.priority,
            {$priorityScoreSelect}
            i.status,
            i.location_address,
            i.created_at,
            c.caller_name,
            c.caller_phone,
            u.identifier AS unit_identifier
        FROM incidents i
        LEFT JOIN calls c ON c.id = i.reported_by_call_id
        LEFT JOIN dispatches d ON d.id = (
            SELECT d2.id
            FROM dispatches d2
            WHERE d2.incident_id = i.id
            ORDER BY d2.assigned_at DESC, d2.id DESC
            LIMIT 1
        )
        LEFT JOIN units u ON u.id = d.unit_id
        WHERE i.status IN ('pending', 'dispatched', 'active', 'in_progress')
        ORDER BY CASE LOWER(i.priority)
            WHEN 'critical' THEN 1
            WHEN 'high' THEN 2
            WHEN 'urgent' THEN 3
            WHEN 'moderate' THEN 4
            WHEN 'medium' THEN 4
            WHEN 'low' THEN 5
            ELSE 6
        END, {$priorityScoreOrder} i.created_at ASC
        LIMIT 10
    ");
    $queueItems = $queueStmt ? $queueStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if ($vehicleResourceTable !== null) {
        $unitStmt = $pdo->query("
            SELECT
                rr.id,
                rr.code AS identifier,
                rr.name AS unit_type,
                rr.status,
                i.reference_no AS incident_code
            FROM `" . $vehicleResourceTable . "` rr
            LEFT JOIN units u ON u.identifier = rr.code
            LEFT JOIN incidents i ON i.id = u.current_incident_id
            WHERE LOWER(rr.category) = 'vehicles'
            ORDER BY FIELD(LOWER(rr.status), 'available', 'in_use', 'busy', 'assigned', 'enroute', 'on_scene', 'maintenance', 'offline', 'unavailable'), rr.code ASC
        ");
    } else {
        $unitStmt = $pdo->query("
            SELECT
                u.id,
                u.identifier,
                u.unit_type,
                u.status,
                i.reference_no AS incident_code
            FROM units u
            LEFT JOIN incidents i ON i.id = u.current_incident_id
            ORDER BY FIELD(u.status, 'available', 'assigned', 'busy', 'in_use', 'enroute', 'on_scene', 'maintenance', 'offline', 'unavailable'), u.identifier ASC
        ");
    }
    $unitItems = $unitStmt ? $unitStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $activityItems = [];
    if (ers_table_exists($pdo, 'activity_log')) {
        $activityStmt = $pdo->query("
            SELECT a.action, a.entity_type, a.details, a.created_at, u.name AS username
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            ORDER BY a.created_at DESC
            LIMIT 8
        ");
        $activityItems = $activityStmt ? $activityStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $typeCounts = [
        'medical' => 0,
        'fire' => 0,
        'police' => 0,
        'traffic' => 0,
    ];
    $typeStmt = $pdo->query("
        SELECT LOWER(type) AS type_name, COUNT(*) AS c
        FROM incidents
        WHERE status IN ('pending', 'dispatched', 'active', 'in_progress')
        GROUP BY LOWER(type)
    ");
    foreach (($typeStmt ? $typeStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $key = (string)($row['type_name'] ?? '');
        if ($key === 'crime') {
            $key = 'police';
        } elseif ($key === 'accident') {
            $key = 'traffic';
        }
        if (isset($typeCounts[$key])) {
            $typeCounts[$key] += (int)($row['c'] ?? 0);
        }
    }

    echo json_encode([
        'ok' => true,
        'metrics' => [
            'pending_incidents' => $pendingIncidents,
            'active_dispatches' => $activeDispatches,
            'available_units' => $availableUnits,
            'units_in_field' => $unitsInField,
            'today_calls' => $todayCalls,
            'avg_response_min' => round($avgResponseMin, 1),
        ],
        'queue_items' => $queueItems,
        'unit_items' => $unitItems,
        'type_counts' => $typeCounts,
        'activity_items' => $activityItems,
        'generated_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
