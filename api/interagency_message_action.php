<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/interagency_time.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
interagency_apply_database_timezone($pdo);

function ers_interagency_reports_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_message_reports` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT NOT NULL,
            `reported_by` INT UNSIGNED NOT NULL,
            `reason` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('open','reviewed','dismissed') NOT NULL DEFAULT 'open',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_message_reporter` (`message_id`, `reported_by`),
            KEY `idx_interagency_message_reports_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = strtolower(trim((string)($input['action'] ?? '')));
$messageId = (int)($input['message_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = current_session_role();

if (!in_array($action, ['unsend', 'report'], true) || $messageId <= 0 || $userId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid message action']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, user_id, entity_type, entity_id
         FROM activity_log
         WHERE id = ?
           AND entity_type IN ('agency_chat','agency_user_chat','agency_group_chat')
         LIMIT 1"
    );
    $stmt->execute([$messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$message) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Message not found']);
        exit;
    }

    if ($action === 'unsend') {
        $ownerId = (int)($message['user_id'] ?? 0);
        if ($ownerId !== $userId && $role !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only the sender can unsend this message']);
            exit;
        }

        $payload = json_encode([
            'text' => 'This message was unsent.',
            'unsent' => true,
            'unsent_by' => $userId,
            'unsent_at' => interagency_now(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $pdo->beginTransaction();
        $update = $pdo->prepare("UPDATE activity_log SET details = ? WHERE id = ?");
        $update->execute([$payload, $messageId]);

        try {
            $solo = $pdo->prepare("UPDATE interagency_solo_chat SET message_details = ? WHERE activity_log_id = ?");
            $solo->execute([$payload, $messageId]);
        } catch (Throwable $e) {
            // Older installs may not have the mirror table yet.
        }

        try {
            $group = $pdo->prepare("UPDATE interagency_groups_threads_read SET message_details = ? WHERE activity_log_id = ?");
            $group->execute([$payload, $messageId]);
        } catch (Throwable $e) {
            // Older installs may not have the mirror table yet.
        }

        try {
            $deleteAttachments = $pdo->prepare("DELETE FROM interagency_message_attachments WHERE message_id = ?");
            $deleteAttachments->execute([$messageId]);
        } catch (Throwable $e) {
            // Attachment table may not exist on older installs.
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Message unsent']);
        exit;
    }

    if ((int)($message['user_id'] ?? 0) === $userId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'You cannot report your own message']);
        exit;
    }

    ers_interagency_reports_table($pdo);
    $reason = substr(trim((string)($input['reason'] ?? 'Reported from message menu')), 0, 255);
    $stmt = $pdo->prepare(
        "INSERT INTO interagency_message_reports (message_id, reported_by, reason, status, created_at, updated_at)
         VALUES (?, ?, ?, 'open', NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            reason = VALUES(reason),
            status = 'open',
            updated_at = NOW()"
    );
    $stmt->execute([$messageId, $userId, $reason !== '' ? $reason : null]);
    echo json_encode(['ok' => true, 'message' => 'Message reported for admin review']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to process message action']);
}
