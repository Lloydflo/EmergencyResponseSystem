<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/incident_admin_review.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function ers_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_column_exists(PDO $pdo, string $table, string $column): bool
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
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function resolve_incident_range(): array
{
    $start = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
    $end = isset($_GET['end']) ? trim((string)$_GET['end']) : '';
    if ($start !== '' && $end !== '') {
        return [$start . ' 00:00:00', $end . ' 23:59:59'];
    }

    $day = isset($_GET['day']) ? trim((string)$_GET['day']) : '';
    if ($day !== '') {
        return [$day . ' 00:00:00', $day . ' 23:59:59'];
    }

    $month = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
    if ($month !== '') {
        try {
            $monthStart = new DateTime($month . '-01');
            $monthEnd = (clone $monthStart)->modify('+1 month -1 day');
            return [$monthStart->format('Y-m-d') . ' 00:00:00', $monthEnd->format('Y-m-d') . ' 23:59:59'];
        } catch (Throwable $e) {
            return [null, null];
        }
    }

    $period = isset($_GET['period']) ? strtolower(trim((string)$_GET['period'])) : '';
    if ($period === '') {
        return [null, null];
    }

    $today = new DateTime('today');
    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d') . ' 00:00:00', $today->format('Y-m-d') . ' 23:59:59'];
        case 'week':
            $rangeStart = (clone $today)->modify('monday this week');
            $rangeEnd = (clone $rangeStart)->modify('+6 days');
            break;
        case 'quarter':
            $monthNumber = (int)$today->format('n');
            $quarterStartMonth = [1 => 1, 2 => 4, 3 => 7, 4 => 10][intdiv($monthNumber - 1, 3) + 1];
            $rangeStart = new DateTime($today->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $rangeEnd = (clone $rangeStart)->modify('+3 months -1 day');
            break;
        case 'year':
            $rangeStart = new DateTime($today->format('Y-01-01'));
            $rangeEnd = new DateTime($today->format('Y-12-31'));
            break;
        case 'month':
        default:
            $rangeStart = new DateTime($today->format('Y-m-01'));
            $rangeEnd = (clone $rangeStart)->modify('+1 month -1 day');
            break;
    }

    return [$rangeStart->format('Y-m-d') . ' 00:00:00', $rangeEnd->format('Y-m-d') . ' 23:59:59'];
}

function normalized_type_values(string $typeFilter): array
{
    $typeFilter = strtolower(trim($typeFilter));
    if ($typeFilter === '') {
        return [];
    }
    if ($typeFilter === 'traffic' || $typeFilter === 'accident') {
        return ['traffic', 'accident'];
    }
    if ($typeFilter === 'police' || $typeFilter === 'crime') {
        return ['police', 'crime'];
    }
    return [$typeFilter];
}

function append_type_filter(array &$where, array &$params, string $column, array $typeValues, string $prefix): void
{
    if (!$typeValues) {
        return;
    }
    $clauses = [];
    foreach ($typeValues as $index => $value) {
        $exactPlaceholder = ':' . $prefix . '_type_' . $index;
        $startPlaceholder = ':' . $prefix . '_type_start_' . $index;
        $endPlaceholder = ':' . $prefix . '_type_end_' . $index;
        $middlePlaceholder = ':' . $prefix . '_type_middle_' . $index;
        $params[$exactPlaceholder] = $value;
        $params[$startPlaceholder] = $value . ',%';
        $params[$endPlaceholder] = '%, ' . $value;
        $params[$middlePlaceholder] = '%, ' . $value . ',%';
        $clauses[] = '(LOWER(' . $column . ') = ' . $exactPlaceholder
            . ' OR LOWER(' . $column . ') LIKE ' . $startPlaceholder
            . ' OR LOWER(' . $column . ') LIKE ' . $endPlaceholder
            . ' OR LOWER(' . $column . ') LIKE ' . $middlePlaceholder . ')';
    }
    $where[] = '(' . implode(' OR ', $clauses) . ')';
}

$priority = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$adminReview = isset($_GET['admin_review']) ? strtolower(trim((string)$_GET['admin_review'])) : '';
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$typeValues = normalized_type_values($type);
[$rangeStart, $rangeEnd] = resolve_incident_range();

$resourceRecordsTable = null;
if (ers_table_exists($pdo, 'resource_records')) {
    $resourceRecordsTable = 'resource_records';
} elseif (ers_table_exists($pdo, 'admin_resources')) {
    $resourceRecordsTable = 'admin_resources';
}
$hasIncidentNotes = ers_table_exists($pdo, 'incident_notes');
$hasRatingColumn = $hasIncidentNotes && ers_column_exists($pdo, 'incident_notes', 'rating');
$hasAdminReviewTable = ers_ensure_incident_admin_reviews($pdo);

