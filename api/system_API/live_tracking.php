<?php
// Live tracking feed for interagency partners: for every unit currently
// dispatched to an incident, returns the unit's latest GPS position and the
// lat/lng of the incident it's assigned to. Auth matches the rest of
// system_API (X-ERS-API-Key / X-API-Key / Bearer token / ?api_key=).
//
// Need a stripped-down version with just the two coordinate pairs? See
// live_tracking_simple.php (?action=live_tracking_simple) — same auth, same
// data, minimal shape, meant for partners who just want lat/lng.
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/live_tracking_shared.php';

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
?>
