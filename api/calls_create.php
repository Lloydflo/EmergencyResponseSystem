<?php
declare(strict_types=1);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// Map payload
$caller_name = trim((string)($input['caller_name'] ?? ''));
$caller_phone = trim((string)($input['caller_phone'] ?? ''));
$type = trim((string)($input['type'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$priority = trim((string)($input['priority'] ?? ''));
$status = trim((string)($input['status'] ?? 'pending'));
$latitude = array_key_exists('latitude', $input) && $input['latitude'] !== '' ? (float)$input['latitude'] : null;
$longitude = array_key_exists('longitude', $input) && $input['longitude'] !== '' ? (float)$input['longitude'] : null;

if ($latitude !== null && ($latitude < -90 || $latitude > 90)) {
    $latitude = null;
}
if ($longitude !== null && ($longitude < -180 || $longitude > 180)) {
    $longitude = null;
}
if (($latitude === null) xor ($longitude === null)) {
    $latitude = null;
    $longitude = null;
}

if ($caller_name === '' || $caller_phone === '' || $type === '' || $location === '' || $description === '' || $priority === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}


// Duplicate detection: check for similar incident in last 60 minutes
$duplicate_sql = 'SELECT id, reference_no, type, location_address, created_at FROM incidents WHERE type = :type AND location_address = :location AND created_at >= (NOW() - INTERVAL 60 MINUTE) LIMIT 1';
$dup_stmt = $pdo->prepare($duplicate_sql);
$dup_stmt->execute([':type' => $type, ':location' => $location]);
$duplicate = $dup_stmt->fetch();
if ($duplicate) {
    echo json_encode([
        'ok' => false,
        'error' => 'Duplicate incident detected',
        'duplicate_incident' => [
            'id' => $duplicate['id'],
            'reference_no' => $duplicate['reference_no'],
            'type' => $duplicate['type'],
            'location_address' => $duplicate['location_address'],
            'created_at' => $duplicate['created_at'],
        ]
    ]);
    exit;
}

// Generate unique reference number shared between call and incident
$reference_no = 'REF-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);

try {
    $pdo->beginTransaction();
    $sql = 'INSERT INTO calls (reference_no, caller_name, caller_phone, caller_email, location_address, latitude, longitude, incident_type, priority, status, description, received_at) 
            VALUES (:reference_no, :caller_name, :caller_phone, NULL, :location_address, :latitude, :longitude, :incident_type, :priority, :status, :description, NOW())';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':reference_no' => $reference_no,
        ':caller_name' => $caller_name,
        ':caller_phone' => $caller_phone,
        ':location_address' => $location,
        ':latitude' => $latitude,
        ':longitude' => $longitude,
        ':incident_type' => $type,
        ':priority' => $priority,
        ':status' => $status === 'pending' ? 'new' : 'triaged', // map to calls.status domain
        ':description' => $description,
    ]);
    $call_id = (int)$pdo->lastInsertId();

    // Trigger creates incident; fetch it back
    $stmt2 = $pdo->prepare('SELECT id, reference_no, status FROM incidents WHERE reported_by_call_id = :cid LIMIT 1');
    $stmt2->execute([':cid' => $call_id]);
    $incident = $stmt2->fetch();

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'call_id' => $call_id,
        'reference_no' => $reference_no,
        'incident_id' => $incident ? (int)$incident['id'] : null,
        'incident_reference_no' => $incident ? $incident['reference_no'] : null,
        'incident_status' => $incident ? $incident['status'] : null,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Insert failed']);
}
