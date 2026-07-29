<?php
declare(strict_types=1);
require_once __DIR__ . '/_operational_api.php';

op_require_method('POST');
$responderId = op_post_int('responder_id');
$token = op_post_string('token', '', 512);
op_require_positive($responderId, 'responder_id');
op_require_text($token, 'token');

try {
    $pdo = db();
    $statement = $pdo->prepare(
        'UPDATE responder_device_tokens SET is_active = 0, updated_at = NOW() '
        . 'WHERE responder_id = ? AND token = ?'
    );
    $statement->execute([$responderId, $token]);
    op_success(['message' => 'Notification device unregistered.']);
} catch (Throwable $error) {
    error_log('unregister-device-token: ' . $error->getMessage());
    op_error('Unable to unregister this notification device.', 500);
}
