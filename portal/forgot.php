<?php
/**
 * Request a password reset (or first-time password setup) link by email.
 */
require __DIR__ . '/_init.php';

$sent = false;
$error = '';
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (too_many('login_attempts', client_ip(), 15, 10)) {
        $error = 'Too many requests. Please wait 15 minutes and try again.';
    } else {
        db()->prepare('INSERT INTO login_attempts (ip, email, created_at) VALUES (?, ?, ?)')->execute([client_ip(), $email, now()]);
        $stmt = db()->prepare("SELECT * FROM registrations WHERE email = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email]);
        if ($r = $stmt->fetch()) {
            [$s, $h] = tpl_password_reset($r, password_reset_link($r));
            send_mail($r['email'], $s, $h);
        }
        $sent = true; // Same message either way, so nobody can test which emails are registered.
    }
}

app_header('Reset password', '../', 'login');
?>
<section class="app-card narrow">
  <div class="card-icon"><i class="fa-solid fa-key"></i></div>
  <h1 class="center">Forgot or create password</h1>
  <?php if ($sent): ?>
    <div class="notice ok"><i class="fa-solid fa-paper-plane"></i> If <?= e($email) ?> is registered, we have emailed a link to set your password. It expires in 60 minutes — check your inbox and spam folder.</div>
    <p class="center"><a href="login.php">Back to login</a></p>
  <?php else: ?>
    <p class="muted center">Enter the email you registered with and we'll send you a link to set a new password.</p>
    <?php if ($error): ?><div class="form-alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="<?= e($email) ?>" required autofocus autocomplete="email" placeholder="you@example.com"></div>
      <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-paper-plane"></i> Email me a reset link</button>
    </form>
    <p class="center small"><a href="login.php">Back to login</a></p>
  <?php endif; ?>
</section>
<?php app_footer('../');
