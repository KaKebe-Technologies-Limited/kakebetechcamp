<?php
/**
 * Participant login — email + password, with a one-time email code as a fallback.
 */
require __DIR__ . '/_init.php';

if (current_participant()) {
    redirect('./');
}

$mode = ($_GET['mode'] ?? $_POST['mode'] ?? 'password') === 'code' ? 'code' : 'password';
$step = 'email';
$email = strtolower(trim((string) ($_POST['email'] ?? $_SESSION['login_email'] ?? '')));
$error = '';
$info = '';
$ip = client_ip();

$login = function (int $id): never {
    db()->prepare('UPDATE registrations SET last_login_at = ? WHERE id = ?')->execute([now(), $id]);
    session_regenerate_id(true);
    $_SESSION['participant_id'] = $id;
    unset($_SESSION['login_email'], $_SESSION['impersonated_by']);
    redirect('./');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'password') {
        $pass = (string) ($_POST['password'] ?? '');
        if (too_many('login_attempts', $ip, 15, 8)) {
            $error = 'Too many failed attempts. Please wait 15 minutes or reset your password.';
        } else {
            $stmt = db()->prepare("SELECT * FROM registrations WHERE email = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$email]);
            $r = $stmt->fetch();
            if ($r && $r['password_hash'] && password_verify($pass, $r['password_hash'])) {
                db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                $login((int) $r['id']);
            }
            db()->prepare('INSERT INTO login_attempts (ip, email, created_at) VALUES (?, ?, ?)')->execute([$ip, $email, now()]);
            $error = ($r && !$r['password_hash'])
                ? 'You have not created a password yet. Use "Forgot or create password" below and we will email you a link.'
                : 'Incorrect email or password.';
        }
    } elseif ($action === 'send') {
        $mode = 'code';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (too_many('login_codes', $ip, 15, 6)) {
            $error = 'Too many code requests. Please wait 15 minutes.';
        } else {
            $stmt = db()->prepare("SELECT * FROM registrations WHERE email = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$email]);
            $r = $stmt->fetch();
            $code = (string) random_int(100000, 999999);
            db()->prepare('INSERT INTO login_codes (registration_id, code_hash, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$r['id'] ?? 0, $r ? password_hash($code, PASSWORD_DEFAULT) : '-', date('Y-m-d H:i:s', time() + 600), $ip, now()]);
            if ($r) {
                [$s, $h] = tpl_login_code($r, $code);
                send_mail($r['email'], $s, $h);
            }
            $_SESSION['login_email'] = $email;
            $step = 'code';
            $info = 'If ' . $email . ' is registered, we have sent a 6-digit code to it. It expires in 10 minutes.';
        }
    } elseif ($action === 'verify') {
        $mode = 'code';
        $step = 'code';
        $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
        $stmt = db()->prepare("SELECT c.*, r.id AS rid FROM login_codes c JOIN registrations r ON r.id = c.registration_id
            WHERE r.email = ? AND r.status <> 'cancelled' AND c.used_at IS NULL AND c.expires_at > ? ORDER BY c.id DESC LIMIT 1");
        $stmt->execute([$email, now()]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['attempts'] >= 5) {
            $error = 'That code has expired. Please request a new one.';
            $step = 'email';
        } elseif (!password_verify($code, $row['code_hash'])) {
            db()->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
            $error = 'Incorrect code. Please check your email and try again.';
        } else {
            db()->prepare('UPDATE login_codes SET used_at = ? WHERE id = ?')->execute([now(), $row['id']]);
            $login((int) $row['rid']);
        }
    }
} elseif (isset($_GET['change'])) {
    unset($_SESSION['login_email']);
    $email = '';
}

app_header('Participant login', '../', 'login');
?>
<section class="app-card narrow">
  <div class="card-icon"><i class="fa-solid fa-user-lock"></i></div>
  <h1 class="center">Participant dashboard</h1>
  <p class="muted center">Log in to see your profile, learning tracks, payments and ticket.</p>
  <?php if (isset($_GET['reset'])): ?><div class="notice ok"><i class="fa-solid fa-circle-check"></i> Your password has been updated. Please log in.</div><?php endif; ?>
  <?php if ($error): ?><div class="form-alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="notice ok"><i class="fa-solid fa-paper-plane"></i> <?= e($info) ?></div><?php endif; ?>

  <?php if ($mode === 'password'): ?>
  <?php if (google_enabled()): ?>
  <?= google_button('login', '../api/google-auth.php', 'signin_with') ?>
  <div class="or-sep"><span>or log in with your email</span></div>
  <?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" value="<?= e($email) ?>" required autofocus autocomplete="username" placeholder="you@example.com"></div>
    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Log in</button>
  </form>
  <p class="center small"><a href="forgot.php">Forgot or create password</a> · <a href="login.php?mode=code">Email me a login code instead</a></p>
  <?php elseif ($step === 'email'): ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="mode" value="code">
    <div class="field"><label for="email">Email you registered with</label><input id="email" name="email" type="email" value="<?= e($email) ?>" required autofocus autocomplete="email" placeholder="you@example.com"></div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-paper-plane"></i> Send me a login code</button>
  </form>
  <p class="center small"><a href="login.php">Log in with a password</a></p>
  <?php else: ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="mode" value="code"><input type="hidden" name="email" value="<?= e($email) ?>">
    <div class="field"><label for="code">6-digit code</label><input id="code" class="code-input" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" required autofocus autocomplete="one-time-code" placeholder="••••••"></div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Log in</button>
  </form>
  <p class="center small"><a href="login.php?mode=code&amp;change=1">Use a different email</a> · <a href="login.php">Log in with a password</a></p>
  <?php endif; ?>
  <p class="center muted small">Not registered yet? <a href="../#register">Register for the camp</a></p>
</section>
<?php if (google_enabled() && $mode === 'password'): ?>
<script src="../assets/js/google.js?v=<?= filemtime(ROOT . '/assets/js/google.js') ?>"></script>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>
<?php app_footer('../');
