<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

final class AppAssignmentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}

/** @return list<string> */
function app_assignment_active_statuses(): array
{
    return [
        'pending', 'assigned', 'received', 'accepted', 'acknowledged',
        'busy', 'in_use', 'enroute', 'en_route', 'on_scene',
    ];
}

function app_assignment_require_schema(PDO $pdo): void
{
    op_require_tables($pdo, ['dispatch_operator_records']);
    op_require_columns($pdo, 'dispatch_operator_records', ['id', 'assigned_to', 'status']);
}

/**
 * Loads one assignment owned by the responder. Optional columns are projected
 * as NULL so an older but otherwise compatible schema remains readable.
 *
 * @return array<string,mixed>|null
 */
function app_assignment_row(
    PDO $pdo,
    int $assignmentId,
    int $responderId,
    bool $forUpdate = false
): ?array {
    if ($assignmentId <= 0 || $responderId <= 0) {
        return null;
    }
    app_assignment_require_schema($pdo);

    $columns = [
        'id', 'incident_id', 'name', 'vehicle', 'location', 'latitude', 'longitude',
        'priority', 'description', 'created_at', 'status', 'assigned_to',
        'assigned_responder_name', 'assigned_unit_code', 'assigned_unit_type', 'assigned_at',
    ];
    $select = [];
    foreach ($columns as $column) {
        $select[] = op_column_exists($pdo, 'dispatch_operator_records', $column)
            ? 'd.`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM dispatch_operator_records d'
        . ' WHERE d.id = ? AND d.assigned_to = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([$assignmentId, $responderId]);
    $assignment = op_fetch_one($statement);
    if ($assignment === null) {
        return null;
    }

    $assignment['responder_unit_code'] = null;
    if (
        op_table_exists($pdo, 'users')
        && op_column_exists($pdo, 'users', 'id')
        && op_column_exists($pdo, 'users', 'unit_code')
    ) {
        $unitStatement = $pdo->prepare('SELECT unit_code FROM users WHERE id = ? LIMIT 1');
        $unitStatement->execute([$responderId]);
        $assignment['responder_unit_code'] = $unitStatement->fetchColumn() ?: null;
    }

    return $assignment;
}

/** @return array{dispatch_id:int,incident_id:int,unit_id:int}|null */
function app_assignment_find_dispatch(PDO $pdo, array $assignment): ?array
{
    if (
        !op_table_exists($pdo, 'dispatches')
        || !op_column_exists($pdo, 'dispatches', 'id')
        || !op_column_exists($pdo, 'dispatches', 'incident_id')
        || !op_column_exists($pdo, 'dispatches', 'unit_id')
    ) {
        return null;
    }

    $incidentId = (int)($assignment['incident_id'] ?? 0);
    $unitCode = trim((string)(
        $assignment['assigned_unit_code']
        ?? $assignment['responder_unit_code']
        ?? ''
    ));

    $statusOrder = op_column_exists($pdo, 'dispatches', 'status')
        ? "CASE WHEN d.status IN ('assigned','acknowledged','enroute','on_scene') THEN 0 ELSE 1 END, "
        : '';
    $timeOrder = op_column_exists($pdo, 'dispatches', 'assigned_at')
        ? 'd.assigned_at DESC, '
        : '';

    // Prefer the dispatch for the assignment's unit when an incident has more
    // than one responding unit.
    if ($incidentId > 0) {
        $canMatchUnit = $unitCode !== ''
            && op_table_exists($pdo, 'units')
            && op_column_exists($pdo, 'units', 'id')
            && op_column_exists($pdo, 'units', 'identifier');

        $sql = 'SELECT d.id AS dispatch_id, d.incident_id, d.unit_id FROM dispatches d ';
        $params = [$incidentId];
        if ($canMatchUnit) {
            $sql .= 'INNER JOIN units u ON u.id = d.unit_id ';
        }
        $sql .= 'WHERE d.incident_id = ? ';
        if ($canMatchUnit) {
            $sql .= 'AND UPPER(TRIM(u.identifier)) = UPPER(TRIM(?)) ';
            $params[] = $unitCode;
        }
        $sql .= 'ORDER BY ' . $statusOrder . $timeOrder . 'd.id DESC LIMIT 1';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = op_fetch_one($statement);

        // If no exact unit match exists, retain compatibility with legacy rows
        // that only linked the incident and did not persist a unit code.
        if ($row === null && $canMatchUnit) {
            $statement = $pdo->prepare(
                'SELECT d.id AS dispatch_id, d.incident_id, d.unit_id '
                . 'FROM dispatches d WHERE d.incident_id = ? '
                . 'ORDER BY ' . $statusOrder . $timeOrder . 'd.id DESC LIMIT 1'
            );
            $statement->execute([$incidentId]);
            $row = op_fetch_one($statement);
        }

        if ($row !== null) {
            return [
                'dispatch_id' => (int)$row['dispatch_id'],
                'incident_id' => (int)$row['incident_id'],
                'unit_id' => (int)($row['unit_id'] ?? 0),
            ];
        }

        if (
            op_table_exists($pdo, 'incidents')
            && op_column_exists($pdo, 'incidents', 'id')
        ) {
            $exists = $pdo->prepare('SELECT 1 FROM incidents WHERE id = ? LIMIT 1');
            $exists->execute([$incidentId]);
            if ($exists->fetchColumn()) {
                return ['dispatch_id' => 0, 'incident_id' => $incidentId, 'unit_id' => 0];
            }
        }
    }

    if (
        $unitCode === ''
        || !op_table_exists($pdo, 'units')
        || !op_column_exists($pdo, 'units', 'id')
        || !op_column_exists($pdo, 'units', 'identifier')
    ) {
        return null;
    }

    $assignedAt = trim((string)($assignment['assigned_at'] ?? $assignment['created_at'] ?? ''));
    if ($assignedAt !== '' && op_column_exists($pdo, 'dispatches', 'assigned_at')) {
        $statement = $pdo->prepare(
            'SELECT d.id AS dispatch_id, d.incident_id, d.unit_id '
            . 'FROM dispatches d INNER JOIN units u ON u.id = d.unit_id '
            . 'WHERE UPPER(TRIM(u.identifier)) = UPPER(TRIM(?)) '
            . 'AND ABS(TIMESTAMPDIFF(SECOND, d.assigned_at, ?)) <= 300 '
            . 'ORDER BY ABS(TIMESTAMPDIFF(SECOND, d.assigned_at, ?)), d.id DESC LIMIT 1'
        );
        $statement->execute([$unitCode, $assignedAt, $assignedAt]);
        $row = op_fetch_one($statement);
        if ($row !== null) {
            return [
                'dispatch_id' => (int)$row['dispatch_id'],
                'incident_id' => (int)$row['incident_id'],
                'unit_id' => (int)($row['unit_id'] ?? 0),
            ];
        }
    }

    $whereStatus = op_column_exists($pdo, 'dispatches', 'status')
        ? " AND d.status IN ('assigned','acknowledged','enroute','on_scene')"
        : '';
    $statement = $pdo->prepare(
        'SELECT d.id AS dispatch_id, d.incident_id, d.unit_id '
        . 'FROM dispatches d INNER JOIN units u ON u.id = d.unit_id '
        . 'WHERE UPPER(TRIM(u.identifier)) = UPPER(TRIM(?))'
        . $whereStatus
        . ' ORDER BY ' . $timeOrder . 'd.id DESC LIMIT 1'
    );
    $statement->execute([$unitCode]);
    $row = op_fetch_one($statement);

    return $row === null ? null : [
        'dispatch_id' => (int)$row['dispatch_id'],
        'incident_id' => (int)$row['incident_id'],
        'unit_id' => (int)($row['unit_id'] ?? 0),
    ];
}

function app_assignment_resource_status(string $unitStatus): string
{
    return match (strtolower(trim($unitStatus))) {
        'available' => 'available',
        'offline', 'unavailable' => 'offline',
        'maintenance' => 'maintenance',
        default => 'in_use',
    };
}

/**
 * Synchronizes only the responder and unit affected by the request. It never
 * scans or rewrites the full resource catalog.
 */
function app_assignment_set_unit_status(
    PDO $pdo,
    int $responderId,
    string $unitCode,
    string $unitStatus
): void {
    $unitStatus = strtolower(trim($unitStatus));
    if (!in_array($unitStatus, ['available', 'busy', 'en_route', 'on_scene', 'offline', 'maintenance'], true)) {
        throw new AppAssignmentException('Invalid unit status.', 422);
    }

    if (
        $responderId > 0
        && op_table_exists($pdo, 'users')
        && op_column_exists($pdo, 'users', 'id')
        && op_column_exists($pdo, 'users', 'unit_status')
    ) {
        $sets = ['unit_status = ?'];
        if (op_column_exists($pdo, 'users', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        $statement = $pdo->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $statement->execute([$unitStatus, $responderId]);

        if ($unitCode === '' && op_column_exists($pdo, 'users', 'unit_code')) {
            $lookup = $pdo->prepare('SELECT unit_code FROM users WHERE id = ? LIMIT 1');
            $lookup->execute([$responderId]);
            $unitCode = trim((string)$lookup->fetchColumn());
        }
    }

    if (
        $unitCode !== ''
        && op_table_exists($pdo, 'units')
        && op_column_exists($pdo, 'units', 'identifier')
        && op_column_exists($pdo, 'units', 'status')
    ) {
        $mappedStatus = match ($unitStatus) {
            'busy' => 'assigned',
            'en_route' => 'enroute',
            'on_scene' => 'on_scene',
            'offline' => 'unavailable',
            'maintenance' => 'maintenance',
            default => 'available',
        };
        $sets = ['status = ?'];
        if (op_column_exists($pdo, 'units', 'last_status_at')) {
            $sets[] = 'last_status_at = NOW()';
        }
        if (op_column_exists($pdo, 'units', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        $statement = $pdo->prepare(
            'UPDATE units SET ' . implode(', ', $sets)
            . ' WHERE UPPER(TRIM(identifier)) = UPPER(TRIM(?))'
        );
        $statement->execute([$mappedStatus, $unitCode]);
    }

    if ($unitCode === '') {
        return;
    }

    $resourceStatus = app_assignment_resource_status($unitStatus);
    foreach (['resource_records', 'admin_resources'] as $table) {
        if (
            !op_table_exists($pdo, $table)
            || !op_column_exists($pdo, $table, 'code')
            || !op_column_exists($pdo, $table, 'status')
        ) {
            continue;
        }

        $sets = ['status = ?'];
        if (op_column_exists($pdo, $table, 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        $categoryClause = op_column_exists($pdo, $table, 'category')
            ? " AND LOWER(COALESCE(category, 'vehicles')) = 'vehicles'"
            : '';
        $statement = $pdo->prepare(
            'UPDATE `' . $table . '` SET ' . implode(', ', $sets)
            . ' WHERE UPPER(TRIM(code)) = UPPER(TRIM(?))'
            . $categoryClause
        );
        $statement->execute([$resourceStatus, $unitCode]);
    }
}

function app_assignment_normalize_status(string $status): string
{
    return match (strtolower(trim($status))) {
        'acknowledged', 'accepted', 'busy', 'in_use' => 'received',
        'enroute' => 'en_route',
        'cleared' => 'completed',
        default => strtolower(trim($status)),
    };
}

/** Derives the mobile unit state from the responder's latest active assignment. */
function app_assignment_current_unit_status(PDO $pdo, int $responderId): string
{
    if (
        $responderId <= 0
        || !op_table_exists($pdo, 'dispatch_operator_records')
        || !op_column_exists($pdo, 'dispatch_operator_records', 'assigned_to')
        || !op_column_exists($pdo, 'dispatch_operator_records', 'status')
    ) {
        return 'available';
    }

    $order = [];
    if (op_column_exists($pdo, 'dispatch_operator_records', 'assigned_at')) {
        $order[] = 'assigned_at DESC';
    } elseif (op_column_exists($pdo, 'dispatch_operator_records', 'created_at')) {
        $order[] = 'created_at DESC';
    }
    if (op_column_exists($pdo, 'dispatch_operator_records', 'id')) {
        $order[] = 'id DESC';
    }
    if ($order === []) {
        $order[] = 'assigned_to DESC';
    }

    $statement = $pdo->prepare(
        "SELECT status FROM dispatch_operator_records WHERE assigned_to = ? "
        . "AND LOWER(status) IN ('pending','assigned','received','accepted','acknowledged',"
        . "'busy','in_use','enroute','en_route','on_scene') "
        . 'ORDER BY ' . implode(', ', $order) . ' LIMIT 1'
    );
    $statement->execute([$responderId]);
    $latest = strtolower(trim((string)$statement->fetchColumn()));

    return match ($latest) {
        'pending', 'assigned', 'received', 'accepted', 'acknowledged', 'busy', 'in_use' => 'busy',
        'enroute', 'en_route' => 'en_route',
        'on_scene' => 'on_scene',
        default => 'available',
    };
}

/** @return array<string,mixed> */
function app_assignment_change_status(
    PDO $pdo,
    array $assignment,
    string $requestedStatus
): array {
    app_assignment_require_schema($pdo);

    $requestedStatus = app_assignment_normalize_status($requestedStatus);
    if (!in_array($requestedStatus, ['received', 'en_route', 'on_scene', 'completed'], true)) {
        throw new AppAssignmentException('Invalid assignment status.', 422);
    }

    $currentRaw = strtolower(trim((string)($assignment['status'] ?? 'pending')));
    if (in_array($currentRaw, ['cancelled', 'canceled', 'rejected'], true)) {
        throw new AppAssignmentException('A cancelled assignment cannot be updated.', 409);
    }
    $current = app_assignment_normalize_status($currentRaw);
    $rank = ['pending' => 0, 'assigned' => 0, 'received' => 1, 'en_route' => 2, 'on_scene' => 3, 'completed' => 4];
    if (isset($rank[$current]) && $rank[$current] > $rank[$requestedStatus]) {
        throw new AppAssignmentException('Assignment status cannot move backward.', 409);
    }

    $assignmentId = (int)($assignment['id'] ?? 0);
    $responderId = (int)($assignment['assigned_to'] ?? 0);
    if ($assignmentId <= 0 || $responderId <= 0) {
        throw new AppAssignmentException('Assignment ownership is invalid.', 409);
    }

    $unitCode = trim((string)(
        $assignment['assigned_unit_code']
        ?? $assignment['responder_unit_code']
        ?? ''
    ));
    $dispatch = app_assignment_find_dispatch($pdo, $assignment);

    // Idempotent retries are accepted. They still refresh only the affected
    // unit's derived status but do not create duplicate workflow events.
    if ($current !== $requestedStatus) {
        $statement = $pdo->prepare(
            'UPDATE dispatch_operator_records SET status = ? WHERE id = ? AND assigned_to = ?'
        );
        $statement->execute([$requestedStatus, $assignmentId, $responderId]);
        if ($statement->rowCount() < 1) {
            throw new AppAssignmentException('Assignment status was not updated.', 409);
        }
    }

    if (
        $dispatch !== null
        && $dispatch['dispatch_id'] > 0
        && op_column_exists($pdo, 'dispatches', 'status')
    ) {
        $dispatchStatus = match ($requestedStatus) {
            'received' => 'acknowledged',
            'en_route' => 'enroute',
            'on_scene' => 'on_scene',
            default => 'cleared',
        };
        $sets = ['status = ?'];
        if ($requestedStatus === 'received' && op_column_exists($pdo, 'dispatches', 'acknowledged_at')) {
            $sets[] = 'acknowledged_at = COALESCE(acknowledged_at, NOW())';
        }
        if ($requestedStatus === 'en_route' && op_column_exists($pdo, 'dispatches', 'enroute_at')) {
            $sets[] = 'enroute_at = COALESCE(enroute_at, NOW())';
        }
        if ($requestedStatus === 'on_scene' && op_column_exists($pdo, 'dispatches', 'on_scene_at')) {
            $sets[] = 'on_scene_at = COALESCE(on_scene_at, NOW())';
        }
        if ($requestedStatus === 'completed' && op_column_exists($pdo, 'dispatches', 'cleared_at')) {
            $sets[] = 'cleared_at = COALESCE(cleared_at, NOW())';
        }
        $statement = $pdo->prepare(
            'UPDATE dispatches SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $statement->execute([$dispatchStatus, $dispatch['dispatch_id']]);

        if (
            (int)($assignment['incident_id'] ?? 0) <= 0
            && $dispatch['incident_id'] > 0
            && op_column_exists($pdo, 'dispatch_operator_records', 'incident_id')
        ) {
            $statement = $pdo->prepare(
                'UPDATE dispatch_operator_records SET incident_id = ? '
                . 'WHERE id = ? AND (incident_id IS NULL OR incident_id = 0)'
            );
            $statement->execute([$dispatch['incident_id'], $assignmentId]);
        }
    }

    $unitStatus = match ($requestedStatus) {
        'received' => 'busy',
        'en_route' => 'en_route',
        'on_scene' => 'on_scene',
        default => 'available',
    };
    app_assignment_set_unit_status($pdo, $responderId, $unitCode, $unitStatus);

    return [
        'assignment_status' => $requestedStatus,
        'unit_status' => $unitStatus,
        'incident_id' => $dispatch['incident_id'] ?? (int)($assignment['incident_id'] ?? 0),
        'idempotent' => $current === $requestedStatus,
    ];
}

/**
 * Completes the incident linked to one responder-owned assignment.
 *
 * @return array<string,mixed>
 */
function app_assignment_complete_incident(
    PDO $pdo,
    array $assignment,
    int $responderId,
    string $notes,
    string $imagePath
): array {
    app_assignment_require_schema($pdo);
    op_require_tables($pdo, ['incidents']);
    op_require_columns($pdo, 'incidents', ['id', 'status']);

    $assignmentResponderId = (int)($assignment['assigned_to'] ?? 0);
    if ($assignmentResponderId !== $responderId) {
        throw new AppAssignmentException('This assignment does not belong to the responder.', 403);
    }

    $dispatch = app_assignment_find_dispatch($pdo, $assignment);
    $incidentId = (int)($dispatch['incident_id'] ?? $assignment['incident_id'] ?? 0);
    if ($incidentId <= 0) {
        throw new AppAssignmentException('Could not resolve the linked incident.', 409);
    }

    $select = ['id', 'status'];
    foreach (['completion_image_path', 'completed_by_responder_id', 'completed_at'] as $column) {
        $select[] = op_column_exists($pdo, 'incidents', $column)
            ? '`' . $column . '`'
            : 'NULL AS `' . $column . '`';
    }
    $incidentStatement = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . ' FROM incidents WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $incidentStatement->execute([$incidentId]);
    $incident = op_fetch_one($incidentStatement);
    if ($incident === null) {
        throw new AppAssignmentException('Linked incident was not found.', 404);
    }

    $completedBy = (int)($incident['completed_by_responder_id'] ?? 0);
    $alreadyResolved = in_array(strtolower((string)($incident['status'] ?? '')), ['resolved', 'closed'], true);
    if ($alreadyResolved && $completedBy > 0 && $completedBy !== $responderId) {
        throw new AppAssignmentException('The incident was already completed by another responder.', 409);
    }
    if ($alreadyResolved && ($completedBy === 0 || $completedBy === $responderId)) {
        app_assignment_set_unit_status(
            $pdo,
            $responderId,
            trim((string)($assignment['assigned_unit_code'] ?? $assignment['responder_unit_code'] ?? '')),
            'available'
        );
        return [
            'assignment_status' => 'completed',
            'unit_status' => 'available',
            'incident_resolved' => true,
            'incident_id' => $incidentId,
            'already_completed' => true,
            'completion_image_path' => (string)($incident['completion_image_path'] ?? ''),
        ];
    }

    $assignmentId = (int)($assignment['id'] ?? 0);
    $unitCode = trim((string)(
        $assignment['assigned_unit_code']
        ?? $assignment['responder_unit_code']
        ?? ''
    ));

    $statement = $pdo->prepare(
        "UPDATE dispatch_operator_records SET status = 'completed' "
        . 'WHERE id = ? AND assigned_to = ?'
    );
    $statement->execute([$assignmentId, $responderId]);

    if (
        op_table_exists($pdo, 'dispatches')
        && op_column_exists($pdo, 'dispatches', 'incident_id')
        && op_column_exists($pdo, 'dispatches', 'status')
    ) {
        $sets = ["status = 'cleared'"];
        if (op_column_exists($pdo, 'dispatches', 'cleared_at')) {
            $sets[] = 'cleared_at = COALESCE(cleared_at, NOW())';
        }
        $statement = $pdo->prepare(
            'UPDATE dispatches SET ' . implode(', ', $sets)
            . " WHERE incident_id = ? AND status IN ('assigned','acknowledged','enroute','on_scene')"
        );
        $statement->execute([$incidentId]);
    }

    if (
        op_table_exists($pdo, 'units')
        && op_column_exists($pdo, 'units', 'id')
        && op_column_exists($pdo, 'units', 'status')
    ) {
        $sets = ["status = 'available'"];
        if (op_column_exists($pdo, 'units', 'current_incident_id')) {
            $sets[] = 'current_incident_id = NULL';
        }
        if (op_column_exists($pdo, 'units', 'last_status_at')) {
            $sets[] = 'last_status_at = NOW()';
        }
        if (op_column_exists($pdo, 'units', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }

        if (
            op_table_exists($pdo, 'dispatches')
            && op_column_exists($pdo, 'dispatches', 'unit_id')
            && op_column_exists($pdo, 'dispatches', 'incident_id')
        ) {
            $statement = $pdo->prepare(
                'UPDATE units SET ' . implode(', ', $sets)
                . ' WHERE id IN (SELECT unit_id FROM dispatches WHERE incident_id = ?)'
            );
            $statement->execute([$incidentId]);
        }
        if ($unitCode !== '' && op_column_exists($pdo, 'units', 'identifier')) {
            $statement = $pdo->prepare(
                'UPDATE units SET ' . implode(', ', $sets)
                . ' WHERE UPPER(TRIM(identifier)) = UPPER(TRIM(?))'
            );
            $statement->execute([$unitCode]);
        }
    }

    $sets = ["status = 'resolved'"];
    $values = [];
    if (op_column_exists($pdo, 'incidents', 'resolved_at')) {
        $sets[] = 'resolved_at = COALESCE(resolved_at, NOW())';
    }
    if (op_column_exists($pdo, 'incidents', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }
    foreach ([
        'completion_notes' => $notes,
        'completion_image_path' => $imagePath,
        'completed_by_responder_id' => $responderId,
    ] as $column => $value) {
        if (op_column_exists($pdo, 'incidents', $column)) {
            $sets[] = '`' . $column . '` = ?';
            $values[] = $value;
        }
    }
    if (op_column_exists($pdo, 'incidents', 'completed_at')) {
        $sets[] = 'completed_at = NOW()';
    }
    $values[] = $incidentId;
    $statement = $pdo->prepare(
        'UPDATE incidents SET ' . implode(', ', $sets) . ' WHERE id = ?'
    );
    $statement->execute($values);

    app_assignment_set_unit_status($pdo, $responderId, $unitCode, 'available');

    if (
        op_table_exists($pdo, 'activity_log')
        && op_column_exists($pdo, 'activity_log', 'user_id')
        && op_column_exists($pdo, 'activity_log', 'action')
        && op_column_exists($pdo, 'activity_log', 'entity_type')
        && op_column_exists($pdo, 'activity_log', 'entity_id')
        && op_column_exists($pdo, 'activity_log', 'details')
        && op_column_exists($pdo, 'activity_log', 'created_at')
    ) {
        try {
            $exists = $pdo->prepare(
                "SELECT 1 FROM activity_log WHERE action = 'incident_resolved' "
                . "AND entity_type = 'incident' AND entity_id = ? LIMIT 1"
            );
            $exists->execute([$incidentId]);
            if (!$exists->fetchColumn()) {
                $referenceExpr = op_column_exists($pdo, 'incidents', 'reference_no')
                    ? "COALESCE(NULLIF(reference_no, ''), CONCAT('#', id))"
                    : "CONCAT('#', id)";
                $log = $pdo->prepare(
                    'INSERT INTO activity_log '
                    . '(user_id, action, entity_type, entity_id, details, created_at) '
                    . "SELECT ?, 'incident_resolved', 'incident', id, "
                    . "CONCAT('Incident ', {$referenceExpr}, ' has been resolved.'), NOW() "
                    . 'FROM incidents WHERE id = ? LIMIT 1'
                );
                $log->execute([$responderId, $incidentId]);
            }
        } catch (Throwable $error) {
            error_log('[api_app] incident completion audit log skipped: ' . $error->getMessage());
        }
    }

    return [
        'assignment_status' => 'completed',
        'unit_status' => 'available',
        'incident_resolved' => true,
        'incident_id' => $incidentId,
        'already_completed' => false,
        'completion_image_path' => $imagePath,
    ];
}
