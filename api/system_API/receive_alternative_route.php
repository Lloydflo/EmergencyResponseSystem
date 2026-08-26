<?php
// Webhook: receives proactive alternative-route pushes from the interagency
// partner (e.g. "traffic ahead, here's a better route"), unprompted — not
// tied to the existing responder request/approval flow in api/api_app/
// (request-alternative-route.php, submit-alternative-route.php, etc), which
// is a separate in-app feature for responders proposing their own routes.
//
// Stored in Firebase RTDB under "alternative_routes/{unit_identifier}",
// mirroring how live_locations already works — so both the dispatcher's
// Leaflet map and the Android app's MapLibre map can subscribe in real time
// without needing to poll a REST endpoint.
//
// Auth matches the rest of system_API (X-ERS-API-Key / X-API-Key / Bearer
// token / ?api_key=).
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

$auth = ers_external_authenticate();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ers_external_json(405, [
        'success' => false,
        'error' => 'POST method required',
    ]);
}

try {
    $raw = file_get_contents('php://input');
    $payload = $raw !== false ? json_decode($raw, true) : null;

    if (!is_array($payload)) {
        ers_external_json(400, [
            'success' => false,
            'error' => 'Request body must be valid JSON',
        ]);
    }

    $error = ers_alt_route_validate($payload);
    if ($error !== null) {
        ers_external_json(422, [
            'success' => false,
            'error' => $error,
        ]);
    }

    $unitIdentifier = strtoupper(trim((string)$payload['unit_identifier']));
    $record = ers_alt_route_build_record($payload);

    $written = ers_alt_route_write_to_firebase($unitIdentifier, $record);
    if (!$written) {
        error_log('receive_alternative_route.php: Firebase write failed for unit ' . $unitIdentifier);
        ers_external_json(502, [
            'success' => false,
            'error' => 'Unable to publish alternative route right now',
        ]);
    }

    ers_external_json(200, [
        'success' => true,
        'unit_identifier' => $unitIdentifier,
        'stored_at' => $record['received_at'],
    ]);
} catch (Throwable $e) {
    error_log('receive_alternative_route.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to process alternative route',
    ]);
}

/**
 * Validates the incoming payload. Returns null if valid, or an error
 * message string describing the first problem found.
 *
 * Expected shape:
 * {
 *   "unit_identifier": "VEH-004",       // required, matches units.identifier
 *   "incident_id": 79,                   // optional, informational
 *   "route_polyline": [[lat,lng], ...],  // required, at least 2 points
 *   "distance_km": 21.4,                 // optional
 *   "duration_min": 34.0,                // optional
 *   "reason": "Heavy traffic on EDSA",   // optional, shown to dispatchers
 *   "provided_by": "PartnerGroupName"    // optional, who sent this
 * }
 *
 * @param mixed $payload
 */
function ers_alt_route_validate($payload): ?string
{
    if (!isset($payload['unit_identifier']) || trim((string)$payload['unit_identifier']) === '') {
        return 'unit_identifier is required';
    }

    if (!isset($payload['route_polyline']) || !is_array($payload['route_polyline'])) {
        return 'route_polyline is required and must be an array of [lat, lng] pairs';
    }

    if (count($payload['route_polyline']) < 2) {
        return 'route_polyline must contain at least 2 points';
    }

    foreach ($payload['route_polyline'] as $i => $point) {
        if (
            !is_array($point)
            || count($point) < 2
            || !is_numeric($point[0] ?? null)
            || !is_numeric($point[1] ?? null)
        ) {
            return "route_polyline[{$i}] must be a [lat, lng] pair of numbers";
        }
        $lat = (float)$point[0];
        $lng = (float)$point[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return "route_polyline[{$i}] has an out-of-range coordinate";
        }
    }

    return null;
}

/** @param array<string,mixed> $payload */
function ers_alt_route_build_record(array $payload): array
{
    $polyline = [];
    foreach ($payload['route_polyline'] as $point) {
        $polyline[] = [(float)$point[0], (float)$point[1]];
    }

    return [
        'polyline' => $polyline,
        'incident_id' => isset($payload['incident_id']) ? (int)$payload['incident_id'] : null,
        'distance_km' => isset($payload['distance_km']) ? (float)$payload['distance_km'] : null,
        'duration_min' => isset($payload['duration_min']) ? (float)$payload['duration_min'] : null,
        'reason' => isset($payload['reason']) ? trim((string)$payload['reason']) : null,
        'provided_by' => isset($payload['provided_by']) ? trim((string)$payload['provided_by']) : null,
        'received_at' => date('c'),
    ];
}

/**
 * Writes the alternative route under alternative_routes/{unitIdentifier} in
 * Firebase RTDB, reusing the same service-account auth as live_tracking.php.
 * A later push for the same unit simply overwrites the previous one — there
 * is only ever "the current alternative route" per unit, not a history.
 */
function ers_alt_route_write_to_firebase(string $unitIdentifier, array $record): bool
{
    $credentials = ers_alt_route_firebase_credentials();
    if ($credentials === null) {
        error_log('receive_alternative_route.php: Firebase credentials not configured');
        return false;
    }

    $token = ers_alt_route_firebase_token($credentials);
    if ($token === null) {
        error_log('receive_alternative_route.php: could not obtain Firebase access token');
        return false;
    }

    $databaseUrl = rtrim(
        (string)(getenv('FIREBASE_DATABASE_URL') ?: ($_ENV['FIREBASE_DATABASE_URL'] ?? '')),
        '/'
    );
    if ($databaseUrl === '') {
        $projectId = (string)($credentials['project_id'] ?? '');
        if ($projectId === '') {
            return false;
        }
        $databaseUrl = "https://{$projectId}-default-rtdb.firebaseio.com";
    }

    $safeKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $unitIdentifier) ?: $unitIdentifier;
    $url = "{$databaseUrl}/alternative_routes/{$safeKey}.json";

    $curl = curl_init($url);
    if ($curl === false) {
        return false;
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode($record),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($curl);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false || $curlErrno !== 0) {
        error_log("receive_alternative_route.php: curl_exec failed errno={$curlErrno} error={$curlError}");
        return false;
    }

    if ($status < 200 || $status >= 300) {
        error_log("receive_alternative_route.php: Firebase PUT failed status={$status} body=" . substr((string)$raw, 0, 300));
        return false;
    }

    return true;
}

/** @return array<string,mixed>|null */
function ers_alt_route_firebase_credentials(): ?array
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

function ers_alt_route_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @param array<string,mixed> $credentials */
function ers_alt_route_firebase_token(array $credentials): ?string
{
    $now = time();
    $projectId = (string)($credentials['project_id'] ?? 'default');
    // Shares the same token cache file as live_tracking.php's Firebase
    // helper — both request the same RTDB scope, so one cached token
    // serves both endpoints and avoids double the oauth2 round-trips.
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
    $unsigned = ers_alt_route_base64url((string)json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.'
        . ers_alt_route_base64url((string)json_encode($claims, JSON_UNESCAPED_SLASHES));

    $signature = '';
    $signed = openssl_sign($unsigned, $signature, (string)$credentials['private_key'], OPENSSL_ALGO_SHA256);
    if (!$signed) {
        return null;
    }

    $assertion = $unsigned . '.' . ers_alt_route_base64url($signature);
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
?>
