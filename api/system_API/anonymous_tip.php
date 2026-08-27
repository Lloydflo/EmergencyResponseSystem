<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/activity_log.php';
require_once __DIR__ . '/../../includes/geocode_helper.php';

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
        $requestedTipId = ers_external_clean($_GET['tip_id'] ?? $_GET['tipId'] ?? $_GET['tipID'] ?? $_GET['id'] ?? '', 120);
        if ($requestedTipId !== '') {
            ers_external_json(200, ers_tip_status_lookup($pdo, $requestedTipId));
        }

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

    $input = ers_tip_input_with_files();
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
    if (($item['tip_id'] ?? '') === '') {
        $item['tip_id'] = 'TIP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    $saved = ers_tip_save($pdo, $item);
    ers_tip_log_sync($pdo, 'incoming', 'received', (int)$saved['id'], $item, null, null);

    ers_external_json(201, [
        'success' => true,
        'message' => 'Anonymous tip received.',
        'item' => $saved,
    ]);
} catch (Throwable $e) {
    error_log('anonymous_tip.php failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    ers_external_json(500, [
        'success' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to process anonymous tip',
    ]);
}

function ers_tip_input_with_files(): array
{
    $input = ers_external_input();
    if (empty($_FILES) || trim((string)ers_tip_extract_evidence($input)) !== '') {
        return $input;
    }

    foreach ($_FILES as $field => $file) {
        foreach (ers_tip_uploaded_file_entries((string)$field, $file) as $entry) {
            $stored = ers_tip_store_uploaded_file($entry, (string)($input['tip_id'] ?? $input['tipId'] ?? $input['id'] ?? ''));
            if ($stored !== '') {
                $input['photo_of_evidence'] = $stored;
                return $input;
            }
        }
    }

    return $input;
}

function ers_tip_uploaded_file_entries(string $field, array $file): array
{
    $names = $file['name'] ?? null;
    if (!is_array($names)) {
        return [[
            'field' => $field,
            'name' => (string)($file['name'] ?? ''),
            'type' => (string)($file['type'] ?? ''),
            'tmp_name' => (string)($file['tmp_name'] ?? ''),
            'error' => (int)($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($file['size'] ?? 0),
        ]];
    }

    $entries = [];
    foreach ($names as $index => $_) {
        $entries = array_merge($entries, ers_tip_uploaded_file_entries($field, [
            'name' => $file['name'][$index] ?? '',
            'type' => $file['type'][$index] ?? '',
            'tmp_name' => $file['tmp_name'][$index] ?? '',
            'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $file['size'][$index] ?? 0,
        ]));
    }
    return $entries;
}

function ers_tip_store_uploaded_file(array $file, string $tipId = ''): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Tip photo upload failed.',
            'upload_error' => $error,
        ]);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return '';
    }

    $maxBytes = 12 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size > $maxBytes) {
        ers_external_json(413, [
            'success' => false,
            'error' => 'Tip photo is too large. Maximum size is 12 MB.',
        ]);
    }

    $bytes = file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '') {
        return '';
    }

    $extension = ers_tip_evidence_extension($bytes, (string)($file['name'] ?? ''));
    if ($extension === '') {
        $extension = ers_tip_mime_extension((string)($file['type'] ?? ''));
    }
    if ($extension === '') {
        ers_external_json(422, [
            'success' => false,
            'error' => 'Unsupported tip photo type. Send JPG, PNG, GIF, WEBP, BMP, or HEIC.',
        ]);
    }

    return ers_tip_write_evidence_file($bytes, $tipId, $extension);
}

function ers_tip_store_inline_evidence(string $value, string $tipId = ''): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    if (preg_match('/^data:image\/([a-z0-9.+-]+);base64,(.+)$/is', $text, $matches)) {
        $bytes = base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true);
        if (is_string($bytes) && $bytes !== '') {
            $extension = ers_tip_mime_extension('image/' . strtolower($matches[1])) ?: ers_tip_evidence_extension($bytes, '');
            return $extension !== '' ? ers_tip_write_evidence_file($bytes, $tipId, $extension) : $text;
        }
    }

    $compact = preg_replace('/\s+/', '', $text) ?? '';
    if (strlen($compact) > 500 && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact)) {
        $bytes = base64_decode($compact, true);
        if (is_string($bytes) && $bytes !== '') {
            $extension = ers_tip_evidence_extension($bytes, '');
            if ($extension !== '') {
                return ers_tip_write_evidence_file($bytes, $tipId, $extension);
            }
        }
    }

    return $text;
}

function ers_tip_evidence_extension(string $bytes, string $filename): string
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_buffer($finfo, $bytes);
            finfo_close($finfo);
            $mime = is_string($detected) ? strtolower($detected) : '';
        }
    }

    $extension = ers_tip_mime_extension($mime);
    if ($extension !== '') {
        return $extension;
    }

    $original = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($original, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif'], true)
        ? ($original === 'jpeg' ? 'jpg' : $original)
        : '';
}

function ers_tip_mime_extension(string $mime): string
{
    return match (strtolower($mime)) {
        'image/jpeg', 'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp', 'image/x-ms-bmp' => 'bmp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        default => '',
    };
}

function ers_tip_write_evidence_file(string $bytes, string $tipId, string $extension): string
{
    $maxBytes = 12 * 1024 * 1024;
    if (strlen($bytes) > $maxBytes) {
        ers_external_json(413, [
            'success' => false,
            'error' => 'Tip photo is too large. Maximum size is 12 MB.',
        ]);
    }

    $dir = dirname(__DIR__, 2) . '/data/anonymous_tip_evidence';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('Anonymous tip evidence folder unavailable; storing image inline.');
        return ers_tip_evidence_data_url($bytes, $extension);
    }

    $safeTip = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $tipId), '-');
    if ($safeTip === '') {
        $safeTip = 'tip';
    }
    $name = $safeTip . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $path = $dir . '/' . $name;

    if (file_put_contents($path, $bytes, LOCK_EX) === false) {
        error_log('Anonymous tip evidence file write failed; storing image inline.');
        return ers_tip_evidence_data_url($bytes, $extension);
    }

    return 'data/anonymous_tip_evidence/' . $name;
}

function ers_tip_evidence_data_url(string $bytes, string $extension): string
{
    $mime = match (strtolower($extension)) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        default => 'application/octet-stream',
    };

    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

