<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

const QC_VIEWBOX = '121.0000,14.7500,121.1000,14.6000'; // left,top,right,bottom
const CACHE_TTL_SECONDS = 86400; // 24 hours fresh cache
const CACHE_MAX_ENTRIES = 800;

$rawQuery = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 6);
$strict = (string)($_GET['strict'] ?? '') === '1';

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

$items = fetch_geocode_candidates($rawQuery, $limit, $strict);
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
        'source' => 'live',
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
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

function has_location_context(string $text): bool {
    return (bool)preg_match('/(quezon city|qc|metro manila|philippines)\b/i', $text);
}

function build_nominatim_url(string $query, int $limit, bool $strict): string {
    $params = new URLSearchParams();
    $params->set('format', 'jsonv2');
    $params->set('addressdetails', '1');
    $params->set('limit', (string)$limit);
    $params->set('countrycodes', 'ph');
    $params->set('q', $query);
    $params->set('viewbox', QC_VIEWBOX);
    if ($strict) {
        $params->set('bounded', '1');
    }
    return 'https://nominatim.openstreetmap.org/search?' . $params->toString();
}

function score_item(array $item, string $query): float {
    $label = strtolower((string)($item['display_name'] ?? ''));
    $q = strtolower($query);
    $score = isset($item['importance']) ? (float)$item['importance'] : 0.0;
    if (strpos($label, 'quezon city') !== false) $score += 2.0;
    if ($q !== '' && strpos($label, $q) !== false) $score += 1.5;
    return $score;
}

function fetch_geocode_candidates(string $query, int $limit, bool $strict): array {
    $input = trim($query);
    if ($input === '') return [];

    $localized = has_location_context($input)
        ? $input
        : $input . ', Quezon City, Metro Manila, Philippines';

    $attempts = [
        ['q' => $localized, 'strict' => $strict],
        ['q' => $localized, 'strict' => false],
        ['q' => $input, 'strict' => false],
    ];

    $last = [];
    foreach ($attempts as $attempt) {
        $url = build_nominatim_url((string)$attempt['q'], $limit, (bool)$attempt['strict']);
        $data = http_get_json($url);
        if (!is_array($data) || empty($data)) {
            continue;
        }
        $last = $data;
        if ((bool)$attempt['strict']) {
            break;
        }
        foreach ($data as $place) {
            $label = strtolower((string)($place['display_name'] ?? ''));
            if (strpos($label, 'quezon city') !== false) {
                break 2;
            }
        }
    }

    if (empty($last)) return [];

    usort($last, static function ($a, $b) use ($input) {
        return score_item((array)$b, $input) <=> score_item((array)$a, $input);
    });

    return $last;
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

