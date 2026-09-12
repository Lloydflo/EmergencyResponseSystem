<?php
declare(strict_types=1);

/**
 * Shared, auditable definitions for Report Analytics.
 *
 * Accuracy rules:
 *  - Incident volume uses the incident creation timestamp only.
 *  - Dispatch activity uses dispatches.assigned_at only.
 *  - Response time means dispatch assignment to recorded on-scene arrival.
 *    Incident completion/cleared time is never used as an arrival fallback.
 *  - Preset periods are "to date" in Asia/Manila, not future calendar dates.
 *  - Previous-period comparisons use an immediately preceding range with the
 *    exact same duration as the selected range.
 *  - Unit utilization is a live snapshot and is explicitly identified as such.
 */

const ERS_REPORT_TIMEZONE = 'Asia/Manila';
const ERS_REPORT_RESPONSE_SLA_MINUTES = 10;
const ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT = 90.0;
const ERS_REPORT_RESOLUTION_TARGET_PERCENT = 95.0;
const ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT = 95.0;
const ERS_REPORT_UTILIZATION_TARGET_MIN_PERCENT = 70.0;
const ERS_REPORT_UTILIZATION_TARGET_MAX_PERCENT = 85.0;
const ERS_REPORT_MAX_RANGE_DAYS = 731;

function ers_report_timezone(): DateTimeZone
{
    static $timezone;
    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone(ERS_REPORT_TIMEZONE);
    }
    return $timezone;
}

function ers_report_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', ers_report_timezone());
}

function ers_report_parse_date(string $value, string $field): DateTimeImmutable
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, ers_report_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
        throw new InvalidArgumentException("Invalid {$field} date. Expected YYYY-MM-DD.");
    }
    if ($date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException("Invalid {$field} date.");
    }
    return $date;
}

/** @return array<string,mixed> */
function ers_report_scope(array $input, ?DateTimeImmutable $now = null): array
{
    $now = ($now ?? ers_report_now())->setTimezone(ers_report_timezone());
    $today = $now->setTime(0, 0, 0);

    $period = strtolower(trim((string)($input['period'] ?? 'month')));
    $allowedPeriods = ['today', 'week', 'month', 'quarter', 'year', 'custom'];
    if (!in_array($period, $allowedPeriods, true)) {
        $period = 'month';
    }

    $startInput = trim((string)($input['start'] ?? ''));
    $endInput = trim((string)($input['end'] ?? ''));

    if ($period === 'custom') {
        if ($startInput === '' || $endInput === '') {
            throw new InvalidArgumentException('Custom range requires both start and end dates.');
        }
        $start = ers_report_parse_date($startInput, 'start');
        $end = ers_report_parse_date($endInput, 'end');
        $periodLabel = 'Custom range';
    } else {
        switch ($period) {
            case 'today':
                $start = $today;
                $periodLabel = 'Today';
                break;
            case 'week':
                $start = $today->modify('monday this week');
                $periodLabel = 'Week to date';
                break;
            case 'quarter':
                $month = (int)$today->format('n');
                $quarterStartMonth = intdiv($month - 1, 3) * 3 + 1;
                $start = $today->setDate((int)$today->format('Y'), $quarterStartMonth, 1);
                $periodLabel = 'Quarter to date';
                break;
            case 'year':
                $start = $today->setDate((int)$today->format('Y'), 1, 1);
                $periodLabel = 'Year to date';
                break;
            case 'month':
            default:
                $start = $today->modify('first day of this month');
                $period = 'month';
                $periodLabel = 'Month to date';
                break;
        }
        $end = $today;
    }

    if ($end < $start) {
        throw new InvalidArgumentException('End date must be on or after the start date.');
    }

    $endExclusive = $end->modify('+1 day');
    $durationSeconds = $endExclusive->getTimestamp() - $start->getTimestamp();
    $rangeDays = (int)round($durationSeconds / 86400);
    if ($rangeDays < 1 || $rangeDays > ERS_REPORT_MAX_RANGE_DAYS) {
        throw new InvalidArgumentException('Date range must be between 1 and ' . ERS_REPORT_MAX_RANGE_DAYS . ' days.');
    }

    $previousEndExclusive = $start;
    $previousStart = $start->modify('-' . $rangeDays . ' days');
    $previousEnd = $previousEndExclusive->modify('-1 day');

    $type = strtolower(trim((string)($input['type'] ?? '')));
    $typeAliases = [
        '' => '',
        'medical' => 'medical',
        'fire' => 'fire',
        'police' => 'police',
        'crime' => 'police',
        'traffic' => 'traffic',
        'accident' => 'traffic',
        'other' => 'other',
    ];
    if (!array_key_exists($type, $typeAliases)) {
        throw new InvalidArgumentException('Unsupported incident type filter.');
    }
    $type = $typeAliases[$type];

    $priority = strtolower(trim((string)($input['priority'] ?? '')));
    $priorityAliases = [
        '' => '',
        'critical' => 'critical',
        'urgent' => 'high',
        'high' => 'high',
        'moderate' => 'medium',
        'medium' => 'medium',
        'low' => 'low',
    ];
    if (!array_key_exists($priority, $priorityAliases)) {
        throw new InvalidArgumentException('Unsupported priority filter.');
    }
    $priority = $priorityAliases[$priority];

    $typeValues = [];
    if ($type === 'traffic') {
        $typeValues = ['traffic', 'accident'];
    } elseif ($type === 'police') {
        $typeValues = ['police', 'crime'];
    } elseif ($type !== '' && $type !== 'other') {
        $typeValues = [$type];
    }

    $priorityValues = [];
    if ($priority === 'high') {
        $priorityValues = ['high', 'urgent'];
    } elseif ($priority === 'medium') {
        $priorityValues = ['medium', 'moderate'];
    } elseif ($priority !== '') {
        $priorityValues = [$priority];
    }

    return [
        'period' => $period,
        'period_label' => $periodLabel,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_exclusive_at' => $endExclusive->format('Y-m-d H:i:s'),
        'previous_start_date' => $previousStart->format('Y-m-d'),
        'previous_end_date' => $previousEnd->format('Y-m-d'),
        'previous_start_at' => $previousStart->format('Y-m-d H:i:s'),
        'previous_end_exclusive_at' => $previousEndExclusive->format('Y-m-d H:i:s'),
        'range_days' => $rangeDays,
        'timezone' => ERS_REPORT_TIMEZONE,
        'type' => $type,
        'type_values' => $typeValues,
        'priority' => $priority,
        'priority_values' => $priorityValues,
        'response_sla_minutes' => ERS_REPORT_RESPONSE_SLA_MINUTES,
        'generated_at' => $now->format(DateTimeInterface::ATOM),
    ];
}

