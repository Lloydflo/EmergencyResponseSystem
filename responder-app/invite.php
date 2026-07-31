<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/responder_app_invitation.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

function responder_app_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function responder_app_render_error(int $status, string $title, string $message): never
{
    http_response_code($status);
    $safeTitle = responder_app_html($title);
    $safeMessage = responder_app_html($message);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$safeTitle}</title>
</head>
<body style="margin:0;background:#f3f5f6;font-family:Arial,sans-serif;color:#17212b;">
  <main style="max-width:520px;margin:60px auto;padding:0 18px;">
    <section style="background:#fff;border:1px solid #dce3e6;border-radius:16px;padding:28px;text-align:center;box-shadow:0 8px 28px rgba(0,0,0,.06);">
      <div style="font-size:44px;">⚠️</div>
      <h1 style="font-size:22px;margin:12px 0 8px;">{$safeTitle}</h1>
      <p style="color:#5f6d78;line-height:1.6;margin:0;">{$safeMessage}</p>
    </section>
  </main>
</body>
</html>
HTML;
    exit;
}

$userId = filter_input(INPUT_GET, 'uid', FILTER_VALIDATE_INT);
$expiresAt = filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT);
$signature = trim((string)($_GET['sig'] ?? ''));

if (!$userId || !$expiresAt || $signature === '') {
    responder_app_render_error(400, 'Invalid invitation', 'The application invitation link is incomplete.');
}

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    responder_app_render_error(503, 'Service unavailable', 'The responder service is temporarily unavailable.');
}

$stmt = $pdo->prepare(
    "SELECT `id`, `email`, `name`, COALESCE(`department`, '') AS `department`, `role`, `status`
     FROM `users`
     WHERE `id` = ?
       AND LOWER(`role`) = 'responder'
       AND LOWER(`status`) = 'active'
     LIMIT 1"
);
$stmt->execute([(int)$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($user)) {
    responder_app_render_error(403, 'Access unavailable', 'This responder account is inactive or no longer authorized.');
}

$email = trim((string)($user['email'] ?? ''));
if (!ers_validate_responder_app_invite((int)$userId, $email, (int)$expiresAt, $signature)) {
    $message = (int)$expiresAt <= time()
        ? 'This invitation has expired. Ask the system administrator to send a new invitation.'
        : 'This invitation is invalid or no longer matches the responder account.';
    responder_app_render_error(403, 'Invitation unavailable', $message);
}

$apkPath = trim((string)ers_responder_app_env('RESPONDER_APP_APK_PATH', ''));
if ($apkPath === '' || !is_file($apkPath) || !is_readable($apkPath)) {
    responder_app_render_error(503, 'Release unavailable', 'The current Android release has not yet been published. Please contact the system administrator.');
}

$downloadQuery = http_build_query([
    'uid' => (int)$userId,
    'exp' => (int)$expiresAt,
    'sig' => $signature,
], '', '&', PHP_QUERY_RFC3986);
$downloadUrl = 'download.php?' . $downloadQuery;

$name = responder_app_html(trim((string)($user['name'] ?? 'Responder')) ?: 'Responder');
$department = responder_app_html(trim((string)($user['department'] ?? '')) ?: 'Responder Unit');
$version = responder_app_html(trim((string)ers_responder_app_env('RESPONDER_APP_VERSION', 'Current release')));
$fileName = responder_app_html(trim((string)ers_responder_app_env('RESPONDER_APP_FILE_NAME', basename($apkPath))));
$expiresLabel = responder_app_html(date('M j, Y g:i A', (int)$expiresAt));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Emergency Responder App</title>
</head>
<body style="margin:0;background:#f3f5f6;font-family:Arial,sans-serif;color:#17212b;">
  <main style="max-width:620px;margin:28px auto;padding:0 16px 36px;">
    <section style="background:#fff;border:1px solid #dce3e6;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.07);">
      <header style="background:#4C8A89;color:#fff;padding:24px;">
        <div style="font-size:12px;font-weight:800;letter-spacing:.6px;">PRIVATE RESPONDER RELEASE</div>
        <h1 style="font-size:25px;margin:8px 0 0;">Emergency Responder App</h1>
      </header>
      <div style="padding:24px;">
        <p style="margin-top:0;">Hello <strong><?= $name ?></strong>. This download is authorized for your active responder account.</p>
        <div style="background:#f7f9fa;border-radius:12px;padding:14px 16px;line-height:1.8;margin:18px 0;">
          <div><span style="color:#667580;">Department:</span> <strong><?= $department ?></strong></div>
          <div><span style="color:#667580;">Version:</span> <strong><?= $version ?></strong></div>
          <div><span style="color:#667580;">File:</span> <strong><?= $fileName ?></strong></div>
          <div><span style="color:#667580;">Link expires:</span> <strong><?= $expiresLabel ?></strong></div>
        </div>
        <p style="text-align:center;margin:24px 0;">
          <a href="<?= responder_app_html($downloadUrl) ?>" style="display:inline-block;background:#4C8A89;color:#fff;text-decoration:none;font-weight:800;padding:15px 24px;border-radius:11px;">Download APK</a>
        </p>
        <h2 style="font-size:17px;margin:26px 0 8px;">Installation steps</h2>
        <ol style="padding-left:22px;line-height:1.75;color:#45545f;">
          <li>Tap <strong>Download APK</strong> and wait for the download to finish.</li>
          <li>Open the downloaded APK file.</li>
          <li>If Android asks, allow installation from the browser or file manager.</li>
          <li>Tap <strong>Install</strong>, then tap <strong>Open</strong>.</li>
          <li>Tap <strong>Proceed</strong>, allow notifications and location access, and turn on GPS.</li>
          <li>Enter your registered email, request the six-digit OTP, and tap <strong>Verify</strong>.</li>
        </ol>
        <p style="font-size:12px;color:#6d7a84;background:#fff6df;border:1px solid #f0d58c;border-radius:10px;padding:11px 12px;">Do not forward this private invitation. After installation, disable “Allow from this source” in Android settings.</p>
      </div>
    </section>
  </main>
</body>
</html>
