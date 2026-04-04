<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';

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

$pdo = get_db_connection();
$hasId = array_key_exists('id', $_GET) && $_GET['id'] !== '' && is_numeric((string)$_GET['id']);
$id = $hasId ? (int)$_GET['id'] : null;
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';

$out = ['ok' => false, 'incident' => null, 'units' => []];
if (!$pdo) {
    echo json_encode($out);
    exit;
}

$resourceRecordsTable = ers_vehicle_resource_units_table($pdo);
if ($resourceRecordsTable !== null) {
    ers_sync_all_vehicle_resource_units($pdo, $resourceRecordsTable);
}

try {
    if ($hasId) {
        $stmt = $pdo->prepare(
            "SELECT i.*, c.latitude AS call_latitude, c.longitude AS call_longitude
             FROM incidents i
             LEFT JOIN calls c ON c.id = i.reported_by_call_id
             WHERE i.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
    } elseif ($code !== '') {
        $stmt = $pdo->prepare(
            "SELECT i.*, c.latitude AS call_latitude, c.longitude AS call_longitude
             FROM incidents i
             LEFT JOIN calls c ON c.id = i.reported_by_call_id
             WHERE i.reference_no = ?
             LIMIT 1"
        );
        $stmt->execute([$code]);
    } else {
        $stmt = null;
    }

    if ($stmt) {
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($incident) {
            if ((!isset($incident['latitude']) || $incident['latitude'] === null || $incident['latitude'] === '') && isset($incident['call_latitude']) && $incident['call_latitude'] !== null && $incident['call_latitude'] !== '') {
                $incident['latitude'] = $incident['call_latitude'];
            }
            if ((!isset($incident['longitude']) || $incident['longitude'] === null || $incident['longitude'] === '') && isset($incident['call_longitude']) && $incident['call_longitude'] !== null && $incident['call_longitude'] !== '') {
                $incident['longitude'] = $incident['call_longitude'];
            }
            unset($incident['call_latitude'], $incident['call_longitude']);

            $incidentId = (int)$incident['id'];
            $hasIncidentNotes = ers_table_exists($pdo, 'incident_notes');
            $hasRatingColumn = $hasIncidentNotes && ers_column_exists($pdo, 'incident_notes', 'rating');

            $dispatchSelect = 'NULL AS vehicle_name, NULL AS driver_name, NULL AS plate_number';
            $dispatchJoin = '';
            if ($resourceRecordsTable !== null) {
                $dispatchSelect = 'ar.name AS vehicle_name, ar.driver_name AS driver_name, ar.plate_number AS plate_number';
                $dispatchJoin = ' LEFT JOIN `' . $resourceRecordsTable . '` ar ON ar.code = u.identifier ';
            }

            $dispatchStmt = $pdo->prepare(
                "SELECT
                    d.id,
                    d.status,
                    d.assigned_at,
                    d.acknowledged_at,
                    d.enroute_at,
                    d.on_scene_at,
                    d.cleared_at,
                    u.id AS unit_id,
                    u.identifier AS unit_identifier,
                    u.unit_type,
                    {$dispatchSelect}
                 FROM dispatches d
                 LEFT JOIN units u ON u.id = d.unit_id
                 {$dispatchJoin}
                 WHERE d.incident_id = ?
                 ORDER BY d.id DESC
                 LIMIT 1"
            );
            $dispatchStmt->execute([$incidentId]);
            $latestDispatch = $dispatchStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $incident['assigned_unit_identifier'] = null;
            $incident['assigned_unit_type'] = null;
            $incident['vehicle_name'] = null;
            $incident['driver_name'] = null;
            $incident['plate_number'] = null;
            $incident['dispatch_status'] = null;
            $incident['dispatch_assigned_at'] = null;
            $incident['acknowledged_at'] = null;
            $incident['enroute_at'] = null;
            $incident['on_scene_at'] = null;
            $incident['cleared_at'] = null;
            $incident['response_time_min'] = null;
            $incident['resolution_time_min'] = null;
            $incident['feedback_count'] = 0;
            $incident['rating_count'] = 0;
            $incident['avg_rating'] = null;

            if ($latestDispatch) {
                $incident['assigned_unit_identifier'] = $latestDispatch['unit_identifier'] ?? null;
                $incident['assigned_unit_type'] = $latestDispatch['unit_type'] ?? null;
                $incident['vehicle_name'] = $latestDispatch['vehicle_name'] ?? null;
                $incident['driver_name'] = $latestDispatch['driver_name'] ?? null;
                $incident['plate_number'] = $latestDispatch['plate_number'] ?? null;
                $incident['dispatch_status'] = $latestDispatch['status'] ?? null;
                $incident['dispatch_assigned_at'] = $latestDispatch['assigned_at'] ?? null;
                $incident['acknowledged_at'] = $latestDispatch['acknowledged_at'] ?? null;
                $incident['enroute_at'] = $latestDispatch['enroute_at'] ?? null;
                $incident['on_scene_at'] = $latestDispatch['on_scene_at'] ?? null;
                $incident['cleared_at'] = $latestDispatch['cleared_at'] ?? null;

                if (!empty($latestDispatch['assigned_at']) && !empty($latestDispatch['on_scene_at'])) {
                    $assigned = new DateTime($latestDispatch['assigned_at']);
                    $onScene = new DateTime($latestDispatch['on_scene_at']);
                    $diff = $assigned->diff($onScene);
                    $incident['response_time_min'] = (int)($diff->days * 24 * 60 + $diff->h * 60 + $diff->i);
                }

                $closedAt = $incident['resolved_at'] ?? ($latestDispatch['cleared_at'] ?? null);
                if (!empty($latestDispatch['assigned_at']) && !empty($closedAt)) {
                    $assigned = new DateTime($latestDispatch['assigned_at']);
                    $closed = new DateTime($closedAt);
                    $diff = $assigned->diff($closed);
                    $incident['resolution_time_min'] = (int)($diff->days * 24 * 60 + $diff->h * 60 + $diff->i);
                }
            }

            if ($hasIncidentNotes) {
                if ($hasRatingColumn) {
                    $feedbackStmt = $pdo->prepare(
                        "SELECT COUNT(*) AS feedback_count,
                                COUNT(rating) AS rating_count,
                                ROUND(AVG(rating), 1) AS avg_rating
                         FROM incident_notes
                         WHERE incident_id = ?"
                    );
                } else {
                    $feedbackStmt = $pdo->prepare(
                        "SELECT COUNT(*) AS feedback_count,
                                0 AS rating_count,
                                NULL AS avg_rating
                         FROM incident_notes
                         WHERE incident_id = ?"
                    );
                }
                $feedbackStmt->execute([$incidentId]);
                $feedback = $feedbackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($feedback) {
                    $incident['feedback_count'] = isset($feedback['feedback_count']) ? (int)$feedback['feedback_count'] : 0;
                    $incident['rating_count'] = isset($feedback['rating_count']) ? (int)$feedback['rating_count'] : 0;
                    $incident['avg_rating'] = isset($feedback['avg_rating']) && $feedback['avg_rating'] !== null ? (float)$feedback['avg_rating'] : null;
                }
            }

            $out['incident'] = $incident;
            $out['ok'] = true;
        }
    }

    $desiredTypes = [];
    if (!empty($out['incident']) && !empty($out['incident']['type'])) {
        $typeValue = strtolower(trim((string)$out['incident']['type']));
        if (preg_match('/fire|smoke|blaze|burn/i', $typeValue)) {
            $desiredTypes = ['fire'];
        } elseif (preg_match('/medical|injur|cardiac|stroke|ambulance|unconscious|pregnan|health/i', $typeValue)) {
            $desiredTypes = ['ambulance'];
        } elseif (preg_match('/crime|robbery|assault|police|theft|violence|shoot|armed/i', $typeValue)) {
            $desiredTypes = ['police'];
        } elseif (preg_match('/rescue|collapse|trapped|flood|earthquake|landslide|water|drowning/i', $typeValue)) {
            $desiredTypes = ['rescue'];
        } elseif (in_array($typeValue, ['fire', 'ambulance', 'police', 'rescue', 'other'], true)) {
            $desiredTypes = [$typeValue];
        }
    }

    $unitSelect = '*';
    $unitFrom = 'units';
    $unitAlias = '';
    $unitJoin = '';
    if ($resourceRecordsTable !== null) {
        $unitSelect = 'u.*, rr.name AS vehicle_name, rr.driver_name, rr.plate_number';
        $unitFrom = 'units u';
        $unitAlias = 'u.';
        $unitJoin = " INNER JOIN `" . $resourceRecordsTable . "` rr
                      ON rr.code = u.identifier
                     AND LOWER(rr.category) = 'vehicles'";
    }

    if (!empty($desiredTypes)) {
        if (!in_array('other', $desiredTypes, true)) {
            $desiredTypes[] = 'other';
        }
        $placeholders = implode(',', array_fill(0, count($desiredTypes), '?'));
        $unitStmt = $pdo->prepare(
            "SELECT {$unitSelect}
             FROM {$unitFrom}
             {$unitJoin}
             WHERE {$unitAlias}status = 'available'
               AND {$unitAlias}unit_type IN ({$placeholders})"
        );
        $unitStmt->execute($desiredTypes);
        $units = $unitStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $units = $pdo->query(
            "SELECT {$unitSelect}
             FROM {$unitFrom}
             {$unitJoin}
             WHERE {$unitAlias}status = 'available'"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $incidentLat = isset($out['incident']['latitude']) ? (float)$out['incident']['latitude'] : null;
    $incidentLng = isset($out['incident']['longitude']) ? (float)$out['incident']['longitude'] : null;
    $hasCoords = ($incidentLat !== null && $incidentLng !== null);

    if ($hasCoords && !empty($units)) {
        $earthRadiusKm = 6371.0;
        $toRad = static function (float $degrees): float {
            return $degrees * M_PI / 180.0;
        };

        foreach ($units as &$unit) {
            $unitLat = isset($unit['latitude']) ? (float)$unit['latitude'] : null;
            $unitLng = isset($unit['longitude']) ? (float)$unit['longitude'] : null;
            if ($unitLat !== null && $unitLng !== null) {
                $dLat = $toRad($incidentLat - $unitLat);
                $dLon = $toRad($incidentLng - $unitLng);
                $a = sin($dLat / 2) * sin($dLat / 2)
                    + cos($toRad($unitLat)) * cos($toRad($incidentLat))
                    * sin($dLon / 2) * sin($dLon / 2);
                $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                $unit['distance_km'] = round($earthRadiusKm * $c, 2);
            } else {
                $unit['distance_km'] = null;
            }
        }
        unset($unit);

        usort($units, static function (array $a, array $b): int {
            $distanceA = $a['distance_km'];
            $distanceB = $b['distance_km'];
            if ($distanceA === null && $distanceB === null) {
                return 0;
            }
            if ($distanceA === null) {
                return 1;
            }
            if ($distanceB === null) {
                return -1;
            }
            if ($distanceA == $distanceB) {
                return 0;
            }
            return ($distanceA < $distanceB) ? -1 : 1;
        });
    } else {
        usort($units, static function (array $a, array $b): int {
            $typeA = $a['unit_type'] ?? '';
            $typeB = $b['unit_type'] ?? '';
            if ($typeA === $typeB) {
                return strcmp($a['identifier'] ?? '', $b['identifier'] ?? '');
            }
            return strcmp($typeA, $typeB);
        });
    }

    $out['units'] = $units;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Query failed']);
    exit;
}

echo json_encode($out);
