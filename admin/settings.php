<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $section = (string) ($_POST['section'] ?? '');
    $str = fn(string $k, int $max = 500) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $num = fn(string $k) => (string) max(0, (int) preg_replace('/\D/', '', (string) ($_POST[$k] ?? '0')));

    switch ($section) {
        case 'general':
            setting_set('registration_open', !empty($_POST['registration_open']) ? '1' : '0');
            setting_set('camp_capacity', (string) max(1, (int) $str('camp_capacity')));
            flash('General settings saved.');
            break;

        case 'pricing':
            setting_set('camp_fee', $num('camp_fee'));
            setting_set('jersey_fee', $num('jersey_fee'));
            setting_set('park_fee', $num('park_fee'));
            setting_set('park_name', $str('park_name', 60) ?: 'Aruu Falls');
            setting_set('min_deposit_percent', (string) max(1, min(100, (int) $str('min_deposit_percent'))));
            setting_set('sponsor_child_amount', $num('sponsor_child_amount'));
            flash('Pricing saved. New registrations use these amounts; existing participants keep the package they registered with.');
            break;

        case 'email':
            $emails = array_filter(array_map('trim', preg_split('/[\s,;]+/', $str('notify_emails', 1000))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
            setting_set('notify_emails', implode(', ', array_unique($emails)));
            setting_set('applicant_confirmation', !empty($_POST['applicant_confirmation']) ? '1' : '0');
            setting_set('mail_transport', in_array($_POST['mail_transport'] ?? '', ['smtp', 'mail', 'log'], true) ? $_POST['mail_transport'] : 'log');
            setting_set('smtp_host', $str('smtp_host', 190));
            setting_set('smtp_port', (string) (int) $str('smtp_port'));
            setting_set('smtp_secure', in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls');
            setting_set('smtp_user', $str('smtp_user', 190));
            if (($pass = (string) ($_POST['smtp_pass'] ?? '')) !== '') {
                setting_set('smtp_pass', encrypt_secret(str_replace(' ', '', $pass)));
            }
            $from = $str('mail_from_email', 190);
            setting_set('mail_from_email', filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '');
            setting_set('mail_from_name', $str('mail_from_name', 120) ?: 'Kakebe Tech Camp');
            flash('Email settings saved.');
            break;

        case 'test':
            $to = $str('test_to', 190);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('Enter a valid email address.', 'error');
                break;
            }
            [$s, $h] = tpl_test();
            $ok = send_mail($to, $s, $h, null, $err);
            flash(!$ok ? 'Test email failed: ' . $err : (setting('mail_transport') === 'log' ? 'Sending is switched off (Log only) — the test was written to the log. Choose SMTP to send real emails.' : "Test email sent to $to."), $ok ? (setting('mail_transport') === 'log' ? 'warning' : 'success') : 'error');
            break;

        case 'iotec_test':
            $token = iotec_configured() ? iotec_token() : null;
            flash($token ? 'Connected to ioTec successfully (' . (iotec()['sandbox'] ? 'sandbox / test' : 'LIVE') . ' mode).' : 'Could not connect to ioTec — check the IOTEC_* keys in the .env file.', $token ? 'success' : 'error');
            break;

        case 'contact':
            setting_set('contact_phone', $str('contact_phone', 40));
            setting_set('contact_whatsapp', preg_replace('/\D/', '', $str('contact_whatsapp', 20)));
            $ce = $str('contact_email', 190);
            setting_set('contact_email', filter_var($ce, FILTER_VALIDATE_EMAIL) ? $ce : '');
            foreach (['org_website', 'social_tiktok', 'social_x', 'social_linkedin', 'social_facebook', 'social_instagram', 'social_youtube'] as $k) {
                $url = $str($k, 300);
                setting_set($k, preg_match('~^https?://~i', $url) ? $url : '');
            }
            flash('Contacts & social links saved.');
            break;
    }
    redirect('settings.php#' . ($section === 'test' ? 'email' : ($section === 'iotec_test' ? 'payments' : $section)));
}

$s = settings_all(true);
$io = iotec();
admin_header('Settings', 'settings');
?>
<div class="settings-nav">
  <a href="#general"><i class="fa-solid fa-sliders"></i> General</a>
  <a href="#pricing"><i class="fa-solid fa-tags"></i> Pricing</a>
  <a href="#payments"><i class="fa-solid fa-credit-card"></i> Payments</a>
  <a href="#email"><i class="fa-solid fa-envelope"></i> Email</a>
  <a href="#contact"><i class="fa-solid fa-address-book"></i> Contacts &amp; social</a>
</div>

<div class="grid-2 align-start">
  <section class="card" id="general">
    <div class="card-head"><h3><i class="fa-solid fa-sliders"></i> General</h3></div>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="section" value="general">
      <label class="switch"><input type="checkbox" name="registration_open" value="1" <?= $s['registration_open'] === '1' ? 'checked' : '' ?>><span class="slider"></span> Registration is open on the website</label>
      <label>Camp capacity (seats)<input type="number" min="1" name="camp_capacity" value="<?= e($s['camp_capacity']) ?>"></label>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    </form>
  </section>

  <section class="card" id="payments">
    <div class="card-head"><h3><i class="fa-solid fa-credit-card"></i> Payments (ioTec Pay)</h3><span class="badge <?= $io['sandbox'] ? 'st-pending' : 'st-confirmed' ?>"><?= $io['sandbox'] ? 'Sandbox / test' : 'LIVE' ?></span></div>
    <dl class="details one">
      <div><dt>Mode</dt><dd><?= $io['sandbox'] ? 'Sandbox — test numbers only, no real money (localhost)' : 'Live — real Mobile Money & card payments' ?></dd></div>
      <div><dt>Currency</dt><dd><?= e($io['currency']) ?></dd></div>
      <div><dt>Credentials</dt><dd><?= iotec_configured() ? '<span class="ok-text"><i class="fa-solid fa-circle-check"></i> Client ID, secret and wallet configured in .env</span>' : '<span class="err-text">Missing — add IOTEC_* keys to the .env file</span>' ?></dd></div>
      <div><dt>IPN / callback URL</dt><dd class="small"><?= e(base_url('api/iotec-ipn.php')) ?></dd></div>
    </dl>
    <p class="small muted">Payments are confirmed by checking ioTec directly, so they work even without callbacks. On a live domain the live wallet is always used.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="section" value="iotec_test"><button class="btn btn-navy" type="submit"><i class="fa-solid fa-plug"></i> Test ioTec connection</button></form>
  </section>
</div>

<section class="card" id="pricing">
  <div class="card-head"><h3><i class="fa-solid fa-tags"></i> Pricing</h3><span class="muted small">Applies to new registrations</span></div>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="section" value="pricing">
    <div class="row-3">
      <label>Camp fee (UGX) — required<input type="text" inputmode="numeric" name="camp_fee" value="<?= e($s['camp_fee']) ?>"></label>
      <label>Sports jersey vest (UGX) — required<input type="text" inputmode="numeric" name="jersey_fee" value="<?= e($s['jersey_fee']) ?>"></label>
      <label>Deposit to book a slot (%)<input type="number" min="1" max="100" name="min_deposit_percent" value="<?= e($s['min_deposit_percent']) ?>"></label>
      <label>Optional excursion name<input type="text" name="park_name" value="<?= e($s['park_name']) ?>"></label>
      <label>Excursion fee (UGX)<input type="text" inputmode="numeric" name="park_fee" value="<?= e($s['park_fee']) ?>"></label>
      <label>Sponsor-a-child amount (UGX)<input type="text" inputmode="numeric" name="sponsor_child_amount" value="<?= e($s['sponsor_child_amount']) ?>"></label>
    </div>
    <p class="small muted">Camp shirts are free for everyone. Package = camp fee + jersey (+ excursion if chosen).</p>
    <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save pricing</button></div>
  </form>
</section>

<section class="card" id="email">
  <div class="card-head"><h3><i class="fa-solid fa-envelope"></i> Email &amp; notifications</h3>
    <span class="badge <?= $s['mail_transport'] === 'smtp' ? 'st-confirmed' : 'st-pending' ?>"><?= e(['smtp' => 'SMTP — sending', 'mail' => 'PHP mail()', 'log' => 'Log only — not sending'][$s['mail_transport']] ?? $s['mail_transport']) ?></span>
  </div>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="section" value="email">
    <label>Send admin alerts (registrations, payments, messages) to <small class="muted">comma separated</small><input type="text" name="notify_emails" value="<?= e($s['notify_emails']) ?>"></label>
    <label class="switch"><input type="checkbox" name="applicant_confirmation" value="1" <?= $s['applicant_confirmation'] === '1' ? 'checked' : '' ?>><span class="slider"></span> Email applicants a confirmation when they register</label>
    <div class="row-3">
      <label>Transport<select name="mail_transport"><option value="smtp" <?= $s['mail_transport'] === 'smtp' ? 'selected' : '' ?>>SMTP (Gmail)</option><option value="mail" <?= $s['mail_transport'] === 'mail' ? 'selected' : '' ?>>PHP mail()</option><option value="log" <?= $s['mail_transport'] === 'log' ? 'selected' : '' ?>>Log only (don't send)</option></select></label>
      <label>SMTP host<input type="text" name="smtp_host" value="<?= e($s['smtp_host']) ?>"></label>
      <label>Port<input type="number" name="smtp_port" value="<?= e($s['smtp_port']) ?>"></label>
      <label>Encryption<select name="smtp_secure"><option value="tls" <?= $s['smtp_secure'] === 'tls' ? 'selected' : '' ?>>TLS (587)</option><option value="ssl" <?= $s['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option><option value="none" <?= $s['smtp_secure'] === 'none' ? 'selected' : '' ?>>None</option></select></label>
      <label>Username<input type="text" name="smtp_user" value="<?= e($s['smtp_user']) ?>" autocomplete="off"></label>
      <label>App password <?= $s['smtp_pass'] !== '' ? '<small class="ok-text"><i class="fa-solid fa-lock"></i> saved (encrypted)</small>' : '' ?><input type="password" name="smtp_pass" placeholder="<?= $s['smtp_pass'] !== '' ? 'Leave blank to keep' : '16-character app password' ?>" autocomplete="new-password"></label>
      <label>From email<input type="email" name="mail_from_email" value="<?= e($s['mail_from_email']) ?>"></label>
      <label>From name<input type="text" name="mail_from_name" value="<?= e($s['mail_from_name']) ?>"></label>
    </div>
    <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save email settings</button> <a class="btn btn-light" href="emails.php"><i class="fa-solid fa-list"></i> Email log</a></div>
  </form>
  <form method="post" class="test-mail"><?= csrf_field() ?><input type="hidden" name="section" value="test"><input type="email" name="test_to" value="<?= e($admin['email']) ?>" required><button class="btn btn-navy" type="submit"><i class="fa-solid fa-paper-plane"></i> Send test email</button></form>
</section>

<section class="card" id="contact">
  <div class="card-head"><h3><i class="fa-solid fa-address-book"></i> Contacts &amp; social links</h3><span class="muted small">Shown on the website, emails and receipts</span></div>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="section" value="contact">
    <div class="row-3">
      <label>Support phone<input type="text" name="contact_phone" value="<?= e($s['contact_phone']) ?>"></label>
      <label>WhatsApp (digits, e.g. 256779712990)<input type="text" name="contact_whatsapp" value="<?= e($s['contact_whatsapp']) ?>"></label>
      <label>Public email<input type="email" name="contact_email" value="<?= e($s['contact_email']) ?>"></label>
      <label>Organisation website<input type="url" name="org_website" value="<?= e($s['org_website']) ?>"></label>
      <label><i class="fa-brands fa-tiktok"></i> TikTok<input type="url" name="social_tiktok" value="<?= e($s['social_tiktok']) ?>"></label>
      <label><i class="fa-brands fa-x-twitter"></i> X<input type="url" name="social_x" value="<?= e($s['social_x']) ?>"></label>
      <label><i class="fa-brands fa-linkedin"></i> LinkedIn<input type="url" name="social_linkedin" value="<?= e($s['social_linkedin']) ?>"></label>
      <label><i class="fa-brands fa-facebook"></i> Facebook<input type="url" name="social_facebook" value="<?= e($s['social_facebook']) ?>"></label>
      <label><i class="fa-brands fa-instagram"></i> Instagram<input type="url" name="social_instagram" value="<?= e($s['social_instagram']) ?>"></label>
      <label><i class="fa-brands fa-youtube"></i> YouTube<input type="url" name="social_youtube" value="<?= e($s['social_youtube']) ?>"></label>
    </div>
    <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
  </form>
</section>
<?php admin_footer();
