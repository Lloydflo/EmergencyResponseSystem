<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

const CACHE_TTL_SECONDS = 86400; // 24 hours fresh cache
const CACHE_MAX_ENTRIES = 2000;
const DEFAULT_SEARCH_LIMIT = 10;
const DEFAULT_CITY_CONTEXT = 'Quezon City, Metro Manila, Philippines';
const DEFAULT_COUNTRY_CODE = 'ph';
const QUEZON_CITY_VIEWBOX = '120.9300,14.8000,121.1500,14.5200';

$rawQuery = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? DEFAULT_SEARCH_LIMIT);
$strictRaw = strtolower(trim((string)($_GET['strict'] ?? $_GET['bounded'] ?? '')));
$strict = in_array($strictRaw, ['1', 'true', 'yes', 'on'], true);

if ($rawQuery === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing query']);
    exit;
}

if ($limit < 1) $limit = 1;
if ($limit > 10) $limit = 10;

$cacheFile = __DIR__ . '/../data/geocode_cache.json';
$cache = load_cache($cacheFile);
$cacheKey = make_cache_key($rawQuery, $limit, $strict);
$now = time();

if (isset($cache[$cacheKey]) && is_fresh_cache_entry($cache[$cacheKey], $now)) {
    echo json_encode([
        'ok' => true,
        'source' => 'cache',
        'items' => normalize_items($cache[$cacheKey]['items'], $limit),
    ]);
    exit;
}

$localItems = find_local_location_candidates($rawQuery, $limit);
$items = !empty($localItems) ? $localItems : fetch_geocode_candidates($rawQuery, $limit, $strict);
// Keep the dispatch area usable when the public geocoder is rate-limited or
// the server has no outbound internet access. Known local barangays are
// returned immediately instead of being shown as "No results found".
if (!empty($items)) {
    $normalized = normalize_items($items, $limit);
    $cache[$cacheKey] = [
        'ts' => $now,
        'items' => $normalized,
    ];
    prune_cache($cache);
    save_cache($cacheFile, $cache);

    echo json_encode([
        'ok' => true,
        'source' => !empty($localItems) ? 'local' : 'live',
        'items' => $normalized,
    ]);
    exit;
}

if (isset($cache[$cacheKey])) {
    echo json_encode([
        'ok' => true,
        'source' => 'stale_cache',
        'items' => normalize_items($cache[$cacheKey]['items'], $limit),
    ]);
    exit;
}

http_response_code(502);
echo json_encode([
    'ok' => false,
    'error' => 'Geocoding service unavailable',
    'items' => [],
]);

function make_cache_key(string $query, int $limit, bool $strict): string {
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $query)));
    return hash('sha256', $normalized . '|' . $limit . '|' . ($strict ? '1' : '0'));
}

function is_fresh_cache_entry($entry, int $now): bool {
    if (!is_array($entry)) return false;
    $ts = isset($entry['ts']) ? (int)$entry['ts'] : 0;
    if ($ts <= 0) return false;
    return ($now - $ts) <= CACHE_TTL_SECONDS;
}

function load_cache(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_cache(string $path, array $cache): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return;
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return;
    @rename($tmp, $path);
}

function prune_cache(array &$cache): void {
    if (count($cache) <= CACHE_MAX_ENTRIES) return;
    uasort($cache, static function ($a, $b) {
        $ta = isset($a['ts']) ? (int)$a['ts'] : 0;
        $tb = isset($b['ts']) ? (int)$b['ts'] : 0;
        return $tb <=> $ta;
    });
    $cache = array_slice($cache, 0, CACHE_MAX_ENTRIES, true);
}

