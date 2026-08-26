<?php
// Live tracking feed for interagency partners: for every unit currently
// dispatched to an incident, returns the unit's latest GPS position and the
// lat/lng of the incident it's assigned to. Auth matches the rest of
// system_API (X-ERS-API-Key / X-API-Key / Bearer token / ?api_key=).
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

$auth = ers_external_authenticate();
$pdo = ers_external_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    ers_external_json(405, [
        'success' => false,
        'error' => 'GET method required',
    ]);
}

try {
    $rows = ers_live_tracking_fetch($pdo);

    ers_external_json(200, [
        'success' => true,
        'generated_at' => date('c'),
        'count' => count($rows),
        'items' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('live_tracking.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to fetch live tracking data',
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function ers_live_tracking_fetch(PDO $pdo): array
{
    $schema = ers_live_tracking_schema($pdo);

    if (!ers_live_tracking_has_table($schema, 'units')) {
        return [];
    }

    $hasCurrentIncidentId = ers_live_tracking_has_column($schema, 'units', 'current_incident_id');
    if (!$hasCurrentIncidentId) {
        // Nothing to report an "assignment" against without this column.
        return [];
    }

    $hasIncidents = ers_live_tracking_has_table($schema, 'incidents')
        && ers_live_tracking_has_column($schema, 'incidents', 'id');
    if (!$hasIncidents) {
        return [];
    }

    // Latest GPS ping per unit, same pattern as units_list.php — including
    // skipping known seed/placeholder coordinates so a stale test ping
    // never wins over a real, older-but-genuine GPS fix.
    $locationJoin = '';
    $unitLatExpr = 'NULL';
    $unitLngExpr = 'NULL';
    $lastRecordedExpr = 'NULL';
    $locationSourceExpr = 'NULL';
    $canJoinLocation = ers_live_tracking_has_table($schema, 'unit_locations')
        && ers_live_tracking_has_column($schema, 'unit_locations', 'id')
        && ers_live_tracking_has_column($schema, 'unit_locations', 'unit_id')
        && ers_live_tracking_has_column($schema, 'unit_locations', 'recorded_at')
        && ers_live_tracking_has_column($schema, 'unit_locations', 'latitude')
        && ers_live_tracking_has_column($schema, 'unit_locations', 'longitude');

    if ($canJoinLocation) {
        $validLocationWhere = ' AND NOT ' . ers_live_tracking_fallback_coordinate_condition('ul2');
        $hasSource = ers_live_tracking_has_column($schema, 'unit_locations', 'source');
        // 'responder_route' rows are simulated waypoints saved by the route
        // preview/testing feature (save-route-point.php), not real device
        // GPS. Real pings are tagged 'responder_gps'. Prefer a genuine GPS
        // fix no matter how old over a simulated route point, and only
        // fall back to a route point if the unit has never sent real GPS.
        $orderBy = $hasSource
            ? "ORDER BY (ul2.source = 'responder_route') ASC, ul2.recorded_at DESC, ul2.id DESC"
            : 'ORDER BY ul2.recorded_at DESC, ul2.id DESC';
        $locationJoin = " LEFT JOIN unit_locations ul
            ON ul.id = (
                SELECT ul2.id
                FROM unit_locations ul2
                WHERE ul2.unit_id = u.id
                {$validLocationWhere}
                {$orderBy}
                LIMIT 1
            )";
        $unitLatExpr = 'ul.latitude';
        $unitLngExpr = 'ul.longitude';
        $lastRecordedExpr = 'ul.recorded_at';
        $locationSourceExpr = $hasSource ? 'ul.source' : 'NULL';
    } elseif (
        ers_live_tracking_has_column($schema, 'units', 'latitude')
        && ers_live_tracking_has_column($schema, 'units', 'longitude')
    ) {
        // Fall back to the stored coordinate on the unit row itself,
        // still excluding the same known seed/placeholder values.
        $storedFallback = ers_live_tracking_fallback_coordinate_condition('u');
        $unitLatExpr = "CASE WHEN {$storedFallback} THEN NULL ELSE u.latitude END";
        $unitLngExpr = "CASE WHEN {$storedFallback} THEN NULL ELSE u.longitude END";
    }

    // Responder name/unit code, so the other side can label the marker.
    $responderJoin = '';
    $responderNameExpr = 'NULL';
    $hasResponderJoin = ers_live_tracking_has_table($schema, 'users')
        && ers_live_tracking_has_column($schema, 'users', 'id')
        && ers_live_tracking_has_column($schema, 'users', 'role')
        && ers_live_tracking_has_column($schema, 'users', 'unit_code')
        && ers_live_tracking_has_column($schema, 'users', 'name');
    if ($hasResponderJoin) {
        $responderJoin = " LEFT JOIN (
            SELECT usr_base.id, usr_base.unit_code, usr_base.name
            FROM users usr_base
            INNER JOIN (
                SELECT UPPER(TRIM(unit_code)) AS unit_code_key, MAX(id) AS max_id
                FROM users
                WHERE role = 'responder'
                  AND unit_code IS NOT NULL
                  AND TRIM(unit_code) <> ''
                GROUP BY UPPER(TRIM(unit_code))
            ) latest_usr ON latest_usr.max_id = usr_base.id
        ) usr ON UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))";
        $responderNameExpr = 'usr.name';
    }

    $incidentCodeExpr = ers_live_tracking_has_column($schema, 'incidents', 'reference_no') ? 'i.reference_no' : 'NULL';
    $incidentTypeExpr = ers_live_tracking_has_column($schema, 'incidents', 'type') ? 'i.type' : 'NULL';
    $incidentLocationExpr = ers_live_tracking_has_column($schema, 'incidents', 'location_address') ? 'i.location_address' : 'NULL';
    $incidentLatExpr = ers_live_tracking_has_column($schema, 'incidents', 'latitude') ? 'i.latitude' : 'NULL';
    $incidentLngExpr = ers_live_tracking_has_column($schema, 'incidents', 'longitude') ? 'i.longitude' : 'NULL';

    // Some incidents only have coordinates on the originating call record —
    // fall back to that the same way units_list.php does, so "null" isn't
    // reported just because the incident row itself wasn't geocoded.
    $callJoin = '';
    $hasCallJoin = ers_live_tracking_has_column($schema, 'incidents', 'reported_by_call_id')
        && ers_live_tracking_has_table($schema, 'calls')
        && ers_live_tracking_has_column($schema, 'calls', 'id');
    if ($hasCallJoin) {
        $callJoin = ' LEFT JOIN calls c ON c.id = i.reported_by_call_id';
        $callLatExpr = ers_live_tracking_has_column($schema, 'calls', 'latitude') ? 'c.latitude' : 'NULL';
        $callLngExpr = ers_live_tracking_has_column($schema, 'calls', 'longitude') ? 'c.longitude' : 'NULL';
        if ($callLatExpr !== 'NULL') {
            $incidentLatExpr = "COALESCE({$incidentLatExpr}, {$callLatExpr})";
        }
        if ($callLngExpr !== 'NULL') {
            $incidentLngExpr = "COALESCE({$incidentLngExpr}, {$callLngExpr})";
        }
    }

    $statusExpr = ers_live_tracking_has_column($schema, 'units', 'status') ? 'u.status' : "'unknown'";

    $sql = "SELECT
                u.id AS unit_id,
                u.identifier AS unit_identifier,
                {$responderNameExpr} AS responder_name,
                {$statusExpr} AS unit_status,
                {$unitLatExpr} AS responder_latitude,
                {$unitLngExpr} AS responder_longitude,
                {$lastRecordedExpr} AS responder_location_recorded_at,
                {$locationSourceExpr} AS responder_location_source,
                u.current_incident_id AS incident_id,
                {$incidentCodeExpr} AS incident_code,
                {$incidentTypeExpr} AS incident_type,
                {$incidentLocationExpr} AS incident_address,
                {$incidentLatExpr} AS incident_latitude,
                {$incidentLngExpr} AS incident_longitude
            FROM units u
            {$locationJoin}
            {$responderJoin}
            INNER JOIN incidents i ON i.id = u.current_incident_id
            {$callJoin}
            WHERE u.current_incident_id IS NOT NULL
            ORDER BY u.identifier";

    $stmt = $pdo->query($sql);
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // The dispatcher's own live map trusts Firebase Realtime Database
    // (node "live_locations") as the true live GPS feed, not MySQL —
    // MySQL only gets a periodic/occasional write. Overlay it the same way
    // so this feed matches what dispatchers actually see on their screen.
    $firebaseByUnitCode = ers_live_tracking_firebase_locations();

    foreach ($rows as &$row) {
        $unitKey = strtoupper(trim((string)($row['unit_identifier'] ?? '')));
        if ($unitKey !== '' && isset($firebaseByUnitCode[$unitKey])) {
            $live = $firebaseByUnitCode[$unitKey];
            $status = strtolower(trim((string)($live['status'] ?? '')));
            if (!in_array($status, ['offline', 'logged_out', 'inactive'], true)) {
                $row['responder_latitude'] = $live['lat'];
                $row['responder_longitude'] = $live['lng'];
                $row['responder_location_recorded_at'] = date('c');
                $row['responder_location_source'] = 'firebase_live';
                if (!empty($live['responderName'])) {
                    $row['responder_name'] = $live['responderName'];
                }
            }
        }

        foreach (['responder_latitude', 'responder_longitude', 'incident_latitude', 'incident_longitude'] as $coordKey) {
            if ($row[$coordKey] !== null) {
                $row[$coordKey] = (float)$row[$coordKey];
            }
        }
        $row['unit_id'] = (int)$row['unit_id'];
        $row['incident_id'] = (int)$row['incident_id'];

        // Road route from the responder's current position to the incident,
        // same OSRM backend the dispatcher map already uses (js/routing.js,
        // dispatch.php). Cached per unit+incident so a fast-polling partner
        // doesn't hammer the public OSRM server or pile up temp files.
        $route = ers_live_tracking_route_polyline(
            $row['unit_id'],
            $row['incident_id'],
            $row['responder_latitude'],
            $row['responder_longitude'],
            $row['incident_latitude'],
            $row['incident_longitude']
        );
        $row['route_polyline'] = $route['polyline'] ?? null;
        $row['route_distance_km'] = $route['distance_km'] ?? null;
        $row['route_duration_min'] = $route['duration_min'] ?? null;
    }
    unset($row);

    return $rows;
}

/**
 * Road route (turn-by-turn geometry) from a responder's live position to
 * their assigned incident, via the same public OSRM instance the dispatcher
 * web map uses. Never throws — returns null on any failure so a slow/down
 * OSRM never breaks this endpoint, it just omits the route for that unit.
 *
 * Cached to disk per unit_id+incident_id (not per raw coordinate) so the
 * file count stays bounded to "one file per currently-dispatched unit"
 * regardless of how often a consumer polls this endpoint.
 *
 * @return array{polyline: array<int, array{0: float, 1: float}>, distance_km: float, duration_min: float}|null
 */
function ers_live_tracking_route_polyline(
    int $unitId,
    int $incidentId,
    ?float $fromLat,
    ?float $fromLng,
    ?float $toLat,
    ?float $toLng
): ?array {
    if ($fromLat === null || $fromLng === null || $toLat === null || $toLng === null) {
        return null;
    }

    $ttl = (int)(getenv('ERS_ROUTE_POLYLINE_CACHE_TTL') ?: 45); // seconds
    $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . "ers_route_{$unitId}_{$incidentId}.json";

    $now = time();
    if (is_file($cachePath) && is_readable($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (
            is_array($cached)
            && isset($cached['cached_at'], $cached['data'])
            && (int)$cached['cached_at'] > $now - $ttl
        ) {
            return $cached['data'];
        }
    }

    try {
        $url = sprintf(
            'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=full&geometries=geojson&steps=false&alternatives=false',
            $fromLng,
            $fromLat,
            $toLng,
            $toLat
        );

        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $coords = $decoded['routes'][0]['geometry']['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }

        // OSRM returns [lng, lat] pairs; flip to [lat, lng] to match every
        // other coordinate field this endpoint already returns.
        $polyline = [];
        foreach ($coords as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $polyline[] = [(float)$pair[1], (float)$pair[0]];
        }
        if (count($polyline) < 2) {
            return null;
        }

        $meters = (float)($decoded['routes'][0]['distance'] ?? 0);
        $seconds = (float)($decoded['routes'][0]['duration'] ?? 0);

        $result = [
            'polyline' => $polyline,
            'distance_km' => round($meters / 1000, 2),
            'duration_min' => round($seconds / 60, 1),
        ];

        @file_put_contents($cachePath, json_encode(['cached_at' => $now, 'data' => $result]), LOCK_EX);

        return $result;
    } catch (Throwable $e) {
        error_log('live_tracking route_polyline failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Reads the Firebase RTDB "live_locations" node — the same source the
 * dispatcher web map subscribes to for real-time GPS — keyed by unit code.
 * Returns [] (never throws) if Firebase credentials aren't configured or
 * the request fails, so this endpoint degrades to MySQL-only rather than
 * breaking entirely.
 *
 * @return array<string, array{lat: float, lng: float, status: ?string, responderName: ?string}>
 */
function ers_live_tracking_firebase_locations(): array
{
    try {
        $credentials = ers_live_tracking_firebase_credentials();
        if ($credentials === null) {
            return [];
        }

        $token = ers_live_tracking_firebase_token($credentials);
        if ($token === null) {
            return [];
        }

        $databaseUrl = rtrim(
            (string)(getenv('FIREBASE_DATABASE_URL') ?: ($_ENV['FIREBASE_DATABASE_URL'] ?? '')),
            '/'
        );
        if ($databaseUrl === '') {
            $projectId = (string)($credentials['project_id'] ?? '');
            if ($projectId === '') {
                return [];
            }
            $databaseUrl = "https://{$projectId}-default-rtdb.firebaseio.com";
        }

        $curl = curl_init("{$databaseUrl}/live_locations.json");
        if ($curl === false) {
            return [];
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $byUnitCode = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $lat = isset($entry['lat']) ? (float)$entry['lat'] : null;
            $lng = isset($entry['lng']) ? (float)$entry['lng'] : null;
            $unitCode = strtoupper(trim((string)($entry['unitCode'] ?? '')));
            if ($lat === null || $lng === null || $unitCode === '') {
                continue;
            }
            $byUnitCode[$unitCode] = [
                'lat' => $lat,
                'lng' => $lng,
                'status' => isset($entry['status']) ? (string)$entry['status'] : null,
                'responderName' => isset($entry['responderName']) ? (string)$entry['responderName'] : null,
            ];
        }

        return $byUnitCode;
    } catch (Throwable $e) {
        error_log('live_tracking Firebase fetch failed: ' . $e->getMessage());
        return [];
    }
}

/** @return array<string,mixed>|null */
function ers_live_tracking_firebase_credentials(): ?array
{
    $path = trim((string)(
        getenv('FIREBASE_SERVICE_ACCOUNT_PATH')
        ?: ($_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? '')
        ?: getenv('GOOGLE_APPLICATION_CREDENTIALS')
        ?: ($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? '')
    ));
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (
        !is_array($decoded)
        || trim((string)($decoded['project_id'] ?? '')) === ''
        || trim((string)($decoded['client_email'] ?? '')) === ''
        || trim((string)($decoded['private_key'] ?? '')) === ''
    ) {
        return null;
    }

    return $decoded;
}

function ers_live_tracking_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @param array<string,mixed> $credentials */
function ers_live_tracking_firebase_token(array $credentials): ?string
{
    $now = time();
    $projectId = (string)($credentials['project_id'] ?? 'default');
    $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'ers_rtdb_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $projectId) . '.json';

    if (is_file($cachePath) && is_readable($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (
            is_array($cached)
            && trim((string)($cached['token'] ?? '')) !== ''
            && (int)($cached['expires_at'] ?? 0) > $now + 60
        ) {
            return (string)$cached['token'];
        }
    }

    $claims = [
        'iss' => (string)$credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $unsigned = ers_live_tracking_base64url((string)json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.'
        . ers_live_tracking_base64url((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

    $signature = '';
    $signed = openssl_sign($unsigned, $signature, (string)$credentials['private_key'], OPENSSL_ALGO_SHA256);
    if (!$signed) {
        return null;
    }

    $assertion = $unsigned . '.' . ers_live_tracking_base64url($signature);
    $curl = curl_init('https://oauth2.googleapis.com/token');
    if ($curl === false) {
        return null;
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]),
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return null;
    }

    $response = json_decode($raw, true);
    $token = is_array($response) ? trim((string)($response['access_token'] ?? '')) : '';
    $expiresIn = is_array($response) ? max(300, (int)($response['expires_in'] ?? 3600)) : 3600;
    if ($token === '') {
        return null;
    }

    @file_put_contents($cachePath, json_encode(['token' => $token, 'expires_at' => $now + $expiresIn]), LOCK_EX);
    @chmod($cachePath, 0600);

    return $token;
}

/**
 * Same known seed/placeholder coordinates that units_list.php excludes, so
 * this feed never reports a fake test pin as if it were a live position.
 */
function ers_live_tracking_fallback_coordinate_condition(string $alias): string
{
    $safeAlias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'u';

    return "(
        (ABS({$safeAlias}.latitude) < 0.000001 AND ABS({$safeAlias}.longitude) < 0.000001)
        OR
        (ABS({$safeAlias}.latitude - 14.7338) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0368) < 0.000001)
        OR (ABS({$safeAlias}.latitude - 14.7295) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0342) < 0.000001)
        OR (ABS({$safeAlias}.latitude - 14.7351) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0380) < 0.000001)
        OR (ABS({$safeAlias}.latitude - 14.7320) < 0.000001 AND ABS({$safeAlias}.longitude - 121.0351) < 0.000001)
    )";
}

/** @return array<string,array<string,true>> */
function ers_live_tracking_schema(PDO $pdo): array
{
    $tables = ['units', 'unit_locations', 'incidents', 'users'];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));

    try {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute($tables);
    } catch (Throwable $e) {
        error_log('live_tracking schema lookup failed: ' . $e->getMessage());
        return [];
    }

    $schema = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $column = (string)($row['COLUMN_NAME'] ?? '');
        if ($table !== '' && $column !== '') {
            $schema[$table][$column] = true;
        }
    }

    return $schema;
}

/** @param array<string,array<string,true>> $schema */
function ers_live_tracking_has_table(array $schema, string $table): bool
{
    return isset($schema[$table]);
}

/** @param array<string,array<string,true>> $schema */
function ers_live_tracking_has_column(array $schema, string $table, string $column): bool
{
    return isset($schema[$table][$column]);
}
?>