function ers_tip_normalize(array $input, ?string $externalClient = null): array
{
    foreach (['incident', 'data', 'tip', 'item', 'payload', 'body', 'tip_details', 'incident_details', 'report'] as $nestKey) {
        if (isset($input[$nestKey]) && is_array($input[$nestKey])) {
            $input = array_merge($input[$nestKey], $input);
            break;
        }
    }

    $tipId = ers_external_clean(
        $input['tip_id']
            ?? $input['tipId']
            ?? $input['tipID']
            ?? $input['id']
            ?? $input['reference_no']
            ?? $input['referenceNo']
            ?? $input['incident_code']
            ?? $input['incidentCode']
            ?? $input['code']
            ?? $input['uuid']
            ?? $input['ref']
            ?? $input['ticket_id']
            ?? $input['tip_code']
            ?? $input['external_incident_id']
            ?? $input['externalIncidentId']
            ?? '',
        120
    );

    if ($tipId === '') {
        $tipId = 'TIP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    $photo = $input['photo_of_evidence']
        ?? $input['photoOfEvidence']
        ?? $input['tip_photo']
        ?? $input['tipPhoto']
        ?? $input['tip_photo_url']
        ?? $input['tipPhotoUrl']
        ?? $input['photo_evidence']
        ?? $input['photoEvidence']
        ?? $input['photo_url']
        ?? $input['photoUrl']
        ?? $input['photo']
        ?? $input['image']
        ?? $input['evidence_photo']
        ?? $input['evidencePhoto']
        ?? $input['evidence_url']
        ?? $input['evidenceUrl']
        ?? $input['image_url']
        ?? $input['imageUrl']
        ?? $input['attachment']
        ?? $input['attachment_url']
        ?? $input['file']
        ?? $input['media']
        ?? $input['media_url']
        ?? '';

    if (is_array($photo)) {
        $extractedPhoto = ers_tip_extract_evidence($photo);
        if ($extractedPhoto !== '') {
            $photo = $extractedPhoto;
        } else {
            $encodedPhoto = json_encode($photo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $photo = is_string($encodedPhoto) ? $encodedPhoto : '';
        }
    }
    if (trim((string)$photo) === '') {
        $photo = ers_tip_extract_evidence($input);
    }
    $photo = ers_tip_store_inline_evidence((string)$photo, $tipId);

    $status = 'new';

    $rawPayload = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawPayload)) {
        $rawPayload = '{}';
    }

    $location = ers_external_clean(
        $input['location']
            ?? $input['address']
            ?? $input['location_address']
            ?? $input['locationAddress']
            ?? $input['place']
            ?? $input['site']
            ?? $input['spot']
            ?? $input['loc']
            ?? $input['incident_location']
            ?? $input['event_location']
            ?? '',
        255
    );
    if ($location === '') {
        $location = 'Location not specified';
    }

    $tipDescription = trim((string)(
        $input['tip_description']
            ?? $input['tipDescription']
            ?? $input['description']
            ?? $input['details']
            ?? $input['message']
            ?? $input['content']
            ?? $input['text']
            ?? $input['summary']
            ?? $input['notes']
            ?? $input['note']
            ?? $input['report']
            ?? $input['info']
            ?? $input['information']
            ?? $input['body']
            ?? $input['tip']
            ?? $input['subject']
            ?? $input['title']
            ?? $input['incident_description']
            ?? ''
    ));

    $sourceSystem = ers_external_clean(
        $input['source_system']
            ?? $input['sourceSystem']
            ?? $input['source']
            ?? $input['system']
            ?? $input['client']
            ?? $input['client_id']
            ?? $input['sender']
            ?? $externalClient
            ?? 'Group 6',
        120
    );

    return [
        'id' => max(0, (int)($input['id'] ?? 0)),
        'tip_id' => $tipId,
        'tip_datetime' => ers_tip_normalize_datetime(
            $input['tip_datetime']
                ?? $input['tipDateTime']
                ?? $input['date_time']
                ?? $input['dateTime']
                ?? $input['datetime']
                ?? $input['date']
                ?? $input['time']
                ?? $input['created_at']
                ?? $input['timestamp']
                ?? $input['reported_at']
                ?? $input['occurred_at']
                ?? ''
        ),
        'location' => $location,
        'tip_description' => $tipDescription,
        'photo_of_evidence' => trim((string)$photo),
        'status' => $status,
        'outcome' => trim((string)($input['outcome'] ?? '')),
        'source_system' => $sourceSystem,
        'raw_payload' => $rawPayload,
    ];
}

function ers_tip_normalize_datetime($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    }

    try {
        $philippineZone = new DateTimeZone('Asia/Manila');
        if (preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', $raw)) {
            $date = new DateTimeImmutable($raw);
            return $date->setTimezone($philippineZone)->format('Y-m-d H:i:s');
        }
        $date = new DateTimeImmutable($raw, $philippineZone);
        return $date->setTimezone($philippineZone)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $time = strtotime($raw);
        if ($time !== false) {
            $dt = (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Asia/Manila'));
            return $dt->format('Y-m-d H:i:s');
        }
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    }
}

