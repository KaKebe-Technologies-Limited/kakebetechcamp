<?php
/**
 * POST /api/email-code.php — sends a 6-digit code so an applicant can prove they own the email they register with.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if ($problem = email_problem($email)) {
    json_response(['ok' => false, 'message' => $problem], 422);
}
if (email_verified_in_session($email)) {
    json_response(['ok' => true, 'verified' => true, 'message' => 'Email already verified.']);
}

$dup = db()->prepare("SELECT COUNT(*) FROM registrations WHERE email = ? AND status <> 'cancelled'");
$dup->execute([$email]);
if ((int) $dup->fetchColumn() > 0) {
    json_response(['ok' => false, 'message' => 'This email is already registered. Use "My registration" at the top of the page to view it.'], 409);
}

$ip = client_ip();
if (too_many('email_verifications', $ip, 15, 8)) {
    json_response(['ok' => false, 'message' => 'Too many code requests. Please wait 15 minutes and try again.'], 429);
}
$last = db()->prepare('SELECT created_at FROM email_verifications WHERE email = ? ORDER BY id DESC LIMIT 1');
$last->execute([$email]);
if (($lastAt = $last->fetchColumn()) && time() - strtotime($lastAt) < 45) {
    json_response(['ok' => false, 'message' => 'Please wait a few seconds before requesting another code.'], 429);
}

$code = (string) random_int(100000, 999999);
db()->prepare('INSERT INTO email_verifications (email, code_hash, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([$email, password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 900), $ip, now()]);

[$subject, $html] = tpl_verify_code($code);
if (!send_mail($email, $subject, $html, null, $err)) {
    json_response(['ok' => false, 'message' => "We couldn't send a code to that address. Please check it and try again."], 502);
}

json_response(['ok' => true, 'message' => "We sent a 6-digit code to {$email}. Check your inbox (and spam folder)."]);
