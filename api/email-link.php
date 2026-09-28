<?php
/**
 * POST /api/email-link.php — registration step 1.
 * Emails a secure link; opening it confirms the address and unlocks the rest of the registration form.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}
if (!empty($_POST['website'])) {
    json_response(['ok' => true, 'message' => 'Please check your inbox.']);
}
if (setting('registration_open', '1') !== '1') {
    json_response(['ok' => false, 'message' => 'Registration is currently closed.'], 403);
}
if (seats_left() <= 0) {
    json_response(['ok' => false, 'message' => 'Sorry — all ' . seat_capacity() . ' seats are taken, so registration is full.'], 409);
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if ($problem = email_problem($email)) {
    json_response(['ok' => false, 'errors' => ['email' => $problem], 'message' => $problem], 422);
}

$dup = db()->prepare("SELECT COUNT(*) FROM registrations WHERE email = ? AND status <> 'cancelled'");
$dup->execute([$email]);
if ((int) $dup->fetchColumn() > 0) {
    $m = 'This email is already registered. Use "My registration" at the top of the page to view it.';
    json_response(['ok' => false, 'errors' => ['email' => $m], 'message' => $m], 409);
}

$ip = client_ip();
if (too_many('email_verifications', $ip, 15, 8)) {
    json_response(['ok' => false, 'message' => 'Too many requests. Please wait 15 minutes and try again.'], 429);
}
$last = db()->prepare('SELECT created_at FROM email_verifications WHERE email = ? ORDER BY id DESC LIMIT 1');
$last->execute([$email]);
if (($lastAt = $last->fetchColumn()) && time() - strtotime($lastAt) < 45) {
    json_response(['ok' => false, 'message' => 'We just sent a link to this address. Please wait a moment before requesting another.'], 429);
}

$token = bin2hex(random_bytes(32));
db()->prepare('INSERT INTO email_verifications (email, code_hash, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([$email, hash('sha256', $token), date('Y-m-d H:i:s', time() + 86400), $ip, now()]);

[$subject, $html] = tpl_registration_link(base_url('?verify=' . $token . '#register'));
if (!send_mail($email, $subject, $html, setting('contact_email') ?: null, $err)) {
    json_response(['ok' => false, 'message' => "We couldn't send an email to that address. Please check it and try again."], 502);
}

json_response(['ok' => true, 'email' => $email, 'message' => 'Registration link sent.']);