function normalize_items(array $items, int $limit): array {
    $out = [];
    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if (!item_is_in_philippines($item)) continue;
        $display = trim((string)($item['display_name'] ?? ''));
        if ($display === '') continue;
        $key = strtolower($display);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = [
            'display_name' => $display,
            'lat' => (string)($item['lat'] ?? ''),
            'lon' => (string)($item['lon'] ?? ''),
            'importance' => isset($item['importance']) ? (float)$item['importance'] : 0,
            'class' => (string)($item['class'] ?? ''),
            'type' => (string)($item['type'] ?? ''),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

function find_local_location_candidates(string $query, int $limit): array {
    $needle = strtolower(trim((string)preg_replace('/\s+/', ' ', $query)));
    if ($needle === '') {
        return [];
    }

    // The boundary used by Dispatch/GPS is bundled with ERS.  Its centre is
    // a dependable fallback coordinate for barangay-level incident reports.
    $catalog = [
        [
            'name' => 'San Agustin',
            'display_name' => 'San Agustin, Novaliches, Quezon City, Metro Manila, Philippines',
            'lat' => '14.732600',
            'lon' => '121.035200',
        ],
        [
            'name' => 'Quezon City',
            'display_name' => 'Quezon City, Metro Manila, Philippines',
            'lat' => '14.676000',
            'lon' => '121.043700',
        ],
        [
            'name' => 'Novaliches',
            'display_name' => 'Novaliches, Quezon City, Metro Manila, Philippines',
            'lat' => '14.721900',
            'lon' => '121.038800',
        ],
    ];

    $matches = [];
    foreach ($catalog as $place) {
        $haystack = strtolower($place['name'] . ' ' . $place['display_name']);
        if (strpos($haystack, $needle) === false && strpos($needle, strtolower($place['name'])) === false) {
            continue;
        }
        $place['importance'] = 1.0;
        $place['class'] = 'boundary';
        $place['type'] = 'administrative';
        $matches[] = $place;
        if (count($matches) >= $limit) {
            break;
        }
    }
    return $matches;
}

function build_nominatim_url(string $query, int $limit, bool $strict): string {
    $params = new URLSearchParams();
    $params->set('format', 'jsonv2');
    $params->set('addressdetails', '1');
    $params->set('limit', (string)$limit);
    $params->set('countrycodes', DEFAULT_COUNTRY_CODE);
    $params->set('viewbox', QUEZON_CITY_VIEWBOX);
    $params->set('bounded', $strict ? '1' : '0');
    $params->set('dedupe', '1');
    $params->set('q', $query);
    return 'https://nominatim.openstreetmap.org/search?' . $params->toString();
}

function score_item(array $item, string $query): float {
    $label = strtolower((string)($item['display_name'] ?? ''));
    $q = normalize_score_text($query);
    $score = isset($item['importance']) ? (float)$item['importance'] : 0.0;
    $normalizedLabel = normalize_score_text($label);

    if ($q !== '' && strpos($normalizedLabel, $q) !== false) $score += 2.0;
    foreach (extract_search_terms($q) as $term) {
        if ($term !== '' && strpos($normalizedLabel, $term) !== false) {
            $score += 0.35;
        }
    }
    if (strpos($normalizedLabel, 'quezon city') !== false) $score += 1.5;
    if (strpos($normalizedLabel, 'metro manila') !== false || strpos($normalizedLabel, 'national capital region') !== false) $score += 0.8;
    if (strpos($normalizedLabel, 'philippines') !== false) $score += 0.5;
    if (strpos($normalizedLabel, 'san agustin') !== false && strpos($q, 'san agustin') !== false) $score += 1.2;

    $lat = isset($item['lat']) ? (float)$item['lat'] : null;
    $lon = isset($item['lon']) ? (float)$item['lon'] : null;
    if ($lat !== null && $lon !== null) {
        if ($lat >= 14.52 && $lat <= 14.80 && $lon >= 120.93 && $lon <= 121.15) {
            $score += 2.0;
        } else {
            $score -= 2.0;
        }
    }

    return $score;
}

function item_is_in_philippines(array $item): bool {
    $display = strtolower((string)($item['display_name'] ?? ''));
    if (strpos($display, 'philippines') !== false || strpos($display, 'pilipinas') !== false) {
        return true;
    }

    $latRaw = $item['lat'] ?? null;
    $lonRaw = $item['lon'] ?? null;
    if ($latRaw === null || $lonRaw === null || $latRaw === '' || $lonRaw === '') {
        return false;
    }

    $lat = (float)$latRaw;
    $lon = (float)$lonRaw;
    return $lat >= 4.0 && $lat <= 22.5 && $lon >= 116.0 && $lon <= 127.5;
}

function fetch_geocode_candidates(string $query, int $limit, bool $strict): array {
    $input = trim($query);
    if ($input === '') return [];

    $attempts = build_geocode_attempts($input, $strict);

    $results = [];
    $seen = [];
    foreach ($attempts as $attempt) {
        $attemptQuery = (string)$attempt['q'];
        $attemptLimit = max($limit, 10);
        $url = build_nominatim_url($attemptQuery, $attemptLimit, (bool)$attempt['strict']);
        $data = http_get_json($url);
        if (!is_array($data) || empty($data)) {
            continue;
        }
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $display = trim((string)($item['display_name'] ?? ''));
            $lat = trim((string)($item['lat'] ?? ''));
            $lon = trim((string)($item['lon'] ?? ''));
            if ($display === '' || $lat === '' || $lon === '') {
                continue;
            }
            if (!item_is_in_philippines($item)) {
                continue;
            }
            $key = strtolower($display . '|' . $lat . '|' . $lon);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $item['_attempt_query'] = $attemptQuery;
            $item['_attempt_rank'] = (int)($attempt['rank'] ?? 99);
            $results[] = $item;
        }
        if (count($results) >= $limit * 2) {
            break;
        }
    }

    if (empty($results)) return [];

    usort($results, static function ($a, $b) use ($input) {
        $scoreB = score_item((array)$b, $input) - ((int)($b['_attempt_rank'] ?? 99) * 0.04);
        $scoreA = score_item((array)$a, $input) - ((int)($a['_attempt_rank'] ?? 99) * 0.04);
        return $scoreB <=> $scoreA;
    });

    return $results;
}

function build_geocode_attempts(string $query, bool $preferStrict): array {
    $input = normalize_spaces($query);
    $normalized = normalize_local_address($input);
    $withContext = append_default_context($normalized);
    $withoutBarangayKeyword = remove_barangay_keyword($withContext);
    $partsFallback = barangay_city_fallback($normalized);

    $rawAttempts = $preferStrict
        ? [
            [$input, true],
            [$normalized, true],
            [$withContext, true],
            [$withoutBarangayKeyword, true],
            [$partsFallback, true],
            [$withContext, false],
            [$withoutBarangayKeyword, false],
        ]
        : [
            [$input, false],
            [$normalized, false],
            [$withContext, false],
            [$withoutBarangayKeyword, false],
            [$partsFallback, false],
            [$withContext, true],
            [$withoutBarangayKeyword, true],
        ];

    $attempts = [];
    $seen = [];
    foreach ($rawAttempts as $rank => $attempt) {
        $q = normalize_spaces((string)$attempt[0]);
        if ($q === '') {
            continue;
        }
        $key = strtolower($q) . '|' . ((bool)$attempt[1] ? '1' : '0');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $attempts[] = [
            'q' => $q,
            'strict' => (bool)$attempt[1],
            'rank' => $rank,
        ];
    }
    return $attempts;
}

function normalize_spaces(string $value): string {
    return trim((string)preg_replace('/\s+/', ' ', $value));
}

function normalize_local_address(string $query): string {
    $value = normalize_spaces($query);
    $replacements = [
        '/\bbrgy\.?\b/i' => 'Barangay',
        '/\bbgy\.?\b/i' => 'Barangay',
        '/\bbrg\.?\b/i' => 'Barangay',
        '/\bbarangay\.?\b/i' => 'Barangay',
        '/\bq\.?\s*c\.?\b/i' => 'Quezon City',
        '/\bqc\b/i' => 'Quezon City',
        '/\bst\.?\b/i' => 'Street',
        '/\bave\.?\b/i' => 'Avenue',
        '/\brd\.?\b/i' => 'Road',
        '/\bdr\.?\b/i' => 'Drive',
        '/\bblk\.?\b/i' => 'Block',
        '/\blot\.?\b/i' => 'Lot',
        '/\bsubd\.?\b/i' => 'Subdivision',
    ];

    foreach ($replacements as $pattern => $replacement) {
        $value = preg_replace($pattern, $replacement, $value) ?? $value;
    }

    $value = preg_replace('/\s*,\s*/', ', ', $value) ?? $value;
    return normalize_spaces($value);
}

function append_default_context(string $query): string {
    $value = normalize_spaces($query);
    $lower = strtolower($value);

    if (strpos($lower, 'philippines') !== false) {
        return $value;
    }
    if (strpos($lower, 'quezon city') !== false) {
        if (strpos($lower, 'metro manila') === false && strpos($lower, 'national capital region') === false) {
            return $value . ', Metro Manila, Philippines';
        }
        return $value . ', Philippines';
    }
    if (strpos($lower, 'metro manila') !== false || strpos($lower, 'national capital region') !== false) {
        return $value . ', Philippines';
    }
    return $value . ', ' . DEFAULT_CITY_CONTEXT;
}

function remove_barangay_keyword(string $query): string {
    $value = preg_replace('/\bBarangay\s+/i', '', $query) ?? $query;
    return normalize_spaces($value);
}

function barangay_city_fallback(string $query): string {
    $normalized = append_default_context($query);
    if (preg_match('/\bBarangay\s+([^,]+)/i', $normalized, $match)) {
        $barangay = trim((string)$match[1]);
        if ($barangay !== '') {
            return $barangay . ', ' . DEFAULT_CITY_CONTEXT;
        }
    }
    $parts = array_values(array_filter(array_map('trim', explode(',', $normalized))));
    if (count($parts) >= 2) {
        return implode(', ', array_slice($parts, -3));
    }
    return DEFAULT_CITY_CONTEXT;
}

function normalize_score_text(string $value): string {
    $value = strtolower(normalize_local_address($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return normalize_spaces($value);
}

function extract_search_terms(string $query): array {
    $stopWords = [
        'the' => true,
        'and' => true,
        'of' => true,
        'barangay' => true,
        'brgy' => true,
        'city' => true,
        'metro' => true,
        'manila' => true,
        'philippines' => true,
    ];
    $terms = preg_split('/\s+/', normalize_score_text($query)) ?: [];
    $out = [];
    foreach ($terms as $term) {
        $term = trim($term);
        if (strlen($term) < 3 || isset($stopWords[$term])) {
            continue;
        }
        $out[] = $term;
    }
    return array_values(array_unique($out));
}

function http_get_json(string $url): ?array {
    $headers = [
        'Accept: application/json',
        'User-Agent: ERS-GeocodeProxy/1.0 (+http://localhost/ERS)',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 6,
            'header' => implode("\r\n", $headers),
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body)) return null;
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    if ($status < 200 || $status >= 300) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

final class URLSearchParams {
    private array $data = [];

    public function set(string $key, string $value): void {
        $this->data[$key] = $value;
    }

    public function toString(): string {
        return http_build_query($this->data, '', '&', PHP_QUERY_RFC3986);
    }
}
