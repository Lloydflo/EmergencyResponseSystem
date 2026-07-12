<?php
// Updates a responder/unit GPS coordinate and keeps dispatcher maps in sync.
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/unit_location_tracking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
    parse_str($raw, $input);
}
$input = array_merge($_POST ?? [], $input);

try {
    $result = ers_unit_location_update($pdo, $input);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('unit_location_update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
