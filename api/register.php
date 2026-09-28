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
    $errors['email'] = 'Please confirm your email first using the registration link we emailed you.';
}
$phone = $clean('phone', 40);
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) < 9 || strlen($digits) > 15 || preg_match('/[^\d\s+\-()]/', $phone)) {
    $errors['phone'] = 'Please enter a valid phone number.';
}
$district = $clean('district', 100);
if (mb_strlen($district) < 2) {
    $errors['district'] = 'Please enter your district.';
}
$country = $clean('country', 100) ?: 'Uganda';

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
$mentorship = !isset($_POST['mentorship']) || !empty($_POST['mentorship']); // everyone is enrolled free unless they opt out in their profile

// Payment choice: pay now, pay later, or sponsored by someone else (needs admin approval)
$payWhen = in_array($_POST['pay_when'] ?? '', ['now', 'later', 'sponsored'], true) ? $_POST['pay_when'] : 'later';
$funding = ($payWhen === 'sponsored' || ($_POST['funding'] ?? '') === 'sponsored') ? 'sponsored' : 'self';
$sponsorId = null;
$sponsorName = null;
if ($funding === 'sponsored') {
    $choice = (string) ($_POST['sponsor_id'] ?? '');
    if ($choice === 'other') {
        $sponsorName = $clean('sponsor_other', 150);
        if (mb_strlen($sponsorName) < 2) {
            $errors['sponsor_other'] = 'Please enter the name of the person or organisation sponsoring you.';
        }
    } elseif (ctype_digit($choice) && ($sp = find_sponsor((int) $choice)) && $sp['is_active']) {
        $sponsorId = (int) $sp['id'];
        $sponsorName = sponsor_label($sp);
    } else {
        $errors['sponsor_id'] = 'Please choose who is sponsoring you.';
    }
}

$source = $clean('source', 40);
$source = in_array($source, sources(), true) ? $source : '';
$sourceOther = $source === 'Other' ? $clean('source_other', 150) : '';
$referredBy = $clean('referred_by', 150);
$motivation = mb_substr(trim((string) ($_POST['motivation'] ?? '')), 0, 1000);

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

// Strictly seat_capacity() participants — the seat check and the insert run under one lock.
$pdo->query("SELECT GET_LOCK('ktc_seats', 10)");
if (seats_left() <= 0) {
    $pdo->query("SELECT RELEASE_LOCK('ktc_seats')");
    json_response(['ok' => false, 'full' => true, 'message' => 'Sorry — all ' . seat_capacity() . ' seats have just been taken, so registration is now full.'], 409);
}

$photoFile = $photoExt ? store_upload('photo', $photoExt, STORAGE . '/uploads/photos') : null;
$amounts = new_order_amounts($park);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO registrations
        (program, full_name, age, gender, email, email_verified, phone, district, country, interests, jersey_size, park_visit, mentorship,
         camp_amount, jersey_amount, park_amount, total_amount, funding, sponsor_id, sponsor_name, source, source_other, referred_by, motivation, photo,
         status, payment_status, ip, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        'techcamp', $fullName, $age, $gender ?: null, $email, $phone, $district, $country, implode(', ', $interests), $jersey, (int) $park, (int) $mentorship,
        $amounts['camp_amount'], $amounts['jersey_amount'], $amounts['park_amount'], $amounts['total_amount'],
        $funding, $sponsorId, $sponsorName, $source, $sourceOther ?: null, $referredBy ?: null, $motivation ?: null, $photoFile,
        $funding === 'sponsored' ? 'review' : 'pending', 'unpaid', $ip, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), now(),
    ]);
    $id = (int) $pdo->lastInsertId();
    $reference = reference_for($id);
    $pdo->prepare('UPDATE registrations SET reference = ? WHERE id = ?')->execute([$reference, $id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->query("SELECT RELEASE_LOCK('ktc_seats')");
    if ($photoFile) {
        @unlink(STORAGE . '/uploads/photos/' . $photoFile);
    }
    error_log('Registration failed: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Sorry, we could not save your registration right now. Please try again in a moment.'], 500);
}

$pdo->query("SELECT RELEASE_LOCK('ktc_seats')");

// Registered with "Continue with Google": remember the Google account, and use the Google photo if none was uploaded.
if ($g = google_profile_for($email)) {
    $gPhoto = $photoFile ? null : google_fetch_photo($g['picture'] ?? null);
    $pdo->prepare("UPDATE registrations SET auth_provider = 'google', google_sub = ?, photo = COALESCE(photo, ?) WHERE id = ?")->execute([$g['sub'], $gPhoto, $id]);
    unset($_SESSION['google_profile']);
}

$r = find_registration($id);
$_SESSION['participant_id'] = $id; // lets them open the portal straight away on this device
unset($_SESSION['reg_email'], $_SESSION['verified_emails'][$email]); // registering someone else starts again at step 1

respond_and_continue([
    'ok'        => true,
    'reference' => $reference,
    'name'      => explode(' ', $fullName)[0],
    'email'     => $email,
    'items'     => array_map(fn($i) => ['label' => $i[0], 'amount' => $i[1], 'tag' => $i[2]], order_items($r)),
    'total'     => (int) $r['total_amount'],
    'pay_url'   => pay_url($r),
    'pay_now'   => $funding === 'self' && $payWhen === 'now',
    'seats_left' => seats_left(),
    'capacity'  => seat_capacity(),
    'sponsored' => $funding === 'sponsored',
    'sponsor'   => $sponsorName,
    'message'   => 'Thank you, ' . explode(' ', $fullName)[0] . '! Your registration has been received.',
]);

[$subject, $html] = tpl_admin_registration($r);
notify_team('registration', $subject, $html, $email);
if (setting('applicant_confirmation', '1') === '1') {
    [$subject, $html] = tpl_applicant_received($r);
    send_mail($email, $subject, $html, setting('contact_email') ?: null);
}
