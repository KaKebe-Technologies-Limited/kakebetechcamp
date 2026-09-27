<?php
/**
 * POST /api/pay.php — start a camp payment (Mobile Money or card) for a registration.
 * Authorised by a signed pay link (ref + t) or the participant's portal session.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}

$ref = (string) ($_POST['ref'] ?? '');
$r = null;
if ($ref !== '' && sign_valid('pay', $ref, $_POST['t'] ?? null)) {
    $r = find_registration_by_ref($ref);
} elseif ($p = current_participant()) {
    $r = $p;
}
if (!$r) {
    json_response(['ok' => false, 'message' => 'We could not find your registration. Please reopen the payment link.'], 404);
}

$amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
$method = (string) ($_POST['method'] ?? 'mobile_money');
$phone = trim((string) ($_POST['pay_phone'] ?? ''));

$res = begin_registration_payment($r, $amount, $method, $phone);
json_response($res, $res['ok'] ? 200 : 422);
