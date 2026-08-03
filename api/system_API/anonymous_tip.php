<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';

$pdo = ers_external_db();
$sessionAllowed = is_logged_in() && in_array(current_session_role(), ['admin', 'dispatcher'], true);
$externalAuth = null;

if (!$sessionAllowed) {
    $externalAuth = ers_external_authenticate();
}

try {
    ers_tip_ensure_tables($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        ers_external_json(200, [
            'success' => true,
            'items' => ers_tip_list($pdo),
        ]);
    }

    if ($method !== 'POST' && $method !== 'PATCH') {
        ers_external_json(405, [
            'success' => false,
            'error' => 'GET, POST, or PATCH method required',
        ]);
    }

    $input = ers_external_input();
    $action = strtolower(ers_external_clean($input['action'] ?? '', 40));

    if ($action === 'update_status') {
        $updated = ers_tip_update_status($pdo, $input);
        ers_external_json(200, [
            'success' => true,
            'message' => 'Anonymous tip updated.',
            'item' => $updated,
        ]);
    }

    if ($action === 'convert_to_incident') {
        $converted = ers_tip_convert_to_incident($pdo, $input);
        ers_external_json(200, [
            'success' => true,
            'message' => !empty($converted['duplicate'])
                ? 'Anonymous tip was already converted.'
                : 'Anonymous tip converted to an incident.',
            'item' => $converted['item'],
            'incident' => $converted['incident'],
            'duplicate' => !empty($converted['duplicate']),
        ]);
    }

    $item = ers_tip_normalize($input, $externalAuth['client'] ?? null);
    if ($item['tip_id'] === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'tip_id is required',
        ]);
    }

    $saved = ers_tip_save($pdo, $item);
    ers_tip_log_sync($pdo, 'incoming', 'received', (int)$saved['id'], $item, null, null);

    ers_external_json(201, [
        'success' => true,
        'message' => 'Anonymous tip received.',
        'item' => $saved,
    ]);
} catch (Throwable $e) {
    error_log('anonymous_tip.php failed: ' . $e->getMessage());
    ers_external_json(500, [
        'success' => false,
        'error' => 'Unable to process anonymous tip',
    ]);
}

function ers_tip_normalize(array $input, ?string $externalClient = null): array
{
    $photo = $input['photo_of_evidence']
        ?? $input['photoOfEvidence']
        ?? $input['photo']
        ?? $input['evidence_photo']
        ?? '';
    if (is_array($photo)) {
        $encodedPhoto = json_encode($photo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $photo = is_string($encodedPhoto) ? $encodedPhoto : '';
    }

    $status = strtolower(ers_external_clean($input['status'] ?? 'new', 40));
    if (!in_array($status, ['new', 'reviewing', 'verified', 'dismissed', 'converted_to_incident'], true)) {
        $status = 'new';
    }

    $rawPayload = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        $rawPayload = '{}';
    }

    return [
        'id' => max(0, (int)($input['id'] ?? 0)),
        'tip_id' => ers_external_clean(
            $input['tip_id']
                ?? $input['tipId']
                ?? $input['tipID']
                ?? $input['id']
                ?? '',
            120
        ),
        'tip_datetime' => ers_tip_normalize_datetime(
            $input['tip_datetime']
                ?? $input['tipDateTime']
                ?? $input['date_time']
                ?? $input['dateTime']
                ?? $input['datetime']
                ?? $input['date']
                ?? ''
        ),
        'location' => ers_external_clean($input['location'] ?? $input['address'] ?? '', 255),
        'tip_description' => trim((string)(
            $input['tip_description']
                ?? $input['tipDescription']
                ?? $input['description']
                ?? ''
        )),
        'photo_of_evidence' => trim((string)$photo),
        'status' => $status,
        'outcome' => trim((string)($input['outcome'] ?? '')),
        'source_system' => ers_external_clean(
            $input['source_system']
                ?? $input['sourceSystem']
                ?? $externalClient
                ?? 'Group 6',
            120
        ),
        'raw_payload' => $rawPayload,
    ];
}

function ers_tip_normalize_datetime($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return date('Y-m-d H:i:s');
    }

    $time = strtotime($raw);
    if ($time === false) {
        return date('Y-m-d H:i:s');
    }

    return date('Y-m-d H:i:s', $time);
}

