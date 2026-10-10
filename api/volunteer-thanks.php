<?php
/**
 * One-click "Send the thank-you email" from the team's volunteer alert email.
 * Signed link; sends the thank-you from Moses Komakech (Head of Comms) once, and offers WhatsApp too.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/app_layout.php';

$v = find_volunteer((int) ($_GET['id'] ?? 0));
$valid = $v && sign_valid('volunteer-thanks', (string) $v['id'], $_GET['t'] ?? null);
$sent = false;
$already = false;
$error = null;
if ($valid) {
    if ($v['thanked_at'] && time() - strtotime($v['thanked_at']) < 86400) {
        $already = true;   // clicked twice, or a teammate already did it
    } else {
        $sent = send_volunteer_thanks($v, $error);
    }
}

app_header('Volunteer thank-you', '../');
?>
<section class="app-card narrow center">
<?php if (!$valid): ?>
  <div class="card-icon bad"><i class="fa-solid fa-link-slash"></i></div>
  <h1>This link isn't valid</h1>
  <p class="muted">Open the volunteer application in the control panel instead.</p>
<?php elseif ($sent || $already): ?>
  <div class="card-icon ok"><i class="fa-solid fa-check"></i></div>
  <h1><?= $sent ? 'Thank-you email sent' : 'Already thanked' ?></h1>
  <p><?= $sent ? 'The thank-you from Moses Komakech was emailed to' : 'A thank-you was already emailed to' ?> <b><?= e($v['full_name']) ?></b> (<?= e($v['email']) ?>)<?= $already ? ' ' . e(time_ago($v['thanked_at'])) : '' ?>.</p>
  <div class="btn-row center">
    <a class="btn btn-primary ment-wa" href="<?= e(volunteer_whatsapp_thanks_link($v)) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Also thank on WhatsApp</a>
    <a class="btn btn-ghost" href="../admin/volunteer.php?id=<?= (int) $v['id'] ?>">Open the application</a>
  </div>
<?php else: ?>
  <div class="card-icon bad"><i class="fa-solid fa-triangle-exclamation"></i></div>
  <h1>The email was not sent</h1>
  <p class="muted"><?= e($error ?: 'Please try again from the control panel.') ?></p>
  <a class="btn btn-primary ment-wa" href="<?= e(volunteer_whatsapp_thanks_link($v)) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Thank on WhatsApp instead</a>
<?php endif; ?>
</section>
<?php app_footer('../');
