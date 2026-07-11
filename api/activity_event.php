<?php
// Logs a system activity event into activity_log
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/media_storage.php';
require_once __DIR__ . '/../includes/interagency_time.php';

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

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
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
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($action === '' && $entity_type === 'agency_chat') {
    $action = 'chat';
}

if ($action === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing action']);
    exit;
}

$isInteragencyChat = in_array($entity_type, ['agency_chat', 'agency_user_chat', 'agency_group_chat'], true);

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

function ensure_interagency_solo_chat_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_solo_chat` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activity_log_id` INT NOT NULL,
            `sender_user_id` VARCHAR(255) NOT NULL,
            `recipient_user_id` INT UNSIGNED NOT NULL,
            `message_details` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_solo_chat_activity_log` (`activity_log_id`),
            KEY `idx_interagency_solo_chat_participants` (`sender_user_id`, `recipient_user_id`),
            KEY `idx_interagency_solo_chat_recipient_created` (`recipient_user_id`, `created_at`),
            KEY `idx_interagency_solo_chat_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    ensure_interagency_chat_sender_names($pdo, 'interagency_solo_chat');
}

function ensure_interagency_chat_sender_names(PDO $pdo, string $tableName): void {
    if (!in_array($tableName, ['interagency_solo_chat', 'interagency_groups_threads_read'], true)) {
        throw new InvalidArgumentException('Unsupported Inter Agency chat table.');
    }

    $columnStmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE 'sender_user_id'");
    $column = $columnStmt ? $columnStmt->fetch(PDO::FETCH_ASSOC) : null;
    $columnType = strtolower((string)($column['Type'] ?? $column['type'] ?? ''));
    if (strpos($columnType, 'varchar') !== 0) {
        $pdo->exec("ALTER TABLE `{$tableName}` MODIFY `sender_user_id` VARCHAR(255) NOT NULL");
    }

    $pdo->exec(
        "UPDATE `{$tableName}` chat
         INNER JOIN users u ON CAST(chat.sender_user_id AS UNSIGNED) = u.id
         SET chat.sender_user_id = COALESCE(NULLIF(TRIM(u.name), ''), CONCAT('User #', u.id))
         WHERE chat.sender_user_id REGEXP '^[0-9]+$'"
    );
}

function interagency_sender_name(PDO $pdo, ?int $senderUserId): string {
    if ($senderUserId === null || $senderUserId <= 0) {
        throw new RuntimeException('Invalid Inter Agency message sender.');
    }

    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$senderUserId]);
    $name = trim((string)($stmt->fetchColumn() ?: ''));
    return $name !== '' ? $name : 'User #' . $senderUserId;
}

function persist_interagency_solo_chat(PDO $pdo, int $activityLogId, string $senderName, ?int $recipientUserId, string $details): void {
    if ($activityLogId <= 0 || $senderName === '' || $recipientUserId === null || $recipientUserId <= 0) {
        throw new RuntimeException('Invalid Inter Agency solo chat participants.');
    }

    ensure_interagency_solo_chat_table($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO interagency_solo_chat
            (activity_log_id, sender_user_id, recipient_user_id, message_details, created_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            sender_user_id = VALUES(sender_user_id),
            recipient_user_id = VALUES(recipient_user_id),
            message_details = VALUES(message_details)"
    );
    $stmt->execute([$activityLogId, $senderName, $recipientUserId, $details, interagency_now()]);
}

function ensure_interagency_groups_threads_read_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_groups_threads_read` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activity_log_id` INT NOT NULL,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `sender_user_id` VARCHAR(255) NOT NULL,
            `message_details` LONGTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_group_chat_activity_log` (`activity_log_id`),
            KEY `idx_interagency_group_chat_group_created` (`group_id`, `created_at`),
            KEY `idx_interagency_group_chat_sender_created` (`sender_user_id`, `created_at`),
            KEY `idx_interagency_group_chat_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    ensure_interagency_chat_sender_names($pdo, 'interagency_groups_threads_read');
}

function persist_interagency_group_chat(PDO $pdo, int $activityLogId, string $senderName, ?int $groupId, string $details): void {
    if ($activityLogId <= 0 || $senderName === '' || $groupId === null || $groupId <= 0) {
        throw new RuntimeException('Invalid Inter Agency group chat details.');
    }

    ensure_interagency_groups_threads_read_table($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO interagency_groups_threads_read
            (activity_log_id, group_id, sender_user_id, message_details, created_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            group_id = VALUES(group_id),
            sender_user_id = VALUES(sender_user_id),
            message_details = VALUES(message_details)"
    );
    $stmt->execute([$activityLogId, $groupId, $senderName, $details, interagency_now()]);
}

function activity_log_needs_manual_id_fallback(string $message): bool {
    return strpos($message, "Duplicate entry '0' for key 'PRIMARY'") !== false
        || strpos($message, "Field 'id' doesn't have a default value") !== false
        || strpos($message, "Field 'id' doesn't have a default") !== false;
}

try {
    ensure_activity_log_auto_increment($pdo);
    if ($isInteragencyChat) {
        interagency_apply_database_timezone($pdo);
    }
    if ($isInteragencyChat && $details !== '') {
        prepare_interagency_attachment_storage($pdo, $details);
    }
    $pdo->beginTransaction();

    $insertedMessageId = 0;
    if ($isInteragencyChat) {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $insertParams = [$user_id, $action, $entity_type, $entity_id, $details, interagency_now()];
    } else {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)");
        $insertParams = [$user_id, $action, $entity_type, $entity_id, $details];
    }
    try {
        $stmt->execute($insertParams);
        $insertedMessageId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        if (!activity_log_needs_manual_id_fallback($msg)) {
            throw $e;
        }

        $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log")->fetchColumn();
        if ($isInteragencyChat) {
            $stmtManual = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtManual->execute([$nextId, $user_id, $action, $entity_type, $entity_id, $details, interagency_now()]);
        } else {
            $stmtManual = $pdo->prepare("INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtManual->execute([$nextId, $user_id, $action, $entity_type, $entity_id, $details]);
        }
        $insertedMessageId = $nextId;
    }

    if ($isInteragencyChat && $details !== '') {
        persist_message_attachments($pdo, $insertedMessageId, $details);
    }

    if (in_array($entity_type, ['agency_user_chat', 'agency_group_chat'], true)) {
        $senderName = interagency_sender_name($pdo, $user_id);
        if ($entity_type === 'agency_user_chat') {
            persist_interagency_solo_chat($pdo, $insertedMessageId, $senderName, $entity_id, $details);
        } else {
            persist_interagency_group_chat($pdo, $insertedMessageId, $senderName, $entity_id, $details);
        }
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
