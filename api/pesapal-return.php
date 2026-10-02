<?php
/**
 * Pesapal sends the payer back here after the card page:
 *   ?OrderTrackingId=…&OrderMerchantReference=KTC-P{id}-…&OrderNotificationType=CALLBACKURL
 * We hand over to payment-return.php, which re-checks the payment with Pesapal and shows the result.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$tracking = (string) ($_GET['OrderTrackingId'] ?? '');
$reference = (string) ($_GET['OrderMerchantReference'] ?? '');

$p = preg_match('/^KTC-P(\d+)-/', $reference, $m) ? payment_find((int) $m[1]) : null;
if (!$p || $p['provider'] !== 'pesapal' || !hash_equals((string) $p['provider_txn_id'], $tracking)) {
    redirect(base_url('payment-return.php'));   // shows "Payment not found"
}
redirect(base_url('payment-return.php?id=' . $p['id'] . '&t=' . sign('payment', (string) $p['id'])));
