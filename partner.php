<?php
/**
 * Partner with us — organisations and individuals tell us how they would like to contribute to
 * Kakebe Tech Camp 2026 (short link: /partner). The team follows up from Admin → Partners.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$ways = partner_ways();
$errors = [];
$old = [];
$done = null;

/* ---------- Thank-you page ---------- */
if (isset($_GET['done'])) {
    $ref = (string) $_GET['done'];
    if (sign_valid('partner-done', $ref, $_GET['t'] ?? null)) {
        $st = db()->prepare('SELECT * FROM partners WHERE reference = ?');
        $st->execute([$ref]);
        $done = $st->fetch() ?: null;
    }
}

/* ---------- Submit ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please submit the form again.';
    } else {
        // Spam traps: hidden field + a minimum time on the page
        $renderedAt = (int) ($_SESSION['partner_rendered_at'] ?? 0);
        if (!empty($_POST['website_url']) || ($renderedAt && time() - $renderedAt < 4)) {
            redirect('partner');
        }
        $clean = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$key] ?? ''))), 0, $max);
        $old = [
            'organization' => $clean('organization', 150),
            'org_type'     => $clean('org_type', 60),
            'contact_name' => $clean('contact_name', 150),
            'job_title'    => $clean('job_title', 120),
            'email'        => strtolower($clean('email', 190)),
            'phone'        => $clean('phone', 40),
            'location'     => $clean('location', 120),
            'website'      => $clean('website', 255),
            'ways'         => array_values(array_unique(array_intersect((array) ($_POST['ways'] ?? []), array_keys($ways)))),
            'contribution' => mb_substr(trim((string) ($_POST['contribution'] ?? '')), 0, 2000),
            'budget'       => $clean('budget', 60),
            'contact_pref' => $clean('contact_pref', 60),
            'referred_by'  => $clean('referred_by', 150),
        ];
        $o = $old;
        if (mb_strlen($o['organization']) < 2) {
            $errors['organization'] = 'Enter the name of your organisation (or your own name if you are partnering as an individual).';
        }
        if (!in_array($o['org_type'], partner_types(), true)) {
            $errors['org_type'] = 'Please choose an option.';
        }
        if (mb_strlen($o['contact_name']) < 3 || !preg_match('/\p{L}/u', $o['contact_name'])) {
            $errors['contact_name'] = 'Please enter the contact person\'s full name.';
        }
        if ($p = email_problem($o['email'])) {
            $errors['email'] = $p;
        }
        $digits = preg_replace('/\D/', '', $o['phone']);
        if (strlen($digits) < 9 || strlen($digits) > 15 || preg_match('/[^\d\s+\-()]/', $o['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number.';
        }
        if (mb_strlen($o['location']) < 2) {
            $errors['location'] = 'Tell us where you are based.';
        }
        if ($o['website'] !== '') {
            if (!preg_match('~^https?://~i', $o['website'])) {
                $o['website'] = $old['website'] = 'https://' . $o['website'];
            }
            if (!filter_var($o['website'], FILTER_VALIDATE_URL)) {
                $errors['website'] = 'Enter a valid link, or leave it empty.';
            }
        }
        if (!$o['ways']) {
            $errors['ways'] = 'Choose at least one way you would like to partner.';
        }
        if (mb_strlen($o['contribution']) < 15) {
            $errors['contribution'] = 'Tell us a little about what you would like to contribute or discuss.';
        }
        if ($o['budget'] !== '' && !in_array($o['budget'], partner_budgets(), true)) {
            $o['budget'] = '';
        }
        if ($o['contact_pref'] !== '' && !in_array($o['contact_pref'], partner_contact_prefs(), true)) {
            $o['contact_pref'] = '';
        }
        if (!$errors && too_many('partners', client_ip(), 60, 8)) {
            $errors['form'] = 'Too many submissions from your network in the last hour. Please try again later.';
        }

        if (!$errors) {
            db()->prepare('INSERT INTO partners (organization, org_type, contact_name, job_title, email, phone, location, website, ways, contribution, budget, contact_pref, referred_by, ip, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$o['organization'], $o['org_type'], $o['contact_name'], $o['job_title'] ?: null, $o['email'], $o['phone'], $o['location'], $o['website'] ?: null,
                    implode(',', $o['ways']), $o['contribution'], $o['budget'] ?: null, $o['contact_pref'] ?: null, $o['referred_by'] ?: null, client_ip(), now(), now()]);
            $id = (int) db()->lastInsertId();
            db()->prepare('UPDATE partners SET reference = ? WHERE id = ?')->execute([partner_reference($id), $id]);
            $pt = find_partner($id);

            [$s, $h] = tpl_partner_received($pt);
            send_mail($pt['email'], $s, $h, setting('contact_email') ?: null);
            [$s2, $h2] = tpl_admin_partner($pt);
            notify_team('registration', $s2, $h2, $pt['email']);

            redirect('partner?done=' . rawurlencode($pt['reference']) . '&t=' . sign('partner-done', $pt['reference']));
        }
    }
}

if (!$done) {
    $_SESSION['partner_rendered_at'] = time();
}
$val = fn(string $k) => e((string) ($old[$k] ?? ''));
$err = fn(string $k) => '<span class="err">' . e($errors[$k] ?? '') . '</span>';
$inv = fn(string $k) => isset($errors[$k]) ? ' invalid' : '';
$sel = fn(string $k, string $v) => ($old[$k] ?? '') === $v ? ' selected' : '';
$image = base_url('assets/img/partner.jpg') . '?v=' . filemtime(__DIR__ . '/assets/img/partner.jpg');
$heroImage = 'assets/img/building-group.jpg?v=' . filemtime(__DIR__ . '/assets/img/building-group.jpg');

app_header('Partner with us — Kakebe Tech Camp 2026', '', '', $done ? [] : [
    'description' => 'Partner with Kakebe Tech Camp 2026 in Kitgum (' . camp()['dates_short'] . ') and help 800 young people from across Uganda learn, build and innovate — through funding, sponsorship, equipment, expertise, internships, prizes or media.',
    'canonical'   => base_url('partner'),
    'image'       => $image,
    'image_size'  => [1200, 630],
    'image_alt'   => 'Young innovators gathered at a Kakebe Tech Camp event',
]);
?>
<?php if ($done): ?>
<section class="app-card narrow center">
  <div class="card-icon ok"><i class="fa-solid fa-handshake"></i></div>
  <h1>Thank you for reaching out 🤝</h1>
  <p>Thank you, <?= first_name($done['contact_name']) ?>, for your interest in partnering with Kakebe Tech Camp 2026. We've emailed a confirmation to <b><?= e($done['email']) ?></b>.</p>
  <div class="ment-ref"><small>Reference</small><b><?= e($done['reference']) ?></b></div>
  <p class="muted">Our team will get in touch soon to talk about working together.</p>
  <a class="btn btn-ghost" href="./">Back to the website</a>
</section>

<?php else: ?>
<section class="mh">
  <div class="mh-media">
    <img src="<?= e($heroImage) ?>" alt="Young innovators gathered at a Kakebe Tech Camp event" width="1600" height="900" fetchpriority="high">
    <span class="mh-badge pt-badge"><i class="fa-solid fa-handshake"></i> Partner with us</span>
  </div>
  <div class="mh-body">
    <h1>Partner with Kakebe Tech Camp</h1>
    <p class="mh-sub">Kakebe Tech Camp 2026 · <?= e(camp()['dates_short']) ?> · Kitgum</p>
    <p>Join the companies, organisations and people helping 800 young people from across Uganda come together to learn, build and innovate. Tell us how you would like to contribute — and our team will get in touch.</p>
    <ul class="mh-facts">
      <li><i class="fa-solid fa-users"></i><span><b>Reach 800 young innovators</b> aged 14 – 30 from across Uganda</span></li>
      <li><i class="fa-solid fa-award"></i><span><b>Recognition for your support</b> at the camp, online and in the media</span></li>
      <li><i class="fa-solid fa-briefcase"></i><span><b>Meet emerging talent</b> for internships, jobs and your own innovation</span></li>
      <li><i class="fa-solid fa-seedling"></i><span><b>Create lasting impact</b> in Northern Uganda and beyond</span></li>
    </ul>
    <a href="#partner-form" class="btn btn-primary">Partner with us <i class="fa-solid fa-arrow-down"></i></a>
  </div>
</section>

<section class="pt-ways" aria-label="Ways to partner">
  <h2>Ways to partner</h2>
  <div>
    <?php foreach ($ways as $name => [$icon, $desc]): if ($name === 'Talk to the team first' || $name === 'Something else') continue; ?>
    <div class="pt-way"><i class="fa-solid <?= e($icon) ?>"></i><b><?= e($name) ?></b><span><?= e($desc) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="app-card ment-form vol-form" id="partner-form">
  <h2>Tell us how you'd like to partner</h2>
  <p class="muted small">It takes about three minutes. Not sure yet? Choose "Talk to the team first" and we'll reach out.</p>
  <?php if (isset($errors['form'])): ?><div class="form-alert"><?= e($errors['form']) ?></div><?php elseif ($errors): ?><div class="form-alert">Please check the highlighted fields.</div><?php endif; ?>
  <form method="post" action="partner#partner-form" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>

    <h3 class="vol-step"><span>1</span> About you</h3>
    <div class="row-2">
      <div class="field<?= $inv('organization') ?>"><label for="p_org">Organisation name</label><input id="p_org" name="organization" value="<?= $val('organization') ?>" maxlength="150" autocomplete="organization" placeholder="Or your name, if partnering as an individual" required><?= $err('organization') ?></div>
      <div class="field<?= $inv('org_type') ?>"><label for="p_type">Type of organisation</label><select id="p_type" name="org_type" required><option value="">Select…</option><?php foreach (partner_types() as $t): ?><option<?= $sel('org_type', $t) ?>><?= e($t) ?></option><?php endforeach; ?></select><?= $err('org_type') ?></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('contact_name') ?>"><label for="p_name">Contact person</label><input id="p_name" name="contact_name" value="<?= $val('contact_name') ?>" maxlength="150" autocomplete="name" required><?= $err('contact_name') ?></div>
      <div class="field"><label for="p_title">Role / title <small class="muted">(optional)</small></label><input id="p_title" name="job_title" value="<?= $val('job_title') ?>" maxlength="120" autocomplete="organization-title" placeholder="e.g. CSR Manager"></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('email') ?>"><label for="p_email">Email address</label><input id="p_email" type="email" name="email" value="<?= $val('email') ?>" maxlength="190" autocomplete="email" required><?= $err('email') ?></div>
      <div class="field<?= $inv('phone') ?>"><label for="p_phone">Phone / WhatsApp</label><input id="p_phone" type="tel" name="phone" value="<?= $val('phone') ?>" maxlength="40" placeholder="e.g. 0772 123 456" autocomplete="tel" required><?= $err('phone') ?></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('location') ?>"><label for="p_loc">Where are you based?</label><input id="p_loc" name="location" value="<?= $val('location') ?>" maxlength="120" placeholder="City and country, e.g. Kampala, Uganda" required><?= $err('location') ?></div>
      <div class="field<?= $inv('website') ?>"><label for="p_web">Website <small class="muted">(optional)</small></label><input id="p_web" type="url" name="website" value="<?= $val('website') ?>" maxlength="255" placeholder="https://"><?= $err('website') ?></div>
    </div>

    <h3 class="vol-step"><span>2</span> Your contribution</h3>
    <div class="field<?= $inv('ways') ?>">
      <label>How would you like to partner? <small class="muted">— choose all that apply</small></label>
      <div class="pt-pick">
        <?php foreach ($ways as $name => [$icon, $desc]): ?>
        <label class="tp"><input type="checkbox" name="ways[]" value="<?= e($name) ?>" <?= in_array($name, $old['ways'] ?? [], true) ? 'checked' : '' ?>><span><i class="fa-solid <?= e($icon) ?>"></i><em class="pt-txt"><b><?= e($name) ?></b><small><?= e($desc) ?></small></em></span></label>
        <?php endforeach; ?>
      </div><?= $err('ways') ?>
    </div>
    <div class="field<?= $inv('contribution') ?>"><label for="p_contrib">What would you like to contribute or discuss?</label><textarea id="p_contrib" name="contribution" rows="5" maxlength="2000" placeholder="e.g. We would like to sponsor 20 young people, provide 10 laptops for the robotics track, and offer internships to the best innovators." required><?= e($old['contribution'] ?? '') ?></textarea><?= $err('contribution') ?></div>
    <div class="row-2">
      <div class="field"><label for="p_budget">Estimated value <small class="muted">(optional)</small></label><select id="p_budget" name="budget"><option value="">Prefer not to say</option><?php foreach (partner_budgets() as $b): ?><option<?= $sel('budget', $b) ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="p_pref">Best way to reach you <small class="muted">(optional)</small></label><select id="p_pref" name="contact_pref"><option value="">Any</option><?php foreach (partner_contact_prefs() as $c): ?><option<?= $sel('contact_pref', $c) ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="p_ref">How did you hear about Kakebe Tech Camp? <small class="muted">(optional)</small></label><input id="p_ref" name="referred_by" value="<?= $val('referred_by') ?>" maxlength="150"></div>

    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-handshake"></i> Send my partnership interest</button>
    <p class="hint center">Prefer to talk now? <a href="<?= e(whatsapp_link('Hello, I would like to partner with Kakebe Tech Camp 2026.')) ?>" target="_blank" rel="noopener">Message us on WhatsApp</a>. We'll only use your details to discuss partnering with you.</p>
  </form>
</section>
<?php endif; ?>
<?php app_footer('');
