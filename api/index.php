<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$auth = ers_external_authenticate();
$pdo = ers_external_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(ers_external_clean($_GET['action'] ?? 'overview', 50));

if ($method !== 'GET' && !ers_external_intake_enabled()) {
    ers_external_json(403, [
        'success' => false,
        'error' => 'External incident intake is disabled',
        'hint' => 'Set ERS_EXTERNAL_INTAKE_ENABLED=true only when the external system is ready.',
    ]);
}

try {
    if ($method === 'GET') {
        if ($action === 'overview') {
            ers_external_json(200, ers_api_overview($pdo));
        }
        if ($action === 'incidents') {
            ers_external_json(200, ['success' => true, 'incidents' => ers_api_incidents($pdo)]);
        }
        if ($action === 'resources') {
            ers_external_json(200, ['success' => true, 'resources' => ers_api_resources($pdo)]);
        }
        if ($action === 'alerts') {
            ers_external_json(200, ['success' => true, 'alerts' => ers_api_alerts($pdo)]);
        }
        if ($action === 'calls') {
            ers_external_json(200, ['success' => true, 'calls' => ers_api_calls($pdo)]);
        }
        if ($action === 'conversations') {
            ers_external_json(200, ['success' => true, 'conversations' => ers_api_conversations($pdo)]);
        }
        ers_external_json(404, ['success' => false, 'error' => 'Unknown action']);
    }

    if ($method === 'POST' && in_array($action, ['overview', 'create_incident', 'incident'], true)) {
        if (ers_api_is_incoming_transfer_payload()) {
            ers_external_json(201, ers_api_create_incoming_transfer($pdo, $auth));
        }
        ers_external_json(201, ers_api_create_incident($pdo, $auth));
    }

    if ($method === 'POST' && in_array($action, ['incoming-transfer', 'incoming_transfer', 'transfer_call'], true)) {
        ers_external_json(201, ers_api_create_incoming_transfer($pdo, $auth));
    }

    if (in_array($method, ['POST', 'PATCH'], true) && in_array($action, ['status', 'incident_status'], true)) {
        ers_external_json(200, ers_api_update_incident_status($pdo));
    }

    ers_external_json(405, ['success' => false, 'error' => 'Method not allowed']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('central api failed: ' . $e->getMessage());
    ers_external_json(500, ['success' => false, 'error' => 'Central API request failed']);
}

function ers_api_limit(int $default = 20, int $max = 100): int
{
    return max(1, min($max, (int)($_GET['limit'] ?? $default)));
}

function ers_api_overview(PDO $pdo): array
{
    return [
        'success' => true,
        'message' => 'Centralized ERS system overview retrieved successfully.',
        'timestamp' => date('c'),
        'department' => 'Integrated Central Access',
        'summary' => ers_api_summary($pdo),
        'alerts' => ers_api_alerts($pdo),
        'incidents' => ers_api_incidents($pdo),
        'resources' => ers_api_resources($pdo),
        'calls' => ers_api_calls($pdo),
        'locations' => ers_api_locations($pdo),
        'conversations' => ers_api_conversations($pdo),
        'available_actions' => [
            'overview' => 'GET /ERS/api/?api_key=KEY',
            'incidents' => 'GET /ERS/api/?action=incidents&api_key=KEY',
            'resources' => 'GET /ERS/api/?action=resources&api_key=KEY',
            'create_incident' => 'POST /ERS/api/?action=create_incident',
            'incoming_transfer' => 'POST /ERS/api/?action=incoming-transfer',
            'incident_status' => 'PATCH /ERS/api/?action=incident_status',
        ],
    ];
}

function ers_api_summary(PDO $pdo): array
{
    $summary = [
        'pending_incidents' => 0,
        'dispatched_incidents' => 0,
        'resolved_incidents_today' => 0,
        'active_calls_today' => 0,
        'available_resources' => 0,
        'resources_in_use' => 0,
    ];

    if (ers_external_table_exists($pdo, 'incidents')) {
        $summary['pending_incidents'] = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'pending'")->fetchColumn();
        $summary['dispatched_incidents'] = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'dispatched'")->fetchColumn();
        $summary['resolved_incidents_today'] = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'resolved' AND DATE(COALESCE(resolved_at, updated_at, created_at)) = CURDATE()")->fetchColumn();
    }

    if (ers_external_table_exists($pdo, 'calls')) {
        $summary['active_calls_today'] = (int)$pdo->query("SELECT COUNT(*) FROM calls WHERE DATE(COALESCE(received_at, created_at)) = CURDATE()")->fetchColumn();
    }

    $resourceTable = ers_api_resource_table($pdo);
    if ($resourceTable !== null) {
        $summary['available_resources'] = (int)$pdo->query("SELECT COUNT(*) FROM `{$resourceTable}` WHERE status = 'available'")->fetchColumn();
        $summary['resources_in_use'] = (int)$pdo->query("SELECT COUNT(*) FROM `{$resourceTable}` WHERE status IN ('in_use', 'assigned', 'enroute', 'on_scene')")->fetchColumn();
    } elseif (ers_external_table_exists($pdo, 'units')) {
        $summary['available_resources'] = (int)$pdo->query("SELECT COUNT(*) FROM units WHERE status = 'available'")->fetchColumn();
        $summary['resources_in_use'] = (int)$pdo->query("SELECT COUNT(*) FROM units WHERE status IN ('assigned', 'enroute', 'on_scene')")->fetchColumn();
    }

    return $summary;
}

function ers_api_alerts(PDO $pdo): array
{
    $alerts = [];
    if (ers_external_table_exists($pdo, 'incidents')) {
        $stmt = $pdo->query(
            "SELECT id, reference_no, title, type, priority, status, location_address, created_at
             FROM incidents
             WHERE status IN ('pending', 'dispatched')
             ORDER BY FIELD(priority, 'high', 'medium', 'low'), created_at DESC
             LIMIT 10"
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $priority = strtolower((string)($row['priority'] ?? 'medium'));
            $alerts[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? ('Incident ' . ($row['reference_no'] ?? ''))),
                'message' => trim((string)($row['type'] ?? 'Incident') . ' at ' . (string)($row['location_address'] ?? '')),
                'severity' => $priority === 'high' ? 'High' : ucfirst($priority),
                'category' => (string)($row['type'] ?? 'incident'),
                'status' => (string)($row['status'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }
    }

    $resourceTable = ers_api_resource_table($pdo);
    if ($resourceTable !== null) {
        $stmt = $pdo->query(
            "SELECT id, code, name, category, status, location, updated_at
             FROM `{$resourceTable}`
             WHERE status IN ('maintenance', 'offline')
             ORDER BY updated_at DESC, id DESC
             LIMIT 10"
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $alerts[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => 'Resource ' . ucfirst((string)($row['status'] ?? 'unavailable')),
                'message' => trim((string)($row['code'] ?? '') . ' - ' . (string)($row['name'] ?? '')),
                'severity' => strtolower((string)($row['status'] ?? '')) === 'offline' ? 'High' : 'Medium',
                'category' => (string)($row['category'] ?? 'resource'),
                'status' => (string)($row['status'] ?? ''),
                'created_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
    }

    return $alerts;
}

function ers_api_incidents(PDO $pdo): array
{
    if (!ers_external_table_exists($pdo, 'incidents')) {
        return [];
    }

    $limit = ers_api_limit(25);
    $status = ers_external_normalize_status($_GET['status'] ?? '');
    $where = '';
    $params = [];
    if ($status !== '') {
        $where = 'WHERE i.status = ?';
        $params[] = $status;
    }

    $stmt = $pdo->prepare(
        "SELECT i.id, i.reference_no, i.type, i.priority, i.status, i.title, i.description,
                i.location_address, i.latitude, i.longitude, i.created_at, i.updated_at,
                c.caller_name, c.caller_phone
         FROM incidents i
         LEFT JOIN calls c ON c.id = i.reported_by_call_id
         {$where}
         ORDER BY i.created_at DESC, i.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ers_api_resources(PDO $pdo): array
{
    $resourceTable = ers_api_resource_table($pdo);
    if ($resourceTable !== null) {
        $quantity = ers_external_column_exists($pdo, $resourceTable, 'quantity') ? 'quantity' : '1 AS quantity';
        $stmt = $pdo->query(
            "SELECT id, code, name, category, status, location, driver_name, plate_number,
                    position_title, assignment, {$quantity}, notes, updated_at
             FROM `{$resourceTable}`
             ORDER BY category, name
             LIMIT " . ers_api_limit(50)
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    if (!ers_external_table_exists($pdo, 'units')) {
        return [];
    }
    $stmt = $pdo->query(
        "SELECT id, identifier AS code, identifier AS name, unit_type AS category,
                status, latitude, longitude, current_incident_id, updated_at
         FROM units
         ORDER BY unit_type, identifier
         LIMIT " . ers_api_limit(50)
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ers_api_calls(PDO $pdo): array
{
    if (!ers_external_table_exists($pdo, 'calls')) {
        return [];
    }
    $stmt = $pdo->query(
        "SELECT id, reference_no, caller_name, caller_phone, location_address,
                latitude, longitude, incident_type, priority, status, description, received_at, created_at
         FROM calls
         ORDER BY COALESCE(received_at, created_at) DESC, id DESC
         LIMIT " . ers_api_limit(25)
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ers_api_locations(PDO $pdo): array
{
    if (!ers_external_table_exists($pdo, 'incidents')) {
        return [];
    }
    $stmt = $pdo->query(
        "SELECT id, reference_no, type, status, location_address, latitude, longitude, created_at
         FROM incidents
         WHERE latitude IS NOT NULL AND longitude IS NOT NULL
         ORDER BY created_at DESC, id DESC
         LIMIT " . ers_api_limit(25)
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ers_api_conversations(PDO $pdo): array
{
    $items = [];
    if (ers_external_table_exists($pdo, 'interagency_solo_chat')) {
        $stmt = $pdo->query(
            "SELECT s.id, s.activity_log_id, s.sender_user_id, s.recipient_user_id,
                    s.message_details, s.created_at, u.name AS recipient_name
             FROM interagency_solo_chat s
             LEFT JOIN users u ON u.id = s.recipient_user_id
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT " . ers_api_limit(20)
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $items[] = ers_api_conversation_row($row, 'solo');
        }
    }

    if (ers_external_table_exists($pdo, 'interagency_groups_threads_read')) {
        $stmt = $pdo->query(
            "SELECT id, activity_log_id, group_id, sender_user_id, message_details, created_at
             FROM interagency_groups_threads_read
             ORDER BY created_at DESC, id DESC
             LIMIT " . ers_api_limit(20)
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $row['recipient_name'] = 'Group #' . (string)($row['group_id'] ?? '');
            $items[] = ers_api_conversation_row($row, 'group');
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b['last_message_time'] ?? ''), (string)($a['last_message_time'] ?? ''));
    });

    return array_slice($items, 0, ers_api_limit(20));
}

function ers_api_conversation_row(array $row, string $type): array
{
    $raw = (string)($row['message_details'] ?? '');
    $decoded = json_decode($raw, true);
    $message = $raw;
    if (is_array($decoded)) {
        $message = (string)($decoded['text'] ?? $decoded['message'] ?? $decoded['caption'] ?? $raw);
    }

    return [
        'conversation_id' => (int)($row['id'] ?? 0),
        'activity_log_id' => (int)($row['activity_log_id'] ?? 0),
        'type' => $type,
        'sender' => (string)($row['sender_user_id'] ?? ''),
        'recipient' => (string)($row['recipient_name'] ?? $row['recipient_user_id'] ?? ''),
        'last_message' => $message,
        'last_message_time' => (string)($row['created_at'] ?? ''),
    ];
}

function ers_api_resource_table(PDO $pdo): ?string
{
    if (ers_external_table_exists($pdo, 'resource_records')) {
        return 'resource_records';
    }
    if (ers_external_table_exists($pdo, 'admin_resources')) {
        return 'admin_resources';
    }
    return null;
}

function ers_api_is_incoming_transfer_payload(): bool
{
    $input = ers_external_input();
    if (isset($input['payload_json']) && is_string($input['payload_json'])) {
        $decoded = json_decode($input['payload_json'], true);
        if (is_array($decoded)) {
            $input = array_replace($input, $decoded);
        }
    }
    $call = isset($input['call']) && is_array($input['call']) ? $input['call'] : $input;
    foreach (['event', 'callId', 'call_id', 'room', 'socketUrl', 'socket_url', 'socketPath', 'socket_path', 'conversation', 'conversationId', 'conversation_id', 'transfer_id'] as $key) {
        if (array_key_exists($key, $call) || array_key_exists($key, $input)) {
            return true;
        }
    }
    return false;
}

function ers_api_create_incident(PDO $pdo, array $auth): array
{
    $input = ers_external_input();
    $incident = isset($input['incident']) && is_array($input['incident']) ? $input['incident'] : $input;

    $sourceSystem = ers_external_clean($input['source_system'] ?? $input['system_name'] ?? $auth['client'] ?? 'external', 120);
    $externalIncidentId = ers_external_clean($input['external_incident_id'] ?? $input['external_id'] ?? $incident['tip_id'] ?? $incident['reference_no'] ?? '', 120);
    $type = ers_external_normalize_type($incident['type'] ?? $incident['incident_type'] ?? $incident['requested_department'] ?? $incident['department'] ?? '');
    $priority = ers_external_normalize_priority($incident['priority'] ?? 'high');
    $location = ers_external_clean($incident['location_address'] ?? $incident['location'] ?? '', 255);
    $description = ers_external_clean($incident['description'] ?? $incident['details'] ?? $incident['message'] ?? '', 0);
    $reasonForBackup = ers_external_clean($incident['reason_for_police_backup'] ?? $incident['reason_for_backup'] ?? $incident['backup_reason'] ?? '', 0);
    if ($reasonForBackup !== '' && stripos($description, $reasonForBackup) === false) {
        $description = trim($description . "\nReason for backup: " . $reasonForBackup);
    }

    if ($type === '' || $location === '' || $description === '') {
        ers_external_json(422, ['success' => false, 'error' => 'Missing required fields: type, location, description']);
    }

    $latitude = isset($incident['latitude']) && $incident['latitude'] !== '' ? (float)$incident['latitude'] : null;
    $longitude = isset($incident['longitude']) && $incident['longitude'] !== '' ? (float)$incident['longitude'] : null;
    if (($latitude !== null && ($latitude < -90 || $latitude > 90)) || ($longitude !== null && ($longitude < -180 || $longitude > 180))) {
        $latitude = null;
        $longitude = null;
    }
    if (($latitude === null) xor ($longitude === null)) {
        $latitude = null;
        $longitude = null;
    }

    ers_external_ensure_identity($pdo, 'calls');
    ers_external_ensure_identity($pdo, 'incidents');
    ers_external_ensure_link_table($pdo);

    if ($sourceSystem !== '' && $externalIncidentId !== '') {
        $existing = $pdo->prepare(
            "SELECT i.id, i.reference_no, i.status
             FROM external_incident_links l
             INNER JOIN incidents i ON i.id = l.incident_id
             WHERE l.source_system = ? AND l.external_incident_id = ?
             LIMIT 1"
        );
        $existing->execute([$sourceSystem, $externalIncidentId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'success' => true,
                'duplicate' => true,
                'incident_id' => (int)$row['id'],
                'reference_no' => $row['reference_no'],
                'status' => $row['status'],
            ];
        }
    }

    $referenceNo = ers_external_clean($incident['reference_no'] ?? $incident['tip_id'] ?? '', 50);
    if ($referenceNo === '') {
        $referenceNo = 'EXT-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
    $title = ers_external_clean($incident['title'] ?? ('Incident from ' . $sourceSystem), 200);

    $pdo->beginTransaction();
    $callId = ers_external_insert_call($pdo, [
        ':reference_no' => $referenceNo,
        ':caller_name' => ers_external_clean($incident['caller_name'] ?? $incident['reporter_name'] ?? $sourceSystem, 150),
        ':caller_phone' => ers_external_clean($incident['caller_phone'] ?? $incident['contact_number'] ?? $incident['contact'] ?? 'N/A', 50),
        ':caller_email' => ers_external_clean($incident['caller_email'] ?? $incident['email'] ?? '', 150) ?: null,
        ':location_address' => $location,
        ':latitude' => $latitude,
        ':longitude' => $longitude,
        ':incident_type' => $type,
        ':priority' => $priority,
        ':description' => $description,
    ]);

    $lookup = $pdo->prepare('SELECT id, reference_no, status FROM incidents WHERE reported_by_call_id = ? LIMIT 1');
    $lookup->execute([$callId]);
    $created = $lookup->fetch(PDO::FETCH_ASSOC);
    if ($created) {
        $update = $pdo->prepare('UPDATE incidents SET title = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$title, (int)$created['id']]);
    } else {
        $incidentId = ers_external_insert_incident($pdo, [
            ':reference_no' => $referenceNo,
            ':type' => $type,
            ':priority' => $priority,
            ':title' => $title,
            ':description' => $description,
            ':location_address' => $location,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':reported_by_call_id' => $callId,
        ]);
        $created = ['id' => $incidentId, 'reference_no' => $referenceNo, 'status' => 'pending'];
    }

    ers_external_link_incident($pdo, $sourceSystem, $externalIncidentId, (int)$created['id'], $input);
    $cardMessages = ers_api_create_incident_card_messages($pdo, [
        'incident_id' => (int)$created['id'],
        'reference_no' => (string)$created['reference_no'],
        'external_incident_id' => $externalIncidentId,
        'source_system' => $sourceSystem,
        'title' => $title,
        'type' => $type,
        'location' => $location,
        'priority' => $priority,
        'description' => $description,
        'contact_number' => ers_external_clean($incident['contact_number'] ?? $incident['contact'] ?? $incident['caller_phone'] ?? '', 50),
        'timestamp' => ers_external_clean($incident['timestamp'] ?? $incident['reported_at'] ?? '', 50),
        'reason_for_backup' => $reasonForBackup,
    ], $input);
    $pdo->commit();

    return [
        'success' => true,
        'message' => 'Incident created successfully and sent as an incident card.',
        'call_id' => $callId,
        'incident_id' => (int)$created['id'],
        'reference_no' => $created['reference_no'],
        'status' => $created['status'],
        'incident_card_messages' => $cardMessages,
    ];
}

function ers_api_create_incoming_transfer(PDO $pdo, array $auth): array
{
    $input = ers_external_input();
    if (isset($input['payload_json']) && is_string($input['payload_json'])) {
        $decodedPayload = json_decode($input['payload_json'], true);
        if (is_array($decodedPayload)) {
            $input = array_replace($input, $decodedPayload);
        }
    }
    $call = isset($input['call']) && is_array($input['call']) ? $input['call'] : $input;

    $sourceSystem = ers_external_clean(
        $input['source_system'] ?? $input['system_name'] ?? $input['from_system'] ?? $input['from_agency'] ?? $auth['client'] ?? 'AlertaraQC Emergency Communication',
        120
    );
    $transferId = ers_external_clean(
        $input['transfer_id'] ?? $input['external_transfer_id'] ?? $call['transfer_id'] ?? $call['callId'] ?? $call['call_id'] ?? $call['reference_no'] ?? '',
        120
    );
    $type = ers_external_normalize_type($call['emergencyType'] ?? $call['emergency_type'] ?? $call['incident_type'] ?? $call['type'] ?? $call['department'] ?? 'other');
    if ($type === '') {
        $type = 'other';
    }

    $priority = ers_external_normalize_priority($call['priority'] ?? $input['priority'] ?? 'medium');
    $location = ers_external_clean($call['location_address'] ?? $call['caller_address'] ?? $call['location'] ?? $call['address'] ?? '', 255);
    if ($location === '') {
        $location = 'Location pending from transferred call';
    }
    $description = ers_external_clean($call['description'] ?? $call['notes'] ?? $call['message'] ?? $call['summary'] ?? '', 0);
    if ($description === '' && isset($call['conversation'])) {
        $description = ers_api_transfer_conversation_text($call['conversation']);
    }
    if ($description === '') {
        $description = 'Incoming transferred call from ' . ($sourceSystem !== '' ? $sourceSystem : 'external system') . '.';
    }

    $latitude = isset($call['latitude']) && $call['latitude'] !== '' ? (float)$call['latitude'] : null;
    $longitude = isset($call['longitude']) && $call['longitude'] !== '' ? (float)$call['longitude'] : null;
    if (($latitude !== null && ($latitude < -90 || $latitude > 90)) || ($longitude !== null && ($longitude < -180 || $longitude > 180))) {
        $latitude = null;
        $longitude = null;
    }
    if (($latitude === null) xor ($longitude === null)) {
        $latitude = null;
        $longitude = null;
    }

    ers_external_ensure_identity($pdo, 'calls');
    ers_external_ensure_identity($pdo, 'incidents');
    ers_external_ensure_link_table($pdo);

    if ($sourceSystem !== '' && $transferId !== '') {
        $existing = $pdo->prepare(
            "SELECT i.id, i.reference_no, i.status
             FROM external_incident_links l
             INNER JOIN incidents i ON i.id = l.incident_id
             WHERE l.source_system = ? AND l.external_incident_id = ?
             LIMIT 1"
        );
        $existing->execute([$sourceSystem, $transferId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'success' => true,
                'duplicate' => true,
                'transfer_id' => $transferId,
                'incident_id' => (int)$row['id'],
                'reference_no' => $row['reference_no'],
                'status' => $row['status'],
            ];
        }
    }

    $referenceNo = ers_external_clean($call['reference_no'] ?? '', 50);
    if ($referenceNo === '') {
        $referenceNo = 'TRN-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    $title = ers_external_clean($call['title'] ?? ('Transferred call from ' . $sourceSystem), 200);
    $callerName = ers_external_clean($call['caller_name'] ?? $call['name'] ?? 'Transferred Caller', 150);
    $callerPhone = ers_external_clean($call['caller_phone'] ?? $call['phone'] ?? $call['contact_number'] ?? $call['contact'] ?? 'N/A', 50);
    $room = ers_external_clean($call['room'] ?? $input['room'] ?? '', 150);
    $socketUrl = ers_external_clean($call['socketUrl'] ?? $call['socket_url'] ?? $input['socketUrl'] ?? $input['socket_url'] ?? 'https://emergency-comm.alertaraqc.com', 255);
    $socketPath = ers_external_clean($call['socketPath'] ?? $call['socket_path'] ?? $input['socketPath'] ?? $input['socket_path'] ?? '/socket.io', 100);
    $conversationId = ers_external_clean($call['conversationId'] ?? $call['conversation_id'] ?? $input['conversationId'] ?? $input['conversation_id'] ?? '', 80);

    $pdo->beginTransaction();
    $callId = ers_external_insert_call($pdo, [
        ':reference_no' => $referenceNo,
        ':caller_name' => $callerName,
        ':caller_phone' => $callerPhone,
        ':caller_email' => ers_external_clean($call['caller_email'] ?? $call['email'] ?? '', 150) ?: null,
        ':location_address' => $location,
        ':latitude' => $latitude,
        ':longitude' => $longitude,
        ':incident_type' => $type,
        ':priority' => $priority,
        ':description' => $description,
    ]);

    $lookup = $pdo->prepare('SELECT id, reference_no, status FROM incidents WHERE reported_by_call_id = ? LIMIT 1');
    $lookup->execute([$callId]);
    $created = $lookup->fetch(PDO::FETCH_ASSOC);
    if ($created) {
        $update = $pdo->prepare('UPDATE incidents SET title = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$title, (int)$created['id']]);
    } else {
        $incidentId = ers_external_insert_incident($pdo, [
            ':reference_no' => $referenceNo,
            ':type' => $type,
            ':priority' => $priority,
            ':title' => $title,
            ':description' => $description,
            ':location_address' => $location,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':reported_by_call_id' => $callId,
        ]);
        $created = ['id' => $incidentId, 'reference_no' => $referenceNo, 'status' => 'pending'];
    }

    ers_external_link_incident($pdo, $sourceSystem, $transferId, (int)$created['id'], $input);
    $pdo->commit();

    return [
        'success' => true,
        'message' => 'Incoming transfer call recorded successfully.',
        'event' => ers_external_clean($call['event'] ?? $input['event'] ?? 'incoming-transfer', 80),
        'transfer_id' => $transferId,
        'call_id_external' => ers_external_clean($call['callId'] ?? $call['call_id'] ?? $transferId, 120),
        'conversation_id' => $conversationId,
        'room' => $room,
        'socket_url' => $socketUrl,
        'socket_path' => $socketPath,
        'call_id' => $callId,
        'incident_id' => (int)$created['id'],
        'reference_no' => $created['reference_no'],
        'status' => $created['status'],
    ];
}

function ers_api_transfer_conversation_text($conversation): string
{
    if (is_string($conversation)) {
        $decoded = json_decode($conversation, true);
        if (is_array($decoded)) {
            $conversation = $decoded;
        } else {
            return ers_external_clean($conversation, 0);
        }
    }

    if (!is_array($conversation)) {
        return '';
    }

    $lines = [];
    foreach ($conversation as $entry) {
        if (is_array($entry)) {
            $speaker = ers_external_clean($entry['speaker'] ?? $entry['role'] ?? $entry['from'] ?? '', 80);
            $text = ers_external_clean($entry['text'] ?? $entry['message'] ?? $entry['content'] ?? '', 0);
            if ($text !== '') {
                $lines[] = ($speaker !== '' ? $speaker . ': ' : '') . $text;
            }
        } elseif (is_scalar($entry)) {
            $text = ers_external_clean((string)$entry, 0);
            if ($text !== '') {
                $lines[] = $text;
            }
        }
    }

    return implode("\n", $lines);
}

function ers_api_create_incident_card_messages(PDO $pdo, array $card, array $input): array
{
    $recipients = ers_api_incident_card_recipients($pdo, $input);
    if (count($recipients) === 0) {
        return [];
    }

    ers_api_ensure_incident_card_tables($pdo);

    $systemName = ers_external_clean($card['source_system'] ?? 'External System', 120);
    $conversationTitle = ers_external_clean($input['conversation_title'] ?? $systemName, 120);
    $incidentId = (int)($card['incident_id'] ?? 0);
    $referenceNo = ers_external_clean($card['reference_no'] ?? '', 100);

    $payload = [
        'text' => '[INCIDENT] Incident ' . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId)),
        'external_conversation_title' => $conversationTitle !== '' ? $conversationTitle : $systemName,
        'external_sender_name' => $systemName !== '' ? $systemName : 'External System',
        'incident_card' => $card,
    ];

    $details = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($details) || $details === '') {
        throw new RuntimeException('Unable to encode incident-card payload.');
    }

    $created = [];
    foreach ($recipients as $recipientId) {
        $messageId = ers_api_insert_activity_message($pdo, $recipientId, $details);

        $solo = $pdo->prepare(
            "INSERT INTO interagency_solo_chat
                (activity_log_id, sender_user_id, recipient_user_id, message_details, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                message_details = VALUES(message_details)"
        );
        $solo->execute([$messageId, $systemName, $recipientId, $details]);

        $cardInsert = $pdo->prepare(
            "INSERT INTO interagency_incident_cards (message_id, incident_id, status, created_at)
             VALUES (?, ?, 'pending', NOW())
             ON DUPLICATE KEY UPDATE
                incident_id = VALUES(incident_id),
                status = IF(status = '', 'pending', status)"
        );
        $cardInsert->execute([$messageId, $incidentId]);

        $created[] = [
            'recipient_user_id' => $recipientId,
            'message_id' => $messageId,
        ];
    }

    return $created;
}

function ers_api_incident_card_recipients(PDO $pdo, array $input): array
{
    $recipients = [];
    if (isset($input['recipient_user_id']) && (int)$input['recipient_user_id'] > 0) {
        $recipients[] = (int)$input['recipient_user_id'];
    } elseif (isset($input['recipient_user_ids']) && is_array($input['recipient_user_ids'])) {
        foreach ($input['recipient_user_ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $recipients[] = $id;
            }
        }
    } elseif (ers_external_table_exists($pdo, 'users')) {
        $stmt = $pdo->query(
            "SELECT id
             FROM users
             WHERE status = 'active'
               AND role IN ('admin', 'dispatcher', 'operator')
             ORDER BY FIELD(role, 'admin', 'dispatcher', 'operator'), id"
        );
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $recipients[] = $id;
            }
        }
    }

    return array_values(array_unique($recipients));
}

function ers_api_ensure_incident_card_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_solo_chat` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activity_log_id` INT NOT NULL,
            `sender_user_id` VARCHAR(255) NOT NULL,
            `recipient_user_id` INT UNSIGNED NOT NULL,
            `message_details` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_solo_chat_activity_log` (`activity_log_id`),
            KEY `idx_interagency_solo_chat_participants` (`sender_user_id`, `recipient_user_id`),
            KEY `idx_interagency_solo_chat_recipient_created` (`recipient_user_id`, `created_at`),
            KEY `idx_interagency_solo_chat_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_incident_cards` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT NOT NULL,
            `incident_id` INT NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
            `decided_by` INT DEFAULT NULL,
            `decided_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_incident_card_message` (`message_id`),
            KEY `idx_interagency_incident_card_incident` (`incident_id`),
            KEY `idx_interagency_incident_card_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ers_api_insert_activity_message(PDO $pdo, int $recipientUserId, string $details): int
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
             VALUES (NULL, 'chat', 'agency_user_chat', ?, ?, NOW())"
        );
        $stmt->execute([$recipientUserId, $details]);
        $id = (int)$pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        if (!ers_external_requires_manual_id($e)) {
            throw $e;
        }
        $id = ers_api_next_activity_id($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details, created_at)
             VALUES (?, NULL, 'chat', 'agency_user_chat', ?, ?, NOW())"
        );
        $stmt->execute([$id, $recipientUserId, $details]);
        return $id;
    }

    $id = ers_api_next_activity_id($pdo) - 1;
    if ($id <= 0) {
        throw new RuntimeException('Activity log insert did not return an id.');
    }
    return $id;
}

function ers_api_next_activity_id(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM activity_log');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    return max(1, (int)($row['next_id'] ?? 1));
}

function ers_api_update_incident_status(PDO $pdo): array
{
    $input = ers_external_input();
    $id = isset($input['id']) && is_numeric((string)$input['id']) ? (int)$input['id'] : 0;
    $referenceNo = ers_external_clean($input['reference_no'] ?? $input['code'] ?? '', 50);
    $status = ers_external_normalize_status($input['status'] ?? '');
    if (($id <= 0 && $referenceNo === '') || $status === '') {
        ers_external_json(422, ['success' => false, 'error' => 'Provide id or reference_no and valid status']);
    }

    $stmt = $pdo->prepare($id > 0 ? 'SELECT id, reference_no FROM incidents WHERE id = ? LIMIT 1' : 'SELECT id, reference_no FROM incidents WHERE reference_no = ? LIMIT 1');
    $stmt->execute([$id > 0 ? $id : $referenceNo]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$incident) {
        ers_external_json(404, ['success' => false, 'error' => 'Incident not found']);
    }

    $resolvedSql = $status === 'resolved' ? ', resolved_at = COALESCE(resolved_at, NOW())' : '';
    $update = $pdo->prepare("UPDATE incidents SET status = ?, updated_at = NOW() {$resolvedSql} WHERE id = ?");
    $update->execute([$status, (int)$incident['id']]);

    return [
        'success' => true,
        'message' => 'Incident status updated successfully.',
        'incident_id' => (int)$incident['id'],
        'reference_no' => $incident['reference_no'],
        'status' => $status,
    ];
}
?>
