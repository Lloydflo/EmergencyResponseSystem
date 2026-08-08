<?php
declare(strict_types=1);

function ers_anonymous_tip_sync_env(string $key, string $default = ''): string
{
    if (function_exists('ers_env')) {
        return trim((string)ers_env($key, $default));
    }
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return $value === false || $value === null ? $default : trim((string)$value);
}

function ers_anonymous_tip_status_payload(PDO $pdo, int $incidentId, string $status, string $note = ''): ?array
{
    if ($incidentId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                at.id AS local_tip_id,
                at.tip_id,
                at.status AS tip_status,
                at.outcome,
                i.id AS incident_id,
                i.reference_no,
                i.status AS incident_status,
                i.updated_at,
                i.resolved_at,
                l.external_incident_id
            FROM external_incident_links l
            INNER JOIN incidents i ON i.id = l.incident_id
            LEFT JOIN anonymous_tips at
                ON at.tip_id = l.external_incident_id
                OR l.external_incident_id = CONCAT('anonymous-tip-', at.id)
            WHERE l.incident_id = ?
              AND l.source_system = 'Anonymous Tip Inbox'
            ORDER BY l.id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $rawStatus = strtolower(trim($status !== '' ? $status : (string)($row['incident_status'] ?? '')));
        $syncStatus = match ($rawStatus) {
            'assigned', 'acknowledged', 'dispatching', 'dispatched' => 'dispatched',
            'enroute', 'en_route', 'on_scene', 'ongoing', 'ongoing_dispatch', 'in_progress' => 'ongoing_dispatch',
            'resolved', 'complete', 'completed', 'closed' => 'completed',
            default => $rawStatus !== '' ? $rawStatus : 'new',
        };

        $payload = [
            'success' => true,
            'tip_id' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'tipId' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'tipID' => (string)($row['tip_id'] ?? $row['external_incident_id'] ?? ''),
            'id' => (int)($row['local_tip_id'] ?? 0),
            'action' => 'update_status',
            'status' => $syncStatus,
            'display_status' => $syncStatus,
            'interagency_status' => $syncStatus,
            'inter_agency_status' => $syncStatus,
            'backup_status' => $syncStatus,
            'request_status' => $syncStatus,
            'tip_status' => (string)($row['tip_status'] ?? ''),
            'incident_id' => (int)($row['incident_id'] ?? $incidentId),
            'incident_reference' => (string)($row['reference_no'] ?? ''),
            'incident_status' => (string)($row['incident_status'] ?? $syncStatus),
            'dispatched' => in_array($syncStatus, ['dispatched', 'ongoing_dispatch', 'completed'], true),
            'is_dispatched' => in_array($syncStatus, ['dispatched', 'ongoing_dispatch', 'completed'], true),
            'completed' => $syncStatus === 'completed',
            'is_completed' => $syncStatus === 'completed',
            'resolved' => $syncStatus === 'completed',
            'is_resolved' => $syncStatus === 'completed',
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'resolved_at' => (string)($row['resolved_at'] ?? ''),
            'source_system' => 'ERS',
        ];

        if ($note !== '') {
            $payload['note'] = $note;
        }

        return $payload;
    } catch (Throwable $e) {
        error_log('Anonymous tip status payload skipped: ' . $e->getMessage());
        return null;
    }
}

function ers_notify_anonymous_tip_status(PDO $pdo, int $incidentId, string $status, string $note = ''): bool
{
    $payload = ers_anonymous_tip_status_payload($pdo, $incidentId, $status, $note);
    if (!$payload || !function_exists('curl_init')) {
        return false;
    }

    $callbackUrl = ers_anonymous_tip_sync_env(
        'ANONYMOUS_TIP_STATUS_CALLBACK_URL',
        'https://surveillance.alertaraqc.com/api/tip_backup_status_receive.php'
    );
    if ($callbackUrl === '') {
        return false;
    }

    $apiKey = ers_anonymous_tip_sync_env(
        'SURVEILLANCE_API_KEY',
        ers_anonymous_tip_sync_env('ALERTARA_TRANSFER_API_KEY', ers_anonymous_tip_sync_env('ERS_EXTERNAL_API_KEY'))
    );

    $headers = [
        'Accept: application/json',
    ];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
        $headers[] = 'X-ERS-API-Key: ' . $apiKey;
    }

    $formResult = ers_post_anonymous_tip_status_callback(
        $callbackUrl,
        $headers,
        'application/x-www-form-urlencoded',
        http_build_query($payload)
    );
    if ($formResult['ok']) {
        return true;
    }

    $jsonResult = ers_post_anonymous_tip_status_callback(
        $callbackUrl,
        $headers,
        'application/json',
        (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($jsonResult['ok']) {
        return true;
    }

    error_log(
        'Anonymous tip status callback failed: form HTTP ' . $formResult['status']
        . ' ' . $formResult['error']
        . ' ' . substr($formResult['response'], 0, 300)
        . '; json HTTP ' . $jsonResult['status']
        . ' ' . $jsonResult['error']
        . ' ' . substr($jsonResult['response'], 0, 300)
    );
    return false;
}

/**
 * @param list<string> $headers
 * @return array{ok:bool,status:int,error:string,response:string}
 */
function ers_post_anonymous_tip_status_callback(
    string $callbackUrl,
    array $headers,
    string $contentType,
    string $body
): array {
    $requestHeaders = array_merge(['Content-Type: ' . $contentType], $headers);
    $ch = curl_init($callbackUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $bodyText = is_string($response) ? $response : '';
    return [
        'ok' => $error === '' && $httpStatus >= 200 && $httpStatus < 300
            && ers_anonymous_tip_callback_response_accepted($bodyText),
        'status' => $httpStatus,
        'error' => $error,
        'response' => $bodyText,
    ];
}

function ers_anonymous_tip_callback_response_accepted(string $body): bool
{
    $trimmed = trim($body);
    if ($trimmed === '') {
        return true;
    }

    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded)) {
        return stripos($trimmed, 'error') === false && stripos($trimmed, 'failed') === false;
    }

    foreach (['success', 'ok'] as $key) {
        if (array_key_exists($key, $decoded) && !$decoded[$key]) {
            return false;
        }
    }

    return true;
}
