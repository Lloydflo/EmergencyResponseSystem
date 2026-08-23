<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = get_logged_in_user() ?? [];
$role = canonical_role((string)($user['role'] ?? ''));
if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/../includes/db.php';

/** @return array<string,array<string,true>> */
function ers_admin_dashboard_schema(PDO $pdo): array
{
    $tables = [
        'incidents',
        'users',
        'agencies',
        'units',
        'resource_records',
        'admin_resources',
        'resources',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));

    try {
        $statement = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})"
        );
        $statement->execute($tables);
    } catch (Throwable $e) {
        error_log('admin dashboard schema lookup failed: ' . $e->getMessage());
        return [];
    }

    $schema = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $schema[$table][$column] = true;
        }
    }
    return $schema;
}

/** @param array<string,array<string,true>> $schema */
function ers_admin_dashboard_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]);
}

/** @param array<string,array<string,true>> $schema */
function ers_admin_dashboard_has_column(array $schema, string $table, string $column): bool
{
    return isset($schema[$table][$column]);
}

function ers_admin_dashboard_scalar(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int)$statement->fetchColumn();
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection unavailable']);
    exit;
}

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {
    // Continue when the database account cannot change the session timezone.
}

$schema = ers_admin_dashboard_schema($pdo);
if (!ers_admin_dashboard_has_table($schema, 'incidents')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Incidents table unavailable']);
    exit;
}

$timezone = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('now', $timezone);
$scopeStart = $now->modify('first day of this month')->setTime(0, 0, 0);
$scopeEndExclusive = $now->modify('+1 day')->setTime(0, 0, 0);

