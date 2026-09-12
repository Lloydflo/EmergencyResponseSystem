<?php
// Stripped-down live tracking feed: for every unit currently dispatched to
// an incident, returns ONLY the responder's location and the incident's
// location — no route polyline, no timestamps, no status fields. Built for
// partners who just want to plot two pins per unit.
//
// Auth is the SAME as every other system_API endpoint (see
// _bootstrap.php::ers_external_authenticate): any ONE of —
//   Header:  X-ERS-API-Key: <key>
//   Header:  X-API-Key: <key>
//   Header:  Authorization: Bearer <key>
//   Query:   ?api_key=<key>
// The ?api_key= query param exists specifically so this can be opened as a
// plain link (browser address bar, curl, a spreadsheet's IMPORTJSON, etc.)
// without needing to set a custom header:
//
//   https://emergency-response.alertaraqc.com/api/system_API/?action=live_tracking_simple&api_key=YOUR_KEY
//
// Need the full payload (route polyline, timestamps, unit status, etc.)
// instead? See live_tracking.php (?action=live_tracking) — same auth, same
// underlying data, richer shape.
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
    error_log('live_tracking_simple.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to fetch live tracking data',
    ]);
}
?>
