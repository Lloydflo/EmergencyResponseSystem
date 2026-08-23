<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/responder_app_invitation.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function responder_app_download_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

$userId = filter_input(INPUT_GET, 'uid', FILTER_VALIDATE_INT);
$expiresAt = filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT);
$signature = trim((string)($_GET['sig'] ?? ''));

if (!$userId || !$expiresAt || $signature === '') {
    responder_app_download_fail(400, 'Invalid application download request.');
}

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    responder_app_download_fail(503, 'Responder service is temporarily unavailable.');
}

$stmt = $pdo->prepare(
    "SELECT `id`, `email`
     FROM `users`
     WHERE `id` = ?
       AND LOWER(`role`) = 'responder'
       AND LOWER(`status`) = 'active'
     LIMIT 1"
);
$stmt->execute([(int)$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($user)) {
    responder_app_download_fail(403, 'Responder account is inactive or unauthorized.');
}

$email = trim((string)($user['email'] ?? ''));
if (!ers_validate_responder_app_invite((int)$userId, $email, (int)$expiresAt, $signature)) {
    responder_app_download_fail(403, 'The application invitation is invalid or expired.');
}

$configuredPath = trim((string)ers_responder_app_env('RESPONDER_APP_APK_PATH', ''));
$apkPath = $configuredPath !== '' ? realpath($configuredPath) : false;
if ($apkPath === false || !is_file($apkPath) || !is_readable($apkPath)) {
    responder_app_download_fail(503, 'The current Android release is unavailable.');
}

if (strtolower((string)pathinfo($apkPath, PATHINFO_EXTENSION)) !== 'apk') {
    error_log('Rejected responder app path because it is not an APK: ' . $apkPath);
    responder_app_download_fail(500, 'The Android release is misconfigured.');
}

$fileName = trim((string)ers_responder_app_env('RESPONDER_APP_FILE_NAME', basename($apkPath)));
$fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'EmergencyResponder-release.apk';
if (!str_ends_with(strtolower($fileName), '.apk')) {
    $fileName .= '.apk';
}

$fileSize = filesize($apkPath);
if ($fileSize === false || $fileSize <= 0) {
    responder_app_download_fail(503, 'The Android release file is empty or unavailable.');
}

ignore_user_abort(true);
set_time_limit(0);
session_write_close();

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . $fileSize);
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: none');

error_log('Responder APK download authorized for user_id=' . (int)$userId . ' email=' . $email);

$handle = fopen($apkPath, 'rb');
if ($handle === false) {
    responder_app_download_fail(503, 'Unable to open the Android release.');
}

while (!feof($handle)) {
    $buffer = fread($handle, 1024 * 1024);
    if ($buffer === false) {
        break;
    }
    echo $buffer;
    flush();
}
fclose($handle);
exit;