try {
    $hasIncidentCreatedAt = ers_admin_dashboard_has_column($schema, 'incidents', 'created_at');
    $hasIncidentStatus = ers_admin_dashboard_has_column($schema, 'incidents', 'status');
    $hasIncidentType = ers_admin_dashboard_has_column($schema, 'incidents', 'type');
    $hasIncidentPriority = ers_admin_dashboard_has_column($schema, 'incidents', 'priority');

    $monthlyIncidents = 0;
    if ($hasIncidentCreatedAt) {
        $monthlyIncidents = ers_admin_dashboard_scalar(
            $pdo,
            'SELECT COUNT(*) FROM incidents WHERE created_at >= :scope_start AND created_at < :scope_end',
            [
                ':scope_start' => $scopeStart->format('Y-m-d H:i:s'),
                ':scope_end' => $scopeEndExclusive->format('Y-m-d H:i:s'),
            ]
        );
    }

    $openIncidents = 0;
    if ($hasIncidentStatus) {
        $openIncidents = ers_admin_dashboard_scalar(
            $pdo,
            "SELECT COUNT(*)
             FROM incidents
             WHERE TRIM(COALESCE(status, '')) <> ''
               AND LOWER(TRIM(status)) NOT IN (
                   'resolved', 'completed', 'closed',
                   'cancelled', 'canceled', 'rejected', 'invalid', 'duplicate'
               )"
        );
    }

    $activeAccounts = 0;
    if (
        ers_admin_dashboard_has_table($schema, 'users')
        && ers_admin_dashboard_has_column($schema, 'users', 'status')
    ) {
        $activeAccounts = ers_admin_dashboard_scalar(
            $pdo,
            "SELECT COUNT(*) FROM users WHERE LOWER(COALESCE(status, '')) = 'active'"
        );
    }

    $partnerAgencies = 0;
    if (ers_admin_dashboard_has_table($schema, 'agencies')) {
        if (ers_admin_dashboard_has_column($schema, 'agencies', 'status')) {
            $partnerAgencies = ers_admin_dashboard_scalar(
                $pdo,
                "SELECT COUNT(*) FROM agencies WHERE LOWER(COALESCE(status, '')) = 'active'"
            );
        } else {
            $partnerAgencies = ers_admin_dashboard_scalar($pdo, 'SELECT COUNT(*) FROM agencies');
        }
    }

    $registeredUnits = 0;
    $resourceSource = 'Unavailable';
    foreach (['units', 'resource_records', 'admin_resources', 'resources'] as $table) {
        if (ers_admin_dashboard_has_table($schema, $table)) {
            $registeredUnits = ers_admin_dashboard_scalar($pdo, "SELECT COUNT(*) FROM `{$table}`");
            $resourceSource = $table;
            break;
        }
    }

    $typeCounts = [
        'medical' => 0,
        'fire' => 0,
        'police' => 0,
        'traffic' => 0,
        'other' => 0,
    ];
    if ($hasIncidentCreatedAt && $hasIncidentType) {
        $statement = $pdo->prepare(
            "SELECT
                CASE
                    WHEN LOWER(COALESCE(type, '')) REGEXP 'medical|ambulance|health' THEN 'medical'
                    WHEN LOWER(COALESCE(type, '')) REGEXP 'fire|rescue' THEN 'fire'
                    WHEN LOWER(COALESCE(type, '')) REGEXP 'police|crime|security' THEN 'police'
                    WHEN LOWER(COALESCE(type, '')) REGEXP 'traffic|accident|vehicle|road' THEN 'traffic'
                    ELSE 'other'
                END AS bucket,
                COUNT(*) AS total
             FROM incidents
             WHERE created_at >= :scope_start AND created_at < :scope_end
             GROUP BY bucket"
        );
        $statement->execute([
            ':scope_start' => $scopeStart->format('Y-m-d H:i:s'),
            ':scope_end' => $scopeEndExclusive->format('Y-m-d H:i:s'),
        ]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bucket = (string)($row['bucket'] ?? 'other');
            if (isset($typeCounts[$bucket])) {
                $typeCounts[$bucket] = (int)($row['total'] ?? 0);
            }
        }
    }

    $priorityCounts = [
        'critical' => 0,
        'high' => 0,
        'medium' => 0,
        'low' => 0,
        'other' => 0,
    ];
    if ($hasIncidentCreatedAt && $hasIncidentPriority) {
        $statement = $pdo->prepare(
            "SELECT
                CASE
                    WHEN LOWER(COALESCE(priority, '')) IN ('critical', 'emergency') THEN 'critical'
                    WHEN LOWER(COALESCE(priority, '')) IN ('high', 'urgent') THEN 'high'
                    WHEN LOWER(COALESCE(priority, '')) IN ('medium', 'moderate', 'normal') THEN 'medium'
                    WHEN LOWER(COALESCE(priority, '')) = 'low' THEN 'low'
                    ELSE 'other'
                END AS bucket,
                COUNT(*) AS total
             FROM incidents
             WHERE created_at >= :scope_start AND created_at < :scope_end
             GROUP BY bucket"
        );
        $statement->execute([
            ':scope_start' => $scopeStart->format('Y-m-d H:i:s'),
            ':scope_end' => $scopeEndExclusive->format('Y-m-d H:i:s'),
        ]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bucket = (string)($row['bucket'] ?? 'other');
            if (isset($priorityCounts[$bucket])) {
                $priorityCounts[$bucket] = (int)($row['total'] ?? 0);
            }
        }
    }

    $typeTotal = array_sum($typeCounts);
    $priorityTotal = array_sum($priorityCounts);

    echo json_encode(
        [
            'ok' => true,
            'metrics' => [
                'open_incidents' => $openIncidents,
                'active_accounts' => $activeAccounts,
                // Legacy key retained for existing clients.
                'active_users' => $activeAccounts,
                'partner_agencies' => $partnerAgencies,
                'registered_units' => $registeredUnits,
                // Legacy key retained for existing clients.
                'resource_records' => $registeredUnits,
                'monthly_incidents' => $monthlyIncidents,
            ],
            'charts' => [
                'incidents_by_type' => $typeCounts,
                'incidents_by_priority' => $priorityCounts,
                'type_total' => $typeTotal,
                'priority_total' => $priorityTotal,
            ],
            'scope' => [
                'key' => 'month_to_date',
                'label' => $scopeStart->format('F Y') . ' month to date',
                'start' => $scopeStart->format(DateTimeInterface::ATOM),
                'end' => $now->format(DateTimeInterface::ATOM),
                'timezone' => 'Asia/Manila',
            ],
            'resource_source' => $resourceSource,
            'generated_at' => $now->format(DateTimeInterface::ATOM),
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('admin dashboard summary query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Dashboard query failed']);
}
