<?php

/**
 * HooshPay payment-success webhook.
 *
 * The signature protects the delivery itself; the subsequent verify request protects
 * fulfillment from a stale or mismatched invoice. This endpoint must be publicly
 * reachable over HTTPS at the callback URL configured in HooshPay.
 */
if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', __DIR__);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/botapi.php';
require_once __DIR__ . '/panels.php';
require_once __DIR__ . '/function.php';
require_once __DIR__ . '/lib/PaymentConfirm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
if (strcasecmp((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 'POST') !== 0) {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

/** @return never */
function hooshpayCallbackRespond($statusCode, array $payload)
{
    http_response_code((int)$statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) {
    hooshpayCallbackRespond(400, ['ok' => false, 'error' => 'invalid_payload']);
}

$signature = $_SERVER['HTTP_X_HOOSHPAY_SIGNATURE'] ?? '';
if (!hooshpayCallbackSignatureIsValid($payload, $signature)) {
    error_log('[hooshpay] callback rejected: invalid signature');
    hooshpayCallbackRespond(401, ['ok' => false, 'error' => 'invalid_signature']);
}

if (($payload['event'] ?? '') !== 'payment.success' || strtolower((string)($payload['status'] ?? '')) !== 'paid') {
    // A valid, unsupported event does not need retries.
    hooshpayCallbackRespond(200, ['ok' => true, 'result' => 'ignored']);
}

$orderId = trim((string)($payload['order_id'] ?? ''));
$uid = trim((string)($payload['invoice'] ?? $payload['uid'] ?? ''));
if ($orderId === '' || $uid === '') {
    hooshpayCallbackRespond(400, ['ok' => false, 'error' => 'missing_invoice_reference']);
}

$report = select('Payment_report', '*', 'hooshpay_uid', $uid, 'select');
if (!is_array($report) || (string)($report['Payment_Method'] ?? '') !== 'hooshpay') {
    error_log('[hooshpay] callback rejected: payment record not found for uid=' . $uid);
    hooshpayCallbackRespond(404, ['ok' => false, 'error' => 'invoice_not_found']);
}

$callbackMatch = hooshpayInvoiceMatchesReport($report, $payload);
if (empty($callbackMatch['ok']) || !hash_equals((string)$report['id_order'], $orderId)) {
    error_log('[hooshpay] callback rejected: invoice mismatch for uid=' . $uid);
    hooshpayCallbackRespond(400, ['ok' => false, 'error' => 'invoice_mismatch']);
}

try {
    $verification = hooshpayVerifyPaidInvoiceForReport($report);
} catch (Throwable $e) {
    error_log('[hooshpay] callback verification exception: ' . $e->getMessage());
    hooshpayCallbackRespond(503, ['ok' => false, 'error' => 'verification_unavailable']);
}

if (empty($verification['ok'])) {
    // A non-2xx response makes HooshPay retry its webhook if final verification has
    // not become available yet. We do not fulfill a wallet charge without it.
    error_log('[hooshpay] callback verification pending/failed: ' . (string)($verification['reason'] ?? 'unknown'));
    hooshpayCallbackRespond(409, ['ok' => false, 'error' => 'verification_failed']);
}

hooshpayPersistInvoiceMetadata((string)$report['id_order'], $payload, false);

try {
    global $ManagePanel;
    if ((!isset($ManagePanel) || !($ManagePanel instanceof ManagePanel)) && class_exists('ManagePanel')) {
        $ManagePanel = new ManagePanel();
    }
    $result = payment_confirm_paid((string)$report['id_order'], 'chashbackhooshpay', [
        'method'      => 'hooshpay',
        'extra_lines' => array_filter([
            !empty($payload['tracking_code']) ? ('🔖 کد پیگیری هوش‌پی: ' . (string)$payload['tracking_code']) : '',
            '🔁 تأیید از طریق کال‌بک و verify هوش‌پی',
        ]),
    ]);
} catch (Throwable $e) {
    error_log('[hooshpay] callback fulfillment exception: ' . $e->getMessage());
    hooshpayCallbackRespond(500, ['ok' => false, 'error' => 'fulfillment_failed']);
}

hooshpayCallbackRespond(200, [
    'ok'      => true,
    'result'  => !empty($result['ok']) ? 'paid' : 'already_processed',
    'order_id'=> (string)$report['id_order'],
]);
