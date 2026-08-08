<?php
declare(strict_types=1);

if (!function_exists('ers_geocode_plus_code_alphabet')) {
    function ers_geocode_plus_code_alphabet(): string
    {
        return '23456789CFGHJMPQRVWX';
    }
}

if (!function_exists('ers_geocode_extract_plus_code')) {
    function ers_geocode_extract_plus_code(string $text): ?string
    {
        if (!preg_match('/\b([23456789CFGHJMPQRVWX]{2,8}\+[23456789CFGHJMPQRVWX]{2,})\b/i', $text, $matches)) {
            return null;
        }

        return strtoupper($matches[1]);
    }
}

if (!function_exists('ers_geocode_encode_plus_code_area_prefix')) {
    function ers_geocode_encode_plus_code_area_prefix(float $latitude, float $longitude, int $length): string
    {
        $alphabet = ers_geocode_plus_code_alphabet();
        $lat = min(max($latitude, -90.0), 90.0) + 90.0;
        $lng = min(max($longitude, -180.0), 180.0) + 180.0;
        $resolution = 20.0;
        $code = '';

        while (strlen($code) < $length) {
            $latDigit = (int)floor($lat / $resolution);
            $lngDigit = (int)floor($lng / $resolution);
            $code .= $alphabet[$latDigit] . $alphabet[$lngDigit];
            $lat -= $latDigit * $resolution;
            $lng -= $lngDigit * $resolution;
            $resolution /= 20.0;
        }

        return substr($code, 0, $length);
    }
}

if (!function_exists('ers_geocode_decode_plus_code')) {
    function ers_geocode_decode_plus_code(string $code): ?array
    {
        $alphabet = ers_geocode_plus_code_alphabet();
        $code = strtoupper(trim($code));
        $separatorPosition = strpos($code, '+');
        if ($separatorPosition === false) {
            return null;
        }

        if ($separatorPosition < 8) {
            $missingLength = 8 - $separatorPosition;
            $prefix = ers_geocode_encode_plus_code_area_prefix(14.6760, 121.0437, $missingLength);
            $code = $prefix . $code;
        }

        $clean = str_replace(['+', '0'], '', $code);
        if (strlen($clean) < 4) {
            return null;
        }

        $lat = -90.0;
        $lng = -180.0;
        $resolution = 20.0;
        $lastResolution = 20.0;
        $pairLength = min(strlen($clean), 10);

        for ($i = 0; $i + 1 < $pairLength; $i += 2) {
            $latIndex = strpos($alphabet, $clean[$i]);
            $lngIndex = strpos($alphabet, $clean[$i + 1]);
            if ($latIndex === false || $lngIndex === false) {
                return null;
            }
            $lastResolution = $resolution;
            $lat += (int)$latIndex * $resolution;
            $lng += (int)$lngIndex * $resolution;
            $resolution /= 20.0;
        }

        if (strlen($clean) > 10) {
            $rowResolution = $lastResolution / 5.0;
            $colResolution = $lastResolution / 4.0;
            for ($i = 10; $i < strlen($clean); $i++) {
                if ($i > 10) {
                    $rowResolution /= 5.0;
                    $colResolution /= 4.0;
                }
                $index = strpos($alphabet, $clean[$i]);
                if ($index === false) {
                    return null;
                }
                $row = intdiv((int)$index, 4);
                $col = (int)$index % 4;
                $lat += $row * $rowResolution;
                $lng += $col * $colResolution;
            }
            return [$lat + ($rowResolution / 2.0), $lng + ($colResolution / 2.0)];
        }

        return [$lat + ($lastResolution / 2.0), $lng + ($lastResolution / 2.0)];
    }
}

if (!function_exists('ers_geocode_plus_code_to_coordinates')) {
    function ers_geocode_plus_code_to_coordinates(string $text): ?array
    {
        $plusCode = ers_geocode_extract_plus_code($text);
        return $plusCode !== null ? ers_geocode_decode_plus_code($plusCode) : null;
    }
}

if (!function_exists('ers_geocode_cache_path')) {
    function ers_geocode_cache_path(): string
    {
        return dirname(__DIR__) . '/data/geocode_cache.json';
    }
}

if (!function_exists('ers_geocode_make_cache_key')) {
    function ers_geocode_make_cache_key(string $query, int $limit, bool $strict): string
    {
        $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $query)));
        return hash('sha256', $normalized . '|' . $limit . '|' . ($strict ? '1' : '0'));
    }
}