/** @return array<string,array<string,bool>> */
function ers_report_schema(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tables = [
        'incidents', 'dispatches', 'units', 'users', 'staff', 'calls',
        'dispatch_attempt_logs', 'resources',
    ];
    $schema = [];
    foreach ($tables as $table) {
        $schema[$table] = [];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute($tables);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string)($row['TABLE_NAME'] ?? '');
            $column = (string)($row['COLUMN_NAME'] ?? '');
            if ($table !== '' && $column !== '') {
                $schema[$table][$column] = true;
            }
        }
    } catch (Throwable $e) {
        // Keep empty schema; callers return controlled unavailable metrics.
    }

    return $cache[$key] = $schema;
}

function ers_report_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]) && $schema[$table] !== [];
}

function ers_report_has_column(array $schema, string $table, string $column): bool
{
    return !empty($schema[$table][$column]);
}

/**
 * @param array<string,mixed> $params
 * @param array<int,string> $values
 */
function ers_report_append_values_filter(
    string &$sql,
    array &$params,
    string $column,
    array $values,
    string $prefix
): void {
    if ($values === []) {
        return;
    }
    $placeholders = [];
    foreach (array_values($values) as $index => $value) {
        $placeholder = ':' . $prefix . '_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }
    $sql .= ' AND LOWER(COALESCE(' . $column . ", '')) IN (" . implode(', ', $placeholders) . ')';
}

/** @param array<string,mixed> $params */
function ers_report_append_type_filter(
    string &$sql,
    array &$params,
    string $column,
    array $scope,
    string $prefix
): void {
    $type = (string)($scope['type'] ?? '');
    if ($type === '') {
        return;
    }
    if ($type === 'other') {
        $sql .= ' AND LOWER(COALESCE(' . $column . ", '')) NOT IN ('medical','fire','police','crime','traffic','accident')";
        return;
    }
    ers_report_append_values_filter($sql, $params, $column, (array)($scope['type_values'] ?? []), $prefix . '_type');
}

/** @param array<string,mixed> $params */
function ers_report_append_priority_filter(
    string &$sql,
    array &$params,
    string $column,
    array $scope,
    string $prefix
): void {
    if ((string)($scope['priority'] ?? '') === '') {
        return;
    }
    ers_report_append_values_filter($sql, $params, $column, (array)($scope['priority_values'] ?? []), $prefix . '_priority');
}

function ers_report_dispatch_incident_condition(array $schema, string $dispatchAlias = 'd', string $incidentAlias = 'i'): string
{
    $conditions = [];
    if (ers_report_has_column($schema, 'dispatches', 'incident_id')) {
        $conditions[] = "{$dispatchAlias}.incident_id = {$incidentAlias}.id";
    }
    if (
        ers_report_has_column($schema, 'dispatches', 'reference_no')
        && ers_report_has_column($schema, 'incidents', 'reference_no')
    ) {
        $referenceCondition = "{$dispatchAlias}.reference_no = {$incidentAlias}.reference_no";
        if (ers_report_has_column($schema, 'dispatches', 'incident_id')) {
            $referenceCondition = "(({$dispatchAlias}.incident_id IS NULL OR {$dispatchAlias}.incident_id = 0) AND {$referenceCondition})";
        }
        $conditions[] = $referenceCondition;
    }
    return $conditions ? '(' . implode(' OR ', $conditions) . ')' : '1 = 0';
}

function ers_report_incident_type_key(?string $value): string
{
    $value = strtolower(trim((string)$value));
    if ($value === 'crime') {
        return 'police';
    }
    if ($value === 'accident') {
        return 'traffic';
    }
    return in_array($value, ['medical', 'fire', 'police', 'traffic'], true) ? $value : 'other';
}

function ers_report_priority_key(?string $value): string
{
    $value = strtolower(trim((string)$value));
    if ($value === 'urgent') {
        return 'high';
    }
    if ($value === 'moderate') {
        return 'medium';
    }
    return in_array($value, ['critical', 'high', 'medium', 'low'], true) ? $value : 'other';
}

/** @return array{where:string,params:array<string,mixed>} */
function ers_report_incident_where(array $scope, string $alias = 'i', string $prefix = 'inc', bool $previous = false): array
{
    $start = $previous ? (string)$scope['previous_start_at'] : (string)$scope['start_at'];
    $endExclusive = $previous ? (string)$scope['previous_end_exclusive_at'] : (string)$scope['end_exclusive_at'];
    $where = "{$alias}.created_at >= :{$prefix}_start AND {$alias}.created_at < :{$prefix}_end";
    $params = [
        ":{$prefix}_start" => $start,
        ":{$prefix}_end" => $endExclusive,
    ];
    ers_report_append_type_filter($where, $params, "{$alias}.type", $scope, $prefix);
    ers_report_append_priority_filter($where, $params, "{$alias}.priority", $scope, $prefix);
    return ['where' => $where, 'params' => $params];
}

/** @return array{where:string,params:array<string,mixed>} */
function ers_report_dispatch_where(array $scope, string $dispatchAlias = 'd', string $incidentAlias = 'i', string $prefix = 'disp', bool $previous = false): array
{
    $start = $previous ? (string)$scope['previous_start_at'] : (string)$scope['start_at'];
    $endExclusive = $previous ? (string)$scope['previous_end_exclusive_at'] : (string)$scope['end_exclusive_at'];
    $where = "{$dispatchAlias}.assigned_at >= :{$prefix}_start AND {$dispatchAlias}.assigned_at < :{$prefix}_end";
    $params = [
        ":{$prefix}_start" => $start,
        ":{$prefix}_end" => $endExclusive,
    ];
    ers_report_append_type_filter($where, $params, "{$incidentAlias}.type", $scope, $prefix);
    ers_report_append_priority_filter($where, $params, "{$incidentAlias}.priority", $scope, $prefix);
    return ['where' => $where, 'params' => $params];
}

/** @return array<string,mixed> */
function ers_report_fetch_incident_cohort(PDO $pdo, array $scope, bool $previous = false): array
{
    $schema = ers_report_schema($pdo);
    if (!ers_report_has_table($schema, 'incidents') || !ers_report_has_column($schema, 'incidents', 'created_at')) {
        return [
            'available' => false,
            'total' => 0,
            'resolved' => 0,
            'resolution_rate' => null,
            'cancelled' => 0,
            'open' => 0,
            'avg_resolution_minutes' => null,
            'type_counts' => ['medical' => 0, 'fire' => 0, 'police' => 0, 'traffic' => 0, 'other' => 0],
            'priority_counts' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'other' => 0],
            'status_counts' => ['resolved' => 0, 'cancelled' => 0, 'open' => 0],
        ];
    }

    $prefix = $previous ? 'prev_inc' : 'inc';
    $parts = ers_report_incident_where($scope, 'i', $prefix, $previous);
    $where = $parts['where'];
    $baseParams = $parts['params'];
    $metricParams = $baseParams;
    $periodEnd = $previous ? (string)$scope['previous_end_exclusive_at'] : (string)$scope['end_exclusive_at'];

    $terminalStatusExpression = "LOWER(COALESCE(i.status, '')) IN ('resolved','completed','closed')";
    $cancelledStatusExpression = "LOWER(COALESCE(i.status, '')) IN ('cancelled','canceled','rejected')";
    $resolutionTimestampExpression = null;
    $hasResolvedAt = ers_report_has_column($schema, 'incidents', 'resolved_at');
    $hasCompletedAt = ers_report_has_column($schema, 'incidents', 'completed_at');
    if ($hasResolvedAt && $hasCompletedAt) {
        $resolutionTimestampExpression = 'COALESCE(i.resolved_at, i.completed_at)';
    } elseif ($hasResolvedAt) {
        $resolutionTimestampExpression = 'i.resolved_at';
    } elseif ($hasCompletedAt) {
        $resolutionTimestampExpression = 'i.completed_at';
    }

    // A terminal status without a trustworthy completion timestamp is not
    // counted in the period-end resolution numerator. It remains visible in
    // the current status distribution so data-quality gaps are not hidden.
    $resolvedExpression = '0 = 1';
    $resolvedDurationExpression = 'NULL';
    if ($resolutionTimestampExpression !== null) {
        $resolvedBaseExpression = "{$terminalStatusExpression} AND {$resolutionTimestampExpression} IS NOT NULL AND {$resolutionTimestampExpression} >= i.created_at";
        $resolvedExpression = $resolvedBaseExpression . " AND {$resolutionTimestampExpression} < :{$prefix}_resolved_end";
        $resolvedDurationExpression = "CASE WHEN {$resolvedBaseExpression} AND {$resolutionTimestampExpression} < :{$prefix}_duration_end THEN TIMESTAMPDIFF(SECOND, i.created_at, {$resolutionTimestampExpression}) / 60.0 END";
        $metricParams[":{$prefix}_resolved_end"] = $periodEnd;
        $metricParams[":{$prefix}_duration_end"] = $periodEnd;
    }

    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN {$resolvedExpression} THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN {$cancelledStatusExpression} THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN NOT ({$terminalStatusExpression}) AND NOT ({$cancelledStatusExpression}) THEN 1 ELSE 0 END) AS open_count,
            AVG({$resolvedDurationExpression}) AS avg_resolution_minutes
        FROM incidents i
        WHERE {$where}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($metricParams);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $typeCounts = ['medical' => 0, 'fire' => 0, 'police' => 0, 'traffic' => 0, 'other' => 0];
    $stmt = $pdo->prepare("SELECT i.type, COUNT(*) AS count_value FROM incidents i WHERE {$where} GROUP BY i.type");
    $stmt->execute($baseParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $typeRow) {
        $key = ers_report_incident_type_key((string)($typeRow['type'] ?? ''));
        $typeCounts[$key] += (int)($typeRow['count_value'] ?? 0);
    }

    $priorityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'other' => 0];
    $stmt = $pdo->prepare("SELECT i.priority, COUNT(*) AS count_value FROM incidents i WHERE {$where} GROUP BY i.priority");
    $stmt->execute($baseParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $priorityRow) {
        $key = ers_report_priority_key((string)($priorityRow['priority'] ?? ''));
        $priorityCounts[$key] += (int)($priorityRow['count_value'] ?? 0);
    }

    $statusCounts = ['resolved' => 0, 'cancelled' => 0, 'open' => 0];
    if (ers_report_has_column($schema, 'incidents', 'status')) {
        $stmt = $pdo->prepare("SELECT LOWER(COALESCE(i.status, '')) AS status_key, COUNT(*) AS count_value FROM incidents i WHERE {$where} GROUP BY LOWER(COALESCE(i.status, ''))");
        $stmt->execute($baseParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $statusRow) {
            $status = strtolower(trim((string)($statusRow['status_key'] ?? '')));
            $count = (int)($statusRow['count_value'] ?? 0);
            if (in_array($status, ['resolved', 'completed', 'closed'], true)) {
                $statusCounts['resolved'] += $count;
            } elseif (in_array($status, ['cancelled', 'canceled', 'rejected'], true)) {
                $statusCounts['cancelled'] += $count;
            } else {
                $statusCounts['open'] += $count;
            }
        }
    }

    $total = (int)($row['total'] ?? 0);
    $resolved = (int)($row['resolved'] ?? 0);
    return [
        'available' => true,
        'total' => $total,
        'resolved' => $resolved,
        'resolution_rate' => $total > 0 ? round(($resolved / $total) * 100, 1) : null,
        'cancelled' => (int)($row['cancelled'] ?? 0),
        'open' => (int)($row['open_count'] ?? 0),
        'avg_resolution_minutes' => $row['avg_resolution_minutes'] !== null ? round((float)$row['avg_resolution_minutes'], 1) : null,
        'type_counts' => $typeCounts,
        'priority_counts' => $priorityCounts,
        'status_counts' => $statusCounts,
    ];
}

