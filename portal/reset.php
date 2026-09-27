<?php
/**
 * Set a new password using a single-use emailed link, then log the participant in.
 */
require __DIR__ . '/_init.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$r = find_reset($token);
$error = '';

if ($r && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string) ($_POST['password'] ?? '');
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (strlen($pass) < 8) {
        $error = 'Your password must be at least 8 characters.';
    } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
        $error = 'The two passwords do not match.';
    } else {
        db()->prepare('UPDATE registrations SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), now(), $r['id']]);
        db()->prepare('UPDATE password_resets SET used_at = ? WHERE registration_id = ? AND used_at IS NULL')->execute([now(), $r['id']]);
        session_regenerate_id(true);
        $_SESSION['participant_id'] = (int) $r['id'];
        unset($_SESSION['impersonated_by']);
        db()->prepare('UPDATE registrations SET last_login_at = ? WHERE id = ?')->execute([now(), $r['id']]);
        portal_flash('Your password has been saved. Welcome to your dashboard!');
        redirect('./');
    }
}

app_header('Set your password', '../', 'login');
?>
<section class="app-card narrow">
  <div class="card-icon"><i class="fa-solid fa-lock"></i></div>
  <?php if (!$r): ?>
    <h1 class="center">This link has expired</h1>
    <p class="muted center">Password links are valid for a limited time and can only be used once.</p>
    <a class="btn btn-primary btn-block" href="forgot.php">Request a new link</a>
  <?php else: ?>
    <h1 class="center">Set your password</h1>
    <p class="muted center">For <b><?= e($r['email']) ?></b> · <?= e($r['reference']) ?></p>
    <?php if ($error): ?><div class="form-alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field"><label for="password">New password <small class="muted">(at least 8 characters)</small></label><input id="password" name="password" type="password" minlength="8" required autofocus autocomplete="new-password"></div>
      <div class="field"><label for="password2">Confirm new password</label><input id="password2" name="password2" type="password" minlength="8" required autocomplete="new-password"></div>
      <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save password &amp; log in</button>
    </form>
  <?php endif; ?>
</section>
<?php app_footer('../');
