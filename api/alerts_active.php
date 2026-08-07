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
if (canonical_role((string)($user['role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/dashboard_weather.php';

/** @return array<string,array<string,true>> */
function ers_dashboard_alert_schema(PDO $pdo): array
{
    $tables = [
        'incidents',
        'dispatches',
        'units',
        'shared_resources',
        'resource_records',
        'admin_resources',
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
function ers_dashboard_alert_has(array $schema, string $table, ?string $column = null): bool
{
    return $column === null ? isset($schema[$table]) : isset($schema[$table][$column]);
}

/** @param list<array<string,mixed>> $alerts */
function ers_dashboard_alert_add(array &$alerts, string $type, string $title, string $details, array $extra = []): void
{
    $alerts[] = array_merge([
        'type' => $type,
        'title' => $title,
        'details' => $details,
    ], $extra);
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

$all = isset($_GET['all']) && (string)$_GET['all'] === '1';
$alerts = [];
$schema = ers_dashboard_alert_schema($pdo);

try {
    // Operational response delay: assigned time to actual on-scene time.
    if (
        ers_dashboard_alert_has($schema, 'dispatches', 'assigned_at')
        && ers_dashboard_alert_has($schema, 'dispatches', 'on_scene_at')
    ) {
        if ($all) {
            $select = ['id', 'incident_id', 'assigned_at', 'on_scene_at'];
            if (ers_dashboard_alert_has($schema, 'dispatches', 'unit_id')) {
                $select[] = 'unit_id';
            }
            $statement = $pdo->query(
                'SELECT ' . implode(', ', $select) . ",
                    TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at) AS response_minutes
                 FROM dispatches
                 WHERE assigned_at >= NOW() - INTERVAL 1 HOUR
                   AND on_scene_at IS NOT NULL
                   AND on_scene_at >= assigned_at
                   AND TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at) > 10
                 ORDER BY assigned_at DESC
                 LIMIT 50"
            );
            foreach (($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                ers_dashboard_alert_add(
                    $alerts,
                    'critical',
                    'Response SLA Breach',
                    'Incident #' . (int)($row['incident_id'] ?? 0)
                        . ' reached on scene after ' . (int)($row['response_minutes'] ?? 0) . ' minutes.',
                    [
                        'dispatch_id' => (int)($row['id'] ?? 0),
                        'incident_id' => (int)($row['incident_id'] ?? 0),
                        'created_at' => (string)($row['assigned_at'] ?? date(DateTimeInterface::ATOM)),
                    ]
                );
            }
        } else {
            $statement = $pdo->query(
                "SELECT
                    COUNT(*) AS breach_count,
                    AVG(TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at)) AS avg_minutes
                 FROM dispatches
                 WHERE assigned_at >= NOW() - INTERVAL 1 HOUR
                   AND on_scene_at IS NOT NULL
                   AND on_scene_at >= assigned_at
                   AND TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at) > 10"
            );
            $row = $statement ? ($statement->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            $breachCount = (int)($row['breach_count'] ?? 0);
            if ($breachCount > 0) {
                ers_dashboard_alert_add(
                    $alerts,
                    'critical',
                    'Response SLA Breach',
                    $breachCount . ' dispatch' . ($breachCount === 1 ? '' : 'es')
                        . ' exceeded 10 minutes to on-scene during the last hour.'
                );
            }
        }
    }

    // Current ambulance utilization: in-use / operational ambulances.
    if (
        ers_dashboard_alert_has($schema, 'units', 'unit_type')
        && ers_dashboard_alert_has($schema, 'units', 'status')
    ) {
        $identifierColumn = ers_dashboard_alert_has($schema, 'units', 'identifier')
            ? 'identifier'
            : (ers_dashboard_alert_has($schema, 'units', 'name') ? 'name' : 'id');
        $statement = $pdo->query(
            "SELECT id, `{$identifierColumn}` AS unit_name, LOWER(COALESCE(status, '')) AS unit_status
             FROM units
             WHERE LOWER(COALESCE(unit_type, '')) IN ('ambulance', 'medical')"
        );
        $ambulances = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        $operationalStatuses = ['available', 'assigned', 'acknowledged', 'enroute', 'on_scene'];
        $inUseStatuses = ['assigned', 'acknowledged', 'enroute', 'on_scene'];
        $operational = array_values(array_filter(
            $ambulances,
            static fn(array $unit): bool => in_array((string)($unit['unit_status'] ?? ''), $operationalStatuses, true)
        ));
        $inUse = array_values(array_filter(
            $operational,
            static fn(array $unit): bool => in_array((string)($unit['unit_status'] ?? ''), $inUseStatuses, true)
        ));
        $operationalCount = count($operational);
        $utilization = $operationalCount > 0 ? count($inUse) / $operationalCount : 0.0;

        if ($operationalCount > 0 && $utilization >= 0.8) {
            if ($all) {
                foreach ($inUse as $unit) {
                    ers_dashboard_alert_add(
                        $alerts,
                        'warning',
                        'Ambulance In Use',
                        trim((string)($unit['unit_name'] ?? 'Ambulance'))
                            . ' is currently ' . str_replace('_', ' ', (string)($unit['unit_status'] ?? 'in use')) . '.',
                        ['unit_id' => (int)($unit['id'] ?? 0)]
                    );
                }
            } else {
                ers_dashboard_alert_add(
                    $alerts,
                    'warning',
                    'Ambulance Capacity',
                    count($inUse) . ' of ' . $operationalCount
                        . ' operational ambulance units are currently in use ('
                        . (int)round($utilization * 100) . '%).'
                );
            }
        }
    }

    // Inventory alerts only when the deployed schema contains all required fields.
    if (
        ers_dashboard_alert_has($schema, 'shared_resources', 'quantity_total')
        && ers_dashboard_alert_has($schema, 'shared_resources', 'quantity_available')
        && ers_dashboard_alert_has($schema, 'shared_resources', 'name')
    ) {
        $statusExpression = ers_dashboard_alert_has($schema, 'shared_resources', 'status')
            ? "LOWER(COALESCE(status, ''))"
            : "''";
        $statement = $pdo->query(
            "SELECT id, name, quantity_total, quantity_available, {$statusExpression} AS resource_status
             FROM shared_resources
             WHERE quantity_total > 0
               AND (
                   quantity_available <= 0
                   OR quantity_available <= GREATEST(1, FLOOR(quantity_total * 0.2))
                   OR {$statusExpression} IN ('unavailable', 'maintenance')
               )
             ORDER BY quantity_available ASC
             LIMIT 50"
        );
        $lowStock = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($lowStock !== []) {
            if ($all) {
                foreach ($lowStock as $row) {
                    $available = (int)($row['quantity_available'] ?? 0);
                    $total = (int)($row['quantity_total'] ?? 0);
                    ers_dashboard_alert_add(
                        $alerts,
                        $available <= 0 ? 'critical' : 'warning',
                        'Low Resource Stock',
                        trim((string)($row['name'] ?? 'Resource'))
                            . ' has ' . $available . ' of ' . $total . ' available.',
                        ['resource_id' => (int)($row['id'] ?? 0)]
                    );
                }
            } else {
                $criticalCount = count(array_filter(
                    $lowStock,
                    static fn(array $row): bool => (int)($row['quantity_available'] ?? 0) <= 0
                ));
                ers_dashboard_alert_add(
                    $alerts,
                    $criticalCount > 0 ? 'critical' : 'warning',
                    'Low Resource Stock',
                    count($lowStock) . ' inventory item' . (count($lowStock) === 1 ? '' : 's')
                        . ' require replenishment.'
                );
            }
        }
    }

    // Equipment availability alert for deployments using resource records.
    $equipmentTable = ers_dashboard_alert_has($schema, 'resource_records')
        ? 'resource_records'
        : (ers_dashboard_alert_has($schema, 'admin_resources') ? 'admin_resources' : null);
    if (
        $equipmentTable !== null
        && ers_dashboard_alert_has($schema, $equipmentTable, 'category')
        && ers_dashboard_alert_has($schema, $equipmentTable, 'status')
    ) {
        $statement = $pdo->query(
            "SELECT
                COUNT(*) AS total_equipment,
                SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'available' THEN 1 ELSE 0 END) AS available_equipment
             FROM `{$equipmentTable}`
             WHERE LOWER(COALESCE(category, '')) = 'equipment'"
        );
        $summary = $statement ? ($statement->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $totalEquipment = (int)($summary['total_equipment'] ?? 0);
        $availableEquipment = (int)($summary['available_equipment'] ?? 0);
        $threshold = $totalEquipment > 0 ? max(1, (int)floor($totalEquipment * 0.2)) : 0;

        if ($totalEquipment > 0 && $availableEquipment <= $threshold) {
            ers_dashboard_alert_add(
                $alerts,
                $availableEquipment === 0 ? 'critical' : 'warning',
                'Low Equipment Availability',
                'Only ' . $availableEquipment . ' of ' . $totalEquipment . ' equipment records are currently available.'
            );
        }
    }

    // Weather alert uses the same cached exact-coordinate data shown in the widget.
    $weather = ers_dashboard_weather_get(false);
    if (($weather['ok'] ?? false) && is_array($weather['observation'] ?? null)) {
        $observation = $weather['observation'];
        $severity = (string)($observation['severity'] ?? 'normal');
        $rainProbability = (int)($observation['rain_probability_pct'] ?? 0);
        $precipitation = (float)($observation['precipitation_mm'] ?? 0.0);
        $alertType = null;
        if ($severity === 'critical') {
            $alertType = 'critical';
        } elseif ($severity === 'warning' || $rainProbability >= 70) {
            $alertType = 'warning';
        } elseif ($severity === 'advisory' && ($rainProbability >= 50 || $precipitation > 0.0)) {
            $alertType = 'info';
        }

        if ($alertType !== null) {
            ers_dashboard_alert_add(
                $alerts,
                $alertType,
                'Quezon City Weather Advisory',
                (string)($observation['condition'] ?? 'Weather advisory')
                    . '; rain probability ' . $rainProbability . '%.',
                [
                    'weather_code' => (int)($observation['weather_code'] ?? 0),
                    'created_at' => (string)($observation['time'] ?? date(DateTimeInterface::ATOM)),
                ]
            );
        }
    }

    $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($alerts, static function (array $left, array $right) use ($rank): int {
        $severityCompare = ($rank[(string)($left['type'] ?? 'info')] ?? 9)
            <=> ($rank[(string)($right['type'] ?? 'info')] ?? 9);
        if ($severityCompare !== 0) {
            return $severityCompare;
        }
        return strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
    });

    if (!$all) {
        $alerts = array_slice($alerts, 0, 6);
    }

    echo json_encode(
        [
            'ok' => true,
            'data' => $alerts,
            'generated_at' => date(DateTimeInterface::ATOM),
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
} catch (Throwable $e) {
    error_log('active alerts query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Alerts query failed']);
}