$resourceSelect = ', NULL AS vehicle_name, NULL AS driver_name, NULL AS plate_number';
$resourceJoin = '';
if ($resourceRecordsTable !== null) {
    $resourceSelect = ', ar.name AS vehicle_name, ar.driver_name AS driver_name, ar.plate_number AS plate_number';
    $resourceJoin = ' LEFT JOIN `' . $resourceRecordsTable . '` ar ON ar.code = u.identifier ';
}

$feedbackSelect = ', 0 AS feedback_count, NULL AS avg_rating, 0 AS rating_count';
if ($hasIncidentNotes && $hasRatingColumn) {
    $feedbackSelect = ',
        (SELECT COUNT(*) FROM incident_notes n WHERE n.incident_id = i.id AND n.note NOT LIKE \'Resolution proof uploaded:%\') AS feedback_count,
        (SELECT ROUND(AVG(n.rating), 1) FROM incident_notes n WHERE n.incident_id = i.id AND n.rating IS NOT NULL AND n.note NOT LIKE \'Resolution proof uploaded:%\') AS avg_rating,
        (SELECT COUNT(*) FROM incident_notes n WHERE n.incident_id = i.id AND n.rating IS NOT NULL AND n.note NOT LIKE \'Resolution proof uploaded:%\') AS rating_count';
} elseif ($hasIncidentNotes) {
    $feedbackSelect = ',
        (SELECT COUNT(*) FROM incident_notes n WHERE n.incident_id = i.id AND n.note NOT LIKE \'Resolution proof uploaded:%\') AS feedback_count,
        NULL AS avg_rating,
        0 AS rating_count';
}

$adminReviewSelect = ',
            NULL AS admin_review_sent_at,
            NULL AS admin_review_sent_by_name,
            NULL AS admin_review_sent_by_user_id';
$adminReviewJoin = '';
if ($hasAdminReviewTable) {
    $adminReviewSelect = ',
            iar.sent_at AS admin_review_sent_at,
            iar.sent_by_name AS admin_review_sent_by_name,
            iar.sent_by_user_id AS admin_review_sent_by_user_id';
    $adminReviewJoin = ' LEFT JOIN incident_admin_reviews iar ON iar.incident_id = i.id ';
}

$sql = "SELECT
            i.id,
            i.reference_no,
            i.type,
            i.priority,
            i.status,
            i.location_address,
            i.description,
            i.created_at,
            i.updated_at,
            i.resolved_at,
            COALESCE(i.latitude, c.latitude) AS latitude,
            COALESCE(i.longitude, c.longitude) AS longitude,
            i.title,
            c.caller_name,
            c.caller_phone,
            ld.assigned_at,
            ld.acknowledged_at,
            ld.enroute_at,
            ld.on_scene_at,
            ld.cleared_at,
            ld.status AS latest_dispatch_status,
            u.identifier AS unit_identifier,
            u.unit_type AS unit_type
            {$resourceSelect}
            {$feedbackSelect}
            {$adminReviewSelect},
            CASE
                WHEN ld.assigned_at IS NOT NULL AND ld.on_scene_at IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, ld.assigned_at, ld.on_scene_at)
                ELSE NULL
            END AS response_time_min,
            CASE
                WHEN ld.assigned_at IS NOT NULL AND COALESCE(i.resolved_at, ld.cleared_at) IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, ld.assigned_at, COALESCE(i.resolved_at, ld.cleared_at))
                ELSE NULL
            END AS resolution_time_min
        FROM incidents i
        LEFT JOIN (
            SELECT d1.id, d1.incident_id, d1.unit_id, d1.status, d1.assigned_at, d1.acknowledged_at, d1.enroute_at, d1.on_scene_at, d1.cleared_at
            FROM dispatches d1
            INNER JOIN (
                SELECT incident_id, MAX(id) AS max_id
                FROM dispatches
                GROUP BY incident_id
            ) latest ON latest.max_id = d1.id
        ) ld ON ld.incident_id = i.id
        LEFT JOIN units u ON u.id = ld.unit_id
        {$resourceJoin}
        {$adminReviewJoin}
        LEFT JOIN calls c ON c.id = i.reported_by_call_id";

$where = [];
$params = [];

if ($priority !== '') {
    $where[] = 'i.priority = :priority';
    $params[':priority'] = $priority;
}

if ($status !== '') {
    if ($status === 'pending') {
        $where[] = "i.status = 'pending'";
        if (ers_table_exists($pdo, 'dispatches')) {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM dispatches d_pending
                WHERE d_pending.incident_id = i.id
                  AND d_pending.status IN ('assigned','acknowledged','enroute','on_scene')
            )";
        }
    } elseif ($status === 'active') {
        $where[] = "(i.status = 'pending' OR i.status = 'dispatched')";
    } elseif ($status === 'dispatched') {
        $where[] = "i.status = 'dispatched'";
    } elseif ($status === 'resolved' || $status === 'closed') {
        $where[] = "(i.status = 'resolved' OR i.status = 'cancelled')";
    } elseif ($status === 'resolved_only') {
        $where[] = "i.status = 'resolved'";
    } elseif ($status === 'cancelled') {
        $where[] = "i.status = 'cancelled'";
    }
}

append_type_filter($where, $params, 'i.type', $typeValues, 'incident');

if ($adminReview === 'sent') {
    $where[] = $hasAdminReviewTable ? 'iar.incident_id IS NOT NULL' : '1 = 0';
} elseif ($adminReview === 'unsent') {
    $where[] = $hasAdminReviewTable ? 'iar.incident_id IS NULL' : '1 = 1';
}

if ($search !== '') {
    $where[] = "(
        CAST(i.id AS CHAR) LIKE :search OR
        i.reference_no LIKE :search OR
        i.title LIKE :search OR
        i.type LIKE :search OR
        i.location_address LIKE :search OR
        i.description LIKE :search OR
        c.caller_name LIKE :search OR
        c.caller_phone LIKE :search OR
        u.identifier LIKE :search OR
        u.unit_type LIKE :search" .
        ($resourceRecordsTable !== null ? " OR ar.name LIKE :search OR ar.driver_name LIKE :search OR ar.plate_number LIKE :search" : '') .
    ')';
    $params[':search'] = '%' . $search . '%';
}

if ($rangeStart !== null && $rangeEnd !== null) {
    $where[] = "(
        i.created_at BETWEEN :range_start AND :range_end
        OR (i.updated_at IS NOT NULL AND i.updated_at BETWEEN :range_updated_start AND :range_updated_end)
        OR EXISTS (
            SELECT 1
            FROM dispatches d_window
            WHERE d_window.incident_id = i.id
              AND d_window.assigned_at BETWEEN :range_dispatch_start AND :range_dispatch_end
        )
    )";
    $params[':range_start'] = $rangeStart;
    $params[':range_end'] = $rangeEnd;
    $params[':range_updated_start'] = $rangeStart;
    $params[':range_updated_end'] = $rangeEnd;
    $params[':range_dispatch_start'] = $rangeStart;
    $params[':range_dispatch_end'] = $rangeEnd;
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY COALESCE(i.resolved_at, ld.cleared_at, i.updated_at, i.created_at) DESC, i.id DESC LIMIT 200';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = array_map(static function (array $row): array {
        return [
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'incident_code' => $row['reference_no'] ?? '',
            'type' => $row['type'] ?? '',
            'title' => $row['title'] ?? '',
            'location' => $row['location_address'] ?? '',
            'description' => $row['description'] ?? '',
            'priority' => $row['priority'] ?? '',
            'status' => $row['status'] ?? '',
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'resolved_at' => $row['resolved_at'] ?? null,
            'latitude' => isset($row['latitude']) && $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => isset($row['longitude']) && $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'assigned_unit' => $row['unit_identifier'] ?? null,
            'assigned_unit_type' => $row['unit_type'] ?? null,
            'vehicle_name' => $row['vehicle_name'] ?? null,
            'driver_name' => $row['driver_name'] ?? null,
            'plate_number' => $row['plate_number'] ?? null,
            'caller_name' => $row['caller_name'] ?? null,
            'caller_phone' => $row['caller_phone'] ?? null,
            'assigned_at' => $row['assigned_at'] ?? null,
            'acknowledged_at' => $row['acknowledged_at'] ?? null,
            'enroute_at' => $row['enroute_at'] ?? null,
            'on_scene_at' => $row['on_scene_at'] ?? null,
            'cleared_at' => $row['cleared_at'] ?? null,
            'latest_dispatch_status' => $row['latest_dispatch_status'] ?? null,
            'response_time_min' => isset($row['response_time_min']) && $row['response_time_min'] !== null ? (int)$row['response_time_min'] : null,
            'resolution_time_min' => isset($row['resolution_time_min']) && $row['resolution_time_min'] !== null ? (int)$row['resolution_time_min'] : null,
            'feedback_count' => isset($row['feedback_count']) ? (int)$row['feedback_count'] : 0,
            'avg_rating' => isset($row['avg_rating']) && $row['avg_rating'] !== null ? (float)$row['avg_rating'] : null,
            'rating_count' => isset($row['rating_count']) ? (int)$row['rating_count'] : 0,
            'submitted_to_admin' => !empty($row['admin_review_sent_at']),
            'admin_review_sent_at' => $row['admin_review_sent_at'] ?? null,
            'admin_review_sent_by_name' => $row['admin_review_sent_by_name'] ?? null,
            'admin_review_sent_by_user_id' => isset($row['admin_review_sent_by_user_id']) && $row['admin_review_sent_by_user_id'] !== null ? (int)$row['admin_review_sent_by_user_id'] : null,
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}
