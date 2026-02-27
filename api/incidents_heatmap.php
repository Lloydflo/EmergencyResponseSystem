<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$days = isset($_GET['days']) ? max(1, min(3650, (int)$_GET['days'])) : 90;
$hours = isset($_GET['hours']) ? max(1, min(720, (int)$_GET['hours'])) : 0;
$priority = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$all = isset($_GET['all']) ? strtolower(trim((string)$_GET['all'])) : '';
$includeAll = in_array($all, ['1', 'true', 'yes', 'all'], true);
$qcOnly = !isset($_GET['qc']) || !in_array(strtolower(trim((string)$_GET['qc'])), ['0', 'false', 'no'], true);

$incWhere = [];
$callsWhere = ['i2.id IS NULL'];
$params = [];

if (!$includeAll) {
    $cutoff = new DateTimeImmutable('now');
    if ($hours > 0) {
        $cutoff = $cutoff->sub(new DateInterval('PT' . $hours . 'H'));
    } else {
        $cutoff = $cutoff->sub(new DateInterval('P' . $days . 'D'));
    }
    $cutoffText = $cutoff->format('Y-m-d H:i:s');
    $params[':inc_cutoff'] = $cutoffText;
    $params[':call_cutoff'] = $cutoffText;
    $incWhere[] = 'i.created_at >= :inc_cutoff';
    $callsWhere[] = 'c.created_at >= :call_cutoff';
}

if ($type !== '') {
    $incWhere[] = '(
        i.type = :inc_type OR
        i.type LIKE :inc_type_kw OR i.title LIKE :inc_type_kw OR i.description LIKE :inc_type_kw
    )';
    $callsWhere[] = '(
        c.incident_type = :call_type OR
        c.incident_type LIKE :call_type_kw OR c.description LIKE :call_type_kw OR c.location_address LIKE :call_type_kw
    )';
    $params[':inc_type'] = $type;
    $params[':inc_type_kw'] = '%' . $type . '%';
    $params[':call_type'] = $type;
    $params[':call_type_kw'] = '%' . $type . '%';
}

if ($priority !== '') {
    $incWhere[] = 'COALESCE(i.priority, c.priority) = :inc_priority';
    $callsWhere[] = 'c.priority = :call_priority';
    $params[':inc_priority'] = $priority;
    $params[':call_priority'] = $priority;
}

$includeCallsFallback = true;
if ($status !== '') {
    if (strcasecmp($status, 'active') === 0) {
        $incWhere[] = "i.status IN ('pending','dispatched')";
        $callsWhere[] = "c.status IN ('new','triaged')";
    } else {
        $incWhere[] = 'i.status = :inc_status';
        $params[':inc_status'] = $status;
        // calls.status does not match incidents.status for non-active values.
        $includeCallsFallback = false;
    }
}

