<?php
/**
 * Portal login with a one-time 6-digit code sent to the participant's email (no passwords to remember).
 */
require __DIR__ . '/_init.php';

if (current_participant()) {
    redirect('./');
}

$step = 'email';
$email = strtolower(trim((string) ($_POST['email'] ?? $_SESSION['login_email'] ?? '')));
$error = '';
$info = '';
$ip = client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'send') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (too_many('login_codes', $ip, 15, 6)) {
            $error = 'Too many code requests. Please wait 15 minutes.';
        } else {
            $stmt = db()->prepare("SELECT * FROM registrations WHERE email = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$email]);
            $r = $stmt->fetch();
            if ($r) {
                $code = (string) random_int(100000, 999999);
                db()->prepare('INSERT INTO login_codes (registration_id, code_hash, expires_at, ip, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$r['id'], password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 600), $ip, now()]);
                [$s, $h] = tpl_login_code($r, $code);
                send_mail($r['email'], $s, $h);
            } else {
                // Record the attempt for rate limiting without revealing whether the email exists.
                db()->prepare('INSERT INTO login_codes (registration_id, code_hash, expires_at, ip, created_at) VALUES (0, ?, ?, ?, ?)')
                    ->execute(['-', now(), $ip, now()]);
            }
            $_SESSION['login_email'] = $email;
            $step = 'code';
            $info = 'If ' . $email . ' is registered, we have sent a 6-digit code to it. It expires in 10 minutes.';
        }
    } elseif (($_POST['action'] ?? '') === 'verify') {
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
            db()->prepare('UPDATE registrations SET last_login_at = ? WHERE id = ?')->execute([now(), $row['rid']]);
            session_regenerate_id(true);
            $_SESSION['participant_id'] = (int) $row['rid'];
            unset($_SESSION['login_email']);
            redirect('./');
        }
    }
} elseif (isset($_GET['change'])) {
    unset($_SESSION['login_email']);
    $email = '';
}

app_header('Participant portal', '../', 'login');
?>
<section class="app-card narrow">
  <div class="card-icon"><i class="fa-solid <?= $step === 'code' ? 'fa-envelope-open-text' : 'fa-user-lock' ?>"></i></div>
  <h1 class="center">Participant portal</h1>
  <p class="muted center">View your registration, pay your balance, download receipts and update your profile.</p>
  <?php if ($error): ?><div class="form-alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="notice ok"><i class="fa-solid fa-paper-plane"></i> <?= e($info) ?></div><?php endif; ?>

  <?php if ($step === 'email'): ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="send">
    <div class="field"><label for="email">Email you registered with</label><input id="email" name="email" type="email" value="<?= e($email) ?>" required autofocus autocomplete="email" placeholder="you@example.com"></div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-paper-plane"></i> Send me a login code</button>
  </form>
  <?php else: ?>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="email" value="<?= e($email) ?>">
    <div class="field"><label for="code">6-digit code</label><input id="code" class="code-input" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" required autofocus autocomplete="one-time-code" placeholder="••••••"></div>
    <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Log in</button>
  </form>
  <p class="center small"><a href="login.php?change=1">Use a different email</a> · <a href="#" onclick="document.getElementById('resend').submit();return false;">Resend code</a></p>
  <form method="post" id="resend" hidden><?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="email" value="<?= e($email) ?>"></form>
  <?php endif; ?>
  <p class="center muted small">Just want to pay? <a href="../pay.php">Pay with email &amp; phone</a> · Not registered? <a href="../#register">Register</a></p>
</section>
<?php app_footer('../');
