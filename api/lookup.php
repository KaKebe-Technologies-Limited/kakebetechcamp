<?php
/**
 * POST /api/lookup.php — "Pay later": find a registration by email + phone and return its payment link.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}
$ip = client_ip();
if (too_many('login_attempts', $ip, 15, 10)) {
    json_response(['ok' => false, 'message' => 'Too many attempts. Please wait 15 minutes or contact us on ' . setting('contact_phone') . '.'], 429);
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$phone = phone_key((string) ($_POST['phone'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 9) {
    json_response(['ok' => false, 'message' => 'Enter the email address and phone number you registered with.'], 422);
}

$stmt = db()->prepare("SELECT * FROM registrations WHERE email = ? AND status <> 'cancelled' ORDER BY id DESC");
$stmt->execute([$email]);
$match = null;
foreach ($stmt->fetchAll() as $row) {
    if (phone_key($row['phone']) === $phone) {
        $match = $row;
        break;
    }
}
if (!$match) {
    db()->prepare('INSERT INTO login_attempts (ip, email, created_at) VALUES (?, ?, ?)')->execute([$ip, $email, now()]);
    json_response(['ok' => false, 'message' => "We couldn't find a registration with that email and phone number. Check the details or register first."], 404);
}

json_response(['ok' => true, 'redirect' => pay_url($match)]);
