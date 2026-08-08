<?php
declare(strict_types=1);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/incident_priority.php';
require_once __DIR__ . '/system_API/group1_incident_client.php';
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

$caller_name = trim((string)($input['caller_name'] ?? ''));
$caller_phone = trim((string)($input['caller_phone'] ?? ''));
$type = normalize_incident_type_input($input['type'] ?? '');
$location = trim((string)($input['location'] ?? ''));
$description = trim((string)($input['description'] ?? ''));
$priority = ers_normalize_priority_value(trim((string)($input['priority'] ?? 'medium')));
$status = trim((string)($input['status'] ?? 'pending'));
$latitude = array_key_exists('latitude', $input) && $input['latitude'] !== '' ? (float)$input['latitude'] : null;
$longitude = array_key_exists('longitude', $input) && $input['longitude'] !== '' ? (float)$input['longitude'] : null;
$transfer_incident_id = transfer_incident_id_from_input($input);
$received_at = ers_audit_normalize_operational_datetime($input['received_at'] ?? null, true);
$accepted_at = ers_audit_normalize_operational_datetime($input['accepted_at'] ?? null, false);
$audit_session_id = calls_create_normalize_audit_session_id($input['audit_session_id'] ?? null);
if ($accepted_at !== null && $received_at !== null) {
    try {
        $auditTimezone = new DateTimeZone('Asia/Manila');
        $receivedEpoch = (new DateTimeImmutable($received_at, $auditTimezone))->getTimestamp();
        $acceptedEpoch = (new DateTimeImmutable($accepted_at, $auditTimezone))->getTimestamp();
        if ($acceptedEpoch < $receivedEpoch) {
            $accepted_at = $received_at;
        }
    } catch (Throwable $e) {
        $accepted_at = null;
    }
}

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

if ($caller_name === '' || $caller_phone === '' || $type === '' || $location === '' || $description === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    ensure_no_auto_value_on_zero_mode($pdo);
    // Self-heal for deployments where id columns were created without AUTO_INCREMENT.
    ensure_auto_increment_identity($pdo, 'calls');
    ensure_auto_increment_identity($pdo, 'incidents');
    ers_ensure_incident_priority_schema($pdo);
} catch (Throwable $schemaErr) {
    error_log('calls_create schema check warning: ' . $schemaErr->getMessage());
}

$transfer_incident_id = $transfer_incident_id > 0
    ? $transfer_incident_id
    : resolve_transfer_incident_id($pdo, $input);

