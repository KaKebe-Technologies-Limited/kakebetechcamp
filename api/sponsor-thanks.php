<?php
/**
 * One-click "Send the thank-you email" from the team's "Sponsorship received" email.
 * Signed link; sends the thank-you to the sponsor once (a second click within a day does not resend).
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/app_layout.php';

$st = db()->prepare('SELECT * FROM donations WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$d = $st->fetch() ?: null;
$valid = $d && sign_valid('sponsor-thanks', (string) $d['id'], $_GET['t'] ?? null);
$sent = false;
$already = false;
$error = null;
if ($valid) {
    if ($d['thanked_at'] && time() - strtotime($d['thanked_at']) < 86400) {
        $already = true;   // clicked twice, or a teammate already did it
    } else {
        $sent = send_sponsor_thanks($d, $error);
    }
}

app_header('Sponsor thank-you', '../');
?>
<section class="app-card narrow center">
<?php if (!$valid): ?>
  <div class="card-icon bad"><i class="fa-solid fa-link-slash"></i></div>
  <h1>This link isn't valid</h1>
  <p class="muted">Open Sponsorships in the control panel instead.</p>
<?php elseif ($sent || $already): ?>
  <div class="card-icon ok"><i class="fa-solid fa-check"></i></div>
  <h1><?= $sent ? 'Thank-you email sent' : 'Already thanked' ?></h1>
  <p><?= $sent ? 'The thank-you was emailed to' : 'A thank-you was already emailed to' ?> <b><?= e($d['donor_name']) ?></b> (<?= e($d['email']) ?>)<?= $already ? ' ' . e(time_ago($d['thanked_at'])) : '' ?>.</p>
  <a class="btn btn-ghost" href="../admin/sponsors.php">Open sponsorships</a>
<?php else: ?>
  <div class="card-icon bad"><i class="fa-solid fa-triangle-exclamation"></i></div>
  <h1>The email was not sent</h1>
  <p class="muted"><?= e($error ?: 'Please try again from the control panel.') ?></p>
  <a class="btn btn-primary" href="../admin/sponsors.php">Open sponsorships</a>
<?php endif; ?>
</section>
<?php app_footer('../');
