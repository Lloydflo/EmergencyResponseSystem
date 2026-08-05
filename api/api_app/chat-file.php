<?php
declare(strict_types=1);

/**
 * Streams a previously uploaded coordination attachment with a deterministic
 * MIME type and byte-range support. This avoids silent/failed Android playback
 * when the hosting server does not map .wav/.m4a extensions correctly.
 */

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed.';
    exit;
}

$fileName = trim((string)($_GET['file'] ?? ''));
if (
    $fileName === ''
    || basename($fileName) !== $fileName
    || !preg_match(
        '/\Achat_\d{8}_\d{6}_[a-f0-9]{24}\.(jpg|jpeg|png|webp|pdf|doc|docx|m4a|aac|3gp|ogg|opus|wav|mp3)\z/i',
        $fileName
    )
) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid attachment.';
    exit;
}

$filePath = dirname(__DIR__, 2) . '/uploads/chat/' . $fileName;
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Attachment not found.';
    exit;
}

$extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
$mimeByExtension = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'm4a' => 'audio/mp4',
    'aac' => 'audio/aac',
    '3gp' => 'audio/3gpp',
    'ogg' => 'audio/ogg',
    'opus' => 'audio/ogg',
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
];
$mimeType = $mimeByExtension[$extension] ?? 'application/octet-stream';
$fileSize = filesize($filePath);
if ($fileSize === false || $fileSize <= 0) {
    http_response_code(404);
    exit;
}

$start = 0;
$end = $fileSize - 1;
$statusCode = 200;
$rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

if ($rangeHeader !== '') {
    if (!preg_match('/\Abytes=(\d*)-(\d*)\z/', $rangeHeader, $matches)) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }

    $requestedStart = $matches[1] !== '' ? (int)$matches[1] : null;
    $requestedEnd = $matches[2] !== '' ? (int)$matches[2] : null;

    if ($requestedStart === null && $requestedEnd !== null) {
        $suffixLength = max(1, $requestedEnd);
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = $requestedStart ?? 0;
        $end = $requestedEnd !== null ? min($requestedEnd, $fileSize - 1) : $fileSize - 1;
    }

    if ($start < 0 || $start >= $fileSize || $end < $start) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    $statusCode = 206;
}

$length = $end - $start + 1;
http_response_code($statusCode);
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . addcslashes($fileName, "\"\\") . '"');
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400, immutable');
header('X-Content-Type-Options: nosniff');
if ($statusCode === 206) {
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
}

if ($method === 'HEAD') {
    exit;
}

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}

try {
    if ($start > 0) {
        fseek($handle, $start);
    }
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(64 * 1024, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
} finally {
    fclose($handle);
}
