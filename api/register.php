<?php
/**
 * POST /api/register.php — Tech Camp registration (includes free Mentorship & Digital Bridge enrolment).
 * Saves the application with its package total, then emails the applicant and the admin team.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please refresh the page and submit again.'], 419);
}
if (setting('registration_open', '1') !== '1') {
    json_response(['ok' => false, 'message' => 'Registration is currently closed.'], 403);
}

// Spam traps: hidden honeypot field + a minimum time on the page. Bots get a fake success.
$renderedAt = (int) ($_SESSION['form_rendered_at'] ?? 0);
if (!empty($_POST['website']) || ($renderedAt && time() - $renderedAt < 3)) {
    json_response(['ok' => true, 'reference' => 'KTC26-0000', 'message' => 'Thank you for registering.']);
}

$ip = client_ip();
if (too_many('registrations', $ip, 60, 30)) {
    json_response(['ok' => false, 'message' => 'Too many registrations from your network in the last hour. Please try again later or contact us on WhatsApp.'], 429);
}

$clean = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$key] ?? ''))), 0, $max);
$errors = [];

$fullName = $clean('full_name', 150);
if (mb_strlen($fullName) < 3 || !preg_match('/\p{L}/u', $fullName)) {
    $errors['full_name'] = 'Please enter your full name.';
}
$age = filter_var($_POST['age'] ?? '', FILTER_VALIDATE_INT);
if ($age === false) {
    $errors['age'] = 'Please enter your age.';
} elseif ($age < 14 || $age > 30) {
    $errors['age'] = 'Tech Camp is open to young people aged 14 – 30.';
}
$gender = $clean('gender', 30);
$gender = in_array($gender, genders(), true) ? $gender : '';

$email = strtolower($clean('email', 190));
if ($problem = email_problem($email)) {
    $errors['email'] = $problem;
} elseif (!email_verified_in_session($email)) {
    $errors['email'] = 'Please verify your email address with the code we send you.';
}
$phone = $clean('phone', 40);
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) < 9 || strlen($digits) > 15 || preg_match('/[^\d\s+\-()]/', $phone)) {
    $errors['phone'] = 'Please enter a valid phone number.';
}
$district = $clean('district', 100);
if (mb_strlen($district) < 2) {
    $errors['district'] = 'Please enter your district of origin.';
}
$country = $clean('country', 100);
if (mb_strlen($country) < 2) {
    $errors['country'] = 'Please enter your country.';
}

$interests = array_values(array_unique(array_intersect((array) ($_POST['interests'] ?? []), interests())));
if (!$interests) {
    $errors['interests'] = 'Choose at least one learning track.';
} elseif (count($interests) > 2) {
    $errors['interests'] = 'You can choose a maximum of two learning tracks.';
}

$jersey = $clean('jersey_size', 10);
if (!in_array($jersey, jersey_sizes(), true)) {
    $errors['jersey_size'] = 'Please choose your jersey size.';
}
$park = !empty($_POST['park_visit']);
$mentorship = !empty($_POST['mentorship']);

$source = $clean('source', 40);
if (!in_array($source, sources(), true)) {
    $errors['source'] = 'Please tell us how you heard about the program.';
}
$sourceOther = $source === 'Other' ? $clean('source_other', 150) : '';
if ($source === 'Other' && mb_strlen($sourceOther) < 2) {
    $errors['source_other'] = 'Please specify where you heard about us.';
}
$referredBy = $clean('referred_by', 150);
$motivation = mb_substr(trim((string) ($_POST['motivation'] ?? '')), 0, 1000);
if (empty($_POST['consent'])) {
    $errors['consent'] = 'Please confirm to continue.';
}

[$photoExt, $photoErr] = check_image_upload('photo');
if ($photoErr) {
    $errors['photo'] = $photoErr;
}

if ($errors) {
    json_response(['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'], 422);
}

$pdo = db();
$dup = $pdo->prepare("SELECT reference FROM registrations WHERE email = ? AND status <> 'cancelled' LIMIT 1");
$dup->execute([$email]);
if ($dup->fetchColumn()) {
    json_response([
        'ok' => false,
        'errors' => ['email' => 'This email is already registered.'],
        'message' => 'You have already registered with this email. Use "My registration" at the top of the page to view it.',
    ], 409);
}

$photoFile = $photoExt ? store_upload('photo', $photoExt, STORAGE . '/uploads/photos') : null;
$amounts = new_order_amounts($park);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO registrations
        (program, full_name, age, gender, email, email_verified, phone, district, country, interests, jersey_size, park_visit, mentorship,
         camp_amount, jersey_amount, park_amount, total_amount, source, source_other, referred_by, motivation, photo,
         status, payment_status, ip, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        'techcamp', $fullName, $age, $gender ?: null, $email, $phone, $district, $country, implode(', ', $interests), $jersey, (int) $park, (int) $mentorship,
        $amounts['camp_amount'], $amounts['jersey_amount'], $amounts['park_amount'], $amounts['total_amount'],
        $source, $sourceOther ?: null, $referredBy ?: null, $motivation ?: null, $photoFile,
        'pending', 'unpaid', $ip, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), now(),
    ]);
    $id = (int) $pdo->lastInsertId();
    $reference = reference_for($id);
    $pdo->prepare('UPDATE registrations SET reference = ? WHERE id = ?')->execute([$reference, $id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($photoFile) {
        @unlink(STORAGE . '/uploads/photos/' . $photoFile);
    }
    error_log('Registration failed: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Sorry, we could not save your registration right now. Please try again in a moment.'], 500);
}

$r = find_registration($id);
$_SESSION['participant_id'] = $id; // lets them open the portal straight away on this device

respond_and_continue([
    'ok'        => true,
    'reference' => $reference,
    'name'      => explode(' ', $fullName)[0],
    'email'     => $email,
    'items'     => array_map(fn($i) => ['label' => $i[0], 'amount' => $i[1], 'tag' => $i[2]], order_items($r)),
    'total'     => (int) $r['total_amount'],
    'deposit'   => deposit_amount($r),
    'pay_url'   => pay_url($r),
    'message'   => 'Thank you, ' . explode(' ', $fullName)[0] . '! Your registration has been received.',
]);

$notify = trim((string) setting('notify_emails'));
if ($notify !== '') {
    [$subject, $html] = tpl_admin_registration($r);
    send_mail($notify, $subject, $html, $email);
}
if (setting('applicant_confirmation', '1') === '1') {
    [$subject, $html] = tpl_applicant_received($r);
    send_mail($email, $subject, $html, setting('contact_email') ?: null);
}
