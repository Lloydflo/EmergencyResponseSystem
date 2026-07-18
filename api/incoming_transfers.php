<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable', 'transfers' => []]);
    exit;
}

$afterId = isset($_GET['after_id']) ? max(0, (int)$_GET['after_id']) : 0;
$limit = isset($_GET['limit']) ? max(1, min(25, (int)$_GET['limit'])) : 10;
$latestOnly = isset($_GET['latest']) && (string)$_GET['latest'] === '1';

function incoming_transfer_pick(array $sources, array $keys, string $default = ''): string
{
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || is_array($source[$key]) || is_object($source[$key])) {
                continue;
            }
            $value = trim((string)$source[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return $default;
}

function incoming_transfer_pick_float(array $sources, array $keys): ?float
{
    $value = incoming_transfer_pick($sources, $keys);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

function incoming_transfer_fallback_rows(PDO $pdo, int $limit): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT
                i.id AS incident_id,
                i.reference_no,
                i.status AS incident_status,
                i.type,
                i.priority,
                i.location_address,
                i.latitude,
                i.longitude,
                i.description,
                i.created_at AS transferred_at,
                c.id AS call_db_id,
                c.caller_name,
                c.caller_phone,
                c.location_address AS call_location_address,
                c.latitude AS call_latitude,
                c.longitude AS call_longitude,
                c.incident_type AS call_incident_type,
                c.priority AS call_priority,
                c.description AS call_description
             FROM incidents i
             LEFT JOIN calls c ON c.id = i.reported_by_call_id
             WHERE i.status NOT IN ('resolved', 'cancelled', 'closed', 'rejected')
               AND NOT EXISTS (
                    SELECT 1
                    FROM external_incident_links l
                    WHERE l.incident_id = i.id
                    LIMIT 1
               )
               AND (
                    i.reference_no LIKE 'TRN-%'
                    OR LOWER(COALESCE(i.title, '')) LIKE '%transferred%'
                    OR LOWER(COALESCE(i.description, '')) LIKE '%transferred%'
                    OR LOWER(COALESCE(c.description, '')) LIKE '%transferred%'
                    OR LOWER(COALESCE(c.description, '')) LIKE '%alertaraqc%'
               )
             ORDER BY i.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('incoming transfers fallback failed: ' . $e->getMessage());
        return [];
    }
}

function incoming_transfer_fallback_item(array $row): array
{
    $detailSources = [$row];
    $latitude = incoming_transfer_pick_float($detailSources, ['call_latitude', 'latitude']);
    $longitude = incoming_transfer_pick_float($detailSources, ['call_longitude', 'longitude']);
    $incidentId = (int)($row['incident_id'] ?? 0);
    $fallbackText = strtolower(implode(' ', [
        (string)($row['reference_no'] ?? ''),
        (string)($row['description'] ?? ''),
        (string)($row['call_description'] ?? ''),
    ]));
    $looksLikeCall = strpos($fallbackText, 'transferred call') !== false
        || strpos($fallbackText, 'incoming transferred call') !== false
        || strpos($fallbackText, 'call from external') !== false;
    return [
        'transfer_log_id' => 0,
        'source_system' => 'AlertaraQC Emergency Communication',
        'event' => 'incoming-transfer-fallback',
        'transfer_id' => (string)($row['reference_no'] ?? ('incident-' . $incidentId)),
        'call_id_external' => '',
        'conversation_id' => '',
        'transfer_type' => $looksLikeCall ? 'live_call' : 'report',
        'room' => '',
        'socket_url' => 'https://emergency-comm.alertaraqc.com',
        'socket_path' => '/socket.io',
        'transport' => 'polling',
        'call_id' => (int)($row['call_db_id'] ?? 0),
        'caller_name' => incoming_transfer_pick($detailSources, ['caller_name'], 'Transferred Caller'),
        'caller_phone' => incoming_transfer_pick($detailSources, ['caller_phone']),
        'incident_id' => $incidentId,
        'reference_no' => (string)($row['reference_no'] ?? ''),
        'incident_status' => (string)($row['incident_status'] ?? ''),
        'type' => incoming_transfer_pick($detailSources, ['call_incident_type', 'type'], 'other'),
        'priority' => incoming_transfer_pick($detailSources, ['call_priority', 'priority'], 'medium'),
        'location' => incoming_transfer_pick($detailSources, ['call_location_address', 'location_address'], 'Location not provided'),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'description' => incoming_transfer_pick($detailSources, ['call_description', 'description'], 'Transferred incident has no description.'),
        'transferred_at' => incoming_transfer_pick($detailSources, ['transferred_at']),
        'fallback_notice' => 'This item was stored as an incident without live call room data. Use action=incoming-transfer with room/callId to answer audio.',
    ];
}

