<?php
// Logs a system activity event into activity_log
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/media_storage.php';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$input = is_array($input) ? $input : [];

// Fallback for non-JSON POST payloads
if (!$input && !empty($_POST)) {
    $input = $_POST;
}

$action = isset($input['action']) ? trim($input['action']) : '';
$entity_type = isset($input['entity_type']) ? trim($input['entity_type']) : 'system';
$entity_id = isset($input['entity_id']) ? (int)$input['entity_id'] : null;
$details = isset($input['details']) ? trim($input['details']) : '';
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : null;

if (($user_id === null || $user_id <= 0) && isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if ($user_id !== null && $user_id <= 0) {
    $user_id = null;
}

if ($action === '' && $entity_type === 'agency_chat') {
    $action = 'chat';
}

if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing action']);
    exit;
}

function extract_attachments_from_details(string $details): array {
    $details = trim($details);
    if ($details === '' || ($details[0] !== '{' && $details[0] !== '[')) {
        return [];
    }
    $decoded = json_decode($details, true);
    if (!is_array($decoded) || !isset($decoded['attachments']) || !is_array($decoded['attachments'])) {
        return [];
    }

    $attachments = [];
    foreach ($decoded['attachments'] as $rawAttachment) {
        if (!is_array($rawAttachment)) {
            continue;
        }
        $tempId = (int)($rawAttachment['temp_id'] ?? 0);
        $url = trim((string)($rawAttachment['url'] ?? ''));
        if ($tempId <= 0 && $url === '') {
            continue;
        }
        $name = trim((string)($rawAttachment['name'] ?? ($url !== '' ? basename($url) : 'Attachment')));
        if ($name === '') {
            $name = 'Attachment';
        }
        $mime = trim((string)($rawAttachment['mime_type'] ?? ''));
        $size = (int)($rawAttachment['size'] ?? 0);
        $isImage = !empty($rawAttachment['is_image']) ? 1 : 0;
        $attachments[] = [
            'temp_id' => $tempId,
            'name' => substr($name, 0, 255),
            'url' => ($url !== '' ? substr($url, 0, 500) : ''),
            'mime_type' => ($mime !== '' ? substr($mime, 0, 150) : null),
            'size' => max(0, $size),
            'is_image' => $isImage
        ];
    }
    return $attachments;
}

function persist_message_attachments(PDO $pdo, int $messageId, string $details): void {
    if ($messageId <= 0) {
        return;
    }

    $attachments = extract_attachments_from_details($details);
    if (count($attachments) === 0) {
        return;
    }
    foreach ($attachments as $attachment) {
        $tempId = (int)($attachment['temp_id'] ?? 0);
        if ($tempId > 0) {
            finalize_interagency_attachment_upload($pdo, $messageId, $tempId);
            continue;
        }

        if (trim((string)$attachment['url']) === '') {
            continue;
        }

        $insert = $pdo->prepare(
            "INSERT INTO interagency_message_attachments
                (message_id, file_name, file_url, file_path, mime_type, file_size, file_blob, is_image)
             VALUES (?, ?, ?, NULL, ?, ?, NULL, ?)"
        );
        $insert->execute([
            $messageId,
            $attachment['name'],
            $attachment['url'],
            $attachment['mime_type'],
            (int)$attachment['size'],
            (int)$attachment['is_image'],
        ]);
    }
}

function prepare_interagency_attachment_storage(PDO $pdo, string $details): void {
    $attachments = extract_attachments_from_details($details);
    if (count($attachments) === 0) {
        return;
    }

    ensure_interagency_attachments_table($pdo);

    foreach ($attachments as $attachment) {
        if ((int)($attachment['temp_id'] ?? 0) > 0) {
            ensure_interagency_attachment_uploads_table($pdo);
            break;
        }
    }
}

function ensure_activity_log_auto_increment(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM activity_log LIKE 'id'");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $extra = strtolower((string)($row['Extra'] ?? $row['extra'] ?? ''));
        if (strpos($extra, 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE activity_log MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
        }
    } catch (Throwable $e) {
        // Ignore; insert fallback still handles legacy/broken schemas.
    }
}

function activity_log_needs_manual_id_fallback(string $message): bool {
    return strpos($message, "Duplicate entry '0' for key 'PRIMARY'") !== false
        || strpos($message, "Field 'id' doesn't have a default value") !== false
        || strpos($message, "Field 'id' doesn't have a default") !== false;
}

try {
    ensure_activity_log_auto_increment($pdo);
    if (($entity_type === 'agency_chat' || $entity_type === 'agency_user_chat') && $details !== '') {
        prepare_interagency_attachment_storage($pdo, $details);
    }
    $pdo->beginTransaction();

    $insertedMessageId = 0;
    $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$user_id, $action, $entity_type, $entity_id, $details]);
        $insertedMessageId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        if (!activity_log_needs_manual_id_fallback($msg)) {
            throw $e;
        }

        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log")->fetchColumn();
        $stmtManual = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtManual->execute([$nextId, $user_id, $action, $entity_type, $entity_id, $details]);
        $insertedMessageId = $nextId;
    }

    if (($entity_type === 'agency_chat' || $entity_type === 'agency_user_chat') && $details !== '') {
        persist_message_attachments($pdo, $insertedMessageId, $details);
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    echo json_encode([
        'ok' => true,
        'message_id' => $insertedMessageId
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Log failed',
        'detail' => $e->getMessage()
    ]);
}
