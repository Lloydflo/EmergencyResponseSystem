<?php
declare(strict_types=1);
require_once __DIR__ . '/_location.php';
op_require_method('POST');
try {
 $pdo=db();$input=op_request_data();
 $responderId=(int)($input['responder_id']??$input['user_id']??0);op_require_positive($responderId,'responder_id');
 op_require_active_responder($pdo,$responderId);$input['responder_id']=$responderId;
 $result=app_location_update($pdo,$input);
 if (!$result['ok']) op_error((string)($result['error']??'Location update failed.'),422,['ok'=>false,'location'=>$result]);
 op_success(['ok'=>true,'message'=>'Location updated','location'=>$result]);
} catch (Throwable $error) { error_log('[update-location] '.$error->getMessage());op_error('Server error.',500,['ok'=>false]); }
