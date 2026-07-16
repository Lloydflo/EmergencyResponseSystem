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
            c.id AS call_db_id,
            c.caller_name,
            c.caller_phone,
            c.description
         FROM external_incident_links l
         INNER JOIN incidents i ON i.id = l.incident_id
         LEFT JOIN calls c ON c.id = i.reported_by_call_id
         WHERE l.id > :after_id
           AND (
                (l.payload_json LIKE '%\"room\"%' OR l.payload_json LIKE '%\"room\":%')
                AND (l.payload_json LIKE '%\"callId\"%' OR l.payload_json LIKE '%\"call_id\"%')
           )
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
        $externalCallId = (string)($call['callId'] ?? $call['call_id'] ?? $row['transfer_id'] ?? '');
        $room = (string)($call['room'] ?? $payload['room'] ?? '');
        if (trim($externalCallId) === '' || trim($room) === '') {
            continue;
        }

        $transfers[] = [
            'transfer_log_id' => (int)($row['transfer_log_id'] ?? 0),
            'source_system' => (string)($row['source_system'] ?? ''),
            'event' => (string)($call['event'] ?? $payload['event'] ?? 'incoming-transfer'),
            'transfer_id' => (string)($row['transfer_id'] ?? $call['callId'] ?? $call['call_id'] ?? ''),
            'call_id_external' => $externalCallId,
            'conversation_id' => (string)($call['conversationId'] ?? $call['conversation_id'] ?? $payload['conversationId'] ?? $payload['conversation_id'] ?? ''),
            'transfer_type' => 'live_call',
            'room' => $room,
            'socket_url' => (string)($call['socketUrl'] ?? $call['socket_url'] ?? $payload['socketUrl'] ?? $payload['socket_url'] ?? 'https://emergency-comm.alertaraqc.com'),
            'socket_path' => (string)($call['socketPath'] ?? $call['socket_path'] ?? $payload['socketPath'] ?? $payload['socket_path'] ?? '/socket.io'),
            'transport' => 'polling',
            'call_id' => (int)($row['call_db_id'] ?? 0),
            'caller_name' => (string)($row['caller_name'] ?? $call['caller_name'] ?? $call['name'] ?? 'Transferred Caller'),
            'caller_phone' => (string)($row['caller_phone'] ?? $call['caller_phone'] ?? $call['phone'] ?? ''),
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => (string)($row['reference_no'] ?? ''),
            'incident_status' => (string)($row['incident_status'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'priority' => (string)($row['priority'] ?? ''),
            'location' => (string)($row['location_address'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'transferred_at' => (string)($call['transferred_at'] ?? $payload['transferred_at'] ?? $row['transferred_at'] ?? ''),
        ];
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
