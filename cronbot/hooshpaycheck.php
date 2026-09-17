<?php

/** Polling fallback for HooshPay. Webhooks remain the fastest confirmation path. */
require_once __DIR__ . '/_init.php';
rx_cron_boot('hooshpaycheck', 60);

$ctx = rx_cron_load_payment_context();
if (empty($ctx['db_ready'])) {
    return;
}
require_once __DIR__ . '/../lib/PaymentConfirm.php';

global $pdo, $setting, $ManagePanel;
$setting = $ctx['setting'] ?? [];
$ManagePanel = $ctx['managePanel'] ?? null;

if (!($pdo instanceof PDO) || !function_exists('hooshpayGetInvoice') || !function_exists('hooshpayVerifyPaidInvoiceForReport')) {
    error_log('[hooshpaycheck] HooshPay prerequisites are unavailable');
    return;
}

try {
    $statement = $pdo->prepare(
        "SELECT *
           FROM Payment_report
          WHERE payment_Status IN ('Unpaid', 'pending', 'waiting', 'expire')
            AND Payment_Method = 'hooshpay'
            AND hooshpay_uid IS NOT NULL
            AND hooshpay_uid <> ''
          ORDER BY id DESC
          LIMIT 30"
    );
    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[hooshpaycheck] pending-payment query failed: ' . $e->getMessage());
    return;
}

foreach ($rows ?: [] as $report) {
    if (function_exists('rx_cron_time_up') && rx_cron_time_up()) {
        break;
    }

    $orderId = (string)($report['id_order'] ?? '');
    $uid = trim((string)($report['hooshpay_uid'] ?? ''));
    if ($orderId === '' || $uid === '') {
        continue;
    }

    // A malformed provider response, metadata write, or notification failure for
    // one invoice must never prevent the remaining pending invoices from being
    // reconciled in this run.
    try {
        $invoice = hooshpayGetInvoice($uid);
        if (!is_array($invoice) || empty($invoice['success'])) {
            continue;
        }

        $match = hooshpayInvoiceMatchesReport($report, $invoice);
        if (empty($match['ok'])) {
            error_log('[hooshpaycheck] invoice mismatch for ' . $orderId . ': ' . (string)($match['reason'] ?? 'unknown'));
            continue;
        }

        hooshpayPersistInvoiceMetadata($orderId, $invoice, false);
        $status = hooshpayInvoiceStatus($invoice);

        if (in_array($status, ['expired', 'cancelled', 'canceled'], true)) {
            $localStatus = in_array($status, ['cancelled', 'canceled'], true) ? 'cancelled' : 'expire';
            try {
                $update = $pdo->prepare(
                    "UPDATE Payment_report
                        SET payment_Status = :status, hooshpay_status = :remote_status
                      WHERE id_order = :order_id
                        AND Payment_Method = 'hooshpay'
                        AND payment_Status IN ('Unpaid', 'pending', 'waiting', 'expire')"
                );
                $update->execute([':status' => $localStatus, ':remote_status' => $status, ':order_id' => $orderId]);
                if (function_exists('rx_redis_del') && isset($report['id_user'])) {
                    rx_redis_del('faoxima:paystatus:' . $orderId . ':' . (string)$report['id_user']);
                }
            } catch (Throwable $e) {
                error_log('[hooshpaycheck] unable to save terminal status for ' . $orderId . ': ' . $e->getMessage());
            }
            continue;
        }

        if ($status === 'failed') {
            // Clear a short-lived status response even if sending the notification
            // encounters a temporary Telegram/application error.
            if (function_exists('rx_redis_del') && isset($report['id_user'])) {
                rx_redis_del('faoxima:paystatus:' . $orderId . ':' . (string)$report['id_user']);
            }
            try {
                payment_notify_user_failed($orderId, 'پرداخت توسط هوش‌پی ناموفق شد');
            } catch (Throwable $e) {
                error_log('[hooshpaycheck] failed-payment notification exception for ' . $orderId . ': ' . $e->getMessage());
            }
            continue;
        }

        if (!hooshpayInvoiceIsPaid($invoice)) {
            continue;
        }

        try {
            $verification = hooshpayVerifyPaidInvoiceForReport($report);
        } catch (Throwable $e) {
            error_log('[hooshpaycheck] verification exception for ' . $orderId . ': ' . $e->getMessage());
            continue;
        }
        if (empty($verification['ok'])) {
            error_log('[hooshpaycheck] verification failed for ' . $orderId . ': ' . (string)($verification['reason'] ?? 'unknown'));
            continue;
        }

        $data = hooshpayInvoiceData($verification['response'] ?? []);
        try {
            payment_confirm_paid($orderId, 'chashbackhooshpay', [
                'method'      => 'hooshpay',
                'thread_id'   => $ctx['paymentreports'] ?? null,
                'extra_lines' => array_filter([
                    !empty($data['tracking_code']) ? ('🔖 کد پیگیری هوش‌پی: ' . (string)$data['tracking_code']) : '',
                    '🔁 تأیید خودکار از طریق پولر و verify هوش‌پی',
                ]),
            ]);
        } catch (Throwable $e) {
            // One application-side fulfillment error must not stop polling unrelated
            // HooshPay invoices in this cron run.
            error_log('[hooshpaycheck] fulfillment exception for ' . $orderId . ': ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        error_log('[hooshpaycheck] invoice processing exception for ' . $orderId . ': ' . $e->getMessage());
    }
}
