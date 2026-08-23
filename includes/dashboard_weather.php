<?php
declare(strict_types=1);

/**
 * Shared Quezon City dashboard weather service.
 *
 * Primary source: Open-Meteo forecast API using exact configured coordinates.
 * No API key is stored in the repository. Results are cached server-side so the
 * dashboard does not perform external calls on every request and can use a
 * recent cached observation during a temporary provider outage.
 */

if (!function_exists('ers_dashboard_weather_config')) {
    /** @return array{latitude:float,longitude:float,location:string,timezone:string,cache_ttl:int,stale_ttl:int} */
    function ers_dashboard_weather_config(): array
    {
        $latitude = filter_var(getenv('ERS_WEATHER_LAT') ?: '14.6507', FILTER_VALIDATE_FLOAT);
        $longitude = filter_var(getenv('ERS_WEATHER_LON') ?: '121.0494', FILTER_VALIDATE_FLOAT);

        if ($latitude === false || $latitude < -90 || $latitude > 90) {
            $latitude = 14.6507;
        }
        if ($longitude === false || $longitude < -180 || $longitude > 180) {
            $longitude = 121.0494;
        }

        $location = trim((string)(getenv('ERS_WEATHER_LOCATION') ?: 'Quezon City Command Center'));
        if ($location === '') {
            $location = 'Quezon City Command Center';
        }

        return [
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
            'location' => $location,
            'timezone' => 'Asia/Manila',
            'cache_ttl' => 300,
            'stale_ttl' => 21600,
        ];
    }
}

if (!function_exists('ers_dashboard_weather_http_get')) {
    /** @return array{status:int,body:string,error:string} */
    function ers_dashboard_weather_http_get(string $url): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle !== false) {
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 2,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_USERAGENT => 'AlertaraQC-AdminDashboard/2.0',
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $body = curl_exec($handle);
                $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $error = curl_error($handle);
                curl_close($handle);

                return [
                    'status' => $status,
                    'body' => is_string($body) ? $body : '',
                    'error' => $error,
                ];
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: AlertaraQC-AdminDashboard/2.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string)$headerLine, $matches)) {
                $status = (int)$matches[1];
            }
        }

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $body === false ? 'HTTP request failed' : '',
        ];
    }
}

if (!function_exists('ers_dashboard_weather_code_details')) {
    /** @return array{condition:string,icon:string,theme:string,severity:string} */
    function ers_dashboard_weather_code_details(int $code, bool $isDay = true): array
    {
        $dayIcon = $isDay ? 'fa-sun' : 'fa-moon';
        $partlyIcon = $isDay ? 'fa-cloud-sun' : 'fa-cloud-moon';

        return match ($code) {
            0 => ['condition' => 'Clear sky', 'icon' => $dayIcon, 'theme' => 'weather-clear', 'severity' => 'normal'],
            1 => ['condition' => 'Mainly clear', 'icon' => $partlyIcon, 'theme' => 'weather-clear', 'severity' => 'normal'],
            2 => ['condition' => 'Partly cloudy', 'icon' => $partlyIcon, 'theme' => 'weather-cloudy', 'severity' => 'normal'],
            3 => ['condition' => 'Overcast', 'icon' => 'fa-cloud', 'theme' => 'weather-cloudy', 'severity' => 'normal'],
            45, 48 => ['condition' => 'Foggy', 'icon' => 'fa-smog', 'theme' => 'weather-mist', 'severity' => 'advisory'],
            51 => ['condition' => 'Light drizzle', 'icon' => 'fa-cloud-rain', 'theme' => 'weather-rain', 'severity' => 'advisory'],
            53, 55 => ['condition' => 'Drizzle', 'icon' => 'fa-cloud-rain', 'theme' => 'weather-rain', 'severity' => 'advisory'],
            56, 57 => ['condition' => 'Freezing drizzle', 'icon' => 'fa-cloud-rain', 'theme' => 'weather-rain', 'severity' => 'warning'],
            61 => ['condition' => 'Light rain', 'icon' => 'fa-cloud-rain', 'theme' => 'weather-rain', 'severity' => 'advisory'],
            63 => ['condition' => 'Moderate rain', 'icon' => 'fa-cloud-showers-heavy', 'theme' => 'weather-rain', 'severity' => 'warning'],
            65 => ['condition' => 'Heavy rain', 'icon' => 'fa-cloud-showers-heavy', 'theme' => 'weather-rain', 'severity' => 'warning'],
            66, 67 => ['condition' => 'Freezing rain', 'icon' => 'fa-cloud-rain', 'theme' => 'weather-rain', 'severity' => 'warning'],
            71, 73, 75, 77 => ['condition' => 'Snow', 'icon' => 'fa-snowflake', 'theme' => 'weather-mist', 'severity' => 'warning'],
            80 => ['condition' => 'Light rain showers', 'icon' => 'fa-cloud-rain', 'theme' => 'weather-rain', 'severity' => 'advisory'],
            81 => ['condition' => 'Rain showers', 'icon' => 'fa-cloud-showers-heavy', 'theme' => 'weather-rain', 'severity' => 'warning'],
            82 => ['condition' => 'Violent rain showers', 'icon' => 'fa-cloud-showers-heavy', 'theme' => 'weather-storm', 'severity' => 'critical'],
            85, 86 => ['condition' => 'Snow showers', 'icon' => 'fa-snowflake', 'theme' => 'weather-mist', 'severity' => 'warning'],
            95 => ['condition' => 'Thunderstorm', 'icon' => 'fa-cloud-bolt', 'theme' => 'weather-storm', 'severity' => 'warning'],
            96, 99 => ['condition' => 'Thunderstorm with hail', 'icon' => 'fa-cloud-bolt', 'theme' => 'weather-storm', 'severity' => 'critical'],
            default => ['condition' => 'Weather condition unavailable', 'icon' => 'fa-cloud', 'theme' => 'weather-cloudy', 'severity' => 'normal'],
        };
    }
}