if ($transfer_incident_id > 0) {
    try {
        $updatedIncident = update_existing_transfer_incident(
            $pdo,
            $transfer_incident_id,
            $caller_name,
            $caller_phone,
            $type,
            $location,
            $description,
            $priority,
            $status,
            $latitude,
            $longitude
        );
        if ($updatedIncident !== null) {
            $updatedCallId = isset($updatedIncident['call_id']) ? (int)$updatedIncident['call_id'] : null;
            calls_create_link_audit_session(
                $pdo,
                $audit_session_id,
                $updatedCallId,
                (int)$updatedIncident['id'],
                (string)$updatedIncident['reference_no']
            );
            log_incident_created_audit(
                $pdo,
                $updatedCallId,
                (int)$updatedIncident['id'],
                (string)$updatedIncident['reference_no'],
                $type,
                $priority,
                $location,
                true,
                $accepted_at,
                $audit_session_id
            );
            $group1Sync = calls_create_try_group1_sync(
                $pdo,
                isset($updatedIncident['call_id']) ? (int)$updatedIncident['call_id'] : 0,
                (int)$updatedIncident['id']
            );
            echo json_encode([
                'ok' => true,
                'updated_transfer' => true,
                'call_id' => $updatedIncident['call_id'],
                'reference_no' => $updatedIncident['reference_no'],
                'incident_id' => (int)$updatedIncident['id'],
                'incident_reference_no' => $updatedIncident['reference_no'],
                'incident_status' => $updatedIncident['status'],
                'priority' => $priority,
                'group1_sync' => $group1Sync,
            ]);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        error_log('calls_create transfer update failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => build_user_facing_db_error($e)]);
        exit;
    }
}

$duplicate_sql = 'SELECT id, reference_no, type, location_address, created_at
                  FROM incidents
                  WHERE type = :type
                    AND location_address = :location
                    AND created_at >= (NOW() - INTERVAL 60 MINUTE)
                  LIMIT 1';
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

$reference_no = 'REF-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
$callStatus = $status === 'pending' ? 'new' : 'triaged';

try {
    $pdo->beginTransaction();

    $call_id = insert_call_row($pdo, [
        ':reference_no' => $reference_no,
        ':caller_name' => $caller_name,
        ':caller_phone' => $caller_phone,
        ':location_address' => $location,
        ':latitude' => $latitude,
        ':longitude' => $longitude,
        ':incident_type' => $type,
        ':priority' => $priority,
        ':status' => $callStatus,
        ':description' => $description,
        ':received_at' => $received_at,
    ]);

    $stmt2 = $pdo->prepare('SELECT id, reference_no, status FROM incidents WHERE reported_by_call_id = :cid LIMIT 1');
    $stmt2->execute([':cid' => $call_id]);
    $incident = $stmt2->fetch();

    // Fallback if DB trigger is missing/disabled: create paired incident manually.
    if (!$incident) {
        $incident_id = insert_incident_row($pdo, [
            ':reference_no' => $reference_no,
            ':type' => $type,
            ':priority' => $priority,
            ':title' => 'Incident from call ' . $reference_no,
            ':description' => $description,
            ':location_address' => $location,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':reported_by_call_id' => $call_id,
        ]);
        $incident = [
            'id' => $incident_id,
            'reference_no' => $reference_no,
            'status' => 'pending',
        ];
        update_incident_priority_indicator($pdo, $incident_id, $priority);
    } else {
        update_incident_priority_indicator($pdo, (int)$incident['id'], $priority);
    }

    $pdo->commit();
    log_incident_created_audit($incident ? (int)$incident['id'] : null, $incident ? (string)$incident['reference_no'] : $reference_no, $type, $priority, $location);

    echo json_encode([
        'ok' => true,
        'call_id' => $call_id,
        'reference_no' => $reference_no,
        'incident_id' => $incident ? (int)$incident['id'] : null,
        'incident_reference_no' => $incident ? $incident['reference_no'] : null,
        'incident_status' => $incident ? $incident['status'] : null,
        'priority' => $priority,
        'group1_sync' => $group1Sync,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('calls_create insert failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => build_user_facing_db_error($e)]);
}

function log_incident_created_audit(
    PDO $pdo,
    ?int $callId,
    ?int $incidentId,
    string $referenceNo,
    string $type,
    string $priority,
    string $location,
    bool $updatedTransfer = false,
    ?string $acceptedAt = null,
    ?string $auditSessionId = null
): void {
    if ($incidentId === null || $incidentId < 1) {
        return;
    }

    $userId = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0
        ? (int)$_SESSION['user_id']
        : null;
    $rawRole = strtolower(trim((string)($_SESSION['login_role'] ?? $_SESSION['user_role'] ?? '')));
    $actorRole = in_array($rawRole, ['admin', 'dispatcher'], true) ? $rawRole : 'system';
    $source = $actorRole === 'dispatcher'
        ? 'dispatcher_web'
        : ($actorRole === 'admin' ? 'admin_web' : 'external_api');

    $callReceivedAt = null;
    $incidentCreatedAt = null;
    try {
        if ($callId !== null && $callId > 0) {
            $callStmt = $pdo->prepare('SELECT received_at FROM calls WHERE id = ? LIMIT 1');
            $callStmt->execute([$callId]);
            $callReceivedAt = $callStmt->fetchColumn() ?: null;
        }
        $incidentStmt = $pdo->prepare('SELECT created_at FROM incidents WHERE id = ? LIMIT 1');
        $incidentStmt->execute([$incidentId]);
        $incidentCreatedAt = $incidentStmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        // The operational write remains best effort if a legacy schema differs.
    }

    $safeReference = $referenceNo !== '' ? $referenceNo : ('Incident #' . $incidentId);
    $baseMetadata = [
        'incident_type' => $type,
        'priority' => $priority,
        'location_address' => $location,
        'transfer_intake_updated' => $updatedTransfer,
    ];

    if ($callId !== null && $callId > 0) {
        $callEventPrefix = $auditSessionId !== null
            ? ('call_session:' . $auditSessionId)
            : ('call:' . $callId);
        record_operational_audit_event(
            $pdo,
            $userId,
            'call_received',
            'call',
            $callId,
            ($updatedTransfer ? 'Transferred call intake was confirmed for ' : 'Emergency call was received for ') . $safeReference . '.',
            [
                'actor_role' => $actorRole,
                'source_channel' => $source,
                'event_category' => 'call_intake',
                'event_outcome' => 'success',
                'reference_no' => $referenceNo,
                'incident_id' => $incidentId,
                'call_id' => $callId,
                'occurred_at' => $callReceivedAt,
                'event_key' => $callEventPrefix . ':received',
                'metadata' => $baseMetadata,
            ]
        );

        if ($acceptedAt !== null) {
            $acceptanceDelaySeconds = null;
            try {
                $auditTimezone = new DateTimeZone('Asia/Manila');
                $receivedEpoch = $callReceivedAt !== null
                    ? (new DateTimeImmutable((string)$callReceivedAt, $auditTimezone))->getTimestamp()
                    : null;
                $acceptedEpoch = (new DateTimeImmutable($acceptedAt, $auditTimezone))->getTimestamp();
                if ($receivedEpoch !== null) {
                    $acceptanceDelaySeconds = max(0, $acceptedEpoch - $receivedEpoch);
                }
            } catch (Throwable $e) {
                $acceptanceDelaySeconds = null;
            }

            record_operational_audit_event(
                $pdo,
                $userId,
                'call_accepted',
                'call',
                $callId,
                'Emergency call was accepted for ' . $safeReference . '.',
                [
                    'actor_role' => $actorRole,
                    'source_channel' => $source,
                    'event_category' => 'call_intake',
                    'event_outcome' => 'success',
                    'reference_no' => $referenceNo,
                    'incident_id' => $incidentId,
                    'call_id' => $callId,
                    'occurred_at' => $acceptedAt,
                    'event_key' => $callEventPrefix . ':accepted',
                    'metadata' => array_merge($baseMetadata, [
                        'call_received_at' => $callReceivedAt,
                        'call_accepted_at' => $acceptedAt,
                        'acceptance_delay_seconds' => $acceptanceDelaySeconds,
                    ]),
                ]
            );
        }
    }

    record_operational_audit_event(
        $pdo,
        $userId,
        'incident_created',
        'incident',
        $incidentId,
        'Incident record ' . $safeReference . ' was created and queued for validation and dispatch.',
        [
            'actor_role' => $actorRole,
            'source_channel' => $source,
            'event_category' => 'incident',
            'event_outcome' => 'success',
            'reference_no' => $referenceNo,
            'incident_id' => $incidentId,
            'call_id' => $callId,
            'occurred_at' => $incidentCreatedAt,
            'event_key' => 'incident:' . $incidentId . ':created',
            'metadata' => $baseMetadata,
        ]
    );
}

function calls_create_normalize_audit_session_id($value): ?string {
    $value = trim((string)$value);
    return preg_match('/^[A-Za-z0-9.:-]{8,96}$/', $value) ? $value : null;
}

function calls_create_link_audit_session(
    PDO $pdo,
    ?string $auditSessionId,
    ?int $callId,
    ?int $incidentId,
    string $referenceNo
): void {
    if ($auditSessionId === null || !calls_create_table_exists($pdo, 'activity_log')) {
        return;
    }
    if (!calls_create_column_exists($pdo, 'activity_log', 'event_key')) {
        return;
    }

    $entityType = ($callId !== null && $callId > 0) ? 'call' : 'incident';
    $entityId = ($callId !== null && $callId > 0) ? $callId : $incidentId;
    if ($entityId === null || $entityId < 1) {
        return;
    }

    $set = [];
    $params = [];
    if (calls_create_column_exists($pdo, 'activity_log', 'entity_type')) {
        $set[] = 'entity_type = ?';
        $params[] = $entityType;
    }
    if (calls_create_column_exists($pdo, 'activity_log', 'entity_id')) {
        $set[] = 'entity_id = ?';
        $params[] = $entityId;
    }
    if (calls_create_column_exists($pdo, 'activity_log', 'reference_no')) {
        $set[] = "reference_no = CASE WHEN COALESCE(NULLIF(reference_no, ''), '') = '' THEN ? ELSE reference_no END";
        $params[] = substr(trim($referenceNo), 0, 64);
    }
    if ($set === []) {
        return;
    }

    $eventKeys = [];
    foreach (['received', 'accepted', 'rejected', 'ended'] as $milestone) {
        $eventKeys[] = 'call_session:' . $auditSessionId . ':' . $milestone;
    }
    $params = array_merge($params, $eventKeys);
    try {
        $statement = $pdo->prepare(
            'UPDATE activity_log SET ' . implode(', ', $set)
            . ' WHERE event_key IN (' . implode(',', array_fill(0, count($eventKeys), '?')) . ')'
        );
        $statement->execute($params);
    } catch (Throwable $e) {
        error_log('calls_create audit-session link warning: ' . $e->getMessage());
    }
}

function calls_create_try_group1_sync(PDO $pdo, int $callId, int $incidentId): array {
    $result = ers_group1_send_logged_incident($pdo, $callId, $incidentId);
    return [
        'success' => (bool)($result['success'] ?? false),
        'status' => (string)($result['status'] ?? 'failed'),
        'message' => (string)($result['message'] ?? 'Unable to send incident data.'),
        'sync_log_id' => $result['sync_log_id'] ?? null,
        'http_code' => $result['http_code'] ?? null,
        'error' => $result['error'] ?? null,
    ];
}

function transfer_incident_id_from_input(array $input): int {
    foreach (['transfer_incident_id', 'existing_incident_id', 'linked_incident_id'] as $key) {
        if (isset($input[$key]) && is_numeric((string)$input[$key])) {
            $id = (int)$input[$key];
            if ($id > 0) {
                return $id;
            }
        }
    }
    return 0;
}

function resolve_transfer_incident_id(PDO $pdo, array $input): int {
    $ids = [];
    foreach ([
        $input['transfer_id'] ?? '',
        $input['transfer_call_id'] ?? '',
        $input['call_id_external'] ?? '',
        $input['callId'] ?? '',
        $input['call_id'] ?? '',
    ] as $value) {
        $value = trim((string)$value);
        if ($value !== '' && !in_array($value, $ids, true)) {
            $ids[] = $value;
        }
    }

    if ($ids !== [] && calls_create_table_exists($pdo, 'external_incident_links')) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $stmt = $pdo->prepare(
            "SELECT incident_id
             FROM external_incident_links
             WHERE external_incident_id IN ({$placeholders})
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute($params);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $jsonWhere = [];
        $jsonParams = [];
        foreach ($ids as $idValue) {
            foreach (['callId', 'call_id', 'transferId', 'transfer_id'] as $jsonKey) {
                $jsonWhere[] = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.{$jsonKey}')) = ?";
                $jsonParams[] = $idValue;
                $jsonWhere[] = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.call.{$jsonKey}')) = ?";
                $jsonParams[] = $idValue;
            }
        }
        if ($jsonWhere !== []) {
            $stmt = $pdo->prepare(
                'SELECT incident_id
                 FROM external_incident_links
                 WHERE (' . implode(' OR ', $jsonWhere) . ')
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute($jsonParams);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
    }

    $referenceNo = trim((string)($input['transfer_reference_no'] ?? $input['incident_reference_no'] ?? $input['reference_no'] ?? ''));
    if ($referenceNo !== '' && calls_create_table_exists($pdo, 'incidents')) {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM incidents
             WHERE reference_no = ?
               AND status NOT IN ('resolved', 'cancelled', 'closed', 'rejected')
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$referenceNo]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

function update_existing_transfer_incident(
    PDO $pdo,
    int $incidentId,
    string $callerName,
    string $callerPhone,
    string $type,
    string $location,
    string $description,
    string $priority,
    string $requestedStatus,
    ?float $latitude,
    ?float $longitude
): ?array {
    if ($incidentId <= 0) {
        return null;
    }

    $pdo->beginTransaction();
    $hasExternalLinkTable = calls_create_table_exists($pdo, 'external_incident_links');
    $externalLinkExpr = $hasExternalLinkTable
        ? '(SELECT COUNT(*) FROM external_incident_links l WHERE l.incident_id = incidents.id LIMIT 1) AS transfer_link_count'
        : '0 AS transfer_link_count';
    $stmt = $pdo->prepare(
        'SELECT id, reference_no, status, title, description, reported_by_call_id, ' . $externalLinkExpr . '
         FROM incidents
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$incident) {
        $pdo->rollBack();
        return null;
    }

    $transferMarkerText = strtolower(implode(' ', [
        (string)($incident['reference_no'] ?? ''),
        (string)($incident['title'] ?? ''),
        (string)($incident['description'] ?? ''),
    ]));
    $hasTransferMarker = (int)($incident['transfer_link_count'] ?? 0) > 0
        || strpos($transferMarkerText, 'trn-') !== false
        || strpos($transferMarkerText, 'transfer') !== false
        || strpos($transferMarkerText, 'alertaraqc') !== false;
    if (!$hasTransferMarker) {
        $pdo->rollBack();
        return null;
    }

    $currentStatus = strtolower(trim((string)($incident['status'] ?? '')));
    if (in_array($currentStatus, ['resolved', 'cancelled', 'closed', 'rejected'], true)) {
        $pdo->rollBack();
        return null;
    }

    $referenceNo = trim((string)($incident['reference_no'] ?? ''));
    if ($referenceNo === '') {
        $referenceNo = 'REF-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    $nextIncidentStatus = strtolower(trim($requestedStatus));
    if (!in_array($nextIncidentStatus, ['pending', 'dispatched', 'resolved'], true)) {
        $nextIncidentStatus = $currentStatus !== '' ? $currentStatus : 'pending';
    }
    $nextCallStatus = $nextIncidentStatus === 'pending' ? 'new' : 'triaged';
    $callId = (int)($incident['reported_by_call_id'] ?? 0);

    if ($callId > 0) {
        $callUpdatedAtSet = calls_create_column_exists($pdo, 'calls', 'updated_at')
            ? ', updated_at = CURRENT_TIMESTAMP'
            : '';
        $callUpdate = $pdo->prepare(
            'UPDATE calls
             SET reference_no = ?,
                 caller_name = ?,
                 caller_phone = ?,
                 location_address = ?,
                 latitude = ?,
                 longitude = ?,
                 incident_type = ?,
                 priority = ?,
                 status = ?,
                 description = ?' . $callUpdatedAtSet . '
             WHERE id = ?'
        );
        $callUpdate->execute([
            $referenceNo,
            $callerName,
            $callerPhone,
            $location,
            $latitude,
            $longitude,
            $type,
            $priority,
            $nextCallStatus,
            $description,
            $callId,
        ]);
    }

    $title = 'Incident from call ' . $referenceNo;
    $incidentUpdatedAtSet = calls_create_column_exists($pdo, 'incidents', 'updated_at')
        ? ', updated_at = CURRENT_TIMESTAMP'
        : '';
    $incidentUpdate = $pdo->prepare(
        'UPDATE incidents
         SET reference_no = ?,
             type = ?,
             priority = ?,
             status = ?,
             title = ?,
             description = ?,
             location_address = ?,
             latitude = ?,
             longitude = ?' . $incidentUpdatedAtSet . '
         WHERE id = ?'
    );
    $incidentUpdate->execute([
        $referenceNo,
        $type,
        $priority,
        $nextIncidentStatus,
        $title,
        $description,
        $location,
        $latitude,
        $longitude,
        $incidentId,
    ]);

    update_incident_priority_indicator($pdo, $incidentId, $priority);

    $pdo->commit();

    return [
        'id' => $incidentId,
        'reference_no' => $referenceNo,
        'status' => $nextIncidentStatus,
        'call_id' => $callId > 0 ? $callId : null,
    ];
}

function calls_create_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function calls_create_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$tableName, $columnName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function insert_call_row(PDO $pdo, array $params): int {
    $sql = 'INSERT INTO calls (
                reference_no, caller_name, caller_phone, caller_email, location_address, latitude, longitude,
                incident_type, priority, status, description, received_at
            )
            VALUES (
                :reference_no, :caller_name, :caller_phone, NULL, :location_address, :latitude, :longitude,
                :incident_type, :priority, :status, :description, :received_at
            )';
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($params);
    } catch (Throwable $e) {
        if (!requires_manual_id($e)) {
            throw $e;
        }
        return insert_call_row_with_id($pdo, $params);
    }

    $id = (int)$pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }
    $lookup = $pdo->prepare('SELECT id FROM calls WHERE reference_no = :reference_no LIMIT 1');
    $lookup->execute([':reference_no' => $params[':reference_no']]);
    $row = $lookup->fetch();
    if ($row && isset($row['id'])) {
        return (int)$row['id'];
    }
    throw new RuntimeException('Call insert did not return a valid id');
}

function normalize_incident_type_input($value): string {
    $allowed = ['medical', 'fire', 'police', 'traffic', 'rescue', 'other'];
    $rawItems = [];

    if (is_array($value)) {
        $rawItems = $value;
    } else {
        $rawItems = preg_split('/[,|]+/', (string)$value) ?: [];
    }

    $items = [];
    foreach ($rawItems as $item) {
        $normalized = strtolower(trim((string)$item));
        if ($normalized === 'ambulance') {
            $normalized = 'medical';
        } elseif ($normalized === 'accident') {
            $normalized = 'traffic';
        } elseif ($normalized === 'crime') {
            $normalized = 'police';
        }
        if (in_array($normalized, $allowed, true) && !in_array($normalized, $items, true)) {
            $items[] = $normalized;
        }
    }

    return implode(', ', $items);
}

function insert_call_row_with_id(PDO $pdo, array $params): int {
    $sql = 'INSERT INTO calls (
                id, reference_no, caller_name, caller_phone, caller_email, location_address, latitude, longitude,
                incident_type, priority, status, description, received_at
            )
            VALUES (
                :id, :reference_no, :caller_name, :caller_phone, NULL, :location_address, :latitude, :longitude,
                :incident_type, :priority, :status, :description, :received_at
            )';
    $stmt = $pdo->prepare($sql);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $id = next_numeric_id($pdo, 'calls');
        $payload = $params;
        $payload[':id'] = $id;
        try {
            $stmt->execute($payload);
            return $id;
        } catch (Throwable $e) {
            if (is_duplicate_key_error($e)) {
                continue;
            }
            throw $e;
        }
    }
    throw new RuntimeException('Unable to allocate id for calls table');
}

function insert_incident_row(PDO $pdo, array $params): int {
    $sql = 'INSERT INTO incidents (reference_no, type, priority, status, title, description, location_address, latitude, longitude, reported_by_call_id, created_at)
            VALUES (:reference_no, :type, :priority, \'pending\', :title, :description, :location_address, :latitude, :longitude, :reported_by_call_id, NOW())';
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($params);
    } catch (Throwable $e) {
        if (!requires_manual_id($e)) {
            throw $e;
        }
        return insert_incident_row_with_id($pdo, $params);
    }

    $id = (int)$pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }
    $lookup = $pdo->prepare('SELECT id FROM incidents WHERE reference_no = :reference_no LIMIT 1');
    $lookup->execute([':reference_no' => $params[':reference_no']]);
    $row = $lookup->fetch();
    if ($row && isset($row['id'])) {
        return (int)$row['id'];
    }
    throw new RuntimeException('Incident insert did not return a valid id');
}

function update_incident_priority_indicator(PDO $pdo, int $incidentId, string $priority): void {
    if ($incidentId < 1) {
        return;
    }
    $updatedAtSet = calls_create_column_exists($pdo, 'incidents', 'updated_at')
        ? ', updated_at = CURRENT_TIMESTAMP'
        : '';
    $stmt = $pdo->prepare(
        'UPDATE incidents
         SET priority = :priority' . $updatedAtSet . '
         WHERE id = :id'
    );
    $stmt->execute([
        ':priority' => $priority,
        ':id' => $incidentId,
    ]);
}

function insert_incident_row_with_id(PDO $pdo, array $params): int {
    $sql = 'INSERT INTO incidents (id, reference_no, type, priority, status, title, description, location_address, latitude, longitude, reported_by_call_id, created_at)
            VALUES (:id, :reference_no, :type, :priority, \'pending\', :title, :description, :location_address, :latitude, :longitude, :reported_by_call_id, NOW())';
    $stmt = $pdo->prepare($sql);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $id = next_numeric_id($pdo, 'incidents');
        $payload = $params;
        $payload[':id'] = $id;
        try {
            $stmt->execute($payload);
            return $id;
        } catch (Throwable $e) {
            if (is_duplicate_key_error($e)) {
                continue;
            }
            throw $e;
        }
    }
    throw new RuntimeException('Unable to allocate id for incidents table');
}

function next_numeric_id(PDO $pdo, string $table): int {
    if (!in_array($table, ['calls', 'incidents'], true)) {
        throw new InvalidArgumentException('Invalid table for numeric id allocation');
    }
    $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM `' . $table . '`');
    $row = $stmt ? $stmt->fetch() : null;
    $next = (int)($row['next_id'] ?? 1);
    return $next > 0 ? $next : 1;
}

function ensure_auto_increment_identity(PDO $pdo, string $table): void {
    if (!in_array($table, ['calls', 'incidents'], true)) {
        return;
    }
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = \'id\'
         LIMIT 1'
    );
    $stmt->execute([':table' => $table]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    $extra = strtolower((string)($row['EXTRA'] ?? ''));
    if (strpos($extra, 'auto_increment') !== false) {
        return;
    }
    $columnType = trim((string)($row['COLUMN_TYPE'] ?? ''));
    if ($columnType === '') {
        return;
    }
    $pdo->exec('ALTER TABLE `' . $table . '` MODIFY `id` ' . $columnType . ' NOT NULL AUTO_INCREMENT');
}

function ensure_no_auto_value_on_zero_mode(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $stmt = $pdo->query('SELECT @@SESSION.sql_mode AS mode');
    $row = $stmt ? $stmt->fetch() : null;
    $currentMode = trim((string)($row['mode'] ?? ''));
    if (stripos($currentMode, 'NO_AUTO_VALUE_ON_ZERO') !== false) {
        $done = true;
        return;
    }
    $newMode = $currentMode === '' ? 'NO_AUTO_VALUE_ON_ZERO' : ($currentMode . ',NO_AUTO_VALUE_ON_ZERO');
    $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($newMode));
    $done = true;
}

function requires_manual_id(Throwable $e): bool {
    if ($e instanceof PDOException) {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if (in_array($driverCode, [1048, 1364], true)) {
            return true;
        }
    }
    $msg = strtolower($e->getMessage());
    return strpos($msg, 'field \'id\' doesn\'t have a default value') !== false
        || strpos($msg, 'column \'id\' cannot be null') !== false;
}

function is_duplicate_key_error(Throwable $e): bool {
    if ($e instanceof PDOException) {
        return (int)($e->errorInfo[1] ?? 0) === 1062;
    }
    return false;
}

function build_user_facing_db_error(Throwable $e): string {
    if (requires_manual_id($e)) {
        return 'Database id configuration issue detected. Please retry.';
    }
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        return 'Database key conflict detected. Please retry.';
    }
    return 'Insert failed';
}