/** @return array<string,mixed> */
function ers_report_fetch_dispatch_metrics(PDO $pdo, array $scope, bool $previous = false): array
{
    $schema = ers_report_schema($pdo);
    if (
        !ers_report_has_table($schema, 'dispatches')
        || !ers_report_has_column($schema, 'dispatches', 'assigned_at')
        || !ers_report_has_table($schema, 'incidents')
    ) {
        return [
            'available' => false,
            'total_dispatches' => 0,
            'acknowledged_count' => 0,
            'acknowledgement_rate' => null,
            'avg_ack_minutes' => null,
            'ack_sample_count' => 0,
            'avg_on_scene_minutes' => null,
            'on_scene_sample_count' => 0,
            'response_sla_met_count' => 0,
            'response_sla_breach_count' => 0,
            'response_sla_compliance_rate' => null,
            'avg_enroute_minutes' => null,
            'enroute_sample_count' => 0,
            'avg_clear_minutes' => null,
            'clear_sample_count' => 0,
        ];
    }

    $prefix = $previous ? 'prev_disp' : 'disp';
    $parts = ers_report_dispatch_where($scope, 'd', 'i', $prefix, $previous);
    $join = ers_report_dispatch_incident_condition($schema, 'd', 'i');

    $ackValid = ers_report_has_column($schema, 'dispatches', 'acknowledged_at')
        ? 'd.acknowledged_at IS NOT NULL AND d.acknowledged_at >= d.assigned_at'
        : '0 = 1';
    $enrouteValid = ers_report_has_column($schema, 'dispatches', 'enroute_at')
        ? 'd.enroute_at IS NOT NULL AND d.enroute_at >= d.assigned_at'
        : '0 = 1';
    $sceneValid = ers_report_has_column($schema, 'dispatches', 'on_scene_at')
        ? 'd.on_scene_at IS NOT NULL AND d.on_scene_at >= d.assigned_at'
        : '0 = 1';
    $clearValid = ers_report_has_column($schema, 'dispatches', 'cleared_at')
        ? 'd.cleared_at IS NOT NULL AND d.cleared_at >= d.assigned_at'
        : '0 = 1';

    $sql = "
        SELECT
            COUNT(*) AS total_dispatches,
            SUM(CASE WHEN {$ackValid} THEN 1 ELSE 0 END) AS acknowledged_count,
            AVG(CASE WHEN {$ackValid} THEN TIMESTAMPDIFF(SECOND, d.assigned_at, d.acknowledged_at) / 60.0 END) AS avg_ack_minutes,
            SUM(CASE WHEN {$ackValid} THEN 1 ELSE 0 END) AS ack_sample_count,
            AVG(CASE WHEN {$enrouteValid} THEN TIMESTAMPDIFF(SECOND, d.assigned_at, d.enroute_at) / 60.0 END) AS avg_enroute_minutes,
            SUM(CASE WHEN {$enrouteValid} THEN 1 ELSE 0 END) AS enroute_sample_count,
            AVG(CASE WHEN {$sceneValid} THEN TIMESTAMPDIFF(SECOND, d.assigned_at, d.on_scene_at) / 60.0 END) AS avg_on_scene_minutes,
            SUM(CASE WHEN {$sceneValid} THEN 1 ELSE 0 END) AS on_scene_sample_count,
            SUM(CASE WHEN {$sceneValid} AND TIMESTAMPDIFF(SECOND, d.assigned_at, d.on_scene_at) <= :{$prefix}_sla_met_seconds THEN 1 ELSE 0 END) AS sla_met_count,
            SUM(CASE WHEN {$sceneValid} AND TIMESTAMPDIFF(SECOND, d.assigned_at, d.on_scene_at) > :{$prefix}_sla_breach_seconds THEN 1 ELSE 0 END) AS sla_breach_count,
            AVG(CASE WHEN {$clearValid} THEN TIMESTAMPDIFF(SECOND, d.assigned_at, d.cleared_at) / 60.0 END) AS avg_clear_minutes,
            SUM(CASE WHEN {$clearValid} THEN 1 ELSE 0 END) AS clear_sample_count
        FROM dispatches d
        INNER JOIN incidents i ON {$join}
        WHERE {$parts['where']}
    ";
    $params = $parts['params'];
    $params[":{$prefix}_sla_met_seconds"] = ERS_REPORT_RESPONSE_SLA_MINUTES * 60;
    $params[":{$prefix}_sla_breach_seconds"] = ERS_REPORT_RESPONSE_SLA_MINUTES * 60;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($row['total_dispatches'] ?? 0);
    $ackCount = (int)($row['acknowledged_count'] ?? 0);
    $sceneSamples = (int)($row['on_scene_sample_count'] ?? 0);
    $slaMet = (int)($row['sla_met_count'] ?? 0);
    $slaBreached = (int)($row['sla_breach_count'] ?? 0);

    return [
        'available' => true,
        'total_dispatches' => $total,
        'acknowledged_count' => $ackCount,
        'acknowledgement_rate' => $total > 0 ? round(($ackCount / $total) * 100, 1) : null,
        'avg_ack_minutes' => $row['avg_ack_minutes'] !== null ? round((float)$row['avg_ack_minutes'], 1) : null,
        'ack_sample_count' => (int)($row['ack_sample_count'] ?? 0),
        'avg_enroute_minutes' => $row['avg_enroute_minutes'] !== null ? round((float)$row['avg_enroute_minutes'], 1) : null,
        'enroute_sample_count' => (int)($row['enroute_sample_count'] ?? 0),
        'avg_on_scene_minutes' => $row['avg_on_scene_minutes'] !== null ? round((float)$row['avg_on_scene_minutes'], 1) : null,
        'on_scene_sample_count' => $sceneSamples,
        'response_sla_met_count' => $slaMet,
        'response_sla_breach_count' => $slaBreached,
        'response_sla_compliance_rate' => $sceneSamples > 0 ? round(($slaMet / $sceneSamples) * 100, 1) : null,
        'avg_clear_minutes' => $row['avg_clear_minutes'] !== null ? round((float)$row['avg_clear_minutes'], 1) : null,
        'clear_sample_count' => (int)($row['clear_sample_count'] ?? 0),
    ];
}