if (!function_exists('ers_dashboard_weather_wind_direction')) {
    function ers_dashboard_weather_wind_direction(?float $degrees): string
    {
        if ($degrees === null || !is_finite($degrees)) {
            return '';
        }

        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $normalized = fmod(($degrees + 360.0), 360.0);
        $index = (int)round($normalized / 45.0) % 8;
        return $directions[$index];
    }
}

if (!function_exists('ers_dashboard_weather_datetime')) {
    function ers_dashboard_weather_datetime(?string $value, string $timezone): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone($timezone));
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ers_dashboard_weather_closest_hour_index')) {
    /** @param list<mixed> $times */
    function ers_dashboard_weather_closest_hour_index(array $times, ?DateTimeImmutable $current, string $timezone): ?int
    {
        if ($times === [] || $current === null) {
            return null;
        }

        $bestIndex = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($times as $index => $timeValue) {
            $candidate = ers_dashboard_weather_datetime(is_scalar($timeValue) ? (string)$timeValue : '', $timezone);
            if ($candidate === null) {
                continue;
            }
            $distance = abs($candidate->getTimestamp() - $current->getTimestamp());
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = (int)$index;
            }
        }
        return $bestIndex;
    }
}

if (!function_exists('ers_dashboard_weather_cache_path')) {
    function ers_dashboard_weather_cache_path(array $config): string
    {
        $identity = hash('sha256', $config['latitude'] . '|' . $config['longitude'] . '|' . $config['timezone']);
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'alertaraqc_weather_' . substr($identity, 0, 16) . '.json';
    }
}

if (!function_exists('ers_dashboard_weather_read_cache')) {
    /** @return array<string,mixed>|null */
    function ers_dashboard_weather_read_cache(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('ers_dashboard_weather_write_cache')) {
    /** @param array<string,mixed> $payload */
    function ers_dashboard_weather_write_cache(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) {
            return;
        }

        $temporary = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $encoded, LOCK_EX) !== false) {
            @chmod($temporary, 0640);
            @rename($temporary, $path);
        }
    }
}