function ers_tip_save(PDO $pdo, array $item): array
{
    $phNow = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
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
                 updated_at = ?
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
            $phNow,
            $existingId,
        ]);
        return ers_tip_find($pdo, $existingId);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO anonymous_tips
            (tip_id, tip_datetime, location, tip_description, photo_of_evidence,
             status, outcome, source_system, received_at, updated_at, raw_payload)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
        $phNow,
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

    if (!in_array($status, ['new', 'reviewing', 'verified', 'dismissed', 'converted_to_incident', 'pending', 'dispatched', 'resolved', 'completed'], true)) {
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
    $payload = ers_tip_decode_payload((string)($item['raw_payload'] ?? ''));
    $priority = ers_external_normalize_priority($input['priority'] ?? $payload['priority'] ?? 'medium');
    $existing = ers_tip_find_linked_incident($pdo, $sourceSystem, $externalIncidentId);

    $rawType = trim((string)($input['incident_type'] ?? $input['type'] ?? ''));
    $type = $rawType !== ''
        ? (ers_external_normalize_type($rawType) ?: $rawType)
        : (ers_tip_infer_incident_type((string)($item['tip_description'] ?? '')) ?: 'medical, police, fire');

    $location = ers_external_clean($item['location'] ?? '', 255);
    if ($location === '') {
        $location = 'Location not provided';
    }
    $coordinates = ers_tip_payload_coordinates($payload) ?? ers_tip_location_coordinates($pdo, $location);

    $description = ers_tip_incident_description($item);
    $referenceNo = ers_tip_incident_reference($item);
    $title = 'Anonymous tip ' . ((string)($item['tip_id'] ?? '') !== '' ? (string)$item['tip_id'] : ('#' . (string)$item['id']));

    $hasIntakeCol = ers_external_column_exists($pdo, 'incidents', 'intake_source');
    $intakeSql = $hasIntakeCol ? ", intake_source = 'tip'" : '';

    if ($existing !== null) {
        $update = $pdo->prepare(
            "UPDATE incidents
             SET type = ?,
                 priority = ?,
                 status = 'pending',
                 title = ?,
                 description = ?,
                 location_address = ?,
                 latitude = ?,
                 longitude = ?,
                 updated_at = NOW()
                 {$intakeSql}
             WHERE id = ?"
        );
        $update->execute([
            $type,
            $priority,
            $title,
            $description,
            $location,
            $coordinates['latitude'],
            $coordinates['longitude'],
            (int)$existing['id'],
        ]);

        ers_external_link_incident($pdo, $sourceSystem, $externalIncidentId, (int)$existing['id'], [
            'source' => 'anonymous_tip',
            'tip' => $item,
            'conversion' => [
                'incident_type' => $type,
                'priority' => $priority,
            ],
        ]);

        $outcome = ers_tip_conversion_outcome($input, (string)$existing['reference_no'], true);
        ers_tip_set_status($pdo, (int)$item['id'], 'pending', $outcome);

        log_activity_event(null, 'incident_created', 'incident', (int)$existing['id'], 'Anonymous tip '
            . (string)($item['tip_id'] ?? ('#' . (int)$item['id']))
            . ' was converted to incident ' . (string)$existing['reference_no'] . '.');

        return [
            'duplicate' => false,
            'item' => ers_tip_find($pdo, (int)$item['id']),
            'incident' => [
                'id' => (int)$existing['id'],
                'reference_no' => (string)$existing['reference_no'],
                'status' => 'pending',
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
            ],
        ];
    }

    $existingByReference = ers_tip_find_incident_by_reference($pdo, $referenceNo);
    if ($existingByReference !== null) {
        $update = $pdo->prepare(
            "UPDATE incidents
             SET type = ?,
                 priority = ?,
                 status = 'pending',
                 title = ?,
                 description = ?,
                 location_address = ?,
                 latitude = ?,
                 longitude = ?,
                 updated_at = NOW()
                 {$intakeSql}
             WHERE id = ?"
        );
        $update->execute([
            $type,
            $priority,
            $title,
            $description,
            $location,
            $coordinates['latitude'],
            $coordinates['longitude'],
            (int)$existingByReference['id'],
        ]);

        ers_external_link_incident($pdo, $sourceSystem, $externalIncidentId, (int)$existingByReference['id'], [
            'source' => 'anonymous_tip',
            'tip' => $item,
            'linked_by' => 'reference_no',
            'conversion' => [
                'incident_type' => $type,
                'priority' => $priority,
            ],
        ]);
        ers_tip_activate_incident($pdo, (int)$existingByReference['id'], $priority);
        $outcome = ers_tip_conversion_outcome($input, (string)$existingByReference['reference_no'], true);
        ers_tip_set_status($pdo, (int)$item['id'], 'pending', $outcome);

        log_activity_event(null, 'incident_created', 'incident', (int)$existingByReference['id'], 'Anonymous tip '
            . (string)($item['tip_id'] ?? ('#' . (int)$item['id']))
            . ' was converted to incident ' . (string)$existingByReference['reference_no'] . '.');

        return [
            'duplicate' => false,
            'item' => ers_tip_find($pdo, (int)$item['id']),
            'incident' => [
                'id' => (int)$existingByReference['id'],
                'reference_no' => (string)$existingByReference['reference_no'],
                'status' => 'pending',
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
            ],
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
            ':latitude' => $coordinates['latitude'],
            ':longitude' => $coordinates['longitude'],
            ':incident_type' => $type,
            ':priority' => $priority,
            ':description' => $description,
        ]);

        $hasIntakeSource = ers_external_column_exists($pdo, 'incidents', 'intake_source');
        $lookup = $pdo->prepare('SELECT id, reference_no, status FROM incidents WHERE reported_by_call_id = ? LIMIT 1');
        $lookup->execute([$callId]);
        $created = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($created) {
            $intakeSql = $hasIntakeSource ? ", intake_source = 'tip'" : '';
            $update = $pdo->prepare(
                "UPDATE incidents
                 SET type = ?,
                     priority = ?,
                     status = 'pending',
                     title = ?,
                     description = ?,
                     location_address = ?,
                     latitude = ?,
                     longitude = ?,
                     updated_at = NOW()
                     {$intakeSql}
                 WHERE id = ?"
            );
            $update->execute([
                $type,
                $priority,
                $title,
                $description,
                $location,
                $coordinates['latitude'],
                $coordinates['longitude'],
                (int)$created['id'],
            ]);
            $created['status'] = 'pending';
        } else {
            $incidentId = ers_external_insert_incident($pdo, [
                ':reference_no' => $referenceNo,
                ':type' => $type,
                ':priority' => $priority,
                ':title' => $title,
                ':description' => $description,
                ':location_address' => $location,
                ':latitude' => $coordinates['latitude'],
                ':longitude' => $coordinates['longitude'],
                ':reported_by_call_id' => $callId,
                ':intake_source' => 'tip',
            ]);
            $intakeSql = $hasIntakeSource ? ", intake_source = 'tip'" : '';
            $pdo->prepare("UPDATE incidents SET status = 'pending'{$intakeSql} WHERE id = ?")->execute([$incidentId]);
            ers_tip_activate_incident($pdo, $incidentId);
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
        ers_tip_set_status($pdo, (int)$item['id'], 'pending', $outcome);
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
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ers_tip_activate_incident(PDO $pdo, int $incidentId, string $priority = ''): void
{
    if ($incidentId <= 0) {
        return;
    }

    if ($priority !== '') {
        $stmt = $pdo->prepare("UPDATE incidents SET priority = ?, updated_at = NOW() WHERE id = ? AND status <> 'cancelled'");
        $stmt->execute([$priority, $incidentId]);
        return;
    }

    $stmt = $pdo->prepare("UPDATE incidents SET updated_at = NOW() WHERE id = ? AND status <> 'cancelled'");
    $stmt->execute([$incidentId]);
}

function ers_tip_find(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        "SELECT at.id, at.tip_id, at.tip_datetime, at.location, at.tip_description, at.photo_of_evidence,
                at.status, at.outcome, at.source_system, at.received_at, at.updated_at, at.raw_payload,
                i.id AS converted_incident_id, i.reference_no AS converted_reference_no, i.status AS converted_incident_status
         FROM anonymous_tips at
         LEFT JOIN external_incident_links eil
            ON (eil.source_system IN ('Anonymous Tip Inbox', 'Group 6', 'anonymous_tip', 'Responder App Coordination') OR eil.source_system COLLATE utf8mb4_unicode_ci = at.source_system COLLATE utf8mb4_unicode_ci)
           AND (
                eil.external_incident_id COLLATE utf8mb4_unicode_ci = at.tip_id COLLATE utf8mb4_unicode_ci
             OR eil.external_incident_id COLLATE utf8mb4_unicode_ci = CONCAT('anonymous-tip-', at.id) COLLATE utf8mb4_unicode_ci
             OR eil.external_incident_id COLLATE utf8mb4_unicode_ci = CAST(at.id AS CHAR) COLLATE utf8mb4_unicode_ci
           )
         LEFT JOIN incidents i ON i.id = eil.incident_id
         WHERE at.id = ?
         ORDER BY eil.id DESC
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? ers_tip_prepare_response($row, $pdo) : [];
}

function ers_tip_status_lookup(PDO $pdo, string $tipId): array
{
    ers_external_ensure_link_table($pdo);

    $incidentUpdatedExpr = ers_external_column_exists($pdo, 'incidents', 'updated_at') ? 'i.updated_at' : 'NULL';
    if (ers_external_column_exists($pdo, 'incidents', 'resolved_at')) {
        $incidentCompletedExpr = 'i.resolved_at';
    } elseif (ers_external_column_exists($pdo, 'incidents', 'cleared_at')) {
        $incidentCompletedExpr = 'i.cleared_at';
    } elseif (ers_external_column_exists($pdo, 'incidents', 'completed_at')) {
        $incidentCompletedExpr = 'i.completed_at';
    } else {
        $incidentCompletedExpr = 'NULL';
    }

    $stmt = $pdo->prepare(
        "SELECT at.id, at.tip_id, at.status AS tip_status, at.outcome, at.source_system,
                at.received_at, at.updated_at AS tip_updated_at,
                i.id AS incident_id, i.reference_no AS incident_reference, i.status AS incident_status,
                {$incidentUpdatedExpr} AS incident_updated_at,
                {$incidentCompletedExpr} AS incident_completed_at
         FROM anonymous_tips at
         LEFT JOIN external_incident_links eil
            ON (eil.source_system IN ('Anonymous Tip Inbox', 'Group 6', 'anonymous_tip', 'Responder App Coordination') OR eil.source_system COLLATE utf8mb4_unicode_ci = at.source_system COLLATE utf8mb4_unicode_ci)
           AND (
                eil.external_incident_id COLLATE utf8mb4_unicode_ci = at.tip_id COLLATE utf8mb4_unicode_ci
             OR eil.external_incident_id COLLATE utf8mb4_unicode_ci = CONCAT('anonymous-tip-', at.id) COLLATE utf8mb4_unicode_ci
             OR eil.external_incident_id COLLATE utf8mb4_unicode_ci = CAST(at.id AS CHAR) COLLATE utf8mb4_unicode_ci
           )
         LEFT JOIN incidents i ON i.id = eil.incident_id
         WHERE at.tip_id = ? OR at.id = ?
         ORDER BY eil.id DESC
         LIMIT 1"
    );
    $stmt->execute([$tipId, ctype_digit($tipId) ? (int)$tipId : 0]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        ers_external_json(404, [
            'success' => false,
            'error' => 'Anonymous tip not found',
        ]);
    }

    $incidentId = (int)($row['incident_id'] ?? 0);
    $dispatch = ers_tip_dispatch_summary($pdo, $incidentId, (string)($row['incident_reference'] ?? ''));
    $incidentStatus = strtolower(trim((string)($row['incident_status'] ?? '')));
    $dispatchedStatuses = [
        'assigned', 'acknowledged', 'dispatching', 'dispatched',
        'enroute', 'en_route', 'on_scene', 'ongoing', 'ongoing_dispatch',
        'in_progress',
    ];
    $completedStatuses = ['resolved', 'complete', 'completed', 'closed'];
    $dispatched = $incidentId > 0 && ($dispatch['unit_count'] > 0 || in_array($incidentStatus, $dispatchedStatuses, true));
    $hasDispatch = $dispatch['unit_count'] > 0;
    $completed = $incidentId > 0 && ($hasDispatch && (in_array($incidentStatus, $completedStatuses, true) || trim((string)($row['incident_completed_at'] ?? '')) !== ''));
    $rawTipStatus = strtolower(trim((string)($row['tip_status'] ?? '')));
    $displayStatus = $completed
        ? 'completed'
        : ($dispatched ? 'dispatched' : (in_array($rawTipStatus, ['reviewing', 'verified', 'dismissed', 'pending'], true) ? $rawTipStatus : 'new'));

    return [
        'success' => true,
        'tip_id' => (string)($row['tip_id'] ?? ''),
        'status' => $displayStatus,
        'display_status' => $displayStatus,
        'tip_status' => (string)($row['tip_status'] ?? ''),
        'outcome' => (string)($row['outcome'] ?? ''),
        'source_system' => (string)($row['source_system'] ?? ''),
        'incident_id' => $incidentId > 0 ? $incidentId : null,
        'incident_reference' => (string)($row['incident_reference'] ?? ''),
        'incident_status' => $incidentStatus !== '' ? $incidentStatus : null,
        'dispatched' => $dispatched,
        'is_dispatched' => $dispatched,
        'dispatched_at' => $dispatch['dispatched_at'],
        'dispatch_status' => $dispatch['latest_status'],
        'unit_count' => $dispatch['unit_count'],
        'completed' => $completed,
        'is_completed' => $completed,
        'completed_at' => trim((string)($row['incident_completed_at'] ?? '')) !== ''
            ? (string)$row['incident_completed_at']
            : null,
        'last_updated_at' => (string)($row['incident_updated_at'] ?? $row['tip_updated_at'] ?? ''),
    ];
}

function ers_tip_dispatch_summary(PDO $pdo, int $incidentId, string $referenceNo): array
{
    $summary = [
        'unit_count' => 0,
        'dispatched_at' => null,
        'latest_status' => null,
    ];
    if (!ers_external_table_exists($pdo, 'dispatches')) {
        return $summary;
    }

    $where = [];
    $params = [];
    if ($incidentId > 0 && ers_external_column_exists($pdo, 'dispatches', 'incident_id')) {
        $where[] = 'incident_id = ?';
        $params[] = $incidentId;
    }
    if ($referenceNo !== '' && ers_external_column_exists($pdo, 'dispatches', 'reference_no')) {
        $where[] = 'reference_no = ?';
        $params[] = $referenceNo;
    }
    if ($where === []) {
        return $summary;
    }

    $statusExpr = ers_external_column_exists($pdo, 'dispatches', 'status') ? 'status' : 'NULL';
    $assignedAtExpr = ers_external_column_exists($pdo, 'dispatches', 'assigned_at') ? 'assigned_at' : 'NULL';
    $sql = "SELECT COUNT(*) AS unit_count,
                   MAX({$assignedAtExpr}) AS dispatched_at,
                   SUBSTRING_INDEX(GROUP_CONCAT({$statusExpr} ORDER BY id DESC SEPARATOR ','), ',', 1) AS latest_status
            FROM dispatches
            WHERE " . implode(' OR ', array_map(static fn (string $clause): string => '(' . $clause . ')', $where));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $summary;
    }

    return [
        'unit_count' => max(0, (int)($row['unit_count'] ?? 0)),
        'dispatched_at' => trim((string)($row['dispatched_at'] ?? '')) !== '' ? (string)$row['dispatched_at'] : null,
        'latest_status' => trim((string)($row['latest_status'] ?? '')) !== '' ? (string)$row['latest_status'] : null,
    ];
}

function ers_tip_list(PDO $pdo): array
{
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 60)));
    $status = strtolower(ers_external_clean($_GET['status'] ?? '', 40));
    $search = ers_external_clean($_GET['search'] ?? '', 120);

    $where = [];
    $params = [];
    $displayStatusFilter = in_array($status, ['dispatched', 'resolved', 'pending', 'new', 'reviewing', 'verified', 'dismissed'], true) ? $status : '';
    if ($status !== '' && $status !== 'all' && !in_array($status, ['dispatched', 'resolved', 'pending'], true)) {
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
                   at.status, at.outcome, at.source_system, at.received_at, at.updated_at, at.raw_payload,
                   i.id AS converted_incident_id, i.reference_no AS converted_reference_no, i.status AS converted_incident_status
            FROM anonymous_tips at
            LEFT JOIN external_incident_links eil
               ON (eil.source_system IN ('Anonymous Tip Inbox', 'Group 6', 'anonymous_tip', 'Responder App Coordination') OR eil.source_system COLLATE utf8mb4_unicode_ci = at.source_system COLLATE utf8mb4_unicode_ci)
              AND (
                   eil.external_incident_id COLLATE utf8mb4_unicode_ci = at.tip_id COLLATE utf8mb4_unicode_ci
                OR eil.external_incident_id COLLATE utf8mb4_unicode_ci = CONCAT('anonymous-tip-', at.id) COLLATE utf8mb4_unicode_ci
                OR eil.external_incident_id COLLATE utf8mb4_unicode_ci = CAST(at.id AS CHAR) COLLATE utf8mb4_unicode_ci
              )
            LEFT JOIN incidents i ON i.id = eil.incident_id";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY COALESCE(at.received_at, at.tip_datetime, at.updated_at) DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_map(static fn (array $row): array => ers_tip_prepare_response($row, $pdo), $rows);
    if ($displayStatusFilter !== '') {
        $items = array_values(array_filter($items, static function (array $item) use ($displayStatusFilter): bool {
            return strtolower((string)($item['display_status'] ?? '')) === $displayStatusFilter;
        }));
    }
    return $items;
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
    return is_array($row) ? ers_tip_hydrate_evidence($row) : [];
}

