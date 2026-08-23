<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');
$responderId = op_post_int('responder_id');
$token = op_post_string('token', '', 512);
$platform = strtolower(op_post_string('platform', 'android', 20));
$appVersion = op_post_string('app_version', '', 40);
$deviceName = op_post_string('device_name', '', 150);

op_require_positive($responderId, 'responder_id');
op_require_text($token, 'token');
if ($platform !== 'android') {
    op_error('Unsupported notification platform.', 422);
}

try {
    $pdo = db();
    op_require_active_responder($pdo, $responderId);
    if (!op_table_exists($pdo, 'responder_device_tokens')) {
        op_error('Notification token storage has not been deployed.', 503);
    }

    $statement = $pdo->prepare(
        'INSERT INTO responder_device_tokens '
        . '(responder_id, token, platform, app_version, device_name, is_active, last_registered_at, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE '
        . 'responder_id = VALUES(responder_id), platform = VALUES(platform), '
        . 'app_version = VALUES(app_version), device_name = VALUES(device_name), '
        . 'is_active = 1, last_registered_at = NOW(), updated_at = NOW()'
    );
    $statement->execute([$responderId, $token, $platform, $appVersion, $deviceName]);

    op_success(['message' => 'Notification device registered.']);
} catch (Throwable $error) {
    error_log('register-device-token: ' . $error->getMessage());
    op_error('Unable to register this device for notifications.', 500);
}
