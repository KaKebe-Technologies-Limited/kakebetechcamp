<?php
/**
 * Unsubscribe from Kakebe campaign emails. GET shows a confirmation page; POST is the one-click
 * unsubscribe that Gmail / Apple Mail send for the List-Unsubscribe header.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/app_layout.php';

$token = (string) ($_GET['t'] ?? '');
$send = null;
if (preg_match('/^[a-f0-9]{24}$/', $token)) {
    $st = db()->prepare('SELECT s.*, c.email contact_email, c.status contact_status FROM mk_sends s JOIN mk_contacts c ON c.id = s.contact_id WHERE s.token = ?');
    $st->execute([$token]);
    $send = $st->fetch() ?: null;
}
if ($send && $send['contact_status'] !== 'unsubscribed') {
    db()->prepare("UPDATE mk_contacts SET status = 'unsubscribed', unsubscribed_at = ? WHERE id = ?")->execute([now(), $send['contact_id']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // one-click from the email app
    http_response_code($send ? 200 : 404);
    exit($send ? 'Unsubscribed' : 'Not found');
}

app_header('Unsubscribed', '../');
?>
<section class="app-card narrow center">
<?php if ($send): ?>
  <div class="card-icon ok"><i class="fa-solid fa-check"></i></div>
  <h1>You've been unsubscribed</h1>
  <p class="muted"><b><?= e($send['contact_email']) ?></b> will no longer receive updates from Kakebe Technologies.</p>
  <p class="muted small">Unsubscribed by mistake? Message us on <a href="<?= e(whatsapp_link('Hello, please add me back to the Kakebe email updates.')) ?>" target="_blank" rel="noopener">WhatsApp</a> and we'll add you back.</p>
<?php else: ?>
  <div class="card-icon"><i class="fa-solid fa-envelope-open"></i></div>
  <h1>Link not recognised</h1>
  <p class="muted">This unsubscribe link is not valid. To stop receiving our emails, reply to any of them with "unsubscribe".</p>
<?php endif; ?>
  <a class="btn btn-ghost" href="../">Visit kakebetechcamp.com</a>
</section>
<?php app_footer('../');
