<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';

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

$maxFiles = 5;
$maxSize = 15 * 1024 * 1024; // 15 MB
$allowedExt = [
    'jpg','jpeg','png','gif','webp','bmp',
    'pdf','doc','docx','xls','xlsx','ppt','pptx',
    'txt','csv','zip','rar','7z','mp4','mov','mp3'
];

$uploadBaseFs = dirname(__DIR__) . '/uploads/interagency/' . date('Y/m');
$uploadBaseWeb = (app_base_path() !== '' ? app_base_path() : '') . '/uploads/interagency/' . date('Y/m');

if (!is_dir($uploadBaseFs) && !mkdir($uploadBaseFs, 0775, true) && !is_dir($uploadBaseFs)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to create upload folder']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$attachments = [];

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

for ($i = 0; $i < $count; $i++) {
    $error = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) continue;
    if ($error !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Upload error on one of the files']);
        exit;
    }

    $originalName = (string)($names[$i] ?? '');
    $tmpPath = (string)($tmpNames[$i] ?? '');
    $size = (int)($sizes[$i] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File too large (max 15MB each)']);
        exit;
    }

    $cleanName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
    $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'File type not allowed: ' . $cleanName]);
        exit;
    }

    $mimeType = $finfo ? (string)finfo_file($finfo, $tmpPath) : 'application/octet-stream';
    $isImage = strpos($mimeType, 'image/') === 0;

    $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $targetFs = $uploadBaseFs . '/' . $storedName;
    if (!move_uploaded_file($tmpPath, $targetFs)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded file']);
        exit;
    }

    $attachments[] = [
        'name' => $cleanName,
        'url' => $uploadBaseWeb . '/' . $storedName,
        'mime_type' => $mimeType,
        'size' => $size,
        'is_image' => $isImage
    ];
}

if ($finfo) {
    finfo_close($finfo);
}

echo json_encode([
    'ok' => true,
    'attachments' => $attachments
]);
