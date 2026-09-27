<?php
/**
 * POST admin/api-pay.php — an admin sends a Mobile Money payment prompt on behalf of a registered participant.
 * The payment is always attached to an existing registration.
 */
require __DIR__ . '/_init.php';

$admin = current_admin();
if (!$admin) {
    json_response(['ok' => false, 'message' => 'Your admin session has expired. Please log in again.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Refresh the page and try again.'], 419);
}

$r = find_registration((int) ($_POST['registration_id'] ?? 0));
if (!$r) {
    json_response(['ok' => false, 'message' => 'Participant not found. Payments can only be taken for registered participants.'], 404);
}
if ($r['status'] === 'cancelled') {
    json_response(['ok' => false, 'message' => 'This registration is cancelled.'], 422);
}
if ($r['status'] === 'review') {
    json_response(['ok' => false, 'message' => 'This participant is awaiting sponsorship approval. Approve or decline the sponsorship first.'], 422);
}

$bal = balance($r);
$amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
$phone = trim((string) ($_POST['pay_phone'] ?? ''));
if ($bal <= 0) {
    json_response(['ok' => false, 'message' => 'This participant has nothing left to pay.'], 422);
}
if ($amount < 500 || $amount > $bal) {
    json_response(['ok' => false, 'errors' => ['amount' => 'Enter an amount between UGX 500 and ' . format_ugx($bal) . '.'], 'message' => 'Please check the amount.'], 422);
}
if (!valid_msisdn($phone)) {
    json_response(['ok' => false, 'errors' => ['pay_phone' => 'Enter a valid MTN or Airtel number, e.g. 0772 123 456.'], 'message' => 'Please check the phone number.'], 422);
}

$p = create_payment([
    'registration_id' => $r['id'], 'purpose' => 'camp', 'amount' => $amount, 'method' => 'mobile_money',
    'payer_name' => $r['full_name'], 'payer_phone' => $phone, 'payer_email' => $r['email'],
    'notes' => 'Prompt sent by ' . $admin['name'], 'recorded_by' => (int) $admin['id'],
]);
$res = start_payment($p);
$res['payment'] = payment_public(payment_find((int) $p['id']));
$res['token'] = sign('payment', (string) $p['id']);
json_response($res, $res['ok'] ? 200 : 422);