function ers_tip_ph_datetime(?string $value): ?DateTimeImmutable
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    try {
        $philippineZone = new DateTimeZone('Asia/Manila');
        if (preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', $raw)) {
            $date = new DateTimeImmutable($raw);
            return $date->setTimezone($philippineZone);
        }
        return new DateTimeImmutable($raw, $philippineZone);
    } catch (Throwable $error) {
        $time = strtotime($raw);
        if ($time !== false) {
            return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Asia/Manila'));
        }
        return null;
    }
}

function ers_tip_ph_datetime_ms(?string $value): int
{
    $date = ers_tip_ph_datetime($value);
    return $date instanceof DateTimeImmutable ? $date->getTimestamp() * 1000 : 0;
}

function ers_tip_ph_datetime_iso(?string $value): ?string
{
    $date = ers_tip_ph_datetime($value);
    return $date instanceof DateTimeImmutable ? $date->format('Y-m-d\TH:i:sP') : null;
}

function ers_tip_prepare_response(array $row, ?PDO $pdo = null): array
{
    $row = ers_tip_hydrate_evidence($row);

    $tipDateTime = trim((string)($row['tip_datetime'] ?? ''));
    $receivedAt = trim((string)($row['received_at'] ?? ''));
    $updatedAt = trim((string)($row['updated_at'] ?? ''));
    $displayDateTime = $receivedAt !== '' ? $receivedAt : ($tipDateTime !== '' ? $tipDateTime : $updatedAt);

    $row['tip_datetime_ms'] = ers_tip_ph_datetime_ms($tipDateTime);
    $row['tip_datetime_iso'] = ers_tip_ph_datetime_iso($tipDateTime);
    $row['received_at_ms'] = ers_tip_ph_datetime_ms($receivedAt);
    $row['received_at_iso'] = ers_tip_ph_datetime_iso($receivedAt);
    $row['updated_at_ms'] = ers_tip_ph_datetime_ms($updatedAt);
    $row['updated_at_iso'] = ers_tip_ph_datetime_iso($updatedAt);
    $row['display_datetime'] = $displayDateTime !== '' ? $displayDateTime : null;
    $row['display_datetime_ms'] = ers_tip_ph_datetime_ms($displayDateTime);
    $row['display_datetime_iso'] = ers_tip_ph_datetime_iso($displayDateTime);
    $incidentId = (int)($row['converted_incident_id'] ?? 0);
    $incidentReference = trim((string)($row['converted_reference_no'] ?? ''));
    $dispatch = $pdo !== null
        ? ers_tip_dispatch_summary($pdo, $incidentId, $incidentReference)
        : ['unit_count' => 0, 'dispatched_at' => null, 'latest_status' => null];
    $incidentStatus = strtolower(trim((string)($row['converted_incident_status'] ?? '')));
    $dispatchedStatuses = [
        'assigned', 'acknowledged', 'dispatching', 'dispatched',
        'enroute', 'en_route', 'on_scene', 'ongoing', 'ongoing_dispatch',
        'in_progress',
    ];
    $completedStatuses = ['resolved', 'complete', 'completed', 'closed'];
    $row['raw_status'] = strtolower(trim((string)($row['status'] ?? '')));
    $hasActiveDispatch = (int)($dispatch['unit_count'] ?? 0) > 0 || in_array($incidentStatus, $dispatchedStatuses, true);
    $isCompleted = ($incidentId > 0 && in_array($incidentStatus, $completedStatuses, true))
        || ($incidentId > 0 && $dispatch['latest_status'] === 'cleared' && $incidentStatus === 'resolved')
        || in_array($row['raw_status'], ['resolved', 'completed', 'complete', 'closed'], true);
    $isDispatched = !$isCompleted && ($incidentId > 0 && ($hasActiveDispatch || $row['raw_status'] === 'dispatched'));
    $isConverted = $incidentId > 0 || in_array($row['raw_status'], ['converted_to_incident', 'pending'], true);

    if ($isCompleted) {
        $row['display_status'] = 'resolved';
    } elseif ($isDispatched) {
        $row['display_status'] = 'dispatched';
    } elseif ($isConverted) {
        $row['display_status'] = 'pending';
    } else {
        $row['display_status'] = in_array($row['raw_status'], ['reviewing', 'verified', 'dismissed'], true) ? $row['raw_status'] : 'new';
    }
    $row['interagency_status'] = $row['display_status'];
    $row['dispatched'] = $isDispatched;
    $row['is_dispatched'] = $isDispatched;
    $row['dispatched_at'] = $dispatch['dispatched_at'] ?? null;
    $row['dispatch_status'] = $dispatch['latest_status'] ?? null;
    $row['dispatched_unit_count'] = (int)($dispatch['unit_count'] ?? 0);
    unset($row['raw_payload']);
    return $row;
}

