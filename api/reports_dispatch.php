<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/dispatch_attempt_log.php';
require_once __DIR__ . '/../includes/report_analytics.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

/** @return array{where:string,params:array<string,mixed>} */
function report_dispatch_failure_log_where(array $scope): array
{
    $where = 'dal.created_at >= :failure_start AND dal.created_at < :failure_end';
    $params = [
        ':failure_start' => (string)$scope['start_at'],
        ':failure_end' => (string)$scope['end_exclusive_at'],
    ];
    ers_report_append_type_filter($where, $params, 'i.type', $scope, 'failure');
    ers_report_append_priority_filter($where, $params, 'i.priority', $scope, 'failure');
    return ['where' => $where, 'params' => $params];
}

function report_dispatch_recorded_failure_count(PDO $pdo, array $scope): int
{
    $parts = report_dispatch_failure_log_where($scope);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM dispatch_attempt_logs dal
        LEFT JOIN incidents i ON i.id = dal.incident_id
        WHERE {$parts['where']}
          AND LOWER(COALESCE(dal.status, 'failed')) = 'failed'
    ");
    $stmt->execute($parts['params']);
    return (int)$stmt->fetchColumn();
}

function report_dispatch_derived_failure_count(PDO $pdo, array $scope, string $kind, int $staleMinutes): int
{
    $schema = ers_report_schema($pdo);
    if (!ers_report_has_table($schema, 'dispatches') || !ers_report_has_table($schema, 'incidents')) {
        return 0;
    }
    $prefix = $kind === 'cancelled_dispatch' ? 'count_cancelled' : 'count_stale';
    $parts = ers_report_dispatch_where($scope, 'd', 'i', $prefix, false);
    $where = $parts['where'];
    $params = $parts['params'];
    if ($kind === 'cancelled_dispatch') {
        $where .= " AND LOWER(COALESCE(d.status, '')) IN ('cancelled','canceled')";
    } else {
        $where .= " AND LOWER(COALESCE(d.status, 'assigned')) = 'assigned'
                    AND d.acknowledged_at IS NULL
                    AND TIMESTAMPDIFF(MINUTE, d.assigned_at, :{$prefix}_now) >= :{$prefix}_threshold";
        $params[":{$prefix}_now"] = ers_report_now()->format('Y-m-d H:i:s');
        $params[":{$prefix}_threshold"] = $staleMinutes;
    }
    $join = ers_report_dispatch_incident_condition($schema, 'd', 'i');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dispatches d INNER JOIN incidents i ON {$join} WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** @return array<int,array<string,mixed>> */
function report_dispatch_recorded_failures(PDO $pdo, array $scope, int $limit = 40): array
{
    $parts = report_dispatch_failure_log_where($scope);
    $stmt = $pdo->prepare("
        SELECT
            dal.id,
            dal.incident_id,
            i.reference_no,
            i.type AS incident_type,
            i.priority,
            i.location_address,
            dal.unit_id,
            COALESCE(NULLIF(dal.unit_identifier, ''), u.identifier, '') AS unit_identifier,
            COALESCE(u.unit_type, '') AS unit_type,
            dal.source,
            dal.failure_reason,
            dal.recovery_status,
            dal.recovery_action,
            dal.recovered_dispatch_id,
            dal.recovered_at,
            dal.created_at AS attempted_at
        FROM dispatch_attempt_logs dal
        LEFT JOIN incidents i ON i.id = dal.incident_id
        LEFT JOIN units u ON u.id = dal.unit_id
        WHERE {$parts['where']}
          AND LOWER(COALESCE(dal.status, 'failed')) = 'failed'
        ORDER BY dal.created_at DESC, dal.id DESC
        LIMIT " . max(1, min(100, $limit))
    );
    $stmt->execute($parts['params']);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recoveryStatus = strtolower(trim((string)($row['recovery_status'] ?? 'open'))) ?: 'open';
        $actions = [];
        if ($recoveryStatus === 'open') {
            if ((int)($row['incident_id'] ?? 0) > 0 && (int)($row['unit_id'] ?? 0) > 0) {
                $actions[] = 'retry_same_unit';
            }
            $actions[] = 'close_failure';
        }
        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'incident_type' => (string)($row['incident_type'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
            'location' => (string)($row['location_address'] ?? ''),
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'unit_identifier' => (string)($row['unit_identifier'] ?? ''),
            'unit_type' => (string)($row['unit_type'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'failure_reason' => (string)($row['failure_reason'] ?? ''),
            'attempted_at' => (string)($row['attempted_at'] ?? ''),
            'failure_kind' => 'recorded_failure',
            'recovery_status' => $recoveryStatus,
            'recovery_action' => (string)($row['recovery_action'] ?? ''),
            'recovered_dispatch_id' => (int)($row['recovered_dispatch_id'] ?? 0),
            'recovered_at' => (string)($row['recovered_at'] ?? ''),
            'recovery_actions' => $actions,
        ];
    }
    return $items;
}

/** @return array<int,array<string,mixed>> */
function report_dispatch_derived_failures(PDO $pdo, array $scope, string $kind, int $staleMinutes, int $limit = 40): array
{
    $schema = ers_report_schema($pdo);
    if (!ers_report_has_table($schema, 'dispatches') || !ers_report_has_table($schema, 'incidents')) {
        return [];
    }
    $prefix = $kind === 'cancelled_dispatch' ? 'cancelled' : 'stale';
    $parts = ers_report_dispatch_where($scope, 'd', 'i', $prefix, false);
    $where = $parts['where'];
    $params = $parts['params'];

    if ($kind === 'cancelled_dispatch') {
        $where .= " AND LOWER(COALESCE(d.status, '')) IN ('cancelled','canceled')";
        $reasonExpression = "'Dispatch was cancelled before completion'";
        $recoveryStatus = 'closed';
        $recoveryActions = [];
    } else {
        $where .= "
            AND LOWER(COALESCE(d.status, 'assigned')) = 'assigned'
            AND d.acknowledged_at IS NULL
            AND TIMESTAMPDIFF(MINUTE, d.assigned_at, :{$prefix}_now) >= :{$prefix}_threshold
        ";
        $params[":{$prefix}_now"] = ers_report_now()->format('Y-m-d H:i:s');
        $params[":{$prefix}_threshold"] = $staleMinutes;
        $reasonExpression = "CONCAT('No acknowledgement after ', :{$prefix}_reason_threshold, ' minutes')";
        $params[":{$prefix}_reason_threshold"] = $staleMinutes;
        $recoveryStatus = 'open';
        $recoveryActions = ['cancel_dispatch'];
    }

    $join = ers_report_dispatch_incident_condition($schema, 'd', 'i');
    $stmt = $pdo->prepare("
        SELECT
            d.id,
            i.id AS incident_id,
            i.reference_no,
            i.type AS incident_type,
            i.priority,
            i.location_address,
            d.unit_id,
            COALESCE(u.identifier, '') AS unit_identifier,
            COALESCE(u.unit_type, '') AS unit_type,
            d.assigned_at AS attempted_at,
            {$reasonExpression} AS failure_reason
        FROM dispatches d
        INNER JOIN incidents i ON {$join}
        LEFT JOIN units u ON u.id = d.unit_id
        WHERE {$where}
        ORDER BY d.assigned_at DESC, d.id DESC
        LIMIT " . max(1, min(100, $limit))
    );
    $stmt->execute($params);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'incident_type' => (string)($row['incident_type'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
            'location' => (string)($row['location_address'] ?? ''),
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'unit_identifier' => (string)($row['unit_identifier'] ?? ''),
            'unit_type' => (string)($row['unit_type'] ?? ''),
            'source' => 'dispatches',
            'failure_reason' => (string)($row['failure_reason'] ?? ''),
            'attempted_at' => (string)($row['attempted_at'] ?? ''),
            'failure_kind' => $kind,
            'recovery_status' => $recoveryStatus,
            'recovery_action' => '',
            'recovered_dispatch_id' => 0,
            'recovered_at' => '',
            'recovery_actions' => $recoveryActions,
        ];
    }
    return $items;
}

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }

    ers_dispatch_attempt_ensure_table($pdo);
    $scope = ers_report_scope($_GET);
    $summary = ers_report_fetch_dispatch_summary($pdo, $scope);
    $staleMinutes = 15;

    $recorded = report_dispatch_recorded_failures($pdo, $scope, 50);
    $cancelled = report_dispatch_derived_failures($pdo, $scope, 'cancelled_dispatch', $staleMinutes, 50);
    $stale = report_dispatch_derived_failures($pdo, $scope, 'stale_unacknowledged', $staleMinutes, 50);

    $failedAttempts = array_merge($recorded, $cancelled, $stale);
    usort($failedAttempts, static function (array $left, array $right): int {
        return strcmp((string)($right['attempted_at'] ?? ''), (string)($left['attempted_at'] ?? ''));
    });
    $failedAttempts = array_slice($failedAttempts, 0, 50);

    $recordedCount = report_dispatch_recorded_failure_count($pdo, $scope);
    $cancelledCount = report_dispatch_derived_failure_count($pdo, $scope, 'cancelled_dispatch', $staleMinutes);
    $staleCount = report_dispatch_derived_failure_count($pdo, $scope, 'stale_unacknowledged', $staleMinutes);
    $failureSignalTotal = $recordedCount + $cancelledCount + $staleCount;
    $totalDispatches = (int)($summary['metrics']['total_dispatches'] ?? 0);
    $recordedAttemptDenominator = $totalDispatches + $recordedCount;

    $summary['metrics']['recorded_failed_attempts'] = $recordedCount;
    $summary['metrics']['cancelled_dispatches'] = $cancelledCount;
    $summary['metrics']['stale_unacknowledged_dispatches'] = $staleCount;
    $summary['metrics']['failure_signals_total'] = $failureSignalTotal;
    $summary['metrics']['failed_attempts_total'] = $failureSignalTotal; // Backward-compatible alias.
    $summary['metrics']['recorded_attempt_failure_rate'] = $recordedAttemptDenominator > 0
        ? round(($recordedCount / $recordedAttemptDenominator) * 100, 1)
        : null;
    $summary['metrics']['failed_attempt_rate'] = $summary['metrics']['recorded_attempt_failure_rate'];
    $summary['metrics']['stale_threshold_min'] = $staleMinutes;

    echo json_encode([
        'ok' => true,
        'meta' => array_merge(ers_report_public_scope($scope), [
            'definitions' => [
                'dispatch_volume' => 'Dispatches assigned within the selected date range.',
                'response_time' => 'Assignment to recorded on-scene arrival only.',
                'failure_signals' => 'Recorded failures, cancelled dispatches, and currently stale unacknowledged assignments. Components may describe different failure stages and are not presented as unique incidents.',
                'unit_snapshot' => 'Current live unit status; it is not a historical reconstruction.',
            ],
        ]),
        'metrics' => $summary['metrics'],
        'summary_by_service' => $summary['summary_by_service'],
        'top_units' => $summary['top_units'],
        'all_units' => $summary['all_units'],
        'failed_attempts' => $failedAttempts,
        'daily' => $summary['daily'],
        'unit_snapshot' => $summary['unit_snapshot'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('reports_dispatch.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to calculate dispatch analytics.']);
}
