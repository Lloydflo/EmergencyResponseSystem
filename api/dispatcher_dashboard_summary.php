<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/auth.php';

$requireApiRoles = static function (array $allowedRoles): array {
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $user = get_logged_in_user() ?? [];
    $role = canonical_role((string)($user['role'] ?? ''));
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $user['canonical_role'] = $role;
    return $user;
};
$requireApiRoles(['dispatcher']);
unset($requireApiRoles);

require_once __DIR__ . '/../includes/db.php';

/** @return array<string,array<string,true>> */
function ers_dispatcher_summary_schema(PDO $pdo): array
{
    $tables = [
        'incidents',
        'calls',
        'dispatches',
        'units',
        'resource_records',
        'admin_resources',
        'activity_log',
        'users',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));

    try {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute($tables);
    } catch (Throwable $e) {
        error_log('dispatcher_dashboard_summary schema lookup failed: ' . $e->getMessage());
        return [];
    }

    $schema = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $schema[$table][$column] = true;
        }
    }

    return $schema;
}

/** @param array<string,array<string,true>> $schema */
function ers_dispatcher_summary_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]);
}

/** @param array<string,array<string,true>> $schema */
function ers_dispatcher_summary_has_column(array $schema, string $table, string $column): bool
{
    return isset($schema[$table][$column]);
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$schema = ers_dispatcher_summary_schema($pdo);
if (!ers_dispatcher_summary_has_table($schema, 'incidents')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Incidents table unavailable']);
    exit;
}

try {
    $pendingIncidents = 0;
    $activeDispatches = 0;
    if (ers_dispatcher_summary_has_column($schema, 'incidents', 'status')) {
        $metricStmt = $pdo->query(
            "SELECT
                COALESCE(SUM(status = 'pending'), 0) AS pending_incidents,
                COALESCE(SUM(status IN ('dispatched', 'active', 'in_progress')), 0) AS active_dispatches
             FROM incidents"
        );
        $metricRow = $metricStmt ? ($metricStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $pendingIncidents = (int)($metricRow['pending_incidents'] ?? 0);
        $activeDispatches = (int)($metricRow['active_dispatches'] ?? 0);
    }

    // Read-only count: do not synchronize every vehicle/resource row during a
    // dashboard refresh. Status-changing endpoints remain the source of writes.
    $vehicleResourceTable = null;
    foreach (['resource_records', 'admin_resources'] as $candidate) {
        if (
            ers_dispatcher_summary_has_table($schema, $candidate)
            && ers_dispatcher_summary_has_column($schema, $candidate, 'code')
            && ers_dispatcher_summary_has_column($schema, $candidate, 'category')
        ) {
            $vehicleResourceTable = $candidate;
            break;
        }
    }

    $availableUnits = 0;
    $unitsInField = 0;
    $hasUnitsCore = ers_dispatcher_summary_has_table($schema, 'units')
        && ers_dispatcher_summary_has_column($schema, 'units', 'id')
        && ers_dispatcher_summary_has_column($schema, 'units', 'identifier')
        && ers_dispatcher_summary_has_column($schema, 'units', 'status');
    if ($hasUnitsCore) {
        if ($vehicleResourceTable !== null) {
            $availableStmt = $pdo->query(
                "SELECT COUNT(DISTINCT u.id) AS c
                 FROM units u
                 INNER JOIN `{$vehicleResourceTable}` rr
                    ON rr.code = u.identifier
                   AND rr.category = 'vehicles'
                 WHERE u.status = 'available'"
            );
        } else {
            $availableStmt = $pdo->query("SELECT COUNT(*) AS c FROM units WHERE status = 'available'");
        }
        $availableUnits = (int)(($availableStmt ? $availableStmt->fetch(PDO::FETCH_ASSOC) : [])['c'] ?? 0);

        $fieldStmt = $pdo->query(
            "SELECT COUNT(*) AS c
             FROM units
             WHERE status IN ('assigned', 'enroute', 'en_route', 'on_scene')"
        );
        $unitsInField = (int)(($fieldStmt ? $fieldStmt->fetch(PDO::FETCH_ASSOC) : [])['c'] ?? 0);
    }

    $todayCalls = 0;
    if (ers_dispatcher_summary_has_table($schema, 'calls')) {
        $callDateColumn = null;
        if (ers_dispatcher_summary_has_column($schema, 'calls', 'created_at')) {
            $callDateColumn = 'created_at';
        } elseif (ers_dispatcher_summary_has_column($schema, 'calls', 'received_at')) {
            $callDateColumn = 'received_at';
        }

        if ($callDateColumn !== null) {
            // Range predicates can use a date index; DATE(column) cannot.
            $todayStmt = $pdo->query(
                "SELECT COUNT(*) AS c
                 FROM calls
                 WHERE {$callDateColumn} >= CURDATE()
                   AND {$callDateColumn} < CURDATE() + INTERVAL 1 DAY"
            );
            $todayCalls = (int)(($todayStmt ? $todayStmt->fetch(PDO::FETCH_ASSOC) : [])['c'] ?? 0);
        }
    }

    $avgResponseMin = 0.0;
    $hasDispatchTiming = ers_dispatcher_summary_has_table($schema, 'dispatches')
        && ers_dispatcher_summary_has_column($schema, 'dispatches', 'assigned_at')
        && ers_dispatcher_summary_has_column($schema, 'dispatches', 'on_scene_at')
        && ers_dispatcher_summary_has_column($schema, 'dispatches', 'cleared_at');
    if ($hasDispatchTiming) {
        $avgStmt = $pdo->query(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, assigned_at, COALESCE(on_scene_at, cleared_at))) AS avg_rt
             FROM dispatches
             WHERE assigned_at >= NOW() - INTERVAL 7 DAY
               AND COALESCE(on_scene_at, cleared_at) IS NOT NULL"
        );
        if ($avgStmt) {
            $avgResponseMin = (float)(($avgStmt->fetch(PDO::FETCH_ASSOC)['avg_rt'] ?? 0) ?: 0);
        }
    }

    $queueItems = [];
    $requiredIncidentColumns = ['id', 'reference_no', 'title', 'type', 'priority', 'status', 'location_address', 'created_at'];
    $canLoadQueue = true;
    foreach ($requiredIncidentColumns as $column) {
        if (!ers_dispatcher_summary_has_column($schema, 'incidents', $column)) {
            $canLoadQueue = false;
            break;
        }
    }

    if ($canLoadQueue) {
        $hasPriorityScore = ers_dispatcher_summary_has_column($schema, 'incidents', 'priority_score');
        $priorityScoreSelect = $hasPriorityScore ? 'i.priority_score' : 'NULL AS priority_score';
        $priorityScoreOrder = $hasPriorityScore ? 'COALESCE(i.priority_score, 0) DESC,' : '';

        $callsJoin = '';
        $callerNameExpr = 'NULL';
        $callerPhoneExpr = 'NULL';
        if (
            ers_dispatcher_summary_has_column($schema, 'incidents', 'reported_by_call_id')
            && ers_dispatcher_summary_has_table($schema, 'calls')
            && ers_dispatcher_summary_has_column($schema, 'calls', 'id')
        ) {
            $callsJoin = ' LEFT JOIN calls c ON c.id = i.reported_by_call_id';
            $callerNameExpr = ers_dispatcher_summary_has_column($schema, 'calls', 'caller_name') ? 'c.caller_name' : 'NULL';
            $callerPhoneExpr = ers_dispatcher_summary_has_column($schema, 'calls', 'caller_phone') ? 'c.caller_phone' : 'NULL';
        }

        $dispatchJoin = '';
        $unitsJoin = '';
        $unitIdentifierExpr = 'NULL';
        $hasLatestDispatch = ers_dispatcher_summary_has_table($schema, 'dispatches')
            && ers_dispatcher_summary_has_column($schema, 'dispatches', 'id')
            && ers_dispatcher_summary_has_column($schema, 'dispatches', 'incident_id')
            && ers_dispatcher_summary_has_column($schema, 'dispatches', 'unit_id')
            && ers_dispatcher_summary_has_column($schema, 'dispatches', 'assigned_at');
        if ($hasLatestDispatch) {
            $dispatchJoin = " LEFT JOIN (
                SELECT d1.id, d1.incident_id, d1.unit_id, d1.assigned_at
                FROM dispatches d1
                INNER JOIN (
                    SELECT incident_id, MAX(id) AS max_id
                    FROM dispatches
                    GROUP BY incident_id
                ) latest_dispatch ON latest_dispatch.max_id = d1.id
            ) d ON d.incident_id = i.id";

            if ($hasUnitsCore) {
                $unitsJoin = ' LEFT JOIN units u ON u.id = d.unit_id';
                $unitIdentifierExpr = 'u.identifier';
            }
        }

        $queueSql = "SELECT
                i.id,
                i.reference_no,
                i.title,
                i.type,
                i.priority,
                {$priorityScoreSelect},
                i.status,
                i.location_address,
                i.created_at,
                {$callerNameExpr} AS caller_name,
                {$callerPhoneExpr} AS caller_phone,
                {$unitIdentifierExpr} AS unit_identifier
            FROM incidents i
            {$callsJoin}
            {$dispatchJoin}
            {$unitsJoin}
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
            LIMIT 10";
        $queueStmt = $pdo->query($queueSql);
        $queueItems = $queueStmt ? $queueStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $unitItems = [];
    if ($vehicleResourceTable !== null) {
        $rrIdExpr = ers_dispatcher_summary_has_column($schema, $vehicleResourceTable, 'id') ? 'rr.id' : 'NULL';
        $rrNameExpr = ers_dispatcher_summary_has_column($schema, $vehicleResourceTable, 'name') ? 'rr.name' : 'rr.code';
        $rrStatusExpr = ers_dispatcher_summary_has_column($schema, $vehicleResourceTable, 'status') ? 'rr.status' : "'available'";

        $unitJoin = '';
        $incidentJoin = '';
        $incidentCodeExpr = 'NULL';
        if ($hasUnitsCore) {
            $unitJoin = ' LEFT JOIN units u ON u.identifier = rr.code';
            if (
                ers_dispatcher_summary_has_column($schema, 'units', 'current_incident_id')
                && ers_dispatcher_summary_has_column($schema, 'incidents', 'id')
                && ers_dispatcher_summary_has_column($schema, 'incidents', 'reference_no')
            ) {
                $incidentJoin = ' LEFT JOIN incidents i ON i.id = u.current_incident_id';
                $incidentCodeExpr = 'i.reference_no';
            }
        }

        $unitStmt = $pdo->query(
            "SELECT
                {$rrIdExpr} AS id,
                rr.code AS identifier,
                {$rrNameExpr} AS unit_type,
                {$rrStatusExpr} AS status,
                {$incidentCodeExpr} AS incident_code
             FROM `{$vehicleResourceTable}` rr
             {$unitJoin}
             {$incidentJoin}
             WHERE rr.category = 'vehicles'
             ORDER BY FIELD(LOWER({$rrStatusExpr}), 'available', 'in_use', 'busy', 'assigned', 'enroute', 'on_scene', 'maintenance', 'offline', 'unavailable'), rr.code ASC"
        );
        $unitItems = $unitStmt ? $unitStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } elseif ($hasUnitsCore) {
        $unitTypeExpr = ers_dispatcher_summary_has_column($schema, 'units', 'unit_type') ? 'u.unit_type' : "'other'";
        $incidentJoin = '';
        $incidentCodeExpr = 'NULL';
        if (
            ers_dispatcher_summary_has_column($schema, 'units', 'current_incident_id')
            && ers_dispatcher_summary_has_column($schema, 'incidents', 'id')
            && ers_dispatcher_summary_has_column($schema, 'incidents', 'reference_no')
        ) {
            $incidentJoin = ' LEFT JOIN incidents i ON i.id = u.current_incident_id';
            $incidentCodeExpr = 'i.reference_no';
        }

        $unitStmt = $pdo->query(
            "SELECT
                u.id,
                u.identifier,
                {$unitTypeExpr} AS unit_type,
                u.status,
                {$incidentCodeExpr} AS incident_code
             FROM units u
             {$incidentJoin}
             ORDER BY FIELD(u.status, 'available', 'assigned', 'busy', 'in_use', 'enroute', 'on_scene', 'maintenance', 'offline', 'unavailable'), u.identifier ASC"
        );
        $unitItems = $unitStmt ? $unitStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $activityItems = [];
    if (
        ers_dispatcher_summary_has_table($schema, 'activity_log')
        && ers_dispatcher_summary_has_column($schema, 'activity_log', 'action')
        && ers_dispatcher_summary_has_column($schema, 'activity_log', 'entity_type')
        && ers_dispatcher_summary_has_column($schema, 'activity_log', 'details')
        && ers_dispatcher_summary_has_column($schema, 'activity_log', 'created_at')
    ) {
        $activityUserJoin = '';
        $usernameExpr = 'NULL';
        if (
            ers_dispatcher_summary_has_column($schema, 'activity_log', 'user_id')
            && ers_dispatcher_summary_has_table($schema, 'users')
            && ers_dispatcher_summary_has_column($schema, 'users', 'id')
            && ers_dispatcher_summary_has_column($schema, 'users', 'name')
        ) {
            $activityUserJoin = ' LEFT JOIN users au ON au.id = a.user_id';
            $usernameExpr = 'au.name';
        }

        $activityStmt = $pdo->query(
            "SELECT a.action, a.entity_type, a.details, a.created_at, {$usernameExpr} AS username
             FROM activity_log a
             {$activityUserJoin}
             ORDER BY a.created_at DESC
             LIMIT 8"
        );
        $activityItems = $activityStmt ? $activityStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $typeCounts = [
        'medical' => 0,
        'fire' => 0,
        'police' => 0,
        'traffic' => 0,
    ];
    if (
        ers_dispatcher_summary_has_column($schema, 'incidents', 'type')
        && ers_dispatcher_summary_has_column($schema, 'incidents', 'status')
    ) {
        $typeStmt = $pdo->query(
            "SELECT LOWER(type) AS type_name, COUNT(*) AS c
             FROM incidents
             WHERE status IN ('pending', 'dispatched', 'active', 'in_progress')
             GROUP BY LOWER(type)"
        );
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
    }

    echo json_encode(
        [
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
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('dispatcher_dashboard_summary query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}