function ers_tip_hydrate_evidence(array $row): array
{
    if (trim((string)($row['photo_of_evidence'] ?? '')) !== '') {
        return $row;
    }

    $payload = trim((string)($row['raw_payload'] ?? ''));
    if ($payload === '') {
        return $row;
    }

    $decoded = json_decode($payload, true);
    if (is_array($decoded)) {
        $evidence = ers_tip_extract_evidence($decoded);
        if ($evidence !== '') {
            $row['photo_of_evidence'] = $evidence;
        }
    }

    return $row;
}

function ers_tip_extract_evidence($value, int $depth = 0): string
{
    if ($depth > 4 || $value === null) {
        return '';
    }

    if (is_string($value)) {
        $text = trim($value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^[\[{]/', $text) === 1) {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return ers_tip_extract_evidence($decoded, $depth + 1);
            }
        }
        return $text;
    }

    if (is_scalar($value)) {
        return trim((string)$value);
    }

    if (!is_array($value)) {
        return '';
    }

    if ($value !== [] && array_keys($value) === range(0, count($value) - 1)) {
        foreach ($value as $entry) {
            $evidence = ers_tip_extract_evidence($entry, $depth + 1);
            if ($evidence !== '') {
                return $evidence;
            }
        }
        return '';
    }

    $directKeys = [
        'photo_of_evidence', 'photoOfEvidence', 'evidence_photo', 'evidencePhoto',
        'tip_photo', 'tipPhoto', 'tip_photo_url', 'tipPhotoUrl', 'photo_evidence',
        'photoEvidence', 'evidence_url', 'evidenceUrl', 'image_url', 'imageUrl',
        'photo_url', 'photoUrl', 'url', 'path', 'src', 'href', 'image', 'photo',
        'file_url', 'fileUrl', 'filePath', 'base64', 'data',
    ];
    foreach ($directKeys as $key) {
        if (array_key_exists($key, $value)) {
            $evidence = ers_tip_extract_evidence($value[$key], $depth + 1);
            if ($evidence !== '') {
                return $evidence;
            }
        }
    }

    $nestedKeys = ['evidence', 'attachments', 'attachment', 'images', 'imageFiles', 'photos', 'files', 'media'];
    foreach ($nestedKeys as $key) {
        if (array_key_exists($key, $value)) {
            $evidence = ers_tip_extract_evidence($value[$key], $depth + 1);
            if ($evidence !== '') {
                return $evidence;
            }
        }
    }

    return '';
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
        "SELECT i.id, i.reference_no, i.status, i.latitude, i.longitude
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
        'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
    ];
}

