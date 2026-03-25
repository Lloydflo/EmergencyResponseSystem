<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

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

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

try {
    $openIncidents = (int)($pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('pending','dispatched')")->fetch()['c'] ?? 0);
    $monthlyIncidents = (int)($pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch()['c'] ?? 0);

    $activeUsers = 0;
    if (ers_table_exists($pdo, 'users')) {
        $activeUsers = (int)($pdo->query("SELECT COUNT(*) AS c FROM users WHERE status = 'active'")->fetch()['c'] ?? 0);
    }

    $partnerAgencies = 0;
    if (ers_table_exists($pdo, 'agencies')) {
        $partnerAgencies = (int)($pdo->query("SELECT COUNT(*) AS c FROM agencies WHERE status = 'active'")->fetch()['c'] ?? 0);
    }

    $resourceRecords = 0;
    if (ers_table_exists($pdo, 'resource_records')) {
        $resourceRecords = (int)($pdo->query("SELECT COUNT(*) AS c FROM resource_records")->fetch()['c'] ?? 0);
    } elseif (ers_table_exists($pdo, 'admin_resources')) {
        $resourceRecords = (int)($pdo->query("SELECT COUNT(*) AS c FROM admin_resources")->fetch()['c'] ?? 0);
    } elseif (ers_table_exists($pdo, 'resources')) {
        $resourceRecords = (int)($pdo->query("SELECT COUNT(*) AS c FROM resources")->fetch()['c'] ?? 0);
    }

    $typesCounts = ['medical' => 0, 'fire' => 0, 'police' => 0, 'traffic' => 0];
    $typeStmt = $pdo->query("SELECT LOWER(type) AS type_name, COUNT(*) AS c FROM incidents GROUP BY LOWER(type)");
    foreach (($typeStmt ? $typeStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $key = (string)($row['type_name'] ?? '');
        if ($key === 'crime') {
            $key = 'police';
        } elseif ($key === 'accident') {
            $key = 'traffic';
        }
        if (isset($typesCounts[$key])) {
            $typesCounts[$key] += (int)($row['c'] ?? 0);
        }
    }

    $priorityCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
    $priorityStmt = $pdo->query("SELECT LOWER(priority) AS priority_name, COUNT(*) AS c FROM incidents GROUP BY LOWER(priority)");
    foreach (($priorityStmt ? $priorityStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $key = (string)($row['priority_name'] ?? '');
        if ($key === 'critical') {
            $key = 'high';
        }
        if (isset($priorityCounts[$key])) {
            $priorityCounts[$key] += (int)($row['c'] ?? 0);
        }
    }

    echo json_encode([
        'ok' => true,
        'metrics' => [
            'open_incidents' => $openIncidents,
            'active_users' => $activeUsers,
            'partner_agencies' => $partnerAgencies,
            'resource_records' => $resourceRecords,
            'monthly_incidents' => $monthlyIncidents,
        ],
        'charts' => [
            'incidents_by_type' => $typesCounts,
            'incidents_by_priority' => $priorityCounts,
        ],
        'generated_at' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
