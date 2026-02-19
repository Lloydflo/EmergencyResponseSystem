<?php
// Returns incident details and available units for modal
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
$hasId = array_key_exists('id', $_GET) && $_GET['id'] !== '' && is_numeric((string)$_GET['id']);
$id = $hasId ? (int)$_GET['id'] : null;
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';

$out = ["ok"=>false, "incident"=>null, "units"=>[]];
if ($pdo) {
    try {
        if ($hasId) {
            $stmt = $pdo->prepare("SELECT * FROM incidents WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
        } elseif ($code !== '') {
            $stmt = $pdo->prepare("SELECT * FROM incidents WHERE reference_no=? LIMIT 1");
            $stmt->execute([$code]);
        } else {
            $stmt = null;
        }

        if ($stmt) {
            $incident = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($incident) {
                $out['incident'] = $incident;
                $out['ok'] = true;
            }
        }
        // Determine desired unit types based on incident type
        $desiredTypes = [];
        if (!empty($out['incident']['type'])) {
            $t = strtolower(trim((string)$out['incident']['type']));
            // Simple keyword mapping
            if (preg_match('/fire|smoke|blaze|burn/i', $t)) {
                $desiredTypes = ['fire'];
            } elseif (preg_match('/medical|injur|cardiac|stroke|ambulance|unconscious|pregnan|health/i', $t)) {
                $desiredTypes = ['ambulance'];
            } elseif (preg_match('/crime|robbery|assault|police|theft|violence|shoot|armed/i', $t)) {
                $desiredTypes = ['police'];
            } elseif (preg_match('/rescue|collapse|trapped|flood|earthquake|landslide|water|drowning/i', $t)) {
                $desiredTypes = ['rescue'];
            } else {
                // Fallback: try to match exact enum names if present
                if (in_array($t, ['fire','ambulance','police','rescue','other'], true)) {
                    $desiredTypes = [$t];
                }
            }
        }

        // Build base query for available units
        if (!empty($desiredTypes)) {
            // Filter by desired types
            $placeholders = implode(',', array_fill(0, count($desiredTypes), '?'));
            $q = $pdo->prepare("SELECT * FROM units WHERE status='available' AND unit_type IN ($placeholders)");
            $q->execute($desiredTypes);
            $units = $q->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // No mapping found: return all available units
            $units = $pdo->query("SELECT * FROM units WHERE status='available'")->fetchAll(PDO::FETCH_ASSOC);
        }

        // If incident and units have coordinates, compute distance and sort
        $incLat = isset($out['incident']['latitude']) ? (float)$out['incident']['latitude'] : null;
        $incLng = isset($out['incident']['longitude']) ? (float)$out['incident']['longitude'] : null;
        $hasIncidentCoords = ($incLat !== null && $incLng !== null);

        if ($hasIncidentCoords && !empty($units)) {
            $R = 6371.0; // km
            $toRad = function($d) { return $d * M_PI / 180.0; };
            foreach ($units as &$u) {
                $uLat = isset($u['latitude']) ? (float)$u['latitude'] : null;
                $uLng = isset($u['longitude']) ? (float)$u['longitude'] : null;
                if ($uLat !== null && $uLng !== null) {
                    $dLat = $toRad($incLat - $uLat);
                    $dLon = $toRad($incLng - $uLng);
                    $a = sin($dLat/2) * sin($dLat/2)
                       + cos($toRad($uLat)) * cos($toRad($incLat))
                       * sin($dLon/2) * sin($dLon/2);
                    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
                    $dist = $R * $c;
                    $u['distance_km'] = round($dist, 2);
                } else {
                    $u['distance_km'] = null;
                }
            }
            unset($u);
            // Sort: units with distance first (asc), then those without distance
            usort($units, function($a, $b) {
                $da = $a['distance_km'];
                $db = $b['distance_km'];
                if ($da === null && $db === null) return 0;
                if ($da === null) return 1;
                if ($db === null) return -1;
                if ($da == $db) return 0;
                return ($da < $db) ? -1 : 1;
            });
        } else {
            // Default ordering by unit_type, identifier
            usort($units, function($a, $b) {
                $at = $a['unit_type'] ?? '';
                $bt = $b['unit_type'] ?? '';
                if ($at === $bt) {
                    return strcmp($a['identifier'] ?? '', $b['identifier'] ?? '');
                }
                return strcmp($at, $bt);
            });
        }

        $out['units'] = $units;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(["ok"=>false, "error"=>"Query failed"]);
        exit;
    }
}
echo json_encode($out);
