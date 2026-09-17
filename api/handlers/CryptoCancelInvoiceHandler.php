<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';
require_once __DIR__ . '/DiscountSupport.php';

final class CryptoCancelInvoiceHandler extends BaseHandler
{
    public function handle(): void
    {
        $this->requireMethod('POST');

        $orderId = FaoximaInput::string($this->data, 'order_id');
        if ($orderId === '') {
            FaoximaResponse::badRequest('order_id is required');
        }

        $report = FaoximaDb::fetchOne(
            'SELECT id_order, payment_Status, Payment_Method, atlaspay_order_id, hooshpay_uid FROM Payment_report
              WHERE id_order = :o AND id_user = :u AND source = \'miniapp\' LIMIT 1',
            [':o' => $orderId, ':u' => (string)$this->user['id']]
        );
        if ($report === null) {
            FaoximaResponse::notFound('Payment not found');
        }
        $method = trim((string)($report['Payment_Method'] ?? ''));
        $cancellable = ['arze digital offline', 'plisio', 'nowpayment', 'digitaltron', 'cart to cart', 'carttocart_pv', 'iranpay2', 'tonpay', 'cubepay', 'blupal', 'atlaspay', 'tetrapay', 'hooshpay'];
        if (!in_array($method, $cancellable, true)) {
            FaoximaResponse::fail(422, 'این فاکتور قابل لغو از این طریق نیست');
        }
        $status = (string)($report['payment_Status'] ?? '');


        if (in_array($status, ['cancelled', 'paid', 'reject'], true)) {
            FaoximaResponse::ok([
                'kind'    => 'already_finalized',
                'message' => 'این فاکتور قبلاً نهایی شده است',
                'status'  => $status,
            ]);
            return;
        }

        if (!in_array($status, ['Unpaid', 'AwaitingHash', 'waiting', 'pending', 'expire'], true)) {
            FaoximaResponse::fail(409, 'این فاکتور در وضعیتی نیست که قابل لغو باشد (' . $status . ')');
        }

        // HooshPay cancellation must succeed remotely before its local invoice is
        // discarded. This prevents a paid/settling gateway invoice from being
        // silently marked as cancelled in Faoxima.
        if ($method === 'hooshpay') {
            $uid = trim((string)($report['hooshpay_uid'] ?? ''));
            if ($uid === '' || !function_exists('hooshpayCancelInvoice')) {
                FaoximaResponse::fail(409, 'لغو فاکتور هوش‌پی در دسترس نیست؛ با پشتیبانی تماس بگیرید');
            }
            try {
                $cancel = hooshpayCancelInvoice($uid);
            } catch (Throwable $e) {
                FaoximaLogger::exception($e, 'HooshPay cancel-invoice request failed', ['user' => $this->user['id'], 'order' => $orderId]);
                FaoximaResponse::fail(503, 'ارتباط با هوش‌پی برای لغو فاکتور ناموفق بود؛ دوباره تلاش کنید');
            }
            $remoteStatus = function_exists('hooshpayInvoiceStatus') ? hooshpayInvoiceStatus($cancel) : '';
            if (!is_array($cancel) || empty($cancel['success']) || (function_exists('hooshpayInvoiceIsPaid') && hooshpayInvoiceIsPaid($cancel))) {
                FaoximaLogger::userFacing('HooshPay cancel rejected', [
                    'user' => $this->user['id'],
                    'order' => $orderId,
                    'status' => $remoteStatus,
                ]);
                FaoximaResponse::fail(409, $remoteStatus === 'paid'
                    ? 'پرداخت در هوش‌پی انجام شده است؛ وضعیت فاکتور را بررسی کنید'
                    : 'هوش‌پی لغو فاکتور را تأیید نکرد؛ دوباره تلاش کنید');
            }
            if (function_exists('hooshpayPersistInvoiceMetadata')) {
                try {
                    hooshpayPersistInvoiceMetadata($orderId, $cancel, false);
                } catch (Throwable $e) {
                    // The remote cancellation is authoritative; proceed with the
                    // guarded local terminal update even if optional audit fields
                    // could not be written at this instant.
                    FaoximaLogger::userFacing('HooshPay cancel metadata persistence failed', [
                        'user' => $this->user['id'],
                        'order' => $orderId,
                        'err' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($method === 'atlaspay' && function_exists('atlaspayCancelOrder')) {
            $atlaspayOrderId = trim((string)($report['atlaspay_order_id'] ?? ''));
            if ($atlaspayOrderId !== '') {
                try {
                    atlaspayCancelOrder($atlaspayOrderId);
                } catch (Throwable $e) {
                    FaoximaLogger::exception($e, 'AtlasPay cancel-order call failed', [
                        'user'  => $this->user['id'],
                        'order' => $orderId,
                    ]);
                }
            }
        }

        try {
            $pdo = FaoximaDb::pdo();
            $setClause = $method === 'hooshpay'
                ? "payment_Status = 'cancelled', hooshpay_status = 'cancelled'"
                : "payment_Status = 'cancelled'";
            $stmt = $pdo->prepare(
                "UPDATE Payment_report
                    SET {$setClause}
                  WHERE id_order = :o
                    AND id_user = :u
                    AND source = 'miniapp'
                    AND payment_Status IN ('Unpaid', 'AwaitingHash', 'waiting', 'pending', 'expire')"
            );
            $stmt->bindValue(':o', $orderId, PDO::PARAM_STR);
            $stmt->bindValue(':u', (string)$this->user['id'], PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                // Do not release a discount if a callback/poller finalized the
                // invoice between the upstream cancellation and this update.
                FaoximaResponse::fail(409, 'وضعیت فاکتور هم‌زمان تغییر کرده است؛ وضعیت را دوباره بررسی کنید');
            }
            MiniDiscount::releaseLastUnpaidDiscount((string)$this->user['id']);
            if (function_exists('rx_redis_del')) {
                rx_redis_del('faoxima:paystatus:' . $orderId . ':' . (string)$this->user['id']);
            }
        } catch (Throwable $e) {
            FaoximaLogger::exception($e, 'Crypto cancel-invoice update failed', [
                'user'  => $this->user['id'],
                'order' => $orderId,
            ]);
            FaoximaResponse::serverError('خطا در لغو فاکتور');
        }

        FaoximaLogger::debug('Crypto invoice cancelled by user', [
            'user'  => $this->user['id'],
            'order' => $orderId,
        ]);

        FaoximaResponse::ok([
            'kind'    => 'cancelled',
            'message' => '✅ فاکتور لغو شد',
        ]);
    }
}
