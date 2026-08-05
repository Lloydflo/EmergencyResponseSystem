<?php
declare(strict_types=1);

function ers_status_sync_env(string $key, string $default = ''): string
{
    if (function_exists('ers_env')) {
        return trim((string)ers_env($key, $default));
    }
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value === false || $value === null
        ? $default
        : trim((string)$value);
}

function ers_emergency_com_status(PDO $pdo, int $incidentId): ?array
{
    if ($incidentId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.reference_no, i.status, l.external_incident_id, l.payload_json
            FROM incidents i
            INNER JOIN external_incident_links l ON l.incident_id = i.id
            WHERE i.id = ?
            ORDER BY l.id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $call = isset($payload['call']) && is_array($payload['call']) ? $payload['call'] : $payload;
        $conversationId = (int)(
            $call['emergencyComConversationId']
            ?? $call['emergency_com_conversation_id']
            ?? $call['conversationId']
            ?? $call['conversation_id']
            ?? $payload['conversationId']
            ?? $payload['conversation_id']
            ?? 0
        );

        $rawStatus = strtolower(trim((string)($row['status'] ?? 'pending')));
        $syncStatus = match ($rawStatus) {
            'new', 'pending', 'received' => 'received',
            'assigned', 'acknowledged', 'dispatching', 'dispatched' => 'dispatching',
            'enroute', 'en_route', 'on_scene', 'ongoing', 'ongoing_dispatch', 'in_progress' => 'ongoing_dispatch',
            'resolved' => 'resolved',
            'complete', 'completed', 'closed' => 'completed',
            default => $rawStatus,
        };

        if (in_array($rawStatus, ['dispatched', 'dispatching'], true)) {
            $dispatch = $pdo->prepare("
                SELECT status
                FROM dispatches
                WHERE incident_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $dispatch->execute([$incidentId]);
            $dispatchStatus = strtolower(trim((string)$dispatch->fetchColumn()));
            if (in_array($dispatchStatus, ['enroute', 'on_scene'], true)) {
                $syncStatus = 'ongoing_dispatch';
            }
        }

        return [
            'incidentId' => $incidentId,
            'referenceNo' => (string)($row['reference_no'] ?? ''),
            'transferId' => (string)($row['external_incident_id'] ?? ''),
            'conversationId' => $conversationId,
            'status' => $syncStatus,
        ];
    } catch (Throwable $e) {
        error_log('EmergencyCom status lookup skipped: ' . $e->getMessage());
        return null;
    }
}

function ers_notify_emergency_com_status(
    PDO $pdo,
    int $incidentId,
    string $note = '',
    ?string $statusOverride = null
): bool
{
    $payload = ers_emergency_com_status($pdo, $incidentId);
    if (!$payload || !function_exists('curl_init')) {
        return false;
    }
    if ($statusOverride !== null) {
        $normalizedOverride = strtolower(trim($statusOverride));
        if (in_array($normalizedOverride, [
            'received',
            'dispatching',
            'ongoing_dispatch',
            'resolved',
            'completed',
        ], true)) {
            $payload['status'] = $normalizedOverride;
        }
    }
    if ($note !== '') {
        $payload['note'] = $note;
    }

    $callbackUrl = ers_status_sync_env(
        'EMERGENCY_COM_STATUS_CALLBACK_URL',
        'https://emergency-comm.alertaraqc.com/api/report-status.php'
    );
    $apiKey = ers_status_sync_env(
        'ALERTARA_TRANSFER_API_KEY',
        ers_status_sync_env('ERS_EXTERNAL_API_KEY')
    );
    if ($callbackUrl === '' || $apiKey === '') {
        return false;
    }

    $ch = curl_init($callbackUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $apiKey,
            'X-ERS-API-Key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '' || $status < 200 || $status >= 300) {
        error_log('EmergencyCom status callback failed: HTTP ' . $status . ' ' . $error . ' ' . substr((string)$response, 0, 300));
        return false;
    }
    return true;
}
