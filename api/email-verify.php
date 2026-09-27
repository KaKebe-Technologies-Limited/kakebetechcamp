<?php
/**
 * POST /api/email-verify.php — checks the 6-digit code and marks the email as verified for this session.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
if (strlen($code) !== 6) {
    json_response(['ok' => false, 'message' => 'Enter the 6-digit code from your email.'], 422);
}

$stmt = db()->prepare('SELECT * FROM email_verifications WHERE email = ? AND verified_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$email, now()]);
$row = $stmt->fetch();
if (!$row || (int) $row['attempts'] >= 5) {
    json_response(['ok' => false, 'message' => 'This code has expired. Please request a new one.'], 410);
}
if (!password_verify($code, $row['code_hash'])) {
    db()->prepare('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    json_response(['ok' => false, 'message' => 'That code is not correct. Please check your email and try again.'], 422);
}

db()->prepare('UPDATE email_verifications SET verified_at = ? WHERE id = ?')->execute([now(), $row['id']]);
$_SESSION['verified_emails'][$email] = time();
json_response(['ok' => true, 'message' => 'Email verified — thank you!']);
