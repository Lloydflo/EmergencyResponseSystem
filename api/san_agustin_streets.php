<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$dataFile = __DIR__ . '/../data/san_agustin_streets.json';
if (!file_exists($dataFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Street directory not found']);
    exit;
}

$raw = file_get_contents($dataFile);
$dataset = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($dataset) || !isset($dataset['streets']) || !is_array($dataset['streets'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Invalid street directory format']);
    exit;
}

$streets = $dataset['streets'];
$query = trim((string)($_GET['q'] ?? $_GET['query'] ?? $_GET['search'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));

if ($action === 'match' || $query !== '') {
    $needle = strtolower(preg_replace('/\s+/', ' ', $query));
    // Remove common prefixes/suffixes for cleaner search
    $needleClean = trim((string)preg_replace('/\b(street|st|road|rd|drive|dr|avenue|ave|subdivision|subd|compound|cmpd|village|novaliches|quezon city|qc|metro manila|philippines)\b/i', '', $needle));

    $scored = [];
    foreach ($streets as $item) {
        $score = 0;
        $nameLower = strtolower((string)($item['name'] ?? ''));
        $displayLower = strtolower((string)($item['display_name'] ?? ''));
        $areaLower = strtolower((string)($item['area'] ?? ''));
        $aliases = array_map('strtolower', (array)($item['aliases'] ?? []));

        if ($needle === $nameLower || in_array($needle, $aliases, true)) {
            $score += 100;
        } elseif (strpos($nameLower, $needle) !== false) {
            $score += 80;
        } elseif ($needleClean !== '' && strpos($nameLower, $needleClean) !== false) {
            $score += 70;
        } elseif (strpos($displayLower, $needle) !== false) {
            $score += 50;
        } elseif ($needleClean !== '' && strpos($displayLower, $needleClean) !== false) {
            $score += 40;
        }

        foreach ($aliases as $alias) {
            if (strpos($alias, $needle) !== false || ($needleClean !== '' && strpos($alias, $needleClean) !== false)) {
                $score += 60;
                break;
            }
        }

        if (strpos($areaLower, $needle) !== false) {
            $score += 30;
        }

        // Check individual words
        $terms = array_filter(explode(' ', $needleClean ?: $needle));
        foreach ($terms as $term) {
            if (strlen($term) >= 2) {
                if (strpos($nameLower, $term) !== false) $score += 15;
                if (strpos($displayLower, $term) !== false) $score += 10;
                if (strpos($areaLower, $term) !== false) $score += 8;
            }
        }

        if ($score > 0) {
            $scored[] = [
                'item' => $item,
                'score' => $score,
            ];
        }
    }

    usort($scored, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $results = array_map(static function ($entry) {
        return $entry['item'];
    }, $scored);

    if ($action === 'match') {
        $best = !empty($results) ? $results[0] : null;
        echo json_encode([
            'ok' => true,
            'match' => $best,
            'found' => $best !== null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'query' => $query,
        'count' => count($results),
        'items' => array_slice($results, 0, 20),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Return full directory with metadata
echo json_encode([
    'ok' => true,
    'barangay' => $dataset['barangay'] ?? 'San Agustin',
    'district' => $dataset['district'] ?? '5th District, Novaliches',
    'city' => $dataset['city'] ?? 'Quezon City',
    'postal_code' => $dataset['postal_code'] ?? '1123',
    'center' => $dataset['center'] ?? ['lat' => 14.7295595, 'lng' => 121.0386039],
    'count' => count($streets),
    'items' => $streets,
], JSON_UNESCAPED_UNICODE);
