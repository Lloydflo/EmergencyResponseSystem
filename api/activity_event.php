<?php
// Logs a system activity event into activity_log
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

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

function ensure_interagency_attachments_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_message_attachments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_url` VARCHAR(500) NOT NULL,
            `mime_type` VARCHAR(150) DEFAULT NULL,
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `is_image` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_interagency_msg_attach_message` (`message_id`),
            KEY `idx_interagency_msg_attach_image` (`is_image`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function app_base_path(): string {
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
        return '';
    }
    $dir = rtrim($dir, '/');
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    return rtrim($dir, '/');
}

function normalize_attachment_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url) || strpos($url, '/') === 0) {
        return $url;
    }
    $base = app_base_path();
    return ($base !== '' ? $base : '') . '/' . ltrim($url, '/');
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
        $url = normalize_attachment_url((string)($rawAttachment['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $name = trim((string)($rawAttachment['name'] ?? basename($url)));
        if ($name === '') {
            $name = 'Attachment';
        }
        $mime = trim((string)($rawAttachment['mime_type'] ?? ''));
        $size = (int)($rawAttachment['size'] ?? 0);
        $isImage = !empty($rawAttachment['is_image']) ? 1 : 0;
        $attachments[] = [
            'name' => substr($name, 0, 255),
            'url' => substr($url, 0, 500),
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

    ensure_interagency_attachments_table($pdo);
    $insert = $pdo->prepare(
        "INSERT INTO interagency_message_attachments
            (message_id, file_name, file_url, mime_type, file_size, is_image)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($attachments as $attachment) {
        $insert->execute([
            $messageId,
            $attachment['name'],
            $attachment['url'],
            $attachment['mime_type'],
            $attachment['size'],
            $attachment['is_image']
        ]);
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

try {
    ensure_activity_log_auto_increment($pdo);
    $pdo->beginTransaction();
    $attachmentWarning = null;

    $insertedMessageId = 0;
    $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$user_id, $action, $entity_type, $entity_id, $details]);
        $insertedMessageId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        $isDuplicateZeroPrimary = (strpos($msg, "Duplicate entry '0' for key 'PRIMARY'") !== false);
        if (!$isDuplicateZeroPrimary) {
            throw $e;
        }

        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log")->fetchColumn();
        $stmtManual = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtManual->execute([$nextId, $user_id, $action, $entity_type, $entity_id, $details]);
        $insertedMessageId = $nextId;
    }

    if (($entity_type === 'agency_chat' || $entity_type === 'agency_user_chat') && $details !== '') {
        try {
            persist_message_attachments($pdo, $insertedMessageId, $details);
        } catch (Throwable $attachmentError) {
            $attachmentWarning = $attachmentError->getMessage();
        }
    }

    $pdo->commit();
    echo json_encode([
        'ok' => true,
        'message_id' => $insertedMessageId,
        'attachment_warning' => $attachmentWarning
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
