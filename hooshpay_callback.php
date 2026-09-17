<?php
if (!defined('REFACTORED_LEGACY_ROOT')) define('REFACTORED_LEGACY_ROOT', __DIR__);
require_once __DIR__.'/config.php'; require_once __DIR__.'/botapi.php'; require_once __DIR__.'/panels.php'; require_once __DIR__.'/function.php';
require_once __DIR__.'/lib/PaymentConfirm.php';
$raw=file_get_contents('php://input'); $payload=json_decode($raw,true);
if(!is_array($payload)){ http_response_code(400); exit('invalid payload'); }
$secret=(string)(select('PaySetting','ValuePay','NamePay','secrethooshpay','select')['ValuePay']??'');
$sig=$_SERVER['HTTP_X_HOOSHPAY_SIGNATURE']??''; ksort($payload);
$expected=hash_hmac('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$secret);
if($secret==='' || $sig==='' || !hash_equals($expected,$sig)){ http_response_code(401); exit('invalid signature'); }
if(($payload['event']??'')!=='payment.success' || ($payload['status']??'')!=='paid'){ http_response_code(200); exit('ignored'); }
$order=trim((string)($payload['order_id']??'')); if($order===''){ http_response_code(400); exit('missing order'); }
$payment=select('Payment_report','*','id_order',$order,'select'); if(!is_array($payment) || ($payment['Payment_Method']??'')!=='hooshpay'){ http_response_code(404); exit('not found'); }
$result=payment_confirm_paid($order,'chashbackhooshpay',['method'=>'hooshpay','extra_lines'=>['🔁 تایید از طریق کال‌بک هوش‌پی']]);
http_response_code(200); header('Content-Type: application/json'); echo json_encode(['ok'=>true,'result'=>$result]);