/** @return array<string,mixed> */
function ers_report_fetch_unit_snapshot(PDO $pdo): array
{
    $schema = ers_report_schema($pdo);
    if (!ers_report_has_table($schema, 'units') || !ers_report_has_column($schema, 'units', 'status')) {
        return [
            'available' => false,
            'captured_at' => ers_report_now()->format(DateTimeInterface::ATOM),
            'total_units' => 0,
            'operational_units' => 0,
            'available_units' => 0,
            'in_use_units' => 0,
            'maintenance_units' => 0,
            'unavailable_units' => 0,
            'other_units' => 0,
            'utilization_rate' => null,
            'availability_rate' => null,
            'by_type' => [],
        ];
    }

    $statusCounts = [];
    $stmt = $pdo->query("SELECT LOWER(COALESCE(status, 'unknown')) AS status_key, COUNT(*) AS count_value FROM units GROUP BY LOWER(COALESCE(status, 'unknown'))");
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $statusCounts[(string)$row['status_key']] = (int)$row['count_value'];
    }

    $available = (int)($statusCounts['available'] ?? 0);
    $inUse = 0;
    foreach (['assigned', 'acknowledged', 'enroute', 'on_scene', 'busy', 'deployed'] as $status) {
        $inUse += (int)($statusCounts[$status] ?? 0);
    }
    $maintenance = (int)($statusCounts['maintenance'] ?? 0);
    $unavailable = 0;
    foreach (['unavailable', 'out_of_service', 'offline', 'inactive'] as $status) {
        $unavailable += (int)($statusCounts[$status] ?? 0);
    }
    $total = array_sum($statusCounts);
    $other = max(0, $total - $available - $inUse - $maintenance - $unavailable);
    $operational = $available + $inUse;

    $byType = [];
    if (ers_report_has_column($schema, 'units', 'unit_type')) {
        $stmt = $pdo->query("SELECT LOWER(COALESCE(unit_type, 'other')) AS type_key, COUNT(*) AS count_value FROM units GROUP BY LOWER(COALESCE(unit_type, 'other'))");
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $byType[(string)$row['type_key']] = (int)$row['count_value'];
        }
    }

    return [
        'available' => true,
        'captured_at' => ers_report_now()->format(DateTimeInterface::ATOM),
        'total_units' => $total,
        'operational_units' => $operational,
        'available_units' => $available,
        'in_use_units' => $inUse,
        'maintenance_units' => $maintenance,
        'unavailable_units' => $unavailable,
        'other_units' => $other,
        'utilization_rate' => $operational > 0 ? round(($inUse / $operational) * 100, 1) : null,
        'availability_rate' => $total > 0 ? round(($available / $total) * 100, 1) : null,
        'by_type' => $byType,
        'raw_status_counts' => $statusCounts,
    ];
}