try {
    ers_external_ensure_link_table($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            l.id AS transfer_log_id,
            l.source_system,
            l.external_incident_id AS transfer_id,
            l.payload_json,
            l.created_at AS transferred_at,
            i.id AS incident_id,
            i.reference_no,
            i.status AS incident_status,
            i.type,
            i.priority,
            i.location_address,
            i.latitude,
            i.longitude,
            c.id AS call_db_id,
            c.caller_name,
            c.caller_phone,
            c.location_address AS call_location_address,
            c.latitude AS call_latitude,
            c.longitude AS call_longitude,
            c.incident_type AS call_incident_type,
            c.priority AS call_priority,
            c.status AS call_status,
            c.description
         FROM external_incident_links l
         INNER JOIN incidents i ON i.id = l.incident_id
         LEFT JOIN calls c ON c.id = i.reported_by_call_id
         WHERE l.id > :after_id
         ORDER BY l.id " . ($latestOnly ? 'DESC' : 'ASC') . "
         LIMIT {$limit}"
    );
    $stmt->execute([':after_id' => $afterId]);

    $transfers = [];
    $maxSeenId = $afterId;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $maxSeenId = max($maxSeenId, (int)($row['transfer_log_id'] ?? 0));
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        if (isset($payload['payload_json']) && is_string($payload['payload_json'])) {
            $decodedPayload = json_decode($payload['payload_json'], true);
            if (is_array($decodedPayload)) {
                $payload = array_replace($payload, $decodedPayload);
            }
        }
        $call = isset($payload['call']) && is_array($payload['call']) ? $payload['call'] : $payload;
        $incident = isset($payload['incident']) && is_array($payload['incident']) ? $payload['incident'] : [];
        $detailSources = [$row, $call, $incident, $payload];
        $externalCallId = incoming_transfer_pick($detailSources, ['callId', 'call_id'], (string)($row['transfer_id'] ?? ''));
        $room = incoming_transfer_pick([$call, $payload], ['room']);
        $isLiveCall = trim($externalCallId) !== '' && trim($room) !== '';
        if (trim($externalCallId) === '') {
            $externalCallId = (string)($row['transfer_id'] ?? '');
        }

        $latitude = incoming_transfer_pick_float($detailSources, ['call_latitude', 'latitude', 'lat']);
        $longitude = incoming_transfer_pick_float($detailSources, ['call_longitude', 'longitude', 'lng', 'lon']);

        $transfers[] = [
            'transfer_log_id' => (int)($row['transfer_log_id'] ?? 0),
            'source_system' => (string)($row['source_system'] ?? ''),
            'event' => incoming_transfer_pick([$call, $payload], ['event'], 'incoming-transfer'),
            'transfer_id' => incoming_transfer_pick([$row, $call, $payload], ['transfer_id', 'callId', 'call_id']),
            'call_id_external' => $externalCallId,
            'conversation_id' => incoming_transfer_pick([$call, $payload], ['conversationId', 'conversation_id']),
            'transfer_type' => $isLiveCall ? 'live_call' : 'report',
            'room' => $room,
            'socket_url' => incoming_transfer_pick([$call, $payload], ['socketUrl', 'socket_url'], 'https://emergency-comm.alertaraqc.com'),
            'socket_path' => incoming_transfer_pick([$call, $payload], ['socketPath', 'socket_path'], '/socket.io'),
            'transport' => 'polling',
            'call_id' => (int)($row['call_db_id'] ?? 0),
            'caller_name' => incoming_transfer_pick($detailSources, ['caller_name', 'reporter_name', 'name'], 'Transferred Caller'),
            'caller_phone' => incoming_transfer_pick($detailSources, ['caller_phone', 'phone', 'contact_number', 'contact']),
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'incident_status' => (string)($row['incident_status'] ?? ''),
            'type' => incoming_transfer_pick($detailSources, ['call_incident_type', 'type', 'incident_type', 'emergencyType', 'emergency_type', 'department']),
            'priority' => incoming_transfer_pick($detailSources, ['call_priority', 'priority']),
            'location' => incoming_transfer_pick($detailSources, ['call_location_address', 'location_address', 'caller_address', 'location', 'address']),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'description' => incoming_transfer_pick($detailSources, ['description', 'details', 'notes', 'message', 'summary']),
            'transferred_at' => incoming_transfer_pick([$call, $payload, $row], ['transferred_at', 'created_at']),
        ];
    }

    $fallbackRows = incoming_transfer_fallback_rows($pdo, $limit);
    foreach ($fallbackRows as $fallbackRow) {
        $fallbackItem = incoming_transfer_fallback_item($fallbackRow);
        if ((int)($fallbackItem['incident_id'] ?? 0) > 0) {
            $transfers[] = $fallbackItem;
        }
    }

    echo json_encode([
        'ok' => true,
        'latest_id' => count($transfers) ? max(array_column($transfers, 'transfer_log_id')) : $maxSeenId,
        'transfers' => $transfers,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('incoming transfers feed failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load incoming transfers', 'transfers' => []]);
}
?>
