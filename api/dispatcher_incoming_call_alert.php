<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed', 'calls' => []]);
    exit;
}

if (!is_logged_in() || current_session_role() !== 'dispatcher') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Dispatcher session required', 'calls' => []]);
    exit;
}

/**
 * Pick the first usable scalar from a set of payload objects.
 */
function dispatcher_call_alert_pick(array $sources, array $keys, string $default = ''): string
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

/**
 * Decode the stored transfer envelope without mutating or acknowledging it.
 */
function dispatcher_call_alert_payload(string $raw): array
{
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return [];
    }
    if (isset($payload['payload_json']) && is_string($payload['payload_json'])) {
        $nested = json_decode($payload['payload_json'], true);
        if (is_array($nested)) {
            $payload = array_replace($payload, $nested);
        }
    }
    return $payload;
}

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection unavailable', 'calls' => []]);
    exit;
}

try {
    // This endpoint is deliberately read-only. The existing Call Receiving page
    // remains the only owner of answering, rejecting, signaling, and call logging.
    $stmt = $pdo->query(
        "SELECT
            l.id AS transfer_log_id,
            l.source_system,
            l.external_incident_id,
            l.payload_json,
            l.created_at AS link_created_at,
            i.id AS incident_id,
            i.reference_no,
            i.status AS incident_status,
            i.priority,
            i.location_address,
            i.reported_by_call_id,
            c.caller_name,
            c.caller_phone
         FROM external_incident_links l
         INNER JOIN incidents i ON i.id = l.incident_id
         LEFT JOIN calls c ON c.id = i.reported_by_call_id
         WHERE UPPER(TRIM(COALESCE(i.reference_no, ''))) LIKE 'TRN-%'
           AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN ('resolved', 'cancelled', 'closed', 'rejected')
         ORDER BY l.id DESC
         LIMIT 50"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $callIds = [];
    foreach ($rows as $row) {
        $callId = (int)($row['reported_by_call_id'] ?? 0);
        if ($callId > 0) {
            $callIds[$callId] = true;
        }
    }

    $completedCallIds = [];
    if ($callIds !== []) {
        $ids = array_keys($callIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $auditStmt = $pdo->prepare(
            "SELECT DISTINCT entity_id
             FROM activity_log
             WHERE entity_type = 'call'
               AND action IN ('call_accepted', 'call_ended')
               AND entity_id IN ({$placeholders})"
        );
        $auditStmt->execute($ids);
        foreach ($auditStmt->fetchAll(PDO::FETCH_COLUMN) as $acceptedId) {
            $completedCallIds[(int)$acceptedId] = true;
        }
    }

    $calls = [];
    $seenCallKeys = [];
    $now = time();
    foreach ($rows as $row) {
        $reportedCallId = (int)($row['reported_by_call_id'] ?? 0);
        if ($reportedCallId > 0 && isset($completedCallIds[$reportedCallId])) {
            continue;
        }

        $payload = dispatcher_call_alert_payload((string)($row['payload_json'] ?? ''));
        $call = isset($payload['call']) && is_array($payload['call']) ? $payload['call'] : $payload;
        $caller = isset($payload['caller']) && is_array($payload['caller']) ? $payload['caller'] : [];
        $locationData = isset($payload['locationData']) && is_array($payload['locationData']) ? $payload['locationData'] : [];
        $incident = isset($payload['incident']) && is_array($payload['incident']) ? $payload['incident'] : [];
        $sources = [$call, $caller, $locationData, $incident, $payload, $row];

        $transferType = strtolower(dispatcher_call_alert_pick($sources, ['transfer_type', 'transferType']));
        $room = dispatcher_call_alert_pick([$call, $payload], ['room']);
        $externalCallId = dispatcher_call_alert_pick(
            $sources,
            ['callId', 'call_id', 'external_incident_id'],
            (string)($row['external_incident_id'] ?? '')
        );
        $isLiveCall = $transferType === 'live_call'
            || ($transferType !== 'report' && $room !== '' && $externalCallId !== '');
        if (!$isLiveCall) {
            continue;
        }

        $dedupeKey = $externalCallId !== ''
            ? $externalCallId
            : trim((string)($row['reference_no'] ?? ''));
        if ($dedupeKey !== '' && isset($seenCallKeys[$dedupeKey])) {
            continue;
        }
        if ($dedupeKey !== '') {
            $seenCallKeys[$dedupeKey] = true;
        }

        $transferredAt = dispatcher_call_alert_pick(
            [$call, $payload, $row],
            ['transferred_at', 'transferredAt', 'created_at', 'link_created_at']
        );
        $transferredTimestamp = $transferredAt !== '' ? strtotime($transferredAt) : false;
        if ($transferredTimestamp !== false && $transferredTimestamp <= $now && ($now - $transferredTimestamp) > 7200) {
            continue;
        }

        $referenceNo = trim((string)($row['reference_no'] ?? ''));
        $callerName = dispatcher_call_alert_pick($sources, ['caller_name', 'reporter_name', 'name'], 'Emergency caller');
        $sourceSystem = dispatcher_call_alert_pick($sources, ['source_system'], 'Partner emergency app');
        $location = dispatcher_call_alert_pick(
            $sources,
            ['location_address', 'caller_address', 'location', 'address'],
            'Location pending'
        );

        $calls[] = [
            'key' => (int)($row['transfer_log_id'] ?? 0) . ':' . $externalCallId,
            'transfer_log_id' => (int)($row['transfer_log_id'] ?? 0),
            'incident_id' => (int)($row['incident_id'] ?? 0),
            'reference_no' => $referenceNo,
            'caller_name' => $callerName,
            'caller_phone' => dispatcher_call_alert_pick($sources, ['caller_phone', 'phone', 'contact_number', 'contact']),
            'source_system' => $sourceSystem,
            'priority' => dispatcher_call_alert_pick($sources, ['priority'], (string)($row['priority'] ?? '')),
            'location' => $location,
            'transferred_at' => $transferredAt,
            'has_live_room' => $room !== '',
        ];

        if (count($calls) >= 5) {
            break;
        }
    }

    echo json_encode([
        'ok' => true,
        'count' => count($calls),
        'calls' => $calls,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('dispatcher incoming call alert failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to check incoming calls', 'calls' => []]);
}
