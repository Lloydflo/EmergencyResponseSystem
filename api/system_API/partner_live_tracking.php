<?php
// ============================================================================
// PARTNER-FACING PROXY — no key in the URL, no key in this file.
//
// Purpose: give an external group ONE link that returns live tracking data
// (incident + responder locations), WITHOUT them ever seeing or holding the
// real ERS_EXTERNAL_API_KEY. The key stays server-side, read from .env by
// this file only.
//
// Link to give the partner group (nothing else, no query string needed):
//   https://emergency-response.alertaraqc.com/api/system_API/partner_live_tracking.php
//
// This reuses the exact same data logic as live_tracking.php / live_tracking_simple.php
// (see live_tracking_shared.php) — it does NOT re-implement the query.
// ============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/live_tracking_shared.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    ers_external_json(405, [
        'success' => false,
        'error' => 'GET method required',
    ]);
}

// NOTE: This intentionally skips ers_external_authenticate() — that function
// expects the CALLER to supply the key (?api_key=...), which is exactly what
// we're avoiding here. Instead, THIS SCRIPT is the trusted caller: it reads
// the key straight from the server's own .env (via ers_env / ers_external_expected_keys()
// in _bootstrap.php) and never echoes it back in any response.
//
// IMPORTANT SECURITY NOTE (read this before deploying):
// Because this endpoint requires no key at all, ANYONE who has this exact
// URL can pull live responder + incident GPS locations — not just the
// partner group you intend to share it with. That may be acceptable for a
// short-lived, narrowly-shared link, but it is a real trade-off for a public
// safety system. If you want a middle ground, uncomment the PARTNER_TOKEN
// block below: it adds a simple, separate, revocable token (NOT your real
// ERS_EXTERNAL_API_KEY) that you generate and can rotate independently.

/*
// --- Optional lightweight protection (separate from the real API key) ---
$partnerToken = (string) ers_env('PARTNER_LIVE_TRACKING_TOKEN', '');
if ($partnerToken !== '') {
    $provided = trim((string)($_GET['token'] ?? ''));
    if ($provided === '' || !hash_equals($partnerToken, $provided)) {
        ers_external_json(403, [
            'success' => false,
            'error' => 'Invalid or missing token',
        ]);
    }
}
// Then give the partner:
//   .../partner_live_tracking.php?token=YOUR_PARTNER_TOKEN
// Set PARTNER_LIVE_TRACKING_TOKEN in .env — a NEW random value, not your real key.
*/

$pdo = ers_external_db();

try {
    $rows = ers_live_tracking_fetch($pdo);

    // Using the "simple" shape (lat/lng only) since that's what the partner
    // asked for. Swap to `$rows` directly (like live_tracking.php) if they
    // need route/timestamp/status data too.
    $items = array_map(
        static function (array $row): array {
            return [
                'unit_identifier' => $row['unit_identifier'],
                'incident_code' => $row['incident_code'],
                'responder_location' => [
                    'lat' => $row['responder_latitude'],
                    'lng' => $row['responder_longitude'],
                ],
                'incident_location' => [
                    'lat' => $row['incident_latitude'],
                    'lng' => $row['incident_longitude'],
                ],
            ];
        },
        $rows
    );

    ers_external_json(200, [
        'success' => true,
        'generated_at' => date('c'),
        'count' => count($items),
        'items' => $items,
    ]);
} catch (Throwable $e) {
    error_log('partner_live_tracking.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to fetch live tracking data',
    ]);
}
