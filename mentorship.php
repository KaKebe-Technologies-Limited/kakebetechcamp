<?php
/**
 * Kakebe Mentorship Program & Digital Bridge Internship Program (DBIP) — free registration, limited places.
 * Short link: /mentorship (see .htaccess). Submit → we email a confirmation link → the link confirms the place
 * (or the waiting list once all places are taken) and brings them back here.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$tracks = mentorship_tracks();
$sched = mentorship_schedule();
$capacity = mentorship_capacity();
$view = 'form';
$errors = [];
$old = [];
$m = null;

/* ---------- The emailed link ---------- */
if (isset($_GET['confirm'])) {
    $m = find_mentee((int) $_GET['confirm']);
    if ($m && sign_valid('mentorship-confirm', $m['id'] . '|' . $m['email'], $_GET['t'] ?? null)) {
        $m = confirm_mentee($m);
        unset($_SESSION['mentorship_id']);
        redirect($m['status'] === 'confirmed'
            ? 'mentorship?welcome=' . rawurlencode($m['reference']) . '&t=' . sign('mentorship-ok', $m['reference'])
            : 'mentorship?waitlist=' . (int) $m['id'] . '&t=' . sign('mentorship-wl', (string) $m['id']));
    }
    $view = 'badlink';
}

/* ---------- After confirming: success, or the waiting list ---------- */
if (isset($_GET['welcome'])) {
    $ref = (string) $_GET['welcome'];
    $st = db()->prepare("SELECT * FROM mentorship_registrations WHERE reference = ? AND status = 'confirmed'");
    $st->execute([$ref]);
    $m = $st->fetch() ?: null;
    $view = $m && sign_valid('mentorship-ok', $ref, $_GET['t'] ?? null) ? 'welcome' : 'badlink';
}
if (isset($_GET['waitlist'])) {
    $m = find_mentee((int) $_GET['waitlist']);
    $view = $m && sign_valid('mentorship-wl', (string) $m['id'], $_GET['t'] ?? null) ? ($m['status'] === 'confirmed' ? 'already' : 'waitlist') : 'badlink';
}

/* ---------- "Check your email" ---------- */
if (isset($_GET['sent']) && !empty($_SESSION['mentorship_id'])) {
    $m = find_mentee((int) $_SESSION['mentorship_id']);
    $view = !$m ? 'form' : ['confirmed' => 'already', 'waitlist' => 'waitlist'][$m['status']] ?? 'sent';
}

