<?php
/**
 * GET /api/payment-status.php?id=&t= — polled by the browser while a payment is pending.
 * Checks ioTec directly, so confirmation works even when callbacks cannot reach this server.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id || !sign_valid('payment', (string) $id, $_GET['t'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Invalid payment link.'], 403);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$p = sync_payment($id);
if (!$p) {
    json_response(['ok' => false, 'message' => 'Payment not found.'], 404);
}
json_response(['ok' => true, 'payment' => payment_public($p)]);