if (!function_exists('ers_dashboard_weather_build_url')) {
    function ers_dashboard_weather_build_url(array $config): string
    {
        $params = [
            'latitude' => number_format((float)$config['latitude'], 4, '.', ''),
            'longitude' => number_format((float)$config['longitude'], 4, '.', ''),
            'current' => implode(',', [
                'temperature_2m',
                'relative_humidity_2m',
                'apparent_temperature',
                'is_day',
                'precipitation',
                'rain',
                'weather_code',
                'cloud_cover',
                'wind_speed_10m',
                'wind_direction_10m',
                'wind_gusts_10m',
            ]),
            'hourly' => implode(',', [
                'temperature_2m',
                'precipitation_probability',
                'weather_code',
                'visibility',
            ]),
            'daily' => implode(',', [
                'temperature_2m_max',
                'temperature_2m_min',
                'precipitation_probability_max',
            ]),
            'timezone' => (string)$config['timezone'],
            'forecast_days' => '2',
        ];

        return 'https://api.open-meteo.com/v1/forecast?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('ers_dashboard_weather_normalize')) {
    /** @param array<string,mixed> $providerData @return array<string,mixed> */
    function ers_dashboard_weather_normalize(array $providerData, array $config): array
    {
        $current = is_array($providerData['current'] ?? null) ? $providerData['current'] : [];
        $hourly = is_array($providerData['hourly'] ?? null) ? $providerData['hourly'] : [];
        $daily = is_array($providerData['daily'] ?? null) ? $providerData['daily'] : [];

        $currentTime = ers_dashboard_weather_datetime((string)($current['time'] ?? ''), (string)$config['timezone']);
        if ($currentTime === null || !isset($current['temperature_2m'], $current['weather_code'])) {
            throw new RuntimeException('Weather provider returned an incomplete current observation.');
        }

        $weatherCode = (int)$current['weather_code'];
        $isDay = (int)($current['is_day'] ?? 1) === 1;
        $details = ers_dashboard_weather_code_details($weatherCode, $isDay);

        $hourTimes = is_array($hourly['time'] ?? null) ? array_values($hourly['time']) : [];
        $currentHourIndex = ers_dashboard_weather_closest_hour_index($hourTimes, $currentTime, (string)$config['timezone']);
        $nextHourIndex = null;
        if ($currentHourIndex !== null && isset($hourTimes[$currentHourIndex + 1])) {
            $nextHourIndex = $currentHourIndex + 1;
        } elseif ($currentHourIndex !== null) {
            $nextHourIndex = $currentHourIndex;
        }

        $hourlyRain = is_array($hourly['precipitation_probability'] ?? null)
            ? array_values($hourly['precipitation_probability'])
            : [];
        $hourlyVisibility = is_array($hourly['visibility'] ?? null)
            ? array_values($hourly['visibility'])
            : [];
        $hourlyCodes = is_array($hourly['weather_code'] ?? null)
            ? array_values($hourly['weather_code'])
            : [];

        $rainChance = $currentHourIndex !== null && isset($hourlyRain[$currentHourIndex])
            ? (int)round((float)$hourlyRain[$currentHourIndex])
            : null;
        $visibilityKm = $currentHourIndex !== null && isset($hourlyVisibility[$currentHourIndex])
            ? round(max(0.0, (float)$hourlyVisibility[$currentHourIndex]) / 1000.0, 1)
            : null;

        $nextCode = $nextHourIndex !== null && isset($hourlyCodes[$nextHourIndex])
            ? (int)$hourlyCodes[$nextHourIndex]
            : $weatherCode;
        $nextDetails = ers_dashboard_weather_code_details($nextCode, $isDay);
        $nextTime = $nextHourIndex !== null && isset($hourTimes[$nextHourIndex])
            ? ers_dashboard_weather_datetime((string)$hourTimes[$nextHourIndex], (string)$config['timezone'])
            : null;

        $dailyMax = is_array($daily['temperature_2m_max'] ?? null) ? array_values($daily['temperature_2m_max']) : [];
        $dailyMin = is_array($daily['temperature_2m_min'] ?? null) ? array_values($daily['temperature_2m_min']) : [];
        $dailyRainMax = is_array($daily['precipitation_probability_max'] ?? null)
            ? array_values($daily['precipitation_probability_max'])
            : [];

        $windDirectionDegrees = isset($current['wind_direction_10m']) ? (float)$current['wind_direction_10m'] : null;
        $fetchedAt = new DateTimeImmutable('now', new DateTimeZone((string)$config['timezone']));

        return [
            'ok' => true,
            'provider' => 'Open-Meteo',
            'location' => (string)$config['location'],
            'coordinates' => [
                'latitude' => (float)$config['latitude'],
                'longitude' => (float)$config['longitude'],
            ],
            'timezone' => (string)$config['timezone'],
            'observation' => [
                'time' => $currentTime->format(DateTimeInterface::ATOM),
                'label' => $currentTime->format('M j, Y g:i A'),
                'weather_code' => $weatherCode,
                'condition' => $details['condition'],
                'icon' => $details['icon'],
                'theme' => $details['theme'],
                'severity' => $details['severity'],
                'is_day' => $isDay,
                'temperature_c' => round((float)$current['temperature_2m'], 1),
                'apparent_temperature_c' => isset($current['apparent_temperature'])
                    ? round((float)$current['apparent_temperature'], 1)
                    : null,
                'humidity_pct' => isset($current['relative_humidity_2m'])
                    ? (int)round((float)$current['relative_humidity_2m'])
                    : null,
                'precipitation_mm' => isset($current['precipitation'])
                    ? round((float)$current['precipitation'], 1)
                    : null,
                'rain_mm' => isset($current['rain']) ? round((float)$current['rain'], 1) : null,
                'rain_probability_pct' => $rainChance,
                'cloud_cover_pct' => isset($current['cloud_cover'])
                    ? (int)round((float)$current['cloud_cover'])
                    : null,
                'wind_kmh' => isset($current['wind_speed_10m'])
                    ? round((float)$current['wind_speed_10m'], 1)
                    : null,
                'wind_gust_kmh' => isset($current['wind_gusts_10m'])
                    ? round((float)$current['wind_gusts_10m'], 1)
                    : null,
                'wind_direction_degrees' => $windDirectionDegrees,
                'wind_direction' => ers_dashboard_weather_wind_direction($windDirectionDegrees),
                'visibility_km' => $visibilityKm,
            ],
            'next_hour' => [
                'time' => $nextTime?->format(DateTimeInterface::ATOM),
                'label' => $nextTime?->format('g:i A') ?? 'Unavailable',
                'condition' => $nextDetails['condition'],
                'weather_code' => $nextCode,
                'rain_probability_pct' => $nextHourIndex !== null && isset($hourlyRain[$nextHourIndex])
                    ? (int)round((float)$hourlyRain[$nextHourIndex])
                    : null,
            ],
            'today' => [
                'high_c' => isset($dailyMax[0]) ? round((float)$dailyMax[0], 1) : null,
                'low_c' => isset($dailyMin[0]) ? round((float)$dailyMin[0], 1) : null,
                'max_rain_probability_pct' => isset($dailyRainMax[0])
                    ? (int)round((float)$dailyRainMax[0])
                    : null,
            ],
            'fetched_at' => $fetchedAt->format(DateTimeInterface::ATOM),
            'fetched_at_label' => $fetchedAt->format('M j, Y g:i A'),
            'cache_state' => 'live',
            'stale' => false,
        ];
    }
}

if (!function_exists('ers_dashboard_weather_get')) {
    /** @return array<string,mixed> */
    function ers_dashboard_weather_get(bool $forceRefresh = false): array
    {
        $config = ers_dashboard_weather_config();
        $cachePath = ers_dashboard_weather_cache_path($config);
        $cached = ers_dashboard_weather_read_cache($cachePath);
        $now = time();
        $cachedAt = is_array($cached) ? (int)($cached['cached_at'] ?? 0) : 0;
        $cachedPayload = is_array($cached['payload'] ?? null) ? $cached['payload'] : null;

        if (!$forceRefresh && $cachedPayload !== null && $cachedAt > 0 && ($now - $cachedAt) <= (int)$config['cache_ttl']) {
            $cachedPayload['cache_state'] = 'fresh-cache';
            $cachedPayload['stale'] = false;
            return $cachedPayload;
        }

        try {
            $result = ers_dashboard_weather_http_get(ers_dashboard_weather_build_url($config));
            if ($result['status'] < 200 || $result['status'] >= 300 || $result['body'] === '') {
                throw new RuntimeException(
                    'Weather provider request failed'
                    . ($result['status'] > 0 ? ' (HTTP ' . $result['status'] . ')' : '')
                    . ($result['error'] !== '' ? ': ' . $result['error'] : '.')
                );
            }

            $decoded = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Weather provider returned an invalid payload.');
            }
            if (isset($decoded['error']) && $decoded['error']) {
                throw new RuntimeException((string)($decoded['reason'] ?? 'Weather provider error.'));
            }

            $payload = ers_dashboard_weather_normalize($decoded, $config);
            ers_dashboard_weather_write_cache($cachePath, [
                'cached_at' => $now,
                'payload' => $payload,
            ]);
            return $payload;
        } catch (Throwable $e) {
            error_log('admin dashboard weather failed: ' . $e->getMessage());

            if ($cachedPayload !== null && $cachedAt > 0 && ($now - $cachedAt) <= (int)$config['stale_ttl']) {
                $cachedPayload['cache_state'] = 'stale-cache';
                $cachedPayload['stale'] = true;
                $cachedPayload['warning'] = 'Live weather is temporarily unavailable. Showing the most recent cached observation.';
                return $cachedPayload;
            }

            return [
                'ok' => false,
                'error' => 'Live weather is temporarily unavailable.',
                'location' => (string)$config['location'],
                'coordinates' => [
                    'latitude' => (float)$config['latitude'],
                    'longitude' => (float)$config['longitude'],
                ],
                'timezone' => (string)$config['timezone'],
                'provider' => 'Open-Meteo',
                'cache_state' => 'unavailable',
                'stale' => false,
            ];
        }
    }
}