/* ---------- Submit ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'register');
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please submit the form again.';
    } elseif ($action === 'resend') {
        $m = !empty($_SESSION['mentorship_id']) ? find_mentee((int) $_SESSION['mentorship_id']) : null;
        $wait = $m && $m['link_sent_at'] && time() - strtotime($m['link_sent_at']) < 60;
        if ($m && $m['status'] === 'pending' && !$wait) {
            send_mentorship_link($m);
        }
        redirect('mentorship?sent=1&resent=' . ($wait ? 'wait' : '1'));
    } elseif (!mentorship_open()) {
        $errors['form'] = 'Registration for the mentorship program is closed at the moment.';
    } else {
        // Spam traps: hidden field + a minimum time on the page. Bots get the normal "check your email" page.
        $renderedAt = (int) ($_SESSION['mentorship_rendered_at'] ?? 0);
        if (!empty($_POST['website']) || ($renderedAt && time() - $renderedAt < 3)) {
            redirect('mentorship?sent=1');
        }
        $ip = client_ip();
        $clean = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$key] ?? ''))), 0, $max);
        $phoneOk = static function (string $p): bool {
            $d = preg_replace('/\D/', '', $p);
            return strlen($d) >= 9 && strlen($d) <= 15 && !preg_match('/[^\d\s+\-()]/', $p);
        };
        $old = [
            'full_name' => $clean('full_name', 150),
            'email'     => strtolower($clean('email', 190)),
            'phone'     => $clean('phone', 40),
            'whatsapp'  => $clean('whatsapp', 40),
            'same_wa'   => !empty($_POST['same_wa']),
            'location'  => $clean('location', 120),
            'referred'  => $clean('referred_by', 150),
            'tracks'    => array_values(array_unique(array_intersect((array) ($_POST['tracks'] ?? []), $tracks))),
            'commit'    => !empty($_POST['commit_sessions']),
            'commit75'  => !empty($_POST['commit_attendance']),
        ];
        if ($old['same_wa']) {
            $old['whatsapp'] = $old['phone'];
        }
        if (mb_strlen($old['full_name']) < 3 || !preg_match('/\p{L}/u', $old['full_name'])) {
            $errors['full_name'] = 'Please enter your full name — it goes on your certificate.';
        }
        if ($p = email_problem($old['email'])) {
            $errors['email'] = $p;
        }
        if (!$phoneOk($old['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number.';
        }
        if (!$phoneOk($old['whatsapp'])) {
            $errors['whatsapp'] = 'Please enter a valid WhatsApp number.';
        }
        if (mb_strlen($old['location']) < 2) {
            $errors['location'] = 'Tell us where you are based (town or district).';
        }
        if (!$old['tracks']) {
            $errors['tracks'] = 'Choose at least one field.';
        } elseif (count($old['tracks']) > 3) {
            $errors['tracks'] = 'You can choose a maximum of three fields.';
        }
        if (!$old['commit'] || !$old['commit75']) {
            $errors['commit'] = 'Please confirm both commitments to register.';
        }

        if (!$errors) {
            $st = db()->prepare('SELECT * FROM mentorship_registrations WHERE email = ?');
            $st->execute([$old['email']]);
            $existing = $st->fetch() ?: null;
            $data = [$old['full_name'], $old['phone'], $old['whatsapp'], $old['location'], implode(',', $old['tracks']), $old['referred'] !== '' ? $old['referred'] : null];
            $sent = false;
            if ($existing && in_array($existing['status'], ['confirmed', 'waitlist'], true)) {
                $_SESSION['mentorship_id'] = (int) $existing['id'];
                redirect('mentorship?sent=1');
            }
            if ($existing) {   // registered before but never confirmed: update the details and send the link again
                db()->prepare('UPDATE mentorship_registrations SET full_name = ?, phone = ?, whatsapp = ?, location = ?, tracks = ?, referred_by = ?, status = \'pending\', updated_at = ? WHERE id = ?')
                    ->execute(array_merge($data, [now(), $existing['id']]));
                $m = find_mentee((int) $existing['id']);
                $sent = ($m['link_sent_at'] && time() - strtotime($m['link_sent_at']) < 60) ? true : send_mentorship_link($m, $err);
            } elseif (too_many('mentorship_registrations', $ip, 60, 20)) {
                $errors['form'] = 'Too many registrations from your network in the last hour. Please try again later or message us on WhatsApp.';
            } else {
                db()->prepare('INSERT INTO mentorship_registrations (full_name, phone, whatsapp, location, tracks, referred_by, email, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute(array_merge($data, [$old['email'], $ip, now(), now()]));
                $m = find_mentee((int) db()->lastInsertId());
                $sent = send_mentorship_link($m, $err);
                if (!$sent) {
                    db()->prepare('DELETE FROM mentorship_registrations WHERE id = ?')->execute([$m['id']]);
                }
            }
            if (!$errors) {
                if (!$sent) {
                    $errors['email'] = "We couldn't send an email to this address. Please check it and try again.";
                } else {
                    $_SESSION['mentorship_id'] = (int) $m['id'];
                    redirect('mentorship?sent=1');
                }
            }
        }
    }
}

if ($view === 'form') {
    $_SESSION['mentorship_rendered_at'] = time();
}
$val = fn(string $k) => e((string) ($old[$k] ?? ''));
$err = fn(string $k) => '<span class="err">' . e($errors[$k] ?? '') . '</span>';
$inv = fn(string $k) => isset($errors[$k]) ? ' invalid' : '';
$firstDay = date('l, j F Y', $sched['next'] ?? $sched['start']);
$dates = date('j F', $sched['start']) . ' – ' . date('j F Y', $sched['end']);
$group = mentorship_whatsapp_group();
$left = mentorship_places_left();
$taken = $capacity - $left;
$full = $left <= 0;
$image = base_url('assets/img/mentorship.png') . '?v=' . filemtime(__DIR__ . '/assets/img/mentorship.png');

app_header('Kakebe Mentorship Program — ' . $capacity . ' free places', '', '', $view === 'form' ? [
    'description' => 'Free mentorship from ' . $dates . ', every Monday 8:00–9:30 PM online, with mentors in your field through the Digital Bridge Internship Program and a certificate. Only ' . $capacity . ' places — register now.',
    'canonical'   => base_url('mentorship'),
    'image'       => $image,
    'image_size'  => [1200, 630],
    'image_alt'   => 'A Kakebe mentorship session',
] : []);
?>
<?php if ($view === 'form'): ?>
<section class="mh">
  <div class="mh-media">
    <img src="<?= e($image) ?>" alt="A Kakebe mentorship session" width="1200" height="630" fetchpriority="high">
    <span class="mh-badge"><i class="fa-solid fa-gift"></i> Free</span>
  </div>
  <div class="mh-body">
    <span class="ment-kicker"><i class="fa-solid fa-users"></i> Only <?= $capacity ?> places</span>
    <h1>Kakebe Mentorship Program</h1>
    <p class="mh-sub">with the Digital Bridge Internship Program (DBIP)</p>
    <p>Learn every week from great speakers and professionals, then put your skills to work: through the Digital Bridge Internship Program you intern — virtually or in person — with mentors, companies and development partners in the fields you choose.</p>
    <ul class="mh-facts">
      <li><i class="fa-solid fa-video"></i><span><b>Every Monday</b> · 8:00 – 9:30 PM, online</span></li>
      <li><i class="fa-regular fa-calendar"></i><span><b><?= e($dates) ?></b> · <?= $sched['started'] ? 'next session' : 'first session' ?> <?= e(date('l j F', $sched['next'] ?? $sched['start'])) ?></span></li>
      <li><i class="fa-solid fa-briefcase"></i><span><b>Internship</b> with mentors, companies &amp; development partners</span></li>
      <li><i class="fa-solid fa-certificate"></i><span><b>Certificate</b> and an in-person closing session at 75% attendance</span></li>
    </ul>
    <div class="places<?= $full ? ' full' : '' ?>">
      <div class="places-top"><b><?= $full ? 'All ' . $capacity . ' places are taken' : $left . ' of ' . $capacity . ' places left' ?></b><span><?= $full ? 'Join the waiting list' : $taken . ' taken' ?></span></div>
      <div class="places-bar"><i style="width: <?= min(100, round($taken / $capacity * 100)) ?>%"></i></div>
    </div>
    <a href="#register" class="btn btn-primary"><?= $full ? 'Join the waiting list' : 'Register free' ?> <i class="fa-solid fa-arrow-down"></i></a>
  </div>
</section>

<section class="ment-partners" aria-label="Partners">
  <p>Brought to you by <b>Kakebe Technologies</b> in partnership with</p>
  <div class="ment-partner-logos">
    <?php foreach (mentorship_partners() as $pt): ?>
    <figure title="<?= e($pt['name']) ?>"><img src="<?= e($pt['logo']) ?>?v=<?= filemtime(__DIR__ . '/' . $pt['logo']) ?>" alt="<?= e($pt['name']) ?>" width="<?= (int) $pt['size'][0] ?>" height="<?= (int) $pt['size'][1] ?>" loading="lazy"></figure>
    <?php endforeach; ?>
  </div>
</section>

<section class="ment-about">
  <div><span><i class="fa-solid fa-chalkboard-user"></i></span><b>Learn</b><p>Online sessions every Monday, 8:00 – 9:30 PM, led by great speakers and professionals who champion each session.</p></div>
  <div><span><i class="fa-solid fa-briefcase"></i></span><b>Intern</b><p>Through the Digital Bridge Internship Program you intern virtually or in person with mentors, companies and development partners — practising the skills you learn, in the fields you choose.</p></div>
  <div><span><i class="fa-solid fa-certificate"></i></span><b>Get certified</b><p>Attend at least 75% of the sessions to earn your certificate and join the in-person closing session at selected locations.</p></div>
</section>

<section class="app-card ment-form" id="register">
  <h2><?= $full ? 'Join the waiting list' : 'Register for free' ?></h2>
  <?php if (!mentorship_open()): ?>
    <div class="notice warn"><i class="fa-solid fa-circle-info"></i><span>Registration is closed at the moment. Message us on <a href="<?= e(whatsapp_link('Hello, I would like to join the Kakebe Mentorship Program & DBIP.')) ?>" target="_blank" rel="noopener">WhatsApp</a> to be told when it opens.</span></div>
  <?php else: ?>
  <p class="muted small"><?= $full ? 'All places are taken. Register and confirm your email to join the waiting list — we\'ll email you if a place opens.' : 'Takes about a minute. We\'ll email you a link — places go to the first ' . $capacity . ' people who confirm.' ?></p>
  <?php if (isset($errors['form'])): ?><div class="form-alert"><?= e($errors['form']) ?></div><?php elseif ($errors): ?><div class="form-alert">Please check the highlighted fields.</div><?php endif; ?>
  <form method="post" action="mentorship#register" id="mentForm" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="register">
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <div class="row-2">
      <div class="field<?= $inv('full_name') ?>"><label for="m_name">Full name</label><input id="m_name" name="full_name" value="<?= $val('full_name') ?>" autocomplete="name" placeholder="As it should appear on your certificate" required><?= $err('full_name') ?></div>
      <div class="field<?= $inv('email') ?>"><label for="m_email">Email address</label><input id="m_email" type="email" name="email" value="<?= $val('email') ?>" autocomplete="email" placeholder="you@example.com" required><?= $err('email') ?></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('phone') ?>"><label for="m_phone">Phone number</label><input id="m_phone" type="tel" name="phone" value="<?= $val('phone') ?>" placeholder="e.g. 0772 123 456" autocomplete="tel" required><?= $err('phone') ?></div>
      <div class="field<?= $inv('whatsapp') ?>"><label for="m_wa">WhatsApp number</label><input id="m_wa" type="tel" name="whatsapp" value="<?= $val('whatsapp') ?>" placeholder="e.g. 0772 123 456" required>
        <label class="inline-check"><input type="checkbox" name="same_wa" value="1" id="m_same" <?= !empty($old['same_wa']) ? 'checked' : '' ?>> Same as my phone number</label><?= $err('whatsapp') ?></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('location') ?>"><label for="m_loc">Where are you based?</label><input id="m_loc" name="location" value="<?= $val('location') ?>" placeholder="Town or district, e.g. Gulu" required><?= $err('location') ?></div>
      <div class="field"><label for="m_ref">Who recommended you? <small class="muted">(optional)</small></label><input id="m_ref" name="referred_by" value="<?= $val('referred') ?>" maxlength="150" placeholder="Name of the person who told you"></div>
    </div>

    <div class="field<?= $inv('tracks') ?>">
      <label>Fields you'd love to work in <small class="muted">— choose up to three</small></label>
      <div class="tracks-pick js-max3">
        <?php foreach ($tracks as $t): ?>
        <label class="tp"><input type="checkbox" name="tracks[]" value="<?= e($t) ?>" <?= in_array($t, $old['tracks'] ?? [], true) ? 'checked' : '' ?>><span><i class="fa-solid fa-circle-check"></i><?= e($t) ?></span></label>
        <?php endforeach; ?>
      </div><?= $err('tracks') ?>
    </div>

    <div class="field<?= $inv('commit') ?>">
      <label>Your commitment</label>
      <label class="commit"><input type="checkbox" name="commit_sessions" value="1" <?= !empty($old['commit']) ? 'checked' : '' ?>><span>I will attend the <b>online sessions every Monday, 8:00 – 9:30 PM</b>.</span></label>
      <label class="commit"><input type="checkbox" name="commit_attendance" value="1" <?= !empty($old['commit75']) ? 'checked' : '' ?>><span>I understand the <b>certificate</b> and the in-person <b>closing session</b> need at least <b>75% attendance</b>.</span></label>
      <?= $err('commit') ?>
    </div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-paper-plane"></i> <?= $full ? 'Join the waiting list' : 'Register — it\'s free' ?></button>
    <p class="hint center">By registering you agree to be contacted by Kakebe Technologies about the program.</p>
  </form>
  <?php endif; ?>
</section>

<ol class="ment-how">
  <li><span>1</span><div><b>Register</b><small>Fill in the form and pick up to three fields.</small></div></li>
  <li><span>2</span><div><b>Confirm your email</b><small>Tap the link we send you to secure your place.</small></div></li>
  <li><span>3</span><div><b>Join on Monday</b><small><?= e($firstDay) ?> at 8:00 PM, online.</small></div></li>
</ol>

<?php elseif ($view === 'sent'): ?>
<section class="app-card narrow center">
  <div class="card-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
  <h1>Check your email</h1>
  <p>We've sent a confirmation link to <b><?= e($m['email']) ?></b>. Tap <b>Confirm my registration</b> in that email to secure your place.</p>
  <?php if (($_GET['resent'] ?? '') === '1'): ?><div class="notice ok"><i class="fa-solid fa-circle-check"></i><span>We've sent the link again.</span></div>
  <?php elseif (($_GET['resent'] ?? '') === 'wait'): ?><div class="notice warn"><i class="fa-regular fa-clock"></i><span>We sent it less than a minute ago — give it a moment to arrive, then try again.</span></div><?php endif; ?>
  <p class="muted small">Can't find it? Check your Spam or Promotions folder. The email comes from Kakebe Tech Camp. Places go to the first <?= $capacity ?> people who confirm.</p>
  <form method="post" action="mentorship" class="btn-row center"><?= csrf_field() ?><input type="hidden" name="action" value="resend">
    <button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-rotate-right"></i> Send the link again</button>
    <a class="btn btn-ghost btn-sm" href="mentorship"><i class="fa-regular fa-pen-to-square"></i> Wrong email? Start again</a>
  </form>
</section>

<?php elseif ($view === 'welcome' || $view === 'already'): ?>
<section class="app-card narrow center">
  <div class="card-icon ok"><i class="fa-solid fa-check"></i></div>
  <h1><?= $view === 'welcome' ? "You're in! 🎉" : "You're already registered" ?></h1>
  <p><?= $view === 'welcome' ? 'Welcome to the Kakebe Mentorship Program, ' . first_name($m['full_name']) . '. Your place is confirmed and the details are in your email.' : 'This email already has a place in the Kakebe Mentorship Program. Your welcome email has the details.' ?></p>
  <?php if ($view === 'welcome'): ?><div class="ment-ref"><small>Mentorship number</small><b><?= e($m['reference']) ?></b></div><?php endif; ?>
  <ul class="info-list plain ment-next">
    <li><i class="fa-regular fa-calendar"></i> <b><?= $sched['started'] ? 'Next session' : 'First session' ?>:</b> <?= e($firstDay) ?>, 8:00 – 9:30 PM (online)</li>
    <li><i class="fa-solid fa-repeat"></i> Then every Monday — attend at least 75% for your certificate and the closing session.</li>
  </ul>
  <div class="btn-row center">
    <a class="btn btn-navy" href="<?= e(mentorship_calendar_url()) ?>" target="_blank" rel="noopener"><i class="fa-regular fa-calendar-plus"></i> Add to my calendar</a>
    <?php if ($group): ?><a class="btn btn-primary ment-wa" href="<?= e($group) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Join the WhatsApp group</a><?php endif; ?>
  </div>
  <p class="muted small">Coming to Kakebe Tech Camp in December too? <a href="./#register">Register for the camp</a>.</p>
</section>

<?php elseif ($view === 'waitlist'): ?>
<section class="app-card narrow center">
  <div class="card-icon"><i class="fa-regular fa-hourglass-half"></i></div>
  <h1>You're on the waiting list</h1>
  <p>Thank you for confirming your email, <?= first_name($m['full_name']) ?>. All <?= $capacity ?> places have been taken, so you're on the waiting list.</p>
  <p class="muted">If a place opens up we'll email you straight away — and we'll tell you about the next intake.</p>
  <a class="btn btn-ghost" href="./">Back to the website</a>
</section>

<?php else: ?>
<section class="app-card narrow center">
  <div class="card-icon bad"><i class="fa-solid fa-link-slash"></i></div>
  <h1>This link isn't valid</h1>
  <p class="muted">The link may be incomplete. Open it again from your email, or register again below.</p>
  <a class="btn btn-primary" href="mentorship">Go to registration</a>
</section>
<?php endif; ?>
<?php app_footer('', $view === 'form' ? ['assets/js/mentorship.js'] : []);
