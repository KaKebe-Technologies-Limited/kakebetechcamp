<?php
/**
 * First-run setup: create the first administrator account.
 * Only available while no admin accounts exist.
 */
require __DIR__ . '/_init.php';

if (admins_exist()) {
    redirect('login.php');
}

$errors = [];
$name = $email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');

    if (mb_strlen($name) < 2) $errors[] = 'Please enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== $pass2) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        db()->prepare('INSERT INTO admins (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), now()]);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) db()->lastInsertId();
        $_SESSION['admin_seen'] = time();
        if (setting('notify_emails') === '') {
            setting_set('notify_emails', $email);
        }
        flash('Welcome! Your admin account is ready. Next, set up email notifications in Settings.');
        redirect('index.php');
    }
}

auth_header('Setup');
?>
  <h2>Create your admin account</h2>
  <p class="muted">This one-time setup creates the first administrator for the control panel.</p>
  <?php foreach ($errors as $err): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($err) ?></div><?php endforeach; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Full name<input type="text" name="name" value="<?= e($name) ?>" required autofocus></label>
    <label>Email address<input type="email" name="email" value="<?= e($email) ?>" required></label>
    <label>Password <small class="muted">(min. 8 characters)</small><input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
    <label>Confirm password<input type="password" name="password2" minlength="8" required autocomplete="new-password"></label>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-user-shield"></i> Create account</button>
  </form>
<?php auth_footer();
