<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');

$uploaderUserId = op_post_int('uploader_user_id');
op_require_positive($uploaderUserId, 'uploader_user_id');

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    op_error('No file was uploaded.', 422);
}

$file = $_FILES['file'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $message = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected file is too large.',
        UPLOAD_ERR_PARTIAL => 'The file upload was interrupted.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        default => 'The file could not be uploaded.',
    };
    op_error($message, 422, ['upload_error' => $uploadError]);
}

$tmpPath = (string)($file['tmp_name'] ?? '');
$fileSize = (int)($file['size'] ?? 0);
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    op_error('The uploaded file is invalid.', 422);
}
if ($fileSize <= 0) {
    op_error('The selected file is empty.', 422);
}
if ($fileSize > 25 * 1024 * 1024) {
    op_error('The selected file exceeds the 25 MB limit.', 413);
}

$originalName = trim(basename((string)($file['name'] ?? 'attachment')));
if ($originalName === '') {
    $originalName = 'attachment';
}
$originalName = substr($originalName, 0, 255);
$extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = strtolower(trim((string)$finfo->file($tmpPath)));
if ($detectedMime === '') {
    $detectedMime = 'application/octet-stream';
}

/** @var array<string,list<string>> $allowed */
$allowed = [
    'jpg' => ['image/jpeg', 'image/pjpeg'],
    'jpeg' => ['image/jpeg', 'image/pjpeg'],
    'png' => ['image/png'],
    'webp' => ['image/webp'],
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/octet-stream'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ],

    // Coordination voice notes and common audio attachments.
    'm4a' => [
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/mp4a-latm',
        'video/mp4',
        'video/quicktime',
        'application/mp4',
        'application/x-m4a',
        'application/octet-stream',
    ],
    'aac' => ['audio/aac', 'audio/x-aac', 'application/octet-stream'],
    '3gp' => ['audio/3gpp', 'video/3gpp', 'application/octet-stream'],
    'ogg' => ['audio/ogg', 'application/ogg', 'application/octet-stream'],
    'opus' => ['audio/opus', 'audio/ogg', 'application/ogg', 'application/octet-stream'],
    'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'application/octet-stream'],
    'mp3' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'],
];

$extensionByMime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/aac' => 'aac',
    'audio/3gpp' => '3gp',
    'audio/ogg' => 'ogg',
    'audio/opus' => 'opus',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/mpeg' => 'mp3',
];

if ($extension === '' && isset($extensionByMime[$detectedMime])) {
    $extension = $extensionByMime[$detectedMime];
}
if (!isset($allowed[$extension])) {
    op_error('This file type is not allowed.', 415, [
        'extension' => $extension,
        'mime_type' => $detectedMime,
    ]);
}
if (!in_array($detectedMime, $allowed[$extension], true)) {
    op_error('The file content does not match its allowed type.', 415, [
        'extension' => $extension,
        'mime_type' => $detectedMime,
    ]);
}

try {
    $pdo = db();
    op_require_active_responder($pdo, $uploaderUserId);

    $uploadDir = dirname(__DIR__, 2) . '/uploads/chat/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to initialize the chat upload directory.');
    }
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('The chat upload directory is not writable.');
    }

    $newName = 'chat_' . date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $targetPath = $uploadDir . $newName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Unable to move the uploaded file.');
    }
    // The generated name is non-guessable; read permission is required for
    // the web server to stream the attachment even when PHP-FPM and the HTTP
    // worker use different service accounts.
    @chmod($targetPath, 0644);

    $configuredBaseUrl = trim((string)(getenv('ERS_PUBLIC_BASE_URL') ?: ''));
    $baseUrl = $configuredBaseUrl !== ''
        ? rtrim($configuredBaseUrl, '/')
        : 'https://emergency-response.alertaraqc.com';

    op_success([
        'file_url' => $baseUrl . '/uploads/chat/' . rawurlencode($newName),
        'file_name' => $originalName,
        'file_type' => $extension,
        'file_size' => filesize($targetPath) ?: $fileSize,
        'mime_type' => $detectedMime,
    ], 201);
} catch (Throwable $error) {
    error_log('upload_chat_file: ' . $error->getMessage());
    op_error('The file could not be uploaded.', 500);
}
