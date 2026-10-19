<?php
/**
 * Staff sign-up: anyone on the team creates their own account. It has no access ("pending") until the
 * team lead gives it a role in Admin users (e.g. Team member or Field staff to make requisitions).
 */
require __DIR__ . '/_init.php';

if (!admins_exist()) {
    redirect('setup.php');
}
if (current_admin()) {
    redirect('index.php');
}

$errors = [];
$old = ['name' => '', 'email' => '', 'phone' => '', 'job_title' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $ip = client_ip();
    $old = [
        'name'      => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? ''))), 0, 120),
        'email'     => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone'     => mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40),
        'job_title' => mb_substr(trim((string) ($_POST['job_title'] ?? '')), 0, 120),
    ];
    $pass = (string) ($_POST['password'] ?? '');
    if (!empty($_POST['website'])) {
        redirect('login.php');
    }
    if (too_many('login_attempts', $ip, 60, 12)) {
        $errors[] = 'Too many attempts from your network. Please try again later.';
    }
    if (mb_strlen($old['name']) < 3 || !preg_match('/\p{L}/u', $old['name'])) {
        $errors[] = 'Enter your full name.';
    }
    if ($p = email_problem($old['email'])) {
        $errors[] = $p;
    } elseif (q('SELECT id FROM admins WHERE email = ?', [$old['email']])->fetchColumn()) {
        $errors[] = 'An account with this email already exists — log in instead.';
    }
    $digits = preg_replace('/\D/', '', $old['phone']);
    if (strlen($digits) < 9 || strlen($digits) > 15) {
        $errors[] = 'Enter a valid phone number (used for payments by Mobile Money).';
    }
    if (strlen($pass) < 8 || $pass !== ($_POST['password2'] ?? '')) {
        $errors[] = 'Passwords must match and be at least 8 characters.';
    }
    if (!$errors) {
        q("INSERT INTO admins (name, email, password_hash, role, phone, job_title, created_at) VALUES (?, ?, ?, 'pending', ?, ?, ?)",
            [$old['name'], $old['email'], password_hash($pass, PASSWORD_DEFAULT), $old['phone'], $old['job_title'] ?: null, now()]);
        $newId = (int) db()->lastInsertId();
        db()->prepare('INSERT INTO login_attempts (ip, email, created_at) VALUES (?, ?, ?)')->execute([$ip, 'signup:' . $old['email'], now()]);   // counts towards the limit above
        $inner = '<p><strong>Hi,</strong></p><p>' . e($old['name']) . ' has created a staff account and is waiting for access.</p>'
            . kt_detail(['👤 Name' => $old['name'], '📧 Email' => $old['email'], '📞 Phone' => $old['phone'], '💼 Role / title' => $old['job_title']])
            . '<p>If they are on the team, give them a role — <b>Team member</b> or <b>Field staff</b> to make requisitions.</p>'
            . kt_btn('Give access', base_url('admin/users.php'), 'navy');
        fin_notify_roles(['admin'], '👤 New staff account waiting for access — ' . $old['name'], kt_email('Staff accounts', $inner));
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $newId;
        $_SESSION['admin_seen'] = time();
        redirect('index.php');   // shows "waiting for access" until a role is given
    }
}

auth_header('Create a staff account');
?>
  <h2>Create your staff account</h2>
  <p class="muted">For Kakebe team members — to make requisitions and account for money spent. The team lead gives your account access after you sign up.</p>
  <?php if ($errors): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>
  <form method="post" class="stack" autocomplete="on">
    <?= csrf_field() ?>
    <div class="hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <label>Full name<input type="text" name="name" value="<?= e($old['name']) ?>" maxlength="120" required autocomplete="name"></label>
    <label>Email address<input type="email" name="email" value="<?= e($old['email']) ?>" required autocomplete="email"></label>
    <label>Phone (Mobile Money number)<input type="tel" name="phone" value="<?= e($old['phone']) ?>" maxlength="40" required autocomplete="tel" placeholder="e.g. 0772 123 456"></label>
    <label>Your role on the team <small class="muted">(optional)</small><input type="text" name="job_title" value="<?= e($old['job_title']) ?>" maxlength="120" placeholder="e.g. Field officer, Kitgum"></label>
    <div class="row-2">
      <label>Password<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
      <label>Confirm password<input type="password" name="password2" minlength="8" required autocomplete="new-password"></label>
    </div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-user-plus"></i> Create account</button>
  </form>
  <p class="small center">Already have an account? <a href="login.php">Log in</a></p>
<?php auth_footer();