function ers_report_active_responder_count(PDO $pdo): ?int
{
    $schema = ers_report_schema($pdo);
    if (
        ers_report_has_table($schema, 'users')
        && ers_report_has_column($schema, 'users', 'role')
        && ers_report_has_column($schema, 'users', 'status')
    ) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'responder' AND LOWER(status) = 'active'");
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }
    if (
        ers_report_has_table($schema, 'staff')
        && ers_report_has_column($schema, 'staff', 'status')
    ) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM staff WHERE LOWER(status) IN ('available','on_duty')");
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }
    return null;
}

function ers_report_fetch_call_count(PDO $pdo, array $scope): ?int
{
    $schema = ers_report_schema($pdo);
    if (!ers_report_has_table($schema, 'calls') || !ers_report_has_column($schema, 'calls', 'received_at')) {
        return null;
    }
    $where = 'c.received_at >= :calls_start AND c.received_at < :calls_end';
    $params = [
        ':calls_start' => (string)$scope['start_at'],
        ':calls_end' => (string)$scope['end_exclusive_at'],
    ];
    if (ers_report_has_column($schema, 'calls', 'incident_type')) {
        ers_report_append_type_filter($where, $params, 'c.incident_type', $scope, 'calls');
    }
    if (ers_report_has_column($schema, 'calls', 'priority')) {
        ers_report_append_priority_filter($where, $params, 'c.priority', $scope, 'calls');
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM calls c WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** @return array<string,mixed> */
function ers_report_fetch_metrics(PDO $pdo, array $scope): array
{
    $currentIncidents = ers_report_fetch_incident_cohort($pdo, $scope, false);
    $previousIncidents = ers_report_fetch_incident_cohort($pdo, $scope, true);
    $currentDispatch = ers_report_fetch_dispatch_metrics($pdo, $scope, false);
    $previousDispatch = ers_report_fetch_dispatch_metrics($pdo, $scope, true);
    $units = ers_report_fetch_unit_snapshot($pdo);

    $definitions = [
        'incident_volume' => 'Incidents whose created_at falls within the selected Asia/Manila date range.',
        'resolution_rate' => 'Selected-period incident cohort resolved by the end of that period, divided by incidents created in the period.',
        'response_time' => 'Dispatch assigned_at to recorded on_scene_at. Completion/cleared time is never substituted for arrival.',
        'response_sla' => 'Share of dispatches with a valid on-scene timestamp arriving within ' . ERS_REPORT_RESPONSE_SLA_MINUTES . ' minutes.',
        'unit_utilization' => 'Live snapshot: in-use units divided by currently operational units (available plus in use).',
        'comparison' => 'Immediately preceding range with the same number of days.',
        'benchmarks' => 'Dashboard operational benchmarks: arrival compliance ≥ ' . number_format(ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT, 0) . '%, resolution ≥ ' . number_format(ERS_REPORT_RESOLUTION_TARGET_PERCENT, 0) . '%, acknowledgement ≥ ' . number_format(ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT, 0) . '%, and utilization ' . number_format(ERS_REPORT_UTILIZATION_TARGET_MIN_PERCENT, 0) . '–' . number_format(ERS_REPORT_UTILIZATION_TARGET_MAX_PERCENT, 0) . '%. These are display benchmarks, not measured data.',
    ];

    return [
        'meta' => [
            'period' => $scope['period'],
            'period_label' => $scope['period_label'],
            'start_date' => $scope['start_date'],
            'end_date' => $scope['end_date'],
            'previous_start_date' => $scope['previous_start_date'],
            'previous_end_date' => $scope['previous_end_date'],
            'range_days' => $scope['range_days'],
            'timezone' => $scope['timezone'],
            'generated_at' => $scope['generated_at'],
            'type' => $scope['type'],
            'priority' => $scope['priority'],
            'targets' => [
                'response_sla_minutes' => ERS_REPORT_RESPONSE_SLA_MINUTES,
                'arrival_compliance_percent' => ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT,
                'resolution_percent' => ERS_REPORT_RESOLUTION_TARGET_PERCENT,
                'acknowledgement_percent' => ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT,
                'utilization_min_percent' => ERS_REPORT_UTILIZATION_TARGET_MIN_PERCENT,
                'utilization_max_percent' => ERS_REPORT_UTILIZATION_TARGET_MAX_PERCENT,
            ],
            'definitions' => $definitions,
        ],
        'metrics' => [
            'total_calls' => ers_report_fetch_call_count($pdo, $scope),
            'total_calls_today' => ers_report_fetch_call_count($pdo, $scope),
            'total_incidents' => $currentIncidents['total'],
            'total_incidents_month' => $currentIncidents['total'],
            'previous_total_incidents' => $previousIncidents['total'],
            'total_incidents_last_month' => $previousIncidents['total'],
            'resolved_incidents' => $currentIncidents['resolved'],
            'open_incidents' => $currentIncidents['open'],
            'cancelled_incidents' => $currentIncidents['cancelled'],
            'resolution_rate' => $currentIncidents['resolution_rate'],
            'success_rate' => $currentIncidents['resolution_rate'],
            'previous_resolution_rate' => $previousIncidents['resolution_rate'],
            'previous_success_rate' => $previousIncidents['resolution_rate'],
            'avg_resolution_time_min' => $currentIncidents['avg_resolution_minutes'],
            'avg_response_time_min' => $currentDispatch['avg_on_scene_minutes'],
            'previous_avg_response_time_min' => $previousDispatch['avg_on_scene_minutes'],
            'avg_response_sample_count' => $currentDispatch['on_scene_sample_count'],
            'previous_avg_response_sample_count' => $previousDispatch['on_scene_sample_count'],
            'response_sla_minutes' => ERS_REPORT_RESPONSE_SLA_MINUTES,
            'response_sla_met_count' => $currentDispatch['response_sla_met_count'],
            'response_sla_breach_count' => $currentDispatch['response_sla_breach_count'],
            'response_sla_compliance_rate' => $currentDispatch['response_sla_compliance_rate'],
            'total_dispatches' => $currentDispatch['total_dispatches'],
            'acknowledged_dispatches' => $currentDispatch['acknowledged_count'],
            'dispatch_acknowledgement_rate' => $currentDispatch['acknowledgement_rate'],
            'avg_ack_time_min' => $currentDispatch['avg_ack_minutes'],
            'incidents_by_priority' => $currentIncidents['priority_counts'],
            'incidents_by_type' => $currentIncidents['type_counts'],
            'incident_status_counts' => $currentIncidents['status_counts'],
            'resource_utilization' => $units['utilization_rate'],
            'unit_snapshot' => $units,
            'total_units' => $units['total_units'],
            'operational_units' => $units['operational_units'],
            'available_units' => $units['available_units'],
            'in_use_units' => $units['in_use_units'],
            'maintenance_units' => $units['maintenance_units'],
            'unavailable_units' => $units['unavailable_units'],
            'active_responder_accounts' => ers_report_active_responder_count($pdo),
        ],
    ];
}

/** @return array<string,mixed> */
function ers_report_fetch_daily_response(PDO $pdo, array $scope): array
{
    $schema = ers_report_schema($pdo);
    $labels = [];
    $valuesByDate = [];
    $samplesByDate = [];

    $cursor = ers_report_parse_date((string)$scope['start_date'], 'start');
    $end = ers_report_parse_date((string)$scope['end_date'], 'end');
    while ($cursor <= $end) {
        $dateKey = $cursor->format('Y-m-d');
        $labels[] = $dateKey;
        $valuesByDate[$dateKey] = null;
        $samplesByDate[$dateKey] = 0;
        $cursor = $cursor->modify('+1 day');
    }

    if (
        ers_report_has_table($schema, 'dispatches')
        && ers_report_has_column($schema, 'dispatches', 'assigned_at')
        && ers_report_has_column($schema, 'dispatches', 'on_scene_at')
        && ers_report_has_table($schema, 'incidents')
    ) {
        $parts = ers_report_dispatch_where($scope, 'd', 'i', 'daily_response', false);
        $join = ers_report_dispatch_incident_condition($schema, 'd', 'i');
        $sql = "
            SELECT
                DATE(d.assigned_at) AS date_key,
                AVG(TIMESTAMPDIFF(SECOND, d.assigned_at, d.on_scene_at) / 60.0) AS avg_minutes,
                COUNT(*) AS sample_count
            FROM dispatches d
            INNER JOIN incidents i ON {$join}
            WHERE {$parts['where']}
              AND d.on_scene_at IS NOT NULL
              AND d.on_scene_at >= d.assigned_at
            GROUP BY DATE(d.assigned_at)
            ORDER BY DATE(d.assigned_at)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parts['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dateKey = (string)($row['date_key'] ?? '');
            if (array_key_exists($dateKey, $valuesByDate)) {
                $valuesByDate[$dateKey] = $row['avg_minutes'] !== null ? round((float)$row['avg_minutes'], 1) : null;
                $samplesByDate[$dateKey] = (int)($row['sample_count'] ?? 0);
            }
        }
    }

    $incidentsByDate = [];
    foreach ($labels as $dateKey) {
        $incidentsByDate[$dateKey] = 0;
    }
    if (ers_report_has_table($schema, 'incidents')) {
        $partsInc = ers_report_incident_where($scope, 'i', 'daily_incidents', false);
        $sqlInc = "
            SELECT
                DATE(i.created_at) AS date_key,
                COUNT(*) AS incident_count
            FROM incidents i
            WHERE {$partsInc['where']}
            GROUP BY DATE(i.created_at)
            ORDER BY DATE(i.created_at)
        ";
        $stmtInc = $pdo->prepare($sqlInc);
        $stmtInc->execute($partsInc['params']);
        foreach ($stmtInc->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dateKey = (string)($row['date_key'] ?? '');
            if (array_key_exists($dateKey, $incidentsByDate)) {
                $incidentsByDate[$dateKey] = (int)($row['incident_count'] ?? 0);
            }
        }
    }

    return [
        'labels' => $labels,
        'data' => array_map(static fn(string $date) => $valuesByDate[$date], $labels),
        'sample_counts' => array_map(static fn(string $date): int => $samplesByDate[$date], $labels),
        'incidents_data' => array_map(static fn(string $date): int => $incidentsByDate[$date], $labels),
        'unit' => 'minutes',
    ];
}

/** @return array<string,mixed> */
function ers_report_fetch_dispatch_summary(PDO $pdo, array $scope): array
{
    $schema = ers_report_schema($pdo);
    $metrics = ers_report_fetch_dispatch_metrics($pdo, $scope, false);
    $unitSnapshot = ers_report_fetch_unit_snapshot($pdo);
    $byUnitType = ['ambulance' => 0, 'fire' => 0, 'police' => 0, 'rescue' => 0, 'other' => 0];
    $topUnits = [];
    $allUnits = [];
    $dailyCounts = [];

    $cursor = ers_report_parse_date((string)$scope['start_date'], 'start');
    $end = ers_report_parse_date((string)$scope['end_date'], 'end');
    while ($cursor <= $end) {
        $dailyCounts[$cursor->format('Y-m-d')] = 0;
        $cursor = $cursor->modify('+1 day');
    }

    if (
        ers_report_has_table($schema, 'dispatches')
        && ers_report_has_table($schema, 'incidents')
        && ers_report_has_column($schema, 'dispatches', 'assigned_at')
    ) {
        $parts = ers_report_dispatch_where($scope, 'd', 'i', 'summary_disp', false);
        $join = ers_report_dispatch_incident_condition($schema, 'd', 'i');
        $unitJoin = ers_report_has_table($schema, 'units') && ers_report_has_column($schema, 'dispatches', 'unit_id')
            ? ' LEFT JOIN units u ON u.id = d.unit_id '
            : '';
        $unitTypeExpression = $unitJoin !== '' && ers_report_has_column($schema, 'units', 'unit_type')
            ? "LOWER(COALESCE(u.unit_type, 'other'))"
            : "'other'";
        $hasDispatchUnitId = ers_report_has_column($schema, 'dispatches', 'unit_id');
        $unitIdentifierExpression = $unitJoin !== '' && ers_report_has_column($schema, 'units', 'identifier')
            ? "COALESCE(NULLIF(u.identifier, ''), CONCAT('Unit #', d.unit_id))"
            : ($hasDispatchUnitId ? "CONCAT('Unit #', COALESCE(d.unit_id, 0))" : "'Unassigned'");

        $stmt = $pdo->prepare("SELECT {$unitTypeExpression} AS unit_type_key, COUNT(*) AS count_value FROM dispatches d INNER JOIN incidents i ON {$join} {$unitJoin} WHERE {$parts['where']} GROUP BY {$unitTypeExpression}");
        $stmt->execute($parts['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim((string)($row['unit_type_key'] ?? 'other')));
            if (!isset($byUnitType[$key])) {
                $key = 'other';
            }
            $byUnitType[$key] += (int)($row['count_value'] ?? 0);
        }

        if (ers_report_has_column($schema, 'dispatches', 'unit_id')) {
            $stmt = $pdo->prepare("
                SELECT
                    d.unit_id,
                    {$unitIdentifierExpression} AS identifier,
                    {$unitTypeExpression} AS unit_type,
                    COUNT(*) AS dispatch_count
                FROM dispatches d
                INNER JOIN incidents i ON {$join}
                {$unitJoin}
                WHERE {$parts['where']}
                GROUP BY d.unit_id, identifier, unit_type
                ORDER BY dispatch_count DESC, identifier ASC
            ");
            $stmt->execute($parts['params']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $allUnits[] = [
                    'unit_id' => (int)($row['unit_id'] ?? 0),
                    'identifier' => (string)($row['identifier'] ?? ''),
                    'unit_type' => (string)($row['unit_type'] ?? 'other'),
                    'count' => (int)($row['dispatch_count'] ?? 0),
                ];
            }
            $topUnits = array_slice($allUnits, 0, 10);
        }

        $stmt = $pdo->prepare("SELECT DATE(d.assigned_at) AS date_key, COUNT(*) AS count_value FROM dispatches d INNER JOIN incidents i ON {$join} WHERE {$parts['where']} GROUP BY DATE(d.assigned_at) ORDER BY DATE(d.assigned_at)");
        $stmt->execute($parts['params']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dateKey = (string)($row['date_key'] ?? '');
            if (array_key_exists($dateKey, $dailyCounts)) {
                $dailyCounts[$dateKey] = (int)($row['count_value'] ?? 0);
            }
        }
    }

    return [
        'metrics' => array_merge($metrics, [
            'by_unit_type' => $byUnitType,
            'available_units' => $unitSnapshot['available_units'],
            'in_use_units' => $unitSnapshot['in_use_units'],
            'maintenance_units' => $unitSnapshot['maintenance_units'],
            'unavailable_units' => $unitSnapshot['unavailable_units'],
            'total_units' => $unitSnapshot['total_units'],
            'operational_units' => $unitSnapshot['operational_units'],
            'resource_utilization' => $unitSnapshot['utilization_rate'],
            // Backward-compatible aliases.
            'avg_ack_min' => $metrics['avg_ack_minutes'],
            'avg_enroute_min' => $metrics['avg_enroute_minutes'],
            'avg_on_scene_min' => $metrics['avg_on_scene_minutes'],
            'avg_clear_min' => $metrics['avg_clear_minutes'],
            'sla_breach_count' => $metrics['response_sla_breach_count'],
            'sla_breach_rate' => $metrics['on_scene_sample_count'] > 0
                ? round(($metrics['response_sla_breach_count'] / $metrics['on_scene_sample_count']) * 100, 1)
                : null,
        ]),
        'summary_by_service' => $byUnitType,
        'top_units' => $topUnits,
        'all_units' => $allUnits,
        'daily' => [
            'labels' => array_keys($dailyCounts),
            'data' => array_values($dailyCounts),
        ],
        'unit_snapshot' => $unitSnapshot,
    ];
}

/** @return array<int,array<string,mixed>> */
function ers_report_fetch_incidents(PDO $pdo, array $scope, int $limit = 100): array
{
    $schema = ers_report_schema($pdo);
    if (
        !ers_report_has_table($schema, 'incidents')
        || !ers_report_has_column($schema, 'incidents', 'id')
        || !ers_report_has_column($schema, 'incidents', 'created_at')
    ) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $parts = ers_report_incident_where($scope, 'i', 'list_inc', false);

    $selects = [
        'i.id',
        ers_report_has_column($schema, 'incidents', 'reference_no') ? 'i.reference_no' : "'' AS reference_no",
        ers_report_has_column($schema, 'incidents', 'type') ? 'i.type' : "'' AS type",
        ers_report_has_column($schema, 'incidents', 'priority') ? 'i.priority' : "'' AS priority",
        ers_report_has_column($schema, 'incidents', 'status') ? 'i.status' : "'' AS status",
        ers_report_has_column($schema, 'incidents', 'title') ? 'i.title' : "'' AS title",
        ers_report_has_column($schema, 'incidents', 'description') ? 'i.description' : "'' AS description",
        ers_report_has_column($schema, 'incidents', 'location_address') ? 'i.location_address' : "'' AS location_address",
        ers_report_has_column($schema, 'incidents', 'latitude') ? 'i.latitude' : 'NULL AS latitude',
        ers_report_has_column($schema, 'incidents', 'longitude') ? 'i.longitude' : 'NULL AS longitude',
        'i.created_at',
        ers_report_has_column($schema, 'incidents', 'updated_at') ? 'i.updated_at' : 'NULL AS updated_at',
        ers_report_has_column($schema, 'incidents', 'resolved_at') && ers_report_has_column($schema, 'incidents', 'completed_at')
            ? 'COALESCE(i.resolved_at, i.completed_at) AS resolved_at'
            : (ers_report_has_column($schema, 'incidents', 'resolved_at')
                ? 'i.resolved_at'
                : (ers_report_has_column($schema, 'incidents', 'completed_at') ? 'i.completed_at AS resolved_at' : 'NULL AS resolved_at')),
    ];

    $dispatchJoin = '';
    if (
        ers_report_has_table($schema, 'dispatches')
        && ers_report_has_column($schema, 'dispatches', 'id')
        && ers_report_has_column($schema, 'dispatches', 'assigned_at')
        && ers_report_dispatch_incident_condition($schema, 'd_latest', 'i') !== '1 = 0'
    ) {
        $match = ers_report_dispatch_incident_condition($schema, 'd_latest', 'i');
        $dispatchJoin = "
            LEFT JOIN dispatches d ON d.id = (
                SELECT d_latest.id
                FROM dispatches d_latest
                WHERE {$match}
                ORDER BY d_latest.assigned_at DESC, d_latest.id DESC
                LIMIT 1
            )
        ";
        $selects[] = 'd.id AS dispatch_id';
        $selects[] = 'd.assigned_at';
        $selects[] = ers_report_has_column($schema, 'dispatches', 'acknowledged_at') ? 'd.acknowledged_at' : 'NULL AS acknowledged_at';
        $selects[] = ers_report_has_column($schema, 'dispatches', 'on_scene_at') ? 'd.on_scene_at' : 'NULL AS on_scene_at';
        $selects[] = ers_report_has_column($schema, 'dispatches', 'cleared_at') ? 'd.cleared_at' : 'NULL AS cleared_at';
        $selects[] = ers_report_has_column($schema, 'dispatches', 'unit_id') ? 'd.unit_id' : 'NULL AS unit_id';
    } else {
        $selects[] = 'NULL AS dispatch_id';
        $selects[] = 'NULL AS assigned_at';
        $selects[] = 'NULL AS acknowledged_at';
        $selects[] = 'NULL AS on_scene_at';
        $selects[] = 'NULL AS cleared_at';
        $selects[] = 'NULL AS unit_id';
    }

    $unitJoin = '';
    if (
        $dispatchJoin !== ''
        && ers_report_has_table($schema, 'units')
        && ers_report_has_column($schema, 'dispatches', 'unit_id')
    ) {
        $unitJoin = ' LEFT JOIN units u ON u.id = d.unit_id ';
        $selects[] = ers_report_has_column($schema, 'units', 'identifier') ? 'u.identifier AS unit_identifier' : "'' AS unit_identifier";
        $selects[] = ers_report_has_column($schema, 'units', 'unit_type') ? 'u.unit_type AS unit_type' : "'' AS unit_type";
    } else {
        $selects[] = "'' AS unit_identifier";
        $selects[] = "'' AS unit_type";
    }

    $sql = 'SELECT ' . implode(', ', $selects)
        . ' FROM incidents i '
        . $dispatchJoin
        . $unitJoin
        . ' WHERE ' . $parts['where']
        . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parts['params']);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $responseMinutes = null;
        $assigned = trim((string)($row['assigned_at'] ?? ''));
        $onScene = trim((string)($row['on_scene_at'] ?? ''));
        if ($assigned !== '' && $onScene !== '') {
            $assignedTime = strtotime($assigned);
            $sceneTime = strtotime($onScene);
            if ($assignedTime !== false && $sceneTime !== false && $sceneTime >= $assignedTime) {
                $responseMinutes = round(($sceneTime - $assignedTime) / 60, 1);
            }
        }
        $reference = trim((string)($row['reference_no'] ?? ''));
        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'incident_code' => $reference !== '' ? $reference : ('INC-' . (int)($row['id'] ?? 0)),
            'reference_no' => $reference,
            'title' => (string)($row['title'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'location' => (string)($row['location_address'] ?? ''),
            'location_address' => (string)($row['location_address'] ?? ''),
            'latitude' => $row['latitude'] ?? null,
            'longitude' => $row['longitude'] ?? null,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'resolved_at' => (string)($row['resolved_at'] ?? ''),
            'dispatch_id' => (int)($row['dispatch_id'] ?? 0),
            'assigned_at' => (string)($row['assigned_at'] ?? ''),
            'acknowledged_at' => (string)($row['acknowledged_at'] ?? ''),
            'on_scene_at' => (string)($row['on_scene_at'] ?? ''),
            'cleared_at' => (string)($row['cleared_at'] ?? ''),
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'unit_identifier' => (string)($row['unit_identifier'] ?? ''),
            'unit_type' => (string)($row['unit_type'] ?? ''),
            'response_time_min' => $responseMinutes,
        ];
    }
    return $items;
}

/** @return array<string,mixed> */
function ers_report_public_scope(array $scope): array
{
    return [
        'period' => $scope['period'],
        'period_label' => $scope['period_label'],
        'start_date' => $scope['start_date'],
        'end_date' => $scope['end_date'],
        'previous_start_date' => $scope['previous_start_date'],
        'previous_end_date' => $scope['previous_end_date'],
        'range_days' => $scope['range_days'],
        'timezone' => $scope['timezone'],
        'type' => $scope['type'],
        'priority' => $scope['priority'],
        'response_sla_minutes' => $scope['response_sla_minutes'],
        'targets' => [
            'arrival_compliance_percent' => ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT,
            'resolution_percent' => ERS_REPORT_RESOLUTION_TARGET_PERCENT,
            'acknowledgement_percent' => ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT,
            'utilization_min_percent' => ERS_REPORT_UTILIZATION_TARGET_MIN_PERCENT,
            'utilization_max_percent' => ERS_REPORT_UTILIZATION_TARGET_MAX_PERCENT,
        ],
        'generated_at' => $scope['generated_at'],
    ];
}