if (!function_exists('ers_geocode_load_cache')) {
    function ers_geocode_load_cache(): array
    {
        $path = ers_geocode_cache_path();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('ers_geocode_save_cache')) {
    function ers_geocode_save_cache(array $cache): void
    {
        if (count($cache) > 800) {
            uasort($cache, static function ($a, $b) {
                return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0);
            });
            $cache = array_slice($cache, 0, 800, true);
        }

        $path = ers_geocode_cache_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        @rename($tmp, $path);
    }
}

if (!function_exists('ers_geocode_cache_lookup')) {
    function ers_geocode_cache_lookup(string $query, int $limit, bool $strict): ?array
    {
        $cache = ers_geocode_load_cache();
        $key = ers_geocode_make_cache_key($query, $limit, $strict);
        $entry = $cache[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $ts = (int)($entry['ts'] ?? 0);
        if ($ts <= 0 || (time() - $ts) > 86400) {
            return null;
        }

        $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
        return $items !== [] ? $items : null;
    }
}

if (!function_exists('ers_geocode_cache_store')) {
    function ers_geocode_cache_store(string $query, int $limit, bool $strict, array $items): void
    {
        $cache = ers_geocode_load_cache();
        $key = ers_geocode_make_cache_key($query, $limit, $strict);
        $cache[$key] = [
            'ts' => time(),
            'items' => $items,
        ];
        ers_geocode_save_cache($cache);
    }
}

if (!function_exists('ers_geocode_build_url')) {
    function ers_geocode_build_url(string $query, int $limit, bool $strict): string
    {
        $params = [
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'limit' => (string)$limit,
            'q' => $query,
        ];

        return 'https://nominatim.openstreetmap.org/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('ers_geocode_http_json')) {
    function ers_geocode_http_json(string $url): ?array
    {
        $headers = [
            'Accept: application/json',
            'User-Agent: ERS-GeocodeHelper/1.0 (+http://localhost/ERS)',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
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
                'timeout' => 4,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('ers_geocode_score_item')) {
    function ers_geocode_score_item(array $item, string $query): float
    {
        $label = strtolower((string)($item['display_name'] ?? ''));
        $q = strtolower($query);
        $score = isset($item['importance']) ? (float)$item['importance'] : 0.0;
        if ($q !== '' && strpos($label, $q) !== false) {
            $score += 1.5;
        }
        return $score;
    }
}

if (!function_exists('ers_geocode_fetch_candidates')) {
    function ers_geocode_fetch_candidates(string $query, int $limit, bool $strict): array
    {
        $cached = ers_geocode_cache_lookup($query, $limit, $strict);
        if ($cached !== null) {
            return $cached;
        }

        $url = ers_geocode_build_url($query, $limit, $strict);
        $data = ers_geocode_http_json($url);
        if (!is_array($data)) {
            return [];
        }

        if ($data !== []) {
            ers_geocode_cache_store($query, $limit, $strict, $data);
        }

        return $data;
    }
}

if (!function_exists('ers_geocode_location_to_coordinates')) {
    function ers_geocode_location_to_coordinates(string $location): ?array
    {
        $input = trim((string)preg_replace('/\s+/', ' ', $location));
        if ($input === '') {
            return null;
        }

        if (preg_match('/^\s*(?:lat(?:itude)?\s*[:=]?\s*)?(-?\d{1,3}(?:\.\d+)?)\s*[, ]\s*(?:lon(?:gitude)?|lng)?\s*[:=]?\s*(-?\d{1,3}(?:\.\d+)?)\s*$/i', $input, $matches)) {
            $lat = (float)$matches[1];
            $lng = (float)$matches[2];
            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                return [$lat, $lng];
            }
        }

        $plusCodeCoordinates = ers_geocode_plus_code_to_coordinates($input);
        if ($plusCodeCoordinates !== null) {
            return $plusCodeCoordinates;
        }

        $attempts = [
            ['query' => $input, 'strict' => false],
        ];

        $best = null;
        $bestScore = -INF;
        foreach ($attempts as $attempt) {
            $items = ers_geocode_fetch_candidates((string)$attempt['query'], 3, (bool)$attempt['strict']);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $lat = isset($item['lat']) ? (float)$item['lat'] : null;
                $lng = isset($item['lon']) ? (float)$item['lon'] : null;
                if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    continue;
                }
                $score = ers_geocode_score_item($item, $input);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [$lat, $lng];
                }
            }
            if ($best !== null) {
                break;
            }
        }

        return $best;
    }
}
