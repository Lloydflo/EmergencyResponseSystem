<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/media_storage.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['files'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No files uploaded']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$maxFiles = 5;
$maxSize = 15 * 1024 * 1024;
$allowedExt = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    'txt', 'csv', 'zip', 'rar', '7z', 'mp4', 'mov', 'mp3'
];

$names = $_FILES['files']['name'] ?? [];
$tmpNames = $_FILES['files']['tmp_name'] ?? [];
$sizes = $_FILES['files']['size'] ?? [];
$errors = $_FILES['files']['error'] ?? [];

$count = is_array($names) ? count($names) : 0;
if ($count > $maxFiles) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Maximum 5 files per message']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$attachments = [];

try {
    for ($i = 0; $i < $count; $i++) {
        $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error on one of the files');
        }

        $originalName = (string)($names[$i] ?? '');
        $tmpPath = (string)($tmpNames[$i] ?? '');
        $size = (int)($sizes[$i] ?? 0);
        if ($size <= 0 || $size > $maxSize) {
            throw new RuntimeException('File too large (max 15MB each)');
        }

        $cleanName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
        $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('File type not allowed: ' . $cleanName);
        }

        $mimeType = $finfo ? (string)finfo_file($finfo, $tmpPath) : 'application/octet-stream';
        if ($mimeType === '') {
            $mimeType = 'application/octet-stream';
        }
        $isImage = strpos($mimeType, 'image/') === 0;

        $blob = @file_get_contents($tmpPath);
        if ($blob === false || $blob === '') {
            throw new RuntimeException('Failed to read uploaded file');
        }

        $attachment = create_interagency_attachment_upload(
            $pdo,
            $currentUserId,
            $cleanName !== '' ? $cleanName : ('attachment.' . $ext),
            $mimeType,
            $size,
            $blob,
            $isImage
        );

        if (!$attachment) {
            throw new RuntimeException('Failed to store uploaded file');
        }

        $attachments[] = $attachment;
    }
} catch (Throwable $e) {
    if ($finfo) {
        finfo_close($finfo);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

if ($finfo) {
    finfo_close($finfo);
}

echo json_encode([
    'ok' => true,
    'attachments' => $attachments
]);