function ers_tip_find_incident_by_reference(PDO $pdo, string $referenceNo): ?array
{
    if ($referenceNo === '' || !ers_external_table_exists($pdo, 'incidents')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, reference_no, status, latitude, longitude FROM incidents WHERE reference_no = ? LIMIT 1');
    $stmt->execute([$referenceNo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'reference_no' => (string)$row['reference_no'],
        'status' => (string)$row['status'],
        'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
    ];
}

function ers_tip_decode_payload(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ers_tip_location_coordinates(PDO $pdo, string $location): array
{
    if (trim($location) === '' || strcasecmp(trim($location), 'Location not provided') === 0) {
        return ['latitude' => null, 'longitude' => null];
    }

    $direct = ers_tip_parse_coordinates($location);
    if ($direct !== null) {
        return $direct;
    }

    $existing = ers_tip_existing_location_coordinates($pdo, $location);
    if ($existing !== null) {
        return $existing;
    }

    $cached = ers_tip_cached_location_coordinates($location);
    if ($cached !== null) {
        return $cached;
    }

    $geocoded = ers_geocode_location_to_coordinates($location);
    if ($geocoded !== null) {
        return ['latitude' => (float)$geocoded[0], 'longitude' => (float)$geocoded[1]];
    }

    return ['latitude' => null, 'longitude' => null];
}

function ers_tip_payload_coordinates(array $payload): ?array
{
    $pairs = [
        [$payload['latitude'] ?? null, $payload['longitude'] ?? null],
        [$payload['lat'] ?? null, $payload['lng'] ?? null],
        [$payload['lat'] ?? null, $payload['lon'] ?? null],
    ];

    foreach (['location', 'coordinates', 'coords', 'geo', 'position'] as $key) {
        if (!is_array($payload[$key] ?? null)) {
            continue;
        }
        $source = $payload[$key];
        $pairs[] = [$source['latitude'] ?? null, $source['longitude'] ?? null];
        $pairs[] = [$source['lat'] ?? null, $source['lng'] ?? null];
        $pairs[] = [$source['lat'] ?? null, $source['lon'] ?? null];
    }

    foreach ($pairs as $pair) {
        [$rawLat, $rawLng] = $pair;
        if ($rawLat === null || $rawLng === null || $rawLat === '' || $rawLng === '') {
            continue;
        }
        if (!is_numeric((string)$rawLat) || !is_numeric((string)$rawLng)) {
            continue;
        }
        $lat = (float)$rawLat;
        $lng = (float)$rawLng;
        if (!ers_tip_valid_coordinates($lat, $lng) && ers_tip_valid_coordinates($lng, $lat)) {
            [$lat, $lng] = [$lng, $lat];
        }
        if (ers_tip_valid_coordinates($lat, $lng)) {
            return ['latitude' => $lat, 'longitude' => $lng];
        }
    }

    return null;
}

function ers_tip_existing_location_coordinates(PDO $pdo, string $location): ?array
{
    $location = trim($location);
    if ($location === '') {
        return null;
    }

    foreach (['incidents', 'calls'] as $table) {
        if (!ers_external_table_exists($pdo, $table)) {
            continue;
        }
        if (
            !ers_external_column_exists($pdo, $table, 'location_address')
            || !ers_external_column_exists($pdo, $table, 'latitude')
            || !ers_external_column_exists($pdo, $table, 'longitude')
        ) {
            continue;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT latitude, longitude
                 FROM {$table}
                 WHERE location_address = ?
                   AND latitude IS NOT NULL
                   AND longitude IS NOT NULL
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->execute([$location]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $lat = (float)$row['latitude'];
                $lng = (float)$row['longitude'];
                if (ers_tip_valid_coordinates($lat, $lng)) {
                    return ['latitude' => $lat, 'longitude' => $lng];
                }
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

function ers_tip_parse_coordinates(string $location): ?array
{
    $text = trim((string)preg_replace('/\s+/', ' ', $location));
    if ($text === '') {
        return null;
    }

    if (!preg_match('/(?:lat(?:itude)?\s*[:=]?\s*)?(-?\d{1,3}(?:\.\d+)?)\s*[, ]\s*(?:lon(?:gitude)?|lng)?\s*[:=]?\s*(-?\d{1,3}(?:\.\d+)?)/i', $text, $matches)) {
        return null;
    }

    $lat = (float)$matches[1];
    $lng = (float)$matches[2];
    if (!ers_tip_valid_coordinates($lat, $lng) && ers_tip_valid_coordinates($lng, $lat)) {
        [$lat, $lng] = [$lng, $lat];
    }
    if (!ers_tip_valid_coordinates($lat, $lng)) {
        return null;
    }

    return ['latitude' => $lat, 'longitude' => $lng];
}

function ers_tip_valid_coordinates(float $lat, float $lng): bool
{
    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

function ers_tip_cached_location_coordinates(string $location): ?array
{
    $path = __DIR__ . '/../../data/geocode_cache.json';
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $cache = json_decode($raw, true);
    if (!is_array($cache)) {
        return null;
    }

    $queryTokens = ers_tip_location_tokens($location);
    if ($queryTokens === []) {
        return null;
    }

    $best = null;
    $bestScore = 0;
    foreach ($cache as $entry) {
        $items = is_array($entry) && is_array($entry['items'] ?? null) ? $entry['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $lat = isset($item['lat']) ? (float)$item['lat'] : null;
            $lng = isset($item['lon']) ? (float)$item['lon'] : null;
            $display = (string)($item['display_name'] ?? '');
            if ($lat === null || $lng === null || !ers_tip_valid_coordinates($lat, $lng) || trim($display) === '') {
                continue;
            }

            $displayTokens = ers_tip_location_tokens($display);
            $matches = array_intersect($queryTokens, $displayTokens);
            if ($matches === []) {
                continue;
            }

            $score = count($matches) * 10;
            if (stripos($display, 'Quezon City') !== false) {
                $score += 3;
            }
            if (stripos($display, 'Metro Manila') !== false) {
                $score += 2;
            }
            if (count($matches) === count($queryTokens)) {
                $score += 8;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['latitude' => $lat, 'longitude' => $lng];
            }
        }
    }

    return $bestScore >= 10 ? $best : null;
}

function ers_tip_location_tokens(string $value): array
{
    $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', ' ', $value));
    $parts = preg_split('/\s+/', trim($normalized)) ?: [];
    $stopWords = [
        'street' => true,
        'st' => true,
        'road' => true,
        'rd' => true,
        'drive' => true,
        'dr' => true,
        'avenue' => true,
        'ave' => true,
        'barangay' => true,
        'brgy' => true,
        'quezon' => true,
        'city' => true,
        'metro' => true,
        'manila' => true,
        'philippines' => true,
    ];

    $tokens = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (strlen($part) < 3 || isset($stopWords[$part])) {
            continue;
        }
        $tokens[] = $part;
    }

    return array_values(array_unique($tokens));
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
    $description = ers_tip_clean_incident_description((string)($item['tip_description'] ?? ''));
    return $description !== '' ? $description : 'No description';
}

function ers_tip_clean_incident_description(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/anonymous tip converted to (?:an )?incident|tip id\s*:|date and time\s*:|evidence\s*:/i', $raw)) {
        return $raw;
    }

    $compact = trim((string)preg_replace('/\s+/', ' ', $raw));
    if (preg_match('/\bDescription\s*:\s*(.*?)(?:\s+\bEvidence\s*:|$)/is', $compact, $matches)) {
        $description = trim((string)$matches[1]);
        if ($description !== '') {
            return $description;
        }
    }

    $cleaned = preg_replace('/anonymous tip converted to (?:an )?incident\.?/i', '', $compact);
    $cleaned = preg_replace('/\bTip ID\s*:\s*.*?(?=\s+\b(?:Date and time|Location|Description|Evidence)\s*:|$)/i', '', (string)$cleaned);
    $cleaned = preg_replace('/\bDate and time\s*:\s*.*?(?=\s+\b(?:Location|Description|Evidence)\s*:|$)/i', '', (string)$cleaned);
    $cleaned = preg_replace('/\bLocation\s*:\s*.*?(?=\s+\b(?:Description|Evidence)\s*:|$)/i', '', (string)$cleaned);
    $cleaned = preg_replace('/\bEvidence\s*:\s*.*$/is', '', (string)$cleaned);
    $cleaned = preg_replace('/\bDescription\s*:\s*/i', '', (string)$cleaned);

    return trim((string)$cleaned);
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
            `photo_of_evidence` LONGTEXT DEFAULT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'new',
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
        'photo_of_evidence' => "ALTER TABLE `anonymous_tips` ADD COLUMN `photo_of_evidence` LONGTEXT DEFAULT NULL AFTER `tip_description`",
        'status' => "ALTER TABLE `anonymous_tips` ADD COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'new' AFTER `photo_of_evidence`",
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
    $pdo->exec("ALTER TABLE `anonymous_tips` MODIFY COLUMN `photo_of_evidence` LONGTEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE `anonymous_tips` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'new'");
    try {
        $pdo->exec("ALTER TABLE `anonymous_tips` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    try {
        $pdo->exec(
            "UPDATE anonymous_tips at
             LEFT JOIN external_incident_links eil
                ON eil.source_system = 'Anonymous Tip Inbox'
               AND (
                    eil.external_incident_id COLLATE utf8mb4_unicode_ci = at.tip_id COLLATE utf8mb4_unicode_ci
                 OR eil.external_incident_id COLLATE utf8mb4_unicode_ci = CONCAT('anonymous-tip-', at.id) COLLATE utf8mb4_unicode_ci
               )
             SET at.status = 'new'
             WHERE eil.incident_id IS NULL AND (at.status IS NULL OR at.status = '' OR at.status IN ('resolved', 'completed', 'complete', 'closed'))"
        );
    } catch (Throwable $e) {
        // ignore if links table missing
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
            KEY `idx_api_sync_logs_entity` (`entity_type`, `entity_id`),
            KEY `idx_api_sync_logs_endpoint` (`endpoint_name`),
            KEY `idx_api_sync_logs_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $syncLogColumns = [
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

    foreach ($syncLogColumns as $column => $sql) {
        if (!ers_external_column_exists($pdo, 'api_sync_logs', $column)) {
            $pdo->exec($sql);
        }
    }

    ers_tip_sync_converted_incidents($pdo);
}

function ers_tip_sync_converted_incidents(PDO $pdo): void
{
    if (!ers_external_table_exists($pdo, 'anonymous_tips') || !ers_external_table_exists($pdo, 'incidents')) {
        return;
    }

    $sourceSystem = ers_tip_link_source();
    $hasIntakeCol = ers_external_column_exists($pdo, 'incidents', 'intake_source');
    $hasLinksTable = ers_external_table_exists($pdo, 'external_incident_links');

    try {
        $stmt = $pdo->query(
            "SELECT at.id, at.tip_id, at.tip_datetime, at.location, at.tip_description, at.status, at.outcome, at.raw_payload
             FROM anonymous_tips at
             WHERE at.status IN ('converted_to_incident', 'pending')"
        );
        $convertedTips = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($convertedTips as $tip) {
            $tipId = trim((string)($tip['tip_id'] ?? ''));
            $rawId = (int)$tip['id'];
            $extId = $tipId !== '' ? $tipId : ('anonymous-tip-' . $rawId);

            $incident = null;
            if ($hasLinksTable) {
                $linkStmt = $pdo->prepare("SELECT incident_id FROM external_incident_links WHERE source_system = ? AND external_incident_id = ? LIMIT 1");
                $linkStmt->execute([$sourceSystem, $extId]);
                $linkedIncId = $linkStmt->fetchColumn();
                if ($linkedIncId) {
                    $incStmt = $pdo->prepare("SELECT * FROM incidents WHERE id = ? LIMIT 1");
                    $incStmt->execute([(int)$linkedIncId]);
                    $incident = $incStmt->fetch(PDO::FETCH_ASSOC);
                }
            }

            if (!$incident && $tipId !== '') {
                $incStmt = $pdo->prepare("SELECT * FROM incidents WHERE reference_no = ? OR reference_no LIKE ? OR title LIKE ? LIMIT 1");
                $incStmt->execute([$tipId, $tipId . '-%', '%' . $tipId . '%']);
                $incident = $incStmt->fetch(PDO::FETCH_ASSOC);
                if ($incident && $hasLinksTable) {
                    $pdo->prepare("REPLACE INTO external_incident_links (source_system, external_incident_id, incident_id, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$sourceSystem, $extId, (int)$incident['id'], json_encode(['tip' => $tip])]);
                }
            }

            if (!$incident) {
                $refNo = $tipId !== '' ? ($tipId . '-' . $rawId) : ('TIP-' . date('YmdHis') . '-' . $rawId);
                $location = trim((string)($tip['location'] ?? 'Location not provided'));
                $desc = trim((string)($tip['tip_description'] ?? 'Anonymous tip report'));
                $title = 'Anonymous tip ' . ($tipId !== '' ? $tipId : ('#' . $rawId));
                $type = 'medical, police, fire';
                $priority = 'high';
                $coords = ers_tip_location_coordinates($pdo, $location);

                $insStmt = $pdo->prepare(
                    "INSERT INTO incidents (reference_no, type, priority, status, title, description, location_address, latitude, longitude, intake_source, created_at, updated_at)
                     VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, 'tip', NOW(), NOW())"
                );
                $insStmt->execute([$refNo, $type, $priority, $title, $desc, $location, $coords['latitude'], $coords['longitude']]);
                $newIncId = (int)$pdo->lastInsertId();

                if ($hasLinksTable) {
                    $pdo->prepare("REPLACE INTO external_incident_links (source_system, external_incident_id, incident_id, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$sourceSystem, $extId, $newIncId, json_encode(['tip' => $tip])]);
                }
                continue;
            }

            $incId = (int)$incident['id'];
            $incStatus = strtolower(trim((string)($incident['status'] ?? '')));

            // Mirror exact incident status (pending -> dispatched -> resolved) into anonymous_tips table in database
            if (in_array($incStatus, ['resolved', 'completed', 'complete', 'closed'], true)) {
                $pdo->prepare("UPDATE anonymous_tips SET status = 'resolved', updated_at = NOW() WHERE id = ? AND status <> 'resolved'")
                    ->execute([$rawId]);
                continue;
            }

            $activeDispatches = 0;
            if (ers_external_table_exists($pdo, 'dispatches')) {
                $dispStmt = $pdo->prepare("SELECT COUNT(*) FROM dispatches WHERE (incident_id = ? OR reference_no = ?) AND status IN ('assigned','acknowledged','enroute','on_scene')");
                $dispStmt->execute([$incId, (string)$incident['reference_no']]);
                $activeDispatches = (int)$dispStmt->fetchColumn();
            }

            if ($activeDispatches > 0 || in_array($incStatus, ['dispatched', 'assigned', 'enroute', 'en_route', 'on_scene'], true)) {
                $pdo->prepare("UPDATE anonymous_tips SET status = 'dispatched', updated_at = NOW() WHERE id = ? AND status <> 'dispatched'")
                    ->execute([$rawId]);
            } elseif ($tip['status'] !== 'pending') {
                $pdo->prepare("UPDATE anonymous_tips SET status = 'pending', updated_at = NOW() WHERE id = ? AND status <> 'pending'")
                    ->execute([$rawId]);
            }

            $intakeSql = $hasIntakeCol ? ", intake_source = 'tip'" : '';
            if ($activeDispatches === 0 && in_array($incStatus, ['new', ''], true)) {
                $pdo->prepare("UPDATE incidents SET status = 'pending'{$intakeSql}, updated_at = NOW() WHERE id = ?")->execute([$incId]);
            } elseif ($hasIntakeCol && empty($incident['intake_source'])) {
                $pdo->prepare("UPDATE incidents SET intake_source = 'tip', updated_at = NOW() WHERE id = ?")->execute([$incId]);
            }
        }
    } catch (Throwable $e) {
        error_log('ers_tip_sync_converted_incidents failed: ' . $e->getMessage());
    }
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