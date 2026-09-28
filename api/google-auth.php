<?php
/**
 * POST /api/google-auth.php — "Continue with Google".
 *   Already registered  → logged in to the participant dashboard.
 *   Not registered yet  → email marked as confirmed; the registration form opens pre-filled.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}
if (!google_enabled()) {
    json_response(['ok' => false, 'message' => 'Google sign-in is not available right now.'], 503);
}
if (too_many('login_attempts', client_ip(), 15, 30)) {
    json_response(['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);
}

$claims = google_verify_id_token((string) ($_POST['credential'] ?? ''));
if (!$claims) {
    db()->prepare('INSERT INTO login_attempts (ip, email, created_at) VALUES (?, ?, ?)')->execute([client_ip(), 'google', now()]);
    json_response(['ok' => false, 'message' => 'We could not verify your Google sign-in. Please try again.'], 401);
}

$email = strtolower(trim((string) $claims['email']));
$sub = (string) $claims['sub'];
$intent = ($_POST['intent'] ?? '') === 'login' ? 'login' : 'register';

$stmt = db()->prepare("SELECT * FROM registrations WHERE (email = ? OR google_sub = ?) AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
$stmt->execute([$email, $sub]);
$r = $stmt->fetch();

if ($r) {
    db()->prepare('UPDATE registrations SET google_sub = COALESCE(google_sub, ?), last_login_at = ? WHERE id = ?')->execute([$sub, now(), $r['id']]);
    session_regenerate_id(true);
    $_SESSION['participant_id'] = (int) $r['id'];
    unset($_SESSION['impersonated_by']);
    portal_flash_message($intent === 'register'
        ? 'You are already registered for Kakebe Tech Camp 2026 — welcome to your dashboard.'
        : 'Welcome back, ' . explode(' ', trim($r['full_name']))[0] . '!');
    json_response(['ok' => true, 'action' => 'login', 'redirect' => base_url('portal/')]);
}

if (setting('registration_open', '1') !== '1') {
    json_response(['ok' => false, 'message' => 'We could not find a registration for ' . $email . ', and registration is currently closed.'], 403);
}
if (seats_left() <= 0) {
    json_response(['ok' => false, 'message' => 'Sorry — all ' . seat_capacity() . ' seats are taken, so registration is full.'], 409);
}

// Not registered yet: Google has confirmed the email, so continue straight to the registration form.
$name = trim((string) ($claims['name'] ?? trim(($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? ''))));
$_SESSION['verified_emails'][$email] = time();
$_SESSION['reg_email'] = $email;
$_SESSION['google_profile'] = ['email' => $email, 'sub' => $sub, 'name' => mb_substr($name, 0, 150), 'picture' => $claims['picture'] ?? null];
$_SESSION['reg_notice'] = ['ok', $intent === 'login'
    ? 'We could not find a registration for ' . $email . ' yet. Complete the form below to register — it only takes a couple of minutes.'
    : 'Signed in with Google as ' . $email . '. Complete your registration below.'];

json_response(['ok' => true, 'action' => 'register', 'redirect' => base_url('?continue=1#register')]);

/** Flash message shown on the participant dashboard. */
function portal_flash_message(string $msg): void
{
    $_SESSION['portal_flash'] = ['ok', $msg];
}
