<?php
/**
 * POST /api/donate.php — "Sponsor a child" / donation: saves the sponsor and starts the ioTec payment.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}
if (!empty($_POST['website'])) {
    json_response(['ok' => false, 'message' => 'Please try again.'], 422);
}
$ip = client_ip();
if (too_many('donations', $ip, 30, 10)) {
    json_response(['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);
}

$clean = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$key] ?? ''))), 0, $max);
$errors = [];
$name = $clean('donor_name', 150);
$email = strtolower($clean('donor_email', 190));
$phone = $clean('donor_phone', 40);
$org = $clean('organization', 150);
$children = max(0, min(100, (int) ($_POST['children'] ?? 0)));
$amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
$message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 1000);
$anonymous = !empty($_POST['anonymous']);
$method = in_array($_POST['method'] ?? '', ['mobile_money', 'card'], true) ? $_POST['method'] : 'mobile_money';
$payPhone = trim((string) ($_POST['pay_phone'] ?? $phone));

if (mb_strlen($name) < 2) $errors['donor_name'] = 'Please enter your name.';
if ($problem = email_problem($email)) $errors['donor_email'] = $problem;
if (strlen(preg_replace('/\D/', '', $phone)) < 9) $errors['donor_phone'] = 'Please enter a valid phone number.';
if ($children > 0) {
    $amount = $children * fees()['sponsor_child'];
}
if ($amount < 1000) $errors['amount'] = 'Please enter an amount of at least UGX 1,000.';
if ($amount > 50000000) $errors['amount'] = 'For large gifts please contact us directly.';
if ($method === 'mobile_money' && !valid_msisdn($payPhone)) $errors['pay_phone'] = 'Enter a valid MTN or Airtel number, e.g. 0772 123 456.';

if ($errors) {
    json_response(['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'], 422);
}

db()->prepare('INSERT INTO donations (donor_name, email, phone, organization, children, amount, message, is_anonymous, status, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$name, $email, $phone, $org ?: null, $children, $amount, $message ?: null, (int) $anonymous, 'pending', $ip, now()]);
$did = (int) db()->lastInsertId();
$ref = 'SPN26-' . str_pad((string) $did, 4, '0', STR_PAD_LEFT);
db()->prepare('UPDATE donations SET reference = ? WHERE id = ?')->execute([$ref, $did]);

$p = create_payment([
    'donation_id' => $did, 'purpose' => 'sponsorship', 'amount' => $amount, 'method' => $method,
    'payer_name' => $name, 'payer_phone' => $method === 'mobile_money' ? $payPhone : $phone, 'payer_email' => $email,
]);
$res = start_payment($p);
$res['payment'] = payment_public(payment_find((int) $p['id']));
$res['token'] = sign('payment', (string) $p['id']);
$res['reference'] = $ref;
json_response($res, $res['ok'] ? 200 : 422);
