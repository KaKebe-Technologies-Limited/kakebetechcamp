<?php
require __DIR__ . '/_init.php';

if (!admins_exist()) {
    redirect('setup.php');
}

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if (!preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next)) {
    $next = 'index.php';
}
if (current_admin()) {
    redirect($next);
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $ip = client_ip();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass = (string) ($_POST['password'] ?? '');

    if (too_many('login_attempts', $ip, 15, 6)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $stmt = db()->prepare('SELECT * FROM admins WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($pass, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_seen'] = time();
            db()->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')->execute([now(), $admin['id']]);
            db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $admin['id']]);
            }
            redirect($next);
        }
        db()->prepare('INSERT INTO login_attempts (ip, email, created_at) VALUES (?, ?, ?)')->execute([$ip, $email, now()]);
        $error = 'Incorrect email or password.';
    }
}

auth_header('Log in');
?>
  <h2>Welcome back 👋</h2>
  <p class="muted">Log in to the Kakebe Tech Camp control panel.</p>
  <?php if ($error): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Email address<input type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Log in</button>
  </form>
  <p class="muted small center"><a href="../"><i class="fa-solid fa-arrow-left"></i> Back to website</a></p>
<?php auth_footer();
