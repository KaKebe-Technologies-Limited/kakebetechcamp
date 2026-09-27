<?php
/**
 * POST /api/contact.php — website contact form.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and try again.'], 419);
}
if (!empty($_POST['company'])) {
    json_response(['ok' => true, 'message' => 'Thank you! Your message has been sent.']);
}

$ip = client_ip();
if (too_many('messages', $ip, 60, 10)) {
    json_response(['ok' => false, 'message' => 'You have sent several messages recently. Please try again later or call us.'], 429);
}

$name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? ''))), 0, 120);
$email = strtolower(mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190));
$phone = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40);
$message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 3000);

$errors = [];
if (mb_strlen($name) < 2) {
    $errors['name'] = 'Please enter your name.';
}
if ($problem = email_problem($email)) {
    $errors['email'] = $problem;
}
if ($phone !== '' && (preg_match('/[^\d\s+\-()]/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 9)) {
    $errors['phone'] = 'Please enter a valid phone number.';
}
if (mb_strlen($message) < 5) {
    $errors['message'] = 'Please write a short message.';
}
if ($errors) {
    json_response(['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'], 422);
}

db()->prepare('INSERT INTO messages (name, email, phone, message, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$name, $email, $phone ?: null, $message, $ip, now()]);

respond_and_continue(['ok' => true, 'message' => 'Thank you, ' . explode(' ', $name)[0] . '! Your message has been sent — we will get back to you soon.']);

$notify = trim((string) setting('notify_emails'));
if ($notify !== '') {
    [$subject, $html] = tpl_admin_message(['name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $message]);
    send_mail($notify, $subject, $html, $email);
}
