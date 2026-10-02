<?php
/**
 * Return page after a hosted card page (Pesapal, or ioTec). Re-checks the payment with the provider and shows the result.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$id = (int) ($_GET['id'] ?? 0);
$p = ($id && sign_valid('payment', (string) $id, $_GET['t'] ?? null)) ? sync_payment($id, true) : null;
$r = $p && $p['registration_id'] ? find_registration((int) $p['registration_id']) : null;

if ($p && $p['status'] === 'pending') {
    header('Refresh: 5');
}
app_header('Payment status');
?>
<section class="app-card narrow center">
<?php if (!$p): ?>
  <div class="card-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
  <h1>Payment not found</h1>
  <p class="muted">This link is invalid. If money left your account, contact us on <?= e(setting('contact_phone')) ?> with your transaction details.</p>
<?php elseif ($p['status'] === 'success'): ?>
  <div class="card-icon ok"><i class="fa-solid fa-check"></i></div>
  <h1>Payment received!</h1>
  <p class="big"><?= e(format_ugx($p['amount'], $p['currency'])) ?></p>
  <p class="muted">Thank you — your receipt has been emailed to you.</p>
  <?php if ($r): ?><p>Balance remaining: <b><?= e(format_ugx(balance($r), $p['currency'])) ?></b></p><?php endif; ?>
  <div class="btn-row">
    <a class="btn btn-primary" href="<?= e(receipt_url($p)) ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> Download receipt</a>
    <?php if ($r): ?><a class="btn btn-ghost" href="<?= e(pay_url($r)) ?>">Back to my payments</a><?php else: ?><a class="btn btn-ghost" href="./">Back to website</a><?php endif; ?>
  </div>
<?php elseif ($p['status'] === 'pending'): ?>
  <div class="card-icon"><span class="spinner dark"></span></div>
  <h1>Confirming your payment…</h1>
  <p class="muted">This page refreshes automatically. Please don't close it.</p>
<?php else: ?>
  <div class="card-icon bad"><i class="fa-solid fa-xmark"></i></div>
  <h1>Payment not completed</h1>
  <p class="muted"><?= e($p['message'] ?: 'The payment was cancelled or declined. No money was taken.') ?></p>
  <div class="btn-row">
    <?php if ($r): ?><a class="btn btn-primary" href="<?= e(pay_url($r)) ?>">Try again</a><?php else: ?><a class="btn btn-primary" href="./#sponsor">Try again</a><?php endif; ?>
  </div>
<?php endif; ?>
</section>
<?php app_footer();
