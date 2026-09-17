<?php
require_once __DIR__.'/_init.php';
rx_cron_boot('hooshpaycheck', 60);
$ctx=rx_cron_load_payment_context(); if(empty($ctx['db_ready'])) return;
require_once __DIR__.'/../lib/PaymentConfirm.php';
global $pdo;
if(!($pdo instanceof PDO) || !function_exists('hooshpayGetInvoice')) return;
$q=$pdo->query("SELECT id_order,hooshpay_uid FROM Payment_report WHERE payment_Status='Unpaid' AND Payment_Method='hooshpay' AND hooshpay_uid<>'' ORDER BY id DESC LIMIT 30");
foreach(($q?$q->fetchAll(PDO::FETCH_ASSOC):[]) as $r){
  $x=hooshpayGetInvoice($r['hooshpay_uid']); if(!is_array($x)) continue;
  $d=$x['data']??$x; $status=strtolower((string)($x['status']??$d['status']??''));
  if(in_array($status,['expired','cancelled','failed'],true)){ payment_mark_expired($r['id_order']); continue; }
  if($status==='paid' || (($x['paid']??false)===true)){ payment_confirm_paid($r['id_order'],'chashbackhooshpay',['method'=>'hooshpay','thread_id'=>$ctx['paymentreports']??null,'extra_lines'=>['🔁 تایید خودکار هوش‌پی']]); }
}
