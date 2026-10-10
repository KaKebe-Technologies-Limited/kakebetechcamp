<?php
/**
 * Volunteer trainers — apply to train campers at Kakebe Tech Camp 2026 (short link: /volunteers).
 * Ages 22–40, at least a Diploma in the field, CV required. Accommodation, travel and allowances provided.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$fields = volunteer_fields();
$errors = [];
$old = [];
$done = null;

/* ---------- Thank-you page after applying ---------- */
if (isset($_GET['done'])) {
    $ref = (string) $_GET['done'];
    if (sign_valid('volunteer-done', $ref, $_GET['t'] ?? null)) {
        $st = db()->prepare('SELECT * FROM volunteers WHERE reference = ?');
        $st->execute([$ref]);
        $done = $st->fetch() ?: null;
    }
}

/* ---------- Apply ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(120);
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please submit the form again.';
    } elseif (setting('volunteers_open', '1') !== '1') {
        $errors['form'] = 'Volunteer applications are closed at the moment.';
    } else {
        // Spam traps: hidden field + a minimum time on the page
        $renderedAt = (int) ($_SESSION['volunteer_rendered_at'] ?? 0);
        if (!empty($_POST['website']) || ($renderedAt && time() - $renderedAt < 4)) {
            redirect('volunteers');
        }
        $clean = static fn(string $key, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$key] ?? ''))), 0, $max);
        $old = [
            'full_name'     => $clean('full_name', 150),
            'email'         => strtolower($clean('email', 190)),
            'phone'         => $clean('phone', 40),
            'gender'        => $clean('gender', 30),
            'dob'           => $clean('dob', 10),
            'nationality'   => $clean('nationality', 80) ?: 'Ugandan',
            'location'      => $clean('location', 120),
            'qualification' => $clean('qualification', 60),
            'course'        => $clean('course', 150),
            'institution'   => $clean('institution', 150),
            'grad_year'     => $clean('grad_year', 4),
            'fields'        => array_values(array_unique(array_intersect((array) ($_POST['fields'] ?? []), array_keys($fields)))),
            'experience'    => $clean('experience', 40),
            'job_role'  => $clean('job_role', 150),
            'portfolio_url' => $clean('portfolio_url', 255),
            'bio'           => mb_substr(trim((string) ($_POST['bio'] ?? '')), 0, 1500),
            'availability'  => $clean('availability', 10),
            'referred_by'   => $clean('referred_by', 150),
            'commit'        => !empty($_POST['commit']),
        ];
        $o = $old;
        if (mb_strlen($o['full_name']) < 3 || !preg_match('/\p{L}/u', $o['full_name'])) {
            $errors['full_name'] = 'Please enter your full name.';
        }
        if ($p = email_problem($o['email'])) {
            $errors['email'] = $p;
        } else {
            $dup = db()->prepare('SELECT COUNT(*) FROM volunteers WHERE email = ?');
            $dup->execute([$o['email']]);
            if ((int) $dup->fetchColumn()) {
                $errors['email'] = 'An application with this email already exists. To update it, message us on WhatsApp.';
            }
        }
        $digits = preg_replace('/\D/', '', $o['phone']);
        if (strlen($digits) < 9 || strlen($digits) > 15 || preg_match('/[^\d\s+\-()]/', $o['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number.';
        }
        if (!in_array($o['gender'], genders(), true)) {
            $errors['gender'] = 'Please choose an option.';
        }
        $dobOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $o['dob']) && checkdate((int) substr($o['dob'], 5, 2), (int) substr($o['dob'], 8, 2), (int) substr($o['dob'], 0, 4));
        if (!$dobOk) {
            $errors['dob'] = 'Please enter your date of birth.';
        } elseif (($age = volunteer_age($o['dob'])) < 22 || $age > 40) {
            $errors['dob'] = 'Volunteer trainers must be between 22 and 40 years old.';
        }
        if (mb_strlen($o['location']) < 2) {
            $errors['location'] = 'Tell us where you are based.';
        }
        if (!in_array($o['qualification'], volunteer_qualifications(), true)) {
            $errors['qualification'] = 'A Diploma or higher is required.';
        }
        if (mb_strlen($o['course']) < 3) {
            $errors['course'] = 'Enter the course or field you studied.';
        }
        if (mb_strlen($o['institution']) < 3) {
            $errors['institution'] = 'Enter your university or institution.';
        }
        $year = (int) $o['grad_year'];
        if ($year < 1975 || $year > (int) date('Y')) {
            $errors['grad_year'] = 'Enter the year you graduated.';
        }
        if (!$o['fields']) {
            $errors['fields'] = 'Choose at least one field you can train.';
        } elseif (count($o['fields']) > 3) {
            $errors['fields'] = 'Choose up to three fields.';
        }
        if (!in_array($o['experience'], volunteer_experience_options(), true)) {
            $errors['experience'] = 'Please choose your experience.';
        }
        if ($o['portfolio_url'] !== '') {
            if (!preg_match('~^https?://~i', $o['portfolio_url'])) {
                $o['portfolio_url'] = $old['portfolio_url'] = 'https://' . $o['portfolio_url'];
            }
            if (!filter_var($o['portfolio_url'], FILTER_VALIDATE_URL)) {
                $errors['portfolio_url'] = 'Enter a valid link, or leave it empty.';
            }
        }
        if (mb_strlen($o['bio']) < 40) {
            $errors['bio'] = 'Tell us a little more (at least a couple of sentences).';
        }
        if (!isset(volunteer_availability_options()[$o['availability']])) {
            $errors['availability'] = 'Please choose your availability.';
        }
        if (!$o['commit']) {
            $errors['commit'] = 'Please confirm the requirements to apply.';
        }
        [$cvExt, $cvErr] = check_cv_upload('cv');
        if ($cvErr) {
            $errors['cv'] = $cvErr;
        }
        if (!$errors && too_many('volunteers', client_ip(), 60, 10)) {
            $errors['form'] = 'Too many applications from your network in the last hour. Please try again later.';
        }

        if (!$errors) {
            $dir = volunteer_cv_dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $cvFile = store_upload('cv', $cvExt, $dir);
            if (!$cvFile) {
                $errors['cv'] = 'Your CV could not be saved. Please try again.';
            } else {
                db()->prepare('INSERT INTO volunteers (full_name, email, phone, gender, dob, nationality, location, qualification, course, institution, grad_year, fields, experience, job_role, portfolio_url, bio, availability, referred_by, cv_file, cv_size, ip, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$o['full_name'], $o['email'], $o['phone'], $o['gender'], $o['dob'], $o['nationality'], $o['location'], $o['qualification'], $o['course'], $o['institution'], $year,
                        implode(',', $o['fields']), $o['experience'], $o['job_role'] ?: null, $o['portfolio_url'] ?: null, $o['bio'], $o['availability'], $o['referred_by'] ?: null,
                        $cvFile, (int) $_FILES['cv']['size'], client_ip(), now(), now()]);
                $id = (int) db()->lastInsertId();
                db()->prepare('UPDATE volunteers SET reference = ? WHERE id = ?')->execute([volunteer_reference($id), $id]);
                $v = find_volunteer($id);

                [$s, $h] = tpl_volunteer_received($v);
                send_mail($v['email'], $s, $h, setting('contact_email') ?: null);
                [$s2, $h2] = tpl_admin_volunteer($v);
                notify_team('registration', $s2, $h2, $v['email'], [['name' => volunteer_cv_name($v), 'type' => volunteer_cv_mime($v['cv_file']), 'data' => (string) file_get_contents(volunteer_cv_path($v))]]);

                redirect('volunteers?done=' . rawurlencode($v['reference']) . '&t=' . sign('volunteer-done', $v['reference']));
            }
        }
    }
}

if (!$done) {
    $_SESSION['volunteer_rendered_at'] = time();
}
$val = fn(string $k) => e((string) ($old[$k] ?? ''));
$err = fn(string $k) => '<span class="err">' . e($errors[$k] ?? '') . '</span>';
$inv = fn(string $k) => isset($errors[$k]) ? ' invalid' : '';
$sel = fn(string $k, string $v) => ($old[$k] ?? '') === $v ? ' selected' : '';
$image = base_url('assets/img/volunteers.jpg') . '?v=' . filemtime(__DIR__ . '/assets/img/volunteers.jpg');
$open = setting('volunteers_open', '1') === '1';

app_header('Volunteer as a trainer — Kakebe Tech Camp 2026', '', '', $done ? [] : [
    'description' => 'Share your expertise with young innovators at Kakebe Tech Camp 2026 in Kitgum (' . camp()['dates_short'] . '). Volunteer trainers aged 22–40 with a Diploma or degree. Accommodation, travel and allowances provided.',
    'canonical'   => base_url('volunteers'),
    'image'       => $image,
    'image_size'  => [1200, 630],
    'image_alt'   => 'Kakebe Tech Camp trainers',
]);
?>
<?php if ($done): ?>
<section class="app-card narrow center">
  <div class="card-icon ok"><i class="fa-solid fa-check"></i></div>
  <h1>Application received 🙌</h1>
  <p>Thank you, <?= first_name($done['full_name']) ?>, for offering your skills to Kakebe Tech Camp 2026. We've emailed a confirmation to <b><?= e($done['email']) ?></b>.</p>
  <div class="ment-ref"><small>Application number</small><b><?= e($done['reference']) ?></b></div>
  <p class="muted">Our team will review applications and contact shortlisted volunteers by email or phone.</p>
  <a class="btn btn-ghost" href="./">Back to the website</a>
</section>

<?php else: ?>
<section class="mh">
  <div class="mh-media">
    <img src="<?= e($image) ?>" alt="Kakebe Tech Camp trainers" width="1200" height="630" fetchpriority="high">
    <span class="mh-badge"><i class="fa-solid fa-hand-holding-heart"></i> Volunteer</span>
  </div>
  <div class="mh-body">
    <span class="ment-kicker"><i class="fa-solid fa-person-chalkboard"></i> Trainers wanted</span>
    <h1>Volunteer as a trainer</h1>
    <p class="mh-sub">Kakebe Tech Camp 2026 · <?= e(camp()['dates_short']) ?> · Kitgum</p>
    <p>Share your expertise with young innovators from across Uganda. We are looking for skilled professionals to lead hands-on training sessions during the camp.</p>
    <ul class="mh-facts">
      <li><i class="fa-solid fa-hotel"></i><span><b>Accommodation, travel and allowances</b> are provided</span></li>
      <li><i class="fa-solid fa-heart"></i><span>Above all, it's a chance to <b>offer your skills</b> to the next generation</span></li>
      <li><i class="fa-solid fa-user-check"></i><span>Aged <b>22 – 40</b>, ready to train during the camp</span></li>
      <li><i class="fa-solid fa-graduation-cap"></i><span>At least a <b>Diploma or degree</b> in your field of expertise</span></li>
    </ul>
    <a href="#apply" class="btn btn-primary">Apply to volunteer <i class="fa-solid fa-arrow-down"></i></a>
  </div>
</section>

<section class="vol-fields" aria-label="Training fields">
  <p>Training fields</p>
  <div>
    <?php foreach ($fields as $name => $icon): ?><span><i class="fa-solid <?= e($icon) ?>"></i> <?= e($name) ?></span><?php endforeach; ?>
  </div>
</section>

<section class="app-card ment-form vol-form" id="apply">
  <h2>Apply to volunteer</h2>
  <?php if (!$open): ?>
    <div class="notice warn"><i class="fa-solid fa-circle-info"></i><span>Volunteer applications are closed at the moment. Message us on <a href="<?= e(whatsapp_link('Hello, I would like to volunteer as a trainer at Kakebe Tech Camp 2026.')) ?>" target="_blank" rel="noopener">WhatsApp</a> to be told when they open.</span></div>
  <?php else: ?>
  <p class="muted small">It takes about five minutes. Have your CV ready (PDF or Word, up to 5 MB).</p>
  <?php if (isset($errors['form'])): ?><div class="form-alert"><?= e($errors['form']) ?></div><?php elseif ($errors): ?><div class="form-alert">Please check the highlighted fields<?= !isset($errors['cv']) ? ' — and attach your CV again' : '' ?>.</div><?php endif; ?>
  <form method="post" action="volunteers#apply" enctype="multipart/form-data" id="volForm" novalidate>
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <h3 class="vol-step"><span>1</span> Personal details</h3>
    <div class="row-2">
      <div class="field<?= $inv('full_name') ?>"><label for="v_name">Full name</label><input id="v_name" name="full_name" value="<?= $val('full_name') ?>" autocomplete="name" required><?= $err('full_name') ?></div>
      <div class="field<?= $inv('email') ?>"><label for="v_email">Email address</label><input id="v_email" type="email" name="email" value="<?= $val('email') ?>" autocomplete="email" required><?= $err('email') ?></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('phone') ?>"><label for="v_phone">Phone / WhatsApp</label><input id="v_phone" type="tel" name="phone" value="<?= $val('phone') ?>" placeholder="e.g. 0772 123 456" autocomplete="tel" required><?= $err('phone') ?></div>
      <div class="field<?= $inv('gender') ?>"><label for="v_gender">Gender</label><select id="v_gender" name="gender" required><option value="">Select…</option><?php foreach (genders() as $g): ?><option<?= $sel('gender', $g) ?>><?= e($g) ?></option><?php endforeach; ?></select><?= $err('gender') ?></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('dob') ?>"><label for="v_dob">Date of birth <small class="muted">(ages 22 – 40)</small></label><input id="v_dob" type="date" name="dob" value="<?= $val('dob') ?>" min="<?= date('Y-m-d', strtotime('-41 years')) ?>" max="<?= date('Y-m-d', strtotime('-22 years')) ?>" required><?= $err('dob') ?></div>
      <div class="field"><label for="v_nat">Nationality</label><input id="v_nat" name="nationality" value="<?= $val('nationality') ?: 'Ugandan' ?>" maxlength="80"></div>
    </div>
    <div class="field<?= $inv('location') ?>"><label for="v_loc">Where are you based?</label><input id="v_loc" name="location" value="<?= $val('location') ?>" placeholder="City or district, e.g. Gulu" required><?= $err('location') ?></div>

    <h3 class="vol-step"><span>2</span> Academic background</h3>
    <div class="row-2">
      <div class="field<?= $inv('qualification') ?>"><label for="v_qual">Highest qualification</label><select id="v_qual" name="qualification" required><option value="">Select…</option><?php foreach (volunteer_qualifications() as $q): ?><option<?= $sel('qualification', $q) ?>><?= e($q) ?></option><?php endforeach; ?></select><span class="hint">A Diploma is the minimum.</span><?= $err('qualification') ?></div>
      <div class="field<?= $inv('course') ?>"><label for="v_course">Course / field of study</label><input id="v_course" name="course" value="<?= $val('course') ?>" placeholder="e.g. BSc Computer Science" required><?= $err('course') ?></div>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('institution') ?>"><label for="v_inst">University / institution</label><input id="v_inst" name="institution" value="<?= $val('institution') ?>" placeholder="e.g. Gulu University" required><?= $err('institution') ?></div>
      <div class="field<?= $inv('grad_year') ?>"><label for="v_year">Year of graduation</label><input id="v_year" type="number" name="grad_year" value="<?= $val('grad_year') ?>" min="1975" max="<?= date('Y') ?>" placeholder="e.g. 2019" inputmode="numeric" required><?= $err('grad_year') ?></div>
    </div>

    <h3 class="vol-step"><span>3</span> Your expertise</h3>
    <div class="field<?= $inv('fields') ?>">
      <label>Fields you can train <small class="muted">— choose up to three</small></label>
      <div class="tracks-pick js-max3">
        <?php foreach ($fields as $name => $icon): ?>
        <label class="tp"><input type="checkbox" name="fields[]" value="<?= e($name) ?>" <?= in_array($name, $old['fields'] ?? [], true) ? 'checked' : '' ?>><span><i class="fa-solid <?= e($icon) ?>"></i><?= e($name) ?></span></label>
        <?php endforeach; ?>
      </div><?= $err('fields') ?>
    </div>
    <div class="row-2">
      <div class="field<?= $inv('experience') ?>"><label for="v_exp">Professional experience</label><select id="v_exp" name="experience" required><option value="">Select…</option><?php foreach (volunteer_experience_options() as $x): ?><option<?= $sel('experience', $x) ?>><?= e($x) ?></option><?php endforeach; ?></select><?= $err('experience') ?></div>
      <div class="field"><label for="v_role">Current role &amp; organisation <small class="muted">(optional)</small></label><input id="v_role" name="job_role" value="<?= $val('job_role') ?>" maxlength="150" placeholder="e.g. Software Engineer, Kakebe Technologies"></div>
    </div>
    <div class="field<?= $inv('portfolio_url') ?>"><label for="v_link">LinkedIn or portfolio link <small class="muted">(optional)</small></label><input id="v_link" type="url" name="portfolio_url" value="<?= $val('portfolio_url') ?>" maxlength="255" placeholder="https://linkedin.com/in/your-name"><?= $err('portfolio_url') ?></div>
    <div class="field<?= $inv('bio') ?>"><label for="v_bio">About you and what you would teach</label><textarea id="v_bio" name="bio" rows="5" maxlength="1500" placeholder="Your experience, the skills you would train campers in, and why you want to volunteer." required><?= e($old['bio'] ?? '') ?></textarea><?= $err('bio') ?></div>

    <h3 class="vol-step"><span>4</span> Availability &amp; CV</h3>
    <div class="row-2">
      <div class="field<?= $inv('availability') ?>"><label for="v_avail">When can you train?</label><select id="v_avail" name="availability" required><option value="">Select…</option><?php foreach (volunteer_availability_options() as $k => $label): ?><option value="<?= e($k) ?>"<?= $sel('availability', $k) ?>><?= e($label) ?></option><?php endforeach; ?></select><?= $err('availability') ?></div>
      <div class="field"><label for="v_ref">Who recommended you? <small class="muted">(optional)</small></label><input id="v_ref" name="referred_by" value="<?= $val('referred_by') ?>" maxlength="150"></div>
    </div>
    <div class="field<?= $inv('cv') ?>">
      <label for="v_cv">Your CV</label>
      <label class="cv-drop" for="v_cv"><i class="fa-solid fa-file-arrow-up"></i><span class="js-cv-name">Choose your CV — PDF or Word, up to 5 MB</span></label>
      <input id="v_cv" type="file" name="cv" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
      <?= $err('cv') ?>
    </div>
    <div class="field<?= $inv('commit') ?>">
      <label class="commit"><input type="checkbox" name="commit" value="1" <?= !empty($old['commit']) ? 'checked' : '' ?>><span>I am <b>between 22 and 40 years old</b>, I hold <b>at least a Diploma</b> in my field of expertise, and I am <b>ready to offer my skills</b> during the camp trainings. The details I've given are true.</span></label>
      <?= $err('commit') ?>
    </div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-paper-plane"></i> Submit my application</button>
    <p class="hint center">We'll only use your details to review your application and contact you about volunteering.</p>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php app_footer('', $done ? [] : ['assets/js/volunteers.js']);
