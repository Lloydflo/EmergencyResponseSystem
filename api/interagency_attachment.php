<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/media_storage.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit;
}

$attachmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tempId = isset($_GET['temp_id']) ? (int)$_GET['temp_id'] : 0;
$forceDownload = isset($_GET['download']) && (string)$_GET['download'] === '1';

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    exit;
}

try {
    if ($tempId > 0) {
        $row = get_interagency_attachment_upload($pdo, $tempId);
        if (!is_array($row) || empty($row) || empty($row['file_blob'])) {
            http_response_code(404);
            exit;
        }
        $uploaderId = (int)($row['user_id'] ?? 0);
        $messageId = (int)($row['message_id'] ?? 0);
        if ($messageId <= 0 && $uploaderId !== (int)($_SESSION['user_id'] ?? 0)) {
            http_response_code(403);
            exit;
        }

        $mimeType = trim((string)($row['mime_type'] ?? 'application/octet-stream'));
        $fileName = trim((string)($row['file_name'] ?? 'attachment'));
        $blob = (string)$row['file_blob'];
    } else {
        ensure_interagency_attachments_table($pdo);
        $stmt = $pdo->prepare(
            "SELECT id, file_name, mime_type, file_size, file_blob
             FROM interagency_message_attachments
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$attachmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || empty($row) || empty($row['file_blob'])) {
            http_response_code(404);
            exit;
        }

        $mimeType = trim((string)($row['mime_type'] ?? 'application/octet-stream'));
        $fileName = trim((string)($row['file_name'] ?? 'attachment'));
        $blob = (string)$row['file_blob'];
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($blob));
    header('Cache-Control: private, max-age=300');

    $disposition = $forceDownload ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($fileName) . '"');
    echo $blob;
} catch (Throwable $e) {
    http_response_code(500);
}
