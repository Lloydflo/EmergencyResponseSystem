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
$requireApiRoles(['admin']);
unset($requireApiRoles);

require_once __DIR__ . '/../includes/db.php';

/** @return array<string,array<string,true>> */
function ers_admin_summary_schema(PDO $pdo): array
{
    $tables = [
        'incidents',
        'users',
        'agencies',
        'resource_records',
        'admin_resources',
        'resources',
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
        error_log('admin_dashboard_summary schema lookup failed: ' . $e->getMessage());
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
function ers_admin_summary_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]);
}

/** @param array<string,array<string,true>> $schema */
function ers_admin_summary_has_column(array $schema, string $table, string $column): bool
{
    return isset($schema[$table][$column]);
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$schema = ers_admin_summary_schema($pdo);
if (!ers_admin_summary_has_table($schema, 'incidents')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Incidents table unavailable']);
    exit;
}

try {
    $openIncidents = 0;
    $monthlyIncidents = 0;
    $hasIncidentStatus = ers_admin_summary_has_column($schema, 'incidents', 'status');
    $hasIncidentCreatedAt = ers_admin_summary_has_column($schema, 'incidents', 'created_at');
    if ($hasIncidentStatus || $hasIncidentCreatedAt) {
        $openExpr = $hasIncidentStatus
            ? "COALESCE(SUM(status IN ('pending', 'dispatched')), 0)"
            : '0';
        // A range predicate can use the created_at index; YEAR()/MONTH() cannot.
        $monthlyExpr = $hasIncidentCreatedAt
            ? "COALESCE(SUM(
                created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                AND created_at < LAST_DAY(CURDATE()) + INTERVAL 1 DAY
              ), 0)"
            : '0';
        $metricStmt = $pdo->query(
            "SELECT
                {$openExpr} AS open_incidents,
                {$monthlyExpr} AS monthly_incidents
             FROM incidents"
        );
        $metricRow = $metricStmt ? ($metricStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $openIncidents = (int)($metricRow['open_incidents'] ?? 0);
        $monthlyIncidents = (int)($metricRow['monthly_incidents'] ?? 0);
    }

    $activeUsers = 0;
    if (
        ers_admin_summary_has_table($schema, 'users')
        && ers_admin_summary_has_column($schema, 'users', 'status')
    ) {
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE status = 'active'");
        $activeUsers = (int)(($stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [])['c'] ?? 0);
    }

    $partnerAgencies = 0;
    if (
        ers_admin_summary_has_table($schema, 'agencies')
        && ers_admin_summary_has_column($schema, 'agencies', 'status')
    ) {
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM agencies WHERE status = 'active'");
        $partnerAgencies = (int)(($stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [])['c'] ?? 0);
    }

    $resourceRecords = 0;
    foreach (['resource_records', 'admin_resources', 'resources'] as $table) {
        if (ers_admin_summary_has_table($schema, $table)) {
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `{$table}`");
            $resourceRecords = (int)(($stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [])['c'] ?? 0);
            break;
        }
    }

    $typeCounts = ['medical' => 0, 'fire' => 0, 'police' => 0, 'traffic' => 0];
    if (ers_admin_summary_has_column($schema, 'incidents', 'type')) {
        $typeStmt = $pdo->query(
            "SELECT LOWER(type) AS type_name, COUNT(*) AS c
             FROM incidents
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

    $priorityCounts = [
        'critical' => 0,
        'high' => 0,
        'urgent' => 0,
        'moderate' => 0,
        'medium' => 0,
        'low' => 0,
    ];
    if (ers_admin_summary_has_column($schema, 'incidents', 'priority')) {
        $priorityStmt = $pdo->query(
            "SELECT LOWER(priority) AS priority_name, COUNT(*) AS c
             FROM incidents
             GROUP BY LOWER(priority)"
        );
        foreach (($priorityStmt ? $priorityStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $key = (string)($row['priority_name'] ?? '');
            if ($key === 'medium') {
                $key = 'moderate';
            }
            if (isset($priorityCounts[$key])) {
                $priorityCounts[$key] += (int)($row['c'] ?? 0);
            }
        }
    }
    // Retain the original response contract: medium mirrors moderate.
    $priorityCounts['medium'] = $priorityCounts['moderate'];

    echo json_encode(
        [
            'ok' => true,
            'metrics' => [
                'open_incidents' => $openIncidents,
                'active_users' => $activeUsers,
                'partner_agencies' => $partnerAgencies,
                'resource_records' => $resourceRecords,
                'monthly_incidents' => $monthlyIncidents,
            ],
            'charts' => [
                'incidents_by_type' => $typeCounts,
                'incidents_by_priority' => $priorityCounts,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('admin_dashboard_summary query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
