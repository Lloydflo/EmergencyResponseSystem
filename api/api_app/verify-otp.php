<?php
declare(strict_types=1);
require_once __DIR__ . '/_location.php';

op_require_method('POST');
$email = op_require_email(op_post_string('email', '', 254));
$otp = op_post_string('otp', '', 12);
if (!preg_match('/^\d{6}$/', $otp)) op_error('A valid 6-digit OTP is required.', 422, ['user' => null]);

try {
    $pdo = db();
    op_require_tables($pdo, ['users','responder_otps']);
    $pdo->beginTransaction();
    $selectColumns = ['id','otp','expires_at','used_at'];
    if (op_column_exists($pdo, 'responder_otps', 'attempt_count')) $selectColumns[]='attempt_count';
    $statement = $pdo->prepare(
        'SELECT ' . implode(',', $selectColumns) . ' FROM responder_otps '
        . 'WHERE LOWER(responder_email) = LOWER(?) AND used_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE'
    );
    $statement->execute([$email]);
    $row = op_fetch_one($statement);
    if ($row === null) { $pdo->rollBack(); op_error('No active OTP was found.', 404, ['user'=>null]); }
    if (strtotime((string)$row['expires_at']) < time()) {
        $pdo->prepare('UPDATE responder_otps SET used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        $pdo->commit();
        op_error('OTP expired.', 410, ['user'=>null]);
    }
    $attempts = (int)($row['attempt_count'] ?? 0);
    if ($attempts >= 5) { $pdo->rollBack(); op_error('Too many invalid attempts. Request a new OTP.', 429, ['user'=>null]); }
    $stored = (string)$row['otp'];
    $valid = password_verify($otp, $stored) || (strlen($stored) <= 12 && hash_equals($stored, $otp));
    if (!$valid) {
        if (op_column_exists($pdo, 'responder_otps', 'attempt_count')) {
            $pdo->prepare('UPDATE responder_otps SET attempt_count = attempt_count + 1 WHERE id = ?')->execute([(int)$row['id']]);
        }
        $pdo->commit();
        op_error('Invalid OTP.', 401, ['user'=>null,'attempts_remaining'=>max(0,4-$attempts)]);
    }
    $updated = $pdo->prepare('UPDATE responder_otps SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $updated->execute([(int)$row['id']]);
    if ($updated->rowCount() !== 1) { $pdo->rollBack(); op_error('OTP was already used.', 409, ['user'=>null]); }

    $idStatement = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) ORDER BY id DESC LIMIT 1');
    $idStatement->execute([$email]);
    $userId = (int)$idStatement->fetchColumn();
    $responder = op_active_responder($pdo, $userId);
    if ($responder === null) { $pdo->rollBack(); op_error('Account not found or inactive.', 403, ['user'=>null]); }
    if (op_column_exists($pdo, 'users', 'last_login')) {
        $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$userId]);
    }
    $pdo->commit();

    op_touch_presence($pdo, $userId);
    $input = op_request_data();
    $locationUpdate = null;
    if (array_intersect(['latitude','lat','longitude','lng','lon'], array_keys($input)) !== []) {
        $input['responder_id']=$userId;
        $input['unit_code']=(string)($responder['unit_code'] ?? '');
        $input['source']=$input['source'] ?? 'responder_otp_verify';
        $locationUpdate = app_location_update($pdo, $input);
    } else {
        $locationUpdate = [
            'ok'=>false,
            'error'=>'Responder GPS is required on login to place the assigned vehicle on the dispatch map'
        ];
    }
    $unit = app_location_resolve_unit($pdo, ['responder_id'=>$userId,'unit_code'=>(string)($responder['unit_code'] ?? '')]);
    op_success([
        'message'=>'OTP verified',
        'user'=>[
            'id'=>$userId,'name'=>(string)($responder['name'] ?? ''),'username'=>(string)($responder['username'] ?? ''),
            'email'=>(string)($responder['email'] ?? ''),'role'=>(string)($responder['role'] ?? ''),
            'department'=>(string)($responder['department'] ?? ''),'unit_id'=>$unit ? (int)$unit['id'] : null,
            'unit_code'=>(string)($responder['unit_code'] ?? ($unit['identifier'] ?? '')),
            'unit_type'=>(string)($responder['unit_type'] ?? ($unit['unit_type'] ?? '')),
            'unit_status'=>(string)($responder['unit_status'] ?? ($unit['status'] ?? 'available')),
            'profile_image_path'=>(string)($responder['profile_image_path'] ?? ''),
        ],
        'location_update'=>$locationUpdate,
        'location_tracking'=>[
            'enabled'=>$unit !== null,
            'location_required'=>true,
            'syncs_vehicle_location'=>true,
            'endpoint'=>'api/unit_location_update.php',
            'api_app_endpoint'=>'api/api_app/update-location.php'
        ],
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[verify-otp] ' . $error->getMessage());
    op_error('Unable to verify the OTP.', 500, ['user'=>null]);
}
