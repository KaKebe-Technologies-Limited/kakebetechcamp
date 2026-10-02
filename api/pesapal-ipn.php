<?php
/**
 * Pesapal IPN (payment notification), registered automatically by pesapal_ipn_id():
 *   ?OrderTrackingId=…&OrderMerchantReference=KTC-P{id}-…&OrderNotificationType=IPNCHANGE
 * The notification itself is never trusted: the payment is re-checked with Pesapal's status API.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$in = $_GET + $_POST + (json_decode((string) file_get_contents('php://input'), true) ?: []);
$tracking = (string) ($in['OrderTrackingId'] ?? $in['orderTrackingId'] ?? '');
$reference = (string) ($in['OrderMerchantReference'] ?? $in['orderMerchantReference'] ?? '');
$type = (string) ($in['OrderNotificationType'] ?? $in['orderNotificationType'] ?? 'IPNCHANGE');

if (preg_match('/^KTC-P(\d+)-/', $reference, $m)) {
    $p = payment_find((int) $m[1]);
    if ($p && $p['provider'] === 'pesapal' && hash_equals((string) $p['provider_txn_id'], $tracking)) {
        sync_payment((int) $p['id'], true);
    }
}

header('Content-Type: application/json');
echo json_encode(['orderNotificationType' => $type, 'orderTrackingId' => $tracking, 'orderMerchantReference' => $reference, 'status' => 200]);