function ers_tip_save(PDO $pdo, array $item): array
{
    $existingId = 0;
    if ($item['id'] > 0) {
        $stmt = $pdo->prepare('SELECT id FROM anonymous_tips WHERE id = ? LIMIT 1');
        $stmt->execute([$item['id']]);
        $existingId = (int)$stmt->fetchColumn();
    }

    if ($existingId <= 0) {
        $stmt = $pdo->prepare('SELECT id FROM anonymous_tips WHERE tip_id = ? LIMIT 1');
        $stmt->execute([$item['tip_id']]);
        $existingId = (int)$stmt->fetchColumn();
    }

    if ($existingId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE anonymous_tips
             SET tip_datetime = ?,
                 location = ?,
                 tip_description = ?,
                 photo_of_evidence = ?,
                 status = ?,
                 outcome = ?,
                 source_system = ?,
                 raw_payload = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $item['tip_datetime'],
            $item['location'],
            $item['tip_description'],
            $item['photo_of_evidence'],
            $item['status'],
            $item['outcome'],
            $item['source_system'],
            $item['raw_payload'],
            $existingId,
        ]);
        return ers_tip_find($pdo, $existingId);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO anonymous_tips
            (tip_id, tip_datetime, location, tip_description, photo_of_evidence,
             status, outcome, source_system, received_at, updated_at, raw_payload)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)"
    );
    $stmt->execute([
        $item['tip_id'],
        $item['tip_datetime'],
        $item['location'],
        $item['tip_description'],
        $item['photo_of_evidence'],
        $item['status'],
        $item['outcome'],
        $item['source_system'],
        $item['raw_payload'],
    ]);

    return ers_tip_find($pdo, (int)$pdo->lastInsertId());
}

function ers_tip_update_status(PDO $pdo, array $input): array
{
    $id = max(0, (int)($input['id'] ?? 0));
    $tipId = ers_external_clean($input['tip_id'] ?? $input['tipId'] ?? '', 120);
    $status = strtolower(ers_external_clean($input['status'] ?? '', 40));
    $outcome = trim((string)($input['outcome'] ?? ''));

    if (!in_array($status, ['new', 'reviewing', 'verified', 'dismissed', 'converted_to_incident'], true)) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Invalid tip status',
        ]);
    }

    if ($id <= 0 && $tipId === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'id or tip_id is required',
        ]);
    }

    if ($status === 'converted_to_incident' && !ers_tip_linked_incident($pdo, $id, $tipId)) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Use Convert to create and link an incident before marking this tip converted.',
        ]);
    }

    $where = $id > 0 ? 'id = ?' : 'tip_id = ?';
    $key = $id > 0 ? $id : $tipId;
    $stmt = $pdo->prepare("UPDATE anonymous_tips SET status = ?, outcome = ?, updated_at = NOW() WHERE {$where}");
    $stmt->execute([$status, $outcome, $key]);

    $updatedId = $id;
    if ($updatedId <= 0) {
        $lookup = $pdo->prepare('SELECT id FROM anonymous_tips WHERE tip_id = ? LIMIT 1');
        $lookup->execute([$tipId]);
        $updatedId = (int)$lookup->fetchColumn();
    }

    $item = ers_tip_find($pdo, $updatedId);
    if (!$item) {
        ers_external_json(404, [
            'success' => false,
            'error' => 'Anonymous tip not found',
        ]);
    }

    ers_tip_log_sync($pdo, 'incoming', 'received', $updatedId, [
        'action' => 'update_status',
        'status' => $status,
        'outcome' => $outcome,
    ], null, null);

    return $item;
}

