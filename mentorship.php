<?php
/**
 * Mentorship Program & Digital Bridge Internship Program (DBIP) — free registration.
 * Submit → we email a confirmation link → the link confirms the place and brings them back here.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$tracks = mentorship_tracks();
$sched = mentorship_schedule();
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
        redirect('mentorship.php?welcome=' . rawurlencode($m['reference']) . '&t=' . sign('mentorship-ok', $m['reference']));
    }
    $view = 'badlink';
}

/* ---------- Success page after confirming ---------- */
if (isset($_GET['welcome'])) {
    $ref = (string) $_GET['welcome'];
    $st = db()->prepare("SELECT * FROM mentorship_registrations WHERE reference = ? AND status = 'confirmed'");
    $st->execute([$ref]);
    $m = $st->fetch() ?: null;
    $view = $m && sign_valid('mentorship-ok', $ref, $_GET['t'] ?? null) ? 'welcome' : 'badlink';
}

/* ---------- "Check your email" ---------- */
if (isset($_GET['sent']) && !empty($_SESSION['mentorship_id'])) {
    $m = find_mentee((int) $_SESSION['mentorship_id']);
    $view = $m ? ($m['status'] === 'confirmed' ? 'already' : 'sent') : 'form';
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
        redirect('mentorship.php?sent=1&resent=' . ($wait ? 'wait' : '1'));
    } elseif (!mentorship_open()) {
        $errors['form'] = 'Registration for the mentorship program is closed at the moment.';
    } else {
        // Spam traps: hidden field + a minimum time on the page. Bots get the normal "check your email" page.
        $renderedAt = (int) ($_SESSION['mentorship_rendered_at'] ?? 0);
        if (!empty($_POST['website']) || ($renderedAt && time() - $renderedAt < 3)) {
            redirect('mentorship.php?sent=1');
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
            $data = [$old['full_name'], $old['phone'], $old['whatsapp'], $old['location'], implode(',', $old['tracks'])];
            if ($existing && $existing['status'] === 'confirmed') {
                $_SESSION['mentorship_id'] = (int) $existing['id'];
                redirect('mentorship.php?sent=1');
            }
            if ($existing) {   // registered before but never confirmed: update the details and send the link again
                db()->prepare('UPDATE mentorship_registrations SET full_name = ?, phone = ?, whatsapp = ?, location = ?, tracks = ?, status = \'pending\', updated_at = ? WHERE id = ?')
                    ->execute(array_merge($data, [now(), $existing['id']]));
                $m = find_mentee((int) $existing['id']);
                $sent = ($m['link_sent_at'] && time() - strtotime($m['link_sent_at']) < 60) ? true : send_mentorship_link($m, $err);
            } elseif (too_many('mentorship_registrations', $ip, 60, 20)) {
                $errors['form'] = 'Too many registrations from your network in the last hour. Please try again later or message us on WhatsApp.';
            } else {
                db()->prepare('INSERT INTO mentorship_registrations (full_name, phone, whatsapp, location, tracks, email, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
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
                    redirect('mentorship.php?sent=1');
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
$group = mentorship_whatsapp_group();

app_header('Mentorship & Digital Bridge Internship — free registration', '', '', $view === 'form' ? [
    'description' => 'Register free for the Kakebe Mentorship Program and Digital Bridge Internship Program (DBIP): online sessions every Monday 8:00–9:30 PM, mentors in your field, certificates and an in-person closing session.',
    'canonical'   => base_url('mentorship.php'),
] : []);
?>
<?php if ($view === 'form'): ?>
<section class="ment-hero">
  <span class="ment-kicker"><i class="fa-solid fa-gift"></i> Free program · open to all</span>
  <h1>Mentorship Program &amp; Digital Bridge Internship</h1>
  <p>Learn from great speakers and professionals every week, get matched with mentors in your field through the Digital Bridge Internship Program (DBIP), and earn a certificate.</p>
  <div class="ment-facts">
    <span><i class="fa-solid fa-video"></i> Online · every Monday</span>
    <span><i class="fa-regular fa-clock"></i> 8:00 – 9:30 PM (EAT)</span>
    <span><i class="fa-regular fa-calendar"></i> <?= $sched['started'] ? 'Next session' : 'Starts' ?> <?= e($firstDay) ?></span>
    <span><i class="fa-solid fa-certificate"></i> Certificate</span>
  </div>
</section>

<div class="app-grid">
  <section class="app-card">
    <h3><i class="fa-solid fa-user-plus"></i> Register for free</h3>
    <?php if (!mentorship_open()): ?>
      <div class="notice warn"><i class="fa-solid fa-circle-info"></i><span>Registration is closed at the moment. Message us on <a href="<?= e(whatsapp_link('Hello, I would like to join the Kakebe Mentorship Program & DBIP.')) ?>" target="_blank" rel="noopener">WhatsApp</a> to be told when it opens.</span></div>
    <?php else: ?>
    <p class="muted small">Takes about a minute. We'll email you a link to confirm your place.</p>
    <?php if (isset($errors['form'])): ?><div class="form-alert"><?= e($errors['form']) ?></div><?php elseif ($errors): ?><div class="form-alert">Please check the highlighted fields.</div><?php endif; ?>
    <form method="post" action="mentorship.php" id="mentForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="register">
      <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="field<?= $inv('full_name') ?>"><label for="m_name">Full name <small class="muted">(as it should appear on your certificate)</small></label><input id="m_name" name="full_name" value="<?= $val('full_name') ?>" autocomplete="name" required><?= $err('full_name') ?></div>
      <div class="field<?= $inv('email') ?>"><label for="m_email">Email address</label><input id="m_email" type="email" name="email" value="<?= $val('email') ?>" autocomplete="email" required><span class="hint">We'll send your confirmation link here.</span><?= $err('email') ?></div>
      <div class="row-2">
        <div class="field<?= $inv('phone') ?>"><label for="m_phone">Phone number</label><input id="m_phone" type="tel" name="phone" value="<?= $val('phone') ?>" placeholder="e.g. 0772 123 456" autocomplete="tel" required><?= $err('phone') ?></div>
        <div class="field<?= $inv('whatsapp') ?>"><label for="m_wa">WhatsApp number</label><input id="m_wa" type="tel" name="whatsapp" value="<?= $val('whatsapp') ?>" placeholder="e.g. 0772 123 456" required>
          <label class="inline-check"><input type="checkbox" name="same_wa" value="1" id="m_same" <?= !empty($old['same_wa']) ? 'checked' : '' ?>> Same as my phone number</label><?= $err('whatsapp') ?></div>
      </div>
      <div class="field<?= $inv('location') ?>"><label for="m_loc">Where are you based?</label><input id="m_loc" name="location" value="<?= $val('location') ?>" placeholder="Town or district, e.g. Gulu" required><?= $err('location') ?></div>

      <div class="field<?= $inv('tracks') ?>">
        <label>Internship fields you'd love to work in <small class="muted">— choose up to three</small></label>
        <div class="tracks-pick js-max3">
          <?php foreach ($tracks as $t): ?>
          <label class="tp"><input type="checkbox" name="tracks[]" value="<?= e($t) ?>" <?= in_array($t, $old['tracks'] ?? [], true) ? 'checked' : '' ?>><span><i class="fa-solid fa-circle-check"></i><?= e($t) ?></span></label>
          <?php endforeach; ?>
        </div><?= $err('tracks') ?>
      </div>

      <div class="field<?= $inv('commit') ?>">
        <label>Your commitment</label>
        <label class="commit"><input type="checkbox" name="commit_sessions" value="1" <?= !empty($old['commit']) ? 'checked' : '' ?>><span>I commit to attending the <b>online sessions every Monday, 8:00 – 9:30 PM</b>.</span></label>
        <label class="commit"><input type="checkbox" name="commit_attendance" value="1" <?= !empty($old['commit75']) ? 'checked' : '' ?>><span>I understand that the <b>certificate</b> and the in-person <b>closing session</b> are for participants with at least <b>75% attendance</b>.</span></label>
        <?= $err('commit') ?>
      </div>
      <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-paper-plane"></i> Register — it's free</button>
      <p class="hint center">By registering you agree to be contacted by Kakebe Technologies about the program.</p>
    </form>
    <?php endif; ?>
  </section>

  <aside class="app-side">
    <div class="app-card">
      <h3><i class="fa-solid fa-star"></i> What you get</h3>
      <ul class="info-list">
        <li><i class="fa-solid fa-microphone-lines"></i><span><b>Weekly sessions with great speakers</b><br>Professionals and leaders champion each session, online every Monday from 8:00 to 9:30 PM.</span></li>
        <li><i class="fa-solid fa-people-arrows"></i><span><b>Mentors in your field</b><br>You are officially attached to the Digital Bridge Internship Program. Mentors in your line of interest will be in touch — virtually or in person.</span></li>
        <li><i class="fa-solid fa-certificate"></i><span><b>Certificate</b><br>Issued when the program ends to everyone who attends at least 75% of the sessions.</span></li>
        <li><i class="fa-solid fa-location-dot"></i><span><b>In-person closing session</b><br>Held at selected locations for participants who reach 75% attendance.</span></li>
        <li><i class="fa-solid fa-hand-holding-heart"></i><span><b>Completely free</b><br>No fees at any stage.</span></li>
      </ul>
    </div>
    <div class="app-card">
      <h3><i class="fa-solid fa-list-ol"></i> How it works</h3>
      <ol class="ment-steps">
        <li>Fill in the form and choose up to three fields.</li>
        <li>Open the email from us and tap <b>Confirm my registration</b>.</li>
        <li>Join the first session on <b><?= e($firstDay) ?></b> at 8:00 PM.</li>
      </ol>
    </div>
  </aside>
</div>

<?php elseif ($view === 'sent'): ?>
<section class="app-card narrow center">
  <div class="card-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
  <h1>Check your email</h1>
  <p>We've sent a confirmation link to <b><?= e($m['email']) ?></b>. Tap <b>Confirm my registration</b> in that email to complete your registration.</p>
  <?php if (($_GET['resent'] ?? '') === '1'): ?><div class="notice ok"><i class="fa-solid fa-circle-check"></i><span>We've sent the link again.</span></div>
  <?php elseif (($_GET['resent'] ?? '') === 'wait'): ?><div class="notice warn"><i class="fa-regular fa-clock"></i><span>We sent it less than a minute ago — give it a moment to arrive, then try again.</span></div><?php endif; ?>
  <p class="muted small">Can't find it? Check your Spam or Promotions folder. The email comes from Kakebe Tech Camp.</p>
  <form method="post" action="mentorship.php" class="btn-row center"><?= csrf_field() ?><input type="hidden" name="action" value="resend">
    <button class="btn btn-ghost btn-sm" type="submit"><i class="fa-solid fa-rotate-right"></i> Send the link again</button>
    <a class="btn btn-ghost btn-sm" href="mentorship.php"><i class="fa-regular fa-pen-to-square"></i> Wrong email? Start again</a>
  </form>
</section>

<?php elseif ($view === 'welcome' || $view === 'already'): ?>
<section class="app-card narrow center">
  <div class="card-icon ok"><i class="fa-solid fa-check"></i></div>
  <h1><?= $view === 'welcome' ? "You're registered! 🎉" : "You're already registered" ?></h1>
  <p><?= $view === 'welcome' ? 'Welcome to the Kakebe Mentorship Program &amp; Digital Bridge Internship Program, ' . first_name($m['full_name']) . '. Your place is confirmed and the details are in your email.' : 'This email is already registered and confirmed for the Mentorship Program &amp; DBIP. Your welcome email has the details.' ?></p>
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

<?php else: ?>
<section class="app-card narrow center">
  <div class="card-icon bad"><i class="fa-solid fa-link-slash"></i></div>
  <h1>This link isn't valid</h1>
  <p class="muted">The link may be incomplete. Open it again from your email, or register again below.</p>
  <a class="btn btn-primary" href="mentorship.php">Go to registration</a>
</section>
<?php endif; ?>
<?php app_footer('', $view === 'form' ? ['assets/js/mentorship.js'] : []);
