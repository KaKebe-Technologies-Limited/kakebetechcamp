<?php
require __DIR__ . '/_init.php';
$r = require_participant();
$errors = [];

$pwError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $current = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $pwError = 'Your session expired. Please try again.';
    } elseif ($r['password_hash'] && !password_verify($current, $r['password_hash'])) {
        $pwError = 'Your current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $pwError = 'Your new password must be at least 8 characters.';
    } elseif ($new !== (string) ($_POST['new2'] ?? '')) {
        $pwError = 'The two new passwords do not match.';
    } else {
        db()->prepare('UPDATE registrations SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), now(), $r['id']]);
        session_regenerate_id(true);
        portal_flash($r['password_hash'] ? 'Your password has been changed.' : 'Your password has been created. You can now log in from any device.');
        redirect('./');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $clean = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
        $name = $clean('full_name', 150);
        $phone = $clean('phone', 40);
        $district = $clean('district', 100);
        $country = $clean('country', 100);
        $gender = in_array($_POST['gender'] ?? '', genders(), true) ? $_POST['gender'] : null;
        $jersey = in_array($_POST['jersey_size'] ?? '', jersey_sizes(), true) ? $_POST['jersey_size'] : null;
        $interests = array_values(array_unique(array_intersect((array) ($_POST['interests'] ?? []), interests())));
        $motivation = mb_substr(trim((string) ($_POST['motivation'] ?? '')), 0, 1000);
        $park = !empty($_POST['park_visit']);
        $mentorship = !empty($_POST['mentorship']);

        if (mb_strlen($name) < 3) $errors['full_name'] = 'Please enter your full name.';
        if (strlen(preg_replace('/\D/', '', $phone)) < 9) $errors['phone'] = 'Please enter a valid phone number.';
        if (mb_strlen($district) < 2) $errors['district'] = 'Please enter your district.';
        if (mb_strlen($country) < 2) $errors['country'] = 'Please enter your country.';
        if (!$jersey) $errors['jersey_size'] = 'Choose your jersey size.';
        if (!$interests || count($interests) > 2) $errors['interests'] = 'Choose one or two learning tracks.';

        $parkAmount = $park ? ((int) $r['park_visit'] ? (int) $r['park_amount'] : fees()['park']) : 0;
        $newTotal = (int) $r['camp_amount'] + (int) $r['jersey_amount'] + $parkAmount;
        if ($newTotal < (int) $r['amount_paid']) {
            $errors['park_visit'] = 'You have already paid for the park visit, so it cannot be removed. Contact us if you need a change.';
        }

        [$ext, $photoErr] = check_image_upload('photo');
        if ($photoErr) $errors['photo'] = $photoErr;

        if (!$errors) {
            $photo = $r['photo'];
            if ($ext && ($new = store_upload('photo', $ext, STORAGE . '/uploads/photos'))) {
                if ($old = photo_path($r['photo'])) {
                    @unlink($old);
                }
                $photo = $new;
            }
            db()->prepare('UPDATE registrations SET full_name = ?, phone = ?, district = ?, country = ?, gender = ?, jersey_size = ?, interests = ?, motivation = ?,
                park_visit = ?, park_amount = ?, total_amount = ?, mentorship = ?, photo = ?, updated_at = ? WHERE id = ?')
                ->execute([$name, $phone, $district, $country, $gender, $jersey, implode(', ', $interests), $motivation ?: null,
                    (int) $park, $parkAmount, $newTotal, (int) $mentorship, $photo, now(), $r['id']]);
            recompute_registration((int) $r['id']);
            portal_flash('Your profile has been updated.');
            redirect('./');
        }
    }
    $r = array_merge($r, array_intersect_key($_POST, array_flip(['full_name', 'phone', 'district', 'country', 'gender', 'jersey_size', 'motivation'])));
    $r['interests'] = implode(', ', (array) ($_POST['interests'] ?? []));
    $r['park_visit'] = !empty($_POST['park_visit']);
    $r['mentorship'] = !empty($_POST['mentorship']);
}

$chosen = array_map('trim', explode(',', (string) $r['interests']));
$photo = participant_photo_url(current_participant());
$err = fn($k) => isset($errors[$k]) ? '<span class="err">' . e($errors[$k]) . '</span>' : '';
$inv = fn($k) => isset($errors[$k]) ? ' invalid' : '';

app_header('Edit profile', '../', 'portal');
?>
<section class="app-card" style="max-width:860px;margin:0 auto 22px;">
  <a href="./" class="small"><i class="fa-solid fa-arrow-left"></i> Back to my portal</a>
  <h1 style="margin-top:12px;">Edit your profile</h1>
  <p class="muted">Keep your details up to date — your photo appears on your camp ticket and card.</p>
  <?php if ($errors): ?><div class="form-alert"><?= e($errors['form'] ?? 'Please correct the highlighted fields.') ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="photo-edit">
      <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="Your photo"><?php else: ?><span class="ph-avatar"><?= e(initials($r['full_name'])) ?></span><?php endif; ?>
      <div class="field<?= $inv('photo') ?>" style="margin:0;flex:1;">
        <label for="photo">Your photo for the camp ticket</label>
        <div class="photo-note">
          <b><i class="fa-solid fa-id-badge"></i> Choose a good, clear photo</b>
          <p>This photo is printed on your camp ticket and card. Staff use it to recognise you and let you into the camp, so make sure it clearly shows your face.</p>
        <ul class="photo-tips">
          <li><i class="fa-solid fa-check"></i> A recent photo of <b>your face only</b> — no group photos</li>
          <li><i class="fa-solid fa-check"></i> Face the camera in <b>good light</b>, with a plain background</li>
          <li><i class="fa-solid fa-check"></i> No sunglasses, caps, filters or stickers</li>
        </ul>
        </div>
        <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
        <span class="hint">JPG, PNG or WEBP · max 3 MB</span><?= $err('photo') ?>
      </div>
    </div>

    <div class="row-2">
      <div class="field<?= $inv('full_name') ?>"><label>Full name</label><input name="full_name" value="<?= e($r['full_name']) ?>" required><?= $err('full_name') ?></div>
      <div class="field"><label>Email <small class="muted">(contact support to change)</small></label><input value="<?= e($r['email']) ?>" readonly></div>
      <div class="field<?= $inv('phone') ?>"><label>Phone</label><input name="phone" type="tel" value="<?= e($r['phone']) ?>" required><?= $err('phone') ?></div>
      <div class="field"><label>Gender</label><select name="gender"><option value="">Select…</option><?php foreach (genders() as $g): ?><option <?= ($r['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></div>
      <div class="field<?= $inv('district') ?>"><label>District of origin</label><input name="district" value="<?= e($r['district']) ?>" required><?= $err('district') ?></div>
      <div class="field<?= $inv('country') ?>"><label>Country</label><input name="country" value="<?= e($r['country']) ?>" required><?= $err('country') ?></div>
      <div class="field<?= $inv('jersey_size') ?>"><label>Jersey size</label><select name="jersey_size" required><option value="">Select…</option><?php foreach (jersey_sizes() as $s): ?><option <?= ($r['jersey_size'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select><?= $err('jersey_size') ?></div>
    </div>

    <div class="field<?= $inv('interests') ?>">
      <label>Learning tracks <small class="muted">(choose up to 2)</small></label>
      <div class="tracks-pick js-max2">
        <?php foreach (interests() as $t): ?>
        <label class="tp"><input type="checkbox" name="interests[]" value="<?= e($t) ?>" <?= in_array($t, $chosen, true) ? 'checked' : '' ?>><span><i class="fa-solid fa-circle-check"></i><?= e($t) ?></span></label>
        <?php endforeach; ?>
      </div><?= $err('interests') ?>
    </div>

    <label class="switch-row<?= $inv('park_visit') ?>">
      <span><b><?= e(fees()['park_name']) ?> park visit (+<?= e(format_ugx($r['park_visit'] && (int) $r['park_amount'] ? $r['park_amount'] : fees()['park'])) ?>)</b><small>Optional excursion during camp</small><?= $err('park_visit') ?></span>
      <input type="checkbox" name="park_visit" value="1" <?= $r['park_visit'] ? 'checked' : '' ?>>
    </label>
    <label class="switch-row">
      <span><b>Free Mentorship &amp; Digital Bridge Internship</b><small><?= e(camp()['mentorship']) ?> · online every Monday 8:00 – 9:30 PM</small></span>
      <input type="checkbox" name="mentorship" value="1" <?= $r['mentorship'] ? 'checked' : '' ?>>
    </label>

    <div class="field"><label>Why do you want to join?</label><textarea name="motivation" rows="3" maxlength="1000"><?= e($r['motivation']) ?></textarea></div>
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
  </form>
</section>

<section class="app-card" id="password" style="max-width:860px;margin:0 auto 22px;">
  <h3><?= $r['password_hash'] ? 'Change password' : 'Create a password' ?></h3>
  <p class="muted"><?= $r['password_hash'] ? 'Choose a new password for your dashboard.' : ($r['google_sub'] ? 'You log in with Google. You can also create a password to log in with your email address.' : 'Create a password so you can log in to your dashboard from any device.') ?></p>
  <?php if ($pwError): ?><div class="form-alert"><?= e($pwError) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="row-2">
      <?php if ($r['password_hash']): ?><div class="field"><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div><?php endif; ?>
      <div class="field"><label>New password <small class="muted">(8+ characters)</small></label><input type="password" name="new" minlength="8" required autocomplete="new-password"></div>
      <div class="field"><label>Confirm new password</label><input type="password" name="new2" minlength="8" required autocomplete="new-password"></div>
    </div>
    <div><button class="btn btn-navy" type="submit"><i class="fa-solid fa-key"></i> <?= $r['password_hash'] ? 'Update password' : 'Create password' ?></button></div>
  </form>
</section>
<script>
  document.querySelectorAll('.js-max2').forEach((box) => {
    const inputs = [...box.querySelectorAll('input')];
    const sync = () => { const n = inputs.filter((i) => i.checked).length; inputs.forEach((i) => { i.disabled = !i.checked && n >= 2; }); };
    inputs.forEach((i) => i.addEventListener('change', sync)); sync();
  });
</script>
<?php app_footer('../');