function ers_tip_convert_to_incident(PDO $pdo, array $input): array
{
    $id = max(0, (int)($input['id'] ?? 0));
    $tipId = ers_external_clean($input['tip_id'] ?? $input['tipId'] ?? '', 120);
    if ($id <= 0 && $tipId === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'id or tip_id is required',
        ]);
    }

    $item = ers_tip_find_for_action($pdo, $id, $tipId);
    if (!$item) {
        ers_external_json(404, [
            'success' => false,
            'error' => 'Anonymous tip not found',
        ]);
    }

    ers_external_ensure_identity($pdo, 'calls');
    ers_external_ensure_identity($pdo, 'incidents');
    ers_external_ensure_link_table($pdo);

    $sourceSystem = ers_tip_link_source();
    $externalIncidentId = ers_tip_external_id($item);
    $existing = ers_tip_find_linked_incident($pdo, $sourceSystem, $externalIncidentId);
    if ($existing !== null) {
        $outcome = ers_tip_conversion_outcome($input, (string)$existing['reference_no'], true);
        ers_tip_set_status($pdo, (int)$item['id'], 'converted_to_incident', $outcome);
        return [
            'duplicate' => true,
            'item' => ers_tip_find($pdo, (int)$item['id']),
            'incident' => $existing,
        ];
    }

    $payload = ers_tip_decode_payload((string)($item['raw_payload'] ?? ''));
    $type = ers_external_normalize_type(
        $input['incident_type']
        ?? $input['type']
        ?? $payload['incident_type']
        ?? $payload['type']
        ?? $payload['requested_department']
        ?? ''
    );
    if ($type === '') {
        $type = ers_tip_infer_incident_type((string)($item['tip_description'] ?? ''));
    }

    $priority = ers_external_normalize_priority($input['priority'] ?? $payload['priority'] ?? 'medium');
    $location = ers_external_clean($item['location'] ?? '', 255);
    if ($location === '') {
        $location = 'Location not provided';
    }

    $description = ers_tip_incident_description($item);
    $referenceNo = ers_tip_incident_reference($item);
    $title = 'Anonymous tip ' . ((string)($item['tip_id'] ?? '') !== '' ? (string)$item['tip_id'] : ('#' . (string)$item['id']));
    $existingByReference = ers_tip_find_incident_by_reference($pdo, $referenceNo);
    if ($existingByReference !== null) {
        ers_external_link_incident($pdo, $sourceSystem, $externalIncidentId, (int)$existingByReference['id'], [
            'source' => 'anonymous_tip',
            'tip' => $item,
            'linked_by' => 'reference_no',
        ]);
        $outcome = ers_tip_conversion_outcome($input, (string)$existingByReference['reference_no'], true);
        ers_tip_set_status($pdo, (int)$item['id'], 'converted_to_incident', $outcome);
        return [
            'duplicate' => true,
            'item' => ers_tip_find($pdo, (int)$item['id']),
            'incident' => $existingByReference,
        ];
    }

    $pdo->beginTransaction();
    try {
        $callId = ers_external_insert_call($pdo, [
            ':reference_no' => $referenceNo,
            ':caller_name' => 'Anonymous Tip',
            ':caller_phone' => 'N/A',
            ':caller_email' => null,
            ':location_address' => $location,
            ':latitude' => null,
            ':longitude' => null,
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
                ':latitude' => null,
                ':longitude' => null,
                ':reported_by_call_id' => $callId,
            ]);
            $created = ['id' => $incidentId, 'reference_no' => $referenceNo, 'status' => 'pending'];
        }

        ers_external_link_incident($pdo, $sourceSystem, $externalIncidentId, (int)$created['id'], [
            'source' => 'anonymous_tip',
            'tip' => $item,
            'conversion' => [
                'incident_type' => $type,
                'priority' => $priority,
            ],
        ]);

        $outcome = ers_tip_conversion_outcome($input, (string)$created['reference_no'], false);
        ers_tip_set_status($pdo, (int)$item['id'], 'converted_to_incident', $outcome);
        $pdo->commit();

        log_activity_event(null, 'incident_created', 'incident', (int)$created['id'], 'Anonymous tip '
            . (string)($item['tip_id'] ?? ('#' . (int)$item['id']))
            . ' was converted to incident ' . (string)$created['reference_no'] . '.');

        return [
            'duplicate' => false,
            'item' => ers_tip_find($pdo, (int)$item['id']),
            'incident' => [
                'id' => (int)$created['id'],
                'reference_no' => (string)$created['reference_no'],
                'status' => (string)$created['status'],
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ers_tip_find(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        "SELECT at.id, at.tip_id, at.tip_datetime, at.location, at.tip_description, at.photo_of_evidence,
                at.status, at.outcome, at.source_system, at.received_at, at.updated_at,
                i.id AS converted_incident_id, i.reference_no AS converted_reference_no, i.status AS converted_incident_status
         FROM anonymous_tips at
         LEFT JOIN external_incident_links eil
            ON eil.source_system = ?
           AND eil.external_incident_id = CASE WHEN at.tip_id IS NULL OR at.tip_id = '' THEN CONCAT('anonymous-tip-', at.id) ELSE at.tip_id END
         LEFT JOIN incidents i ON i.id = eil.incident_id
         WHERE at.id = ?
         LIMIT 1"
    );
    $stmt->execute([ers_tip_link_source(), $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function ers_tip_list(PDO $pdo): array
{
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 60)));
    $status = strtolower(ers_external_clean($_GET['status'] ?? '', 40));
    $search = ers_external_clean($_GET['search'] ?? '', 120);

    $where = [];
    $params = [];
    if ($status !== '' && $status !== 'all') {
        $where[] = 'at.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(at.tip_id LIKE ? OR at.location LIKE ? OR at.tip_description LIKE ? OR at.source_system LIKE ? OR i.reference_no LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT at.id, at.tip_id, at.tip_datetime, at.location, at.tip_description, at.photo_of_evidence,
                   at.status, at.outcome, at.source_system, at.received_at, at.updated_at,
                   i.id AS converted_incident_id, i.reference_no AS converted_reference_no, i.status AS converted_incident_status
            FROM anonymous_tips at
            LEFT JOIN external_incident_links eil
               ON eil.source_system = ?
              AND eil.external_incident_id = CASE WHEN at.tip_id IS NULL OR at.tip_id = '' THEN CONCAT('anonymous-tip-', at.id) ELSE at.tip_id END
            LEFT JOIN incidents i ON i.id = eil.incident_id";
    array_unshift($params, ers_tip_link_source());
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY COALESCE(at.tip_datetime, at.received_at, at.updated_at) DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ers_tip_find_for_action(PDO $pdo, int $id, string $tipId): array
{
    $sql = "SELECT id, tip_id, tip_datetime, location, tip_description, photo_of_evidence,
                   status, outcome, source_system, received_at, updated_at, raw_payload
            FROM anonymous_tips
            WHERE " . ($id > 0 ? 'id = ?' : 'tip_id = ?') . "
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id > 0 ? $id : $tipId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function ers_tip_set_status(PDO $pdo, int $id, string $status, string $outcome): void
{
    $stmt = $pdo->prepare('UPDATE anonymous_tips SET status = ?, outcome = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $outcome, $id]);
}

function ers_tip_link_source(): string
{
    return 'Anonymous Tip Inbox';
}

function ers_tip_external_id(array $item): string
{
    $tipId = ers_external_clean($item['tip_id'] ?? '', 120);
    return $tipId !== '' ? $tipId : ('anonymous-tip-' . (int)($item['id'] ?? 0));
}

function ers_tip_linked_incident(PDO $pdo, int $id, string $tipId): bool
{
    $item = ers_tip_find_for_action($pdo, $id, $tipId);
    if (!$item) {
        return false;
    }
    ers_external_ensure_link_table($pdo);
    return ers_tip_find_linked_incident($pdo, ers_tip_link_source(), ers_tip_external_id($item)) !== null;
}

function ers_tip_find_linked_incident(PDO $pdo, string $sourceSystem, string $externalIncidentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT i.id, i.reference_no, i.status
         FROM external_incident_links eil
         INNER JOIN incidents i ON i.id = eil.incident_id
         WHERE eil.source_system = ? AND eil.external_incident_id = ?
         LIMIT 1"
    );
    $stmt->execute([$sourceSystem, $externalIncidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'reference_no' => (string)$row['reference_no'],
        'status' => (string)$row['status'],
    ];
}

function ers_tip_find_incident_by_reference(PDO $pdo, string $referenceNo): ?array
{
    if ($referenceNo === '' || !ers_external_table_exists($pdo, 'incidents')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, reference_no, status FROM incidents WHERE reference_no = ? LIMIT 1');
    $stmt->execute([$referenceNo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'reference_no' => (string)$row['reference_no'],
        'status' => (string)$row['status'],
    ];
}

function ers_tip_decode_payload(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ers_tip_infer_incident_type(string $description): string
{
    $text = strtolower($description);
    if (preg_match('/\b(fire|smoke|sunog|burning)\b/', $text)) {
        return 'fire';
    }
    if (preg_match('/\b(accident|collision|crash|traffic|vehicle|bangga)\b/', $text)) {
        return 'traffic';
    }
    if (preg_match('/\b(medical|ambulance|injur|patient|heart|collapse|health)\b/', $text)) {
        return 'medical';
    }
    if (preg_match('/\b(police|crime|theft|robbery|gun|weapon|fight|violence)\b/', $text)) {
        return 'police';
    }
    if (preg_match('/\b(rescue|trapped|flood|water|evacuat)\b/', $text)) {
        return 'rescue';
    }
    return 'other';
}

function ers_tip_incident_reference(array $item): string
{
    $base = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', (string)($item['tip_id'] ?? '')) ?? '');
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'TIP-' . (int)($item['id'] ?? 0);
    }
    if (strpos($base, 'TIP-') !== 0) {
        $base = 'TIP-' . $base;
    }
    $suffix = '-' . (int)($item['id'] ?? 0);
    return substr($base, 0, max(1, 50 - strlen($suffix))) . $suffix;
}

function ers_tip_incident_description(array $item): string
{
    $lines = [
        'Anonymous tip converted to incident.',
        'Tip ID: ' . (string)($item['tip_id'] ?? ''),
        'Date and time: ' . (string)($item['tip_datetime'] ?? ''),
        'Location: ' . (string)($item['location'] ?? 'Location not provided'),
        '',
        'Description:',
        (string)($item['tip_description'] ?? 'No description'),
    ];

    $photo = trim((string)($item['photo_of_evidence'] ?? ''));
    if ($photo !== '') {
        $lines[] = '';
        $lines[] = 'Evidence: ' . $photo;
    }

    return trim(implode("\n", $lines));
}

function ers_tip_conversion_outcome(array $input, string $referenceNo, bool $duplicate): string
{
    $provided = trim((string)($input['outcome'] ?? ''));
    $prefix = $duplicate ? 'Already converted to incident ' : 'Converted to incident ';
    $message = $prefix . $referenceNo . '.';
    return $provided !== '' && stripos($provided, $referenceNo) === false
        ? trim($provided . "\n" . $message)
        : ($provided !== '' ? $provided : $message);
}

function ers_tip_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `anonymous_tips` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tip_id` VARCHAR(120) NOT NULL,
            `tip_datetime` DATETIME DEFAULT NULL,
            `location` VARCHAR(255) DEFAULT NULL,
            `tip_description` TEXT DEFAULT NULL,
            `photo_of_evidence` TEXT DEFAULT NULL,
            `status` ENUM('new','reviewing','verified','dismissed','converted_to_incident') NOT NULL DEFAULT 'new',
            `outcome` TEXT DEFAULT NULL,
            `source_system` VARCHAR(120) DEFAULT 'Group 6',
            `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `raw_payload` LONGTEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_anonymous_tip_id` (`tip_id`),
            KEY `idx_anonymous_tip_datetime` (`tip_datetime`),
            KEY `idx_anonymous_tip_status` (`status`),
            KEY `idx_anonymous_tip_source` (`source_system`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'tip_id' => "ALTER TABLE `anonymous_tips` ADD COLUMN `tip_id` VARCHAR(120) DEFAULT NULL AFTER `id`",
        'tip_datetime' => "ALTER TABLE `anonymous_tips` ADD COLUMN `tip_datetime` DATETIME DEFAULT NULL AFTER `tip_id`",
        'location' => "ALTER TABLE `anonymous_tips` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL AFTER `tip_datetime`",
        'tip_description' => "ALTER TABLE `anonymous_tips` ADD COLUMN `tip_description` TEXT DEFAULT NULL AFTER `location`",
        'photo_of_evidence' => "ALTER TABLE `anonymous_tips` ADD COLUMN `photo_of_evidence` TEXT DEFAULT NULL AFTER `tip_description`",
        'status' => "ALTER TABLE `anonymous_tips` ADD COLUMN `status` ENUM('new','reviewing','verified','dismissed','converted_to_incident') NOT NULL DEFAULT 'new' AFTER `photo_of_evidence`",
        'outcome' => "ALTER TABLE `anonymous_tips` ADD COLUMN `outcome` TEXT DEFAULT NULL AFTER `status`",
        'source_system' => "ALTER TABLE `anonymous_tips` ADD COLUMN `source_system` VARCHAR(120) DEFAULT 'Group 6' AFTER `outcome`",
        'received_at' => "ALTER TABLE `anonymous_tips` ADD COLUMN `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `source_system`",
        'updated_at' => "ALTER TABLE `anonymous_tips` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `received_at`",
        'raw_payload' => "ALTER TABLE `anonymous_tips` ADD COLUMN `raw_payload` LONGTEXT DEFAULT NULL AFTER `updated_at`",
    ];

    foreach ($columns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'anonymous_tips', $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `api_sync_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `direction` ENUM('incoming','outgoing') NOT NULL,
            `target_group` VARCHAR(100) DEFAULT NULL,
            `endpoint_name` VARCHAR(150) DEFAULT NULL,
            `entity_type` VARCHAR(100) DEFAULT NULL,
            `entity_id` BIGINT UNSIGNED DEFAULT NULL,
            `status` ENUM('pending','sent','received','failed') NOT NULL DEFAULT 'pending',
            `request_payload` LONGTEXT DEFAULT NULL,
            `response_payload` LONGTEXT DEFAULT NULL,
            `error_message` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_api_sync_entity` (`entity_type`, `entity_id`),
            KEY `idx_api_sync_status` (`status`),
            KEY `idx_api_sync_group` (`target_group`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $syncColumns = [
        'direction' => "ALTER TABLE `api_sync_logs` ADD COLUMN `direction` ENUM('incoming','outgoing') NOT NULL DEFAULT 'incoming' AFTER `id`",
        'target_group' => "ALTER TABLE `api_sync_logs` ADD COLUMN `target_group` VARCHAR(100) DEFAULT NULL AFTER `direction`",
        'endpoint_name' => "ALTER TABLE `api_sync_logs` ADD COLUMN `endpoint_name` VARCHAR(150) DEFAULT NULL AFTER `target_group`",
        'entity_type' => "ALTER TABLE `api_sync_logs` ADD COLUMN `entity_type` VARCHAR(100) DEFAULT NULL AFTER `endpoint_name`",
        'entity_id' => "ALTER TABLE `api_sync_logs` ADD COLUMN `entity_id` BIGINT UNSIGNED DEFAULT NULL AFTER `entity_type`",
        'status' => "ALTER TABLE `api_sync_logs` ADD COLUMN `status` ENUM('pending','sent','received','failed') NOT NULL DEFAULT 'pending' AFTER `entity_id`",
        'request_payload' => "ALTER TABLE `api_sync_logs` ADD COLUMN `request_payload` LONGTEXT DEFAULT NULL AFTER `status`",
        'response_payload' => "ALTER TABLE `api_sync_logs` ADD COLUMN `response_payload` LONGTEXT DEFAULT NULL AFTER `request_payload`",
        'error_message' => "ALTER TABLE `api_sync_logs` ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `response_payload`",
        'created_at' => "ALTER TABLE `api_sync_logs` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `error_message`",
        'updated_at' => "ALTER TABLE `api_sync_logs` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];

    foreach ($syncColumns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'api_sync_logs', $column)) {
            $pdo->exec($sql);
        }
    }

    ers_external_ensure_link_table($pdo);
}

function ers_tip_log_sync(PDO $pdo, string $direction, string $status, int $tipId, array $payload, ?array $response, ?string $error): int
{
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $encodedResponse = $response !== null ? json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $stmt = $pdo->prepare(
        "INSERT INTO api_sync_logs
            (direction, target_group, endpoint_name, entity_type, entity_id, status, request_payload, response_payload, error_message, created_at, updated_at)
         VALUES
            (?, 'Group 6', 'anonymous_tip', 'anonymous_tip', ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $direction,
        $tipId > 0 ? $tipId : null,
        $status,
        is_string($encodedPayload) ? $encodedPayload : '{}',
        is_string($encodedResponse) ? $encodedResponse : null,
        $error,
    ]);

    return (int)$pdo->lastInsertId();
}
?>
