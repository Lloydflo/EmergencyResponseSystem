<?php
declare(strict_types=1);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/incident_priority.php';
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
$priority = ers_normalize_priority_value(trim((string)($input['priority'] ?? 'moderate')));
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

if ($caller_name === '' || $caller_phone === '' || $type === '' || $location === '' || $description === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

$priorityAssessment = ers_build_incident_priority_assessment($input, $priority);
$priority = ers_normalize_priority_value((string)$priorityAssessment['priority']);

try {
    ensure_no_auto_value_on_zero_mode($pdo);
    // Self-heal for deployments where id columns were created without AUTO_INCREMENT.
    ensure_auto_increment_identity($pdo, 'calls');
    ensure_auto_increment_identity($pdo, 'incidents');
    ers_ensure_incident_priority_schema($pdo);
} catch (Throwable $schemaErr) {
    error_log('calls_create schema check warning: ' . $schemaErr->getMessage());
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
    ], $priorityAssessment);

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
        update_incident_priority_indicator($pdo, $incident_id, $priority, $priorityAssessment);
    } else {
        update_incident_priority_indicator($pdo, (int)$incident['id'], $priority, $priorityAssessment);
    }

    $pdo->commit();
    log_incident_created_audit($incident ? (int)$incident['id'] : null, $incident ? (string)$incident['reference_no'] : $reference_no, $type, $priority, $location, $priorityAssessment);

    echo json_encode([
        'ok' => true,
        'call_id' => $call_id,
        'reference_no' => $reference_no,
        'incident_id' => $incident ? (int)$incident['id'] : null,
        'incident_reference_no' => $incident ? $incident['reference_no'] : null,
        'incident_status' => $incident ? $incident['status'] : null,
        'priority' => $priority,
        'priority_score' => (int)$priorityAssessment['score'],
        'priority_label' => (string)$priorityAssessment['label'],
        'priority_color' => (string)$priorityAssessment['color'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log('calls_create insert failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => build_user_facing_db_error($e)]);
}

function log_incident_created_audit(?int $incidentId, string $referenceNo, string $type, string $priority, string $location, array $assessment): void {
    if ($incidentId === null || $incidentId < 1) {
        return;
    }
    $details = 'Incoming incident ' . ($referenceNo !== '' ? $referenceNo : ('#' . $incidentId))
        . ' was logged. Type: ' . $type
        . ' | Priority: ' . $priority
        . ' | Score: ' . (int)($assessment['score'] ?? 0)
        . ' | Location: ' . $location;
    log_activity_event(null, 'incident_created', 'incident', $incidentId, $details);
}

function insert_call_row(PDO $pdo, array $params, array $assessment): int {
    $params = array_merge($params, ers_priority_assessment_db_params($assessment));
    $sql = 'INSERT INTO calls (
                reference_no, caller_name, caller_phone, caller_email, location_address, latitude, longitude,
                incident_type, priority, priority_score, priority_label, priority_color,
                indicator_incident_type, threat_to_life, severity_level, population_affected, verification_status, priority_breakdown,
                status, description, received_at
            )
            VALUES (
                :reference_no, :caller_name, :caller_phone, NULL, :location_address, :latitude, :longitude,
                :incident_type, :priority, :priority_score, :priority_label, :priority_color,
                :indicator_incident_type, :threat_to_life, :severity_level, :population_affected, :verification_status, :priority_breakdown,
                :status, :description, NOW()
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
                incident_type, priority, priority_score, priority_label, priority_color,
                indicator_incident_type, threat_to_life, severity_level, population_affected, verification_status, priority_breakdown,
                status, description, received_at
            )
            VALUES (
                :id, :reference_no, :caller_name, :caller_phone, NULL, :location_address, :latitude, :longitude,
                :incident_type, :priority, :priority_score, :priority_label, :priority_color,
                :indicator_incident_type, :threat_to_life, :severity_level, :population_affected, :verification_status, :priority_breakdown,
                :status, :description, NOW()
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

function update_incident_priority_indicator(PDO $pdo, int $incidentId, string $priority, array $assessment): void {
    if ($incidentId < 1) {
        return;
    }
    $params = ers_priority_assessment_db_params($assessment);
    $params[':priority'] = $priority;
    $params[':id'] = $incidentId;
    $stmt = $pdo->prepare(
        'UPDATE incidents
         SET priority = :priority,
             priority_score = :priority_score,
             priority_label = :priority_label,
             priority_color = :priority_color,
             indicator_incident_type = :indicator_incident_type,
             threat_to_life = :threat_to_life,
             severity_level = :severity_level,
             population_affected = :population_affected,
             verification_status = :verification_status,
             priority_breakdown = :priority_breakdown,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute($params);
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