$incSql = 'SELECT
    COALESCE(i.latitude, c.latitude) AS latitude,
    COALESCE(i.longitude, c.longitude) AS longitude,
    COALESCE(i.priority, c.priority, \'medium\') AS priority,
    COALESCE(i.location_address, c.location_address, \'\') AS location_address,
    i.created_at AS created_at
FROM incidents i
LEFT JOIN calls c ON c.id = i.reported_by_call_id';
if ($incWhere) {
    $incSql .= ' WHERE ' . implode(' AND ', $incWhere);
}

$sql = $incSql;
if ($includeCallsFallback) {
    $callsSql = 'SELECT
        c.latitude AS latitude,
        c.longitude AS longitude,
        COALESCE(c.priority, \'medium\') AS priority,
        COALESCE(c.location_address, \'\') AS location_address,
        c.created_at AS created_at
    FROM calls c
    LEFT JOIN incidents i2 ON i2.reported_by_call_id = c.id';
    if ($callsWhere) {
        $callsSql .= ' WHERE ' . implode(' AND ', $callsWhere);
    }
    $sql = $incSql . ' UNION ALL ' . $callsSql;
}

$sql = 'SELECT latitude, longitude, priority, location_address, created_at
        FROM (' . $sql . ') heat_points
        ORDER BY created_at DESC
        LIMIT 10000';

try {
    $stmt = $pdo->prepare($sql);
    $execParams = [];
    if (preg_match_all('/:[a-zA-Z0-9_]+/', $sql, $m)) {
        foreach (array_unique($m[0]) as $placeholder) {
            if (array_key_exists($placeholder, $params)) {
                $execParams[$placeholder] = $params[$placeholder];
            }
        }
    }
    $stmt->execute($execParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build density buckets so hotspots represent frequent incident zones.
    $grid = 0.0018; // ~200m
    $buckets = [];
    $nowTs = time();

    foreach ($rows as $r) {
        $lat = isset($r['latitude']) && $r['latitude'] !== null ? (float)$r['latitude'] : null;
        $lng = isset($r['longitude']) && $r['longitude'] !== null ? (float)$r['longitude'] : null;
        if ($lat === null || $lng === null) {
            $parsed = parse_coords_from_text((string)($r['location_address'] ?? ''));
            if ($parsed) {
                $lat = $parsed['lat'];
                $lng = $parsed['lng'];
            }
        }
        if ($lat === null || $lng === null) continue;
        if (!is_valid_lat_lng($lat, $lng)) continue;
        if ($qcOnly && !is_within_qc_bounds($lat, $lng)) continue;

        $prio = strtolower((string)($r['priority'] ?? 'medium'));
        $baseWeight = $prio === 'high' ? 1.0 : ($prio === 'medium' ? 0.72 : 0.45);

        $createdTs = strtotime((string)($r['created_at'] ?? ''));
        $ageHours = $createdTs ? max(0.0, ($nowTs - $createdTs) / 3600.0) : 0.0;
        $recency = max(0.35, 1.0 - min($ageHours, 720.0) / 1200.0);
        $weight = $baseWeight * $recency;

        $cellLat = round($lat / $grid) * $grid;
        $cellLng = round($lng / $grid) * $grid;
        $key = number_format($cellLat, 6, '.', '') . ',' . number_format($cellLng, 6, '.', '');

        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'lat_sum' => 0.0,
                'lng_sum' => 0.0,
                'weight' => 0.0,
                'count' => 0,
            ];
        }

        $buckets[$key]['lat_sum'] += $lat;
        $buckets[$key]['lng_sum'] += $lng;
        $buckets[$key]['weight'] += $weight;
        $buckets[$key]['count'] += 1;
    }

    if (empty($buckets)) {
        echo json_encode([
            'ok' => true,
            'points' => [],
            'count' => 0,
            'cluster_count' => 0,
            'hotspots' => [],
        ]);
        exit;
    }

    $maxWeight = 0.0;
    foreach ($buckets as $bucket) {
        if ($bucket['weight'] > $maxWeight) {
            $maxWeight = (float)$bucket['weight'];
        }
    }
    if ($maxWeight <= 0.0) $maxWeight = 1.0;

    $points = [];
    $hotspots = [];
    foreach ($buckets as $bucket) {
        $centerLat = $bucket['lat_sum'] / max(1, (int)$bucket['count']);
        $centerLng = $bucket['lng_sum'] / max(1, (int)$bucket['count']);
        $intensity = $bucket['weight'] / $maxWeight;
        $intensity = max(0.2, min(1.0, $intensity));

        $points[] = [$centerLat, $centerLng, round($intensity, 4)];
        $hotspots[] = [
            'latitude' => round($centerLat, 6),
            'longitude' => round($centerLng, 6),
            'incident_count' => (int)$bucket['count'],
            'score' => round($bucket['weight'], 4),
            'intensity' => round($intensity, 4),
        ];
    }

    usort($hotspots, static function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return $b['incident_count'] <=> $a['incident_count'];
        }
        return $b['score'] <=> $a['score'];
    });

    echo json_encode([
        'ok' => true,
        'points' => $points,
        'count' => count($rows),
        'cluster_count' => count($points),
        'hotspots' => array_slice($hotspots, 0, 10),
    ]);
} catch (Throwable $e) {
    error_log('incidents_heatmap query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
}

function parse_coords_from_text(string $text): ?array {
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $text, $m) !== 1) {
        return null;
    }
    $lat = (float)$m[1];
    $lng = (float)$m[2];
    if (!is_valid_lat_lng($lat, $lng)) return null;
    return ['lat' => $lat, 'lng' => $lng];
}

function is_valid_lat_lng(float $lat, float $lng): bool {
    return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
}

function is_within_qc_bounds(float $lat, float $lng): bool {
    return $lat >= 14.6000 && $lat <= 14.7500 && $lng >= 121.0000 && $lng <= 121.1000;
}
