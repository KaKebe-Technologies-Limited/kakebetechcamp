<?php
/**
 * Take a payment for a participant who is already registered:
 * send a Mobile Money prompt (ioTec) or record a cash / bank / offline payment.
 */
require __DIR__ . '/_init.php';
$admin = require_admin();

$id = (int) ($_GET['id'] ?? $_POST['registration_id'] ?? 0);
$r = $id ? find_registration($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    require_csrf();
    if (!$r || $r['status'] === 'cancelled') {
        flash('Choose a registered participant first.', 'error');
        redirect('take-payment.php');
    }
    $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
    $method = in_array($_POST['method'] ?? '', ['cash', 'mobile_money', 'bank', 'other'], true) ? $_POST['method'] : 'cash';
    if ($r['status'] === 'review') {
        flash('This participant is awaiting sponsorship approval. Approve or decline the sponsorship first.', 'error');
    } elseif ($amount < 500 || $amount > balance($r)) {
        flash('Enter an amount between UGX 500 and ' . format_ugx(balance($r)) . '.', 'error');
    } else {
        $notify = !empty($_POST['notify']);
        $p = record_manual_payment($r, $amount, $method, mb_substr(trim((string) ($_POST['ref'] ?? '')), 0, 64), mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 200), (int) $admin['id'], $notify);
        flash('Payment of ' . format_ugx($amount) . ' recorded for ' . $r['full_name'] . ' (' . receipt_no($p) . ').' . ($notify ? ' Receipt emailed.' . mail_note() : ''));
    }
    redirect('take-payment.php?id=' . $r['id']);
}

$q = trim((string) ($_GET['q'] ?? ''));
$results = [];
if ($q !== '' && !$r) {
    $like = '%' . $q . '%';
    $results = q("SELECT * FROM registrations WHERE status <> 'cancelled' AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR reference LIKE ?) ORDER BY full_name LIMIT 20", [$like, $like, $like, $like])->fetchAll();
}
$recent = $r ? q('SELECT p.*, a.name AS admin_name FROM payments p LEFT JOIN admins a ON a.id = p.recorded_by WHERE p.registration_id = ? ORDER BY p.id DESC LIMIT 8', [$r['id']])->fetchAll() : [];

admin_header('Take a payment', 'take', 'Record or request a payment for a participant who is already registered');
?>
<?php if (!$r): ?>
<div class="card">
  <div class="card-head"><h3><i class="fa-solid fa-magnifying-glass"></i> Find the participant</h3><span class="muted small">Payments can only be attached to someone already registered</span></div>
  <form method="get" class="search-row">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, reference (KTC26-…), email or phone" autofocus>
    <button class="btn btn-navy" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  </form>
  <?php if ($q !== ''): ?>
    <?php if ($results): ?>
    <div class="table-wrap" style="margin-top:18px;">
      <table class="table">
        <thead><tr><th>Participant</th><th>Reference</th><th>Phone</th><th>Paid / package</th><th>Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results as $row): ?>
          <tr>
            <td><div class="person"><?= avatar_html($row) ?><div><b><?= e($row['full_name']) ?></b><small><?= e($row['email']) ?></small></div></div></td>
            <td><span class="ref"><?= e($row['reference']) ?></span></td>
            <td class="nowrap"><?= e($row['phone']) ?></td>
            <td style="min-width:140px;"><span class="money"><b><?= number_format((int) $row['amount_paid']) ?></b> / <?= number_format((int) $row['total_amount']) ?></span><?= progress_bar($row) ?></td>
            <td><?= balance($row) ? '<b class="due">' . number_format(balance($row)) . '</b>' : '<b class="ok-text">0</b>' ?></td>
            <td><?= status_badge($row['status']) ?></td>
            <td><a class="btn btn-primary btn-sm" href="take-payment.php?id=<?= (int) $row['id'] ?>">Select</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <p class="empty-note" style="margin-top:16px;">No registered participant matches "<?= e($q) ?>". The person must register on the website first.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php else:
    $bal = balance($r);
    $blocked = $r['status'] === 'review'; ?>
<a href="take-payment.php" class="back"><i class="fa-solid fa-arrow-left"></i> Choose another participant</a>

<section class="profile-head">
  <div class="ph-person">
    <?= avatar_html($r, 'ph-photo') ?>
    <div>
      <h2><?= e($r['full_name']) ?></h2>
      <p><span class="ref"><?= e($r['reference']) ?></span> <?= status_badge($r['status']) ?> <?= payment_badge($r['payment_status']) ?></p>
      <p class="muted small"><?= e($r['email']) ?> · <?= e($r['phone']) ?></p>
    </div>
  </div>
  <div class="ph-actions"><a class="btn btn-light btn-sm" href="view.php?id=<?= (int) $r['id'] ?>"><i class="fa-regular fa-eye"></i> Full profile</a></div>
</section>

<div class="fin-strip">
  <div><small>Package</small><b><?= e(format_ugx($r['total_amount'])) ?></b></div>
  <div><small>Paid</small><b class="ok-text"><?= e(format_ugx($r['amount_paid'])) ?></b></div>
  <div><small>Balance</small><b class="<?= $bal ? 'due' : 'ok-text' ?>"><?= e(format_ugx($bal)) ?></b></div>
  <div class="grow"><small><?= paid_percent($r) ?>% paid</small><div class="big-progress"><i style="width: <?= paid_percent($r) ?>%"></i><span style="left: <?= fees()['deposit_pct'] ?>%"></span></div></div>
</div>

<?php if ($blocked): ?>
  <div class="alert alert-warning"><i class="fa-solid fa-user-check"></i> <span>This participant is awaiting sponsorship approval. <a href="view.php?id=<?= (int) $r['id'] ?>">Approve or decline the sponsorship</a> before taking a payment.</span></div>
<?php elseif ($bal <= 0): ?>
  <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <span>Nothing to pay — this participant's camp package is fully covered.</span></div>
<?php else: ?>
<div class="grid-2 align-start">
  <div class="card accent-card">
    <div class="card-head"><h3><i class="fa-solid fa-mobile-screen-button"></i> Send a Mobile Money prompt</h3></div>
    <p class="small muted">The payer receives a prompt on their phone (MTN or Airtel) and approves it with their PIN. The payment is attached to <?= e($r['full_name']) ?> and their receipt is emailed automatically.</p>
    <form class="js-pay-form admin-pay" action="api-pay.php" data-status="../api/payment-status.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="registration_id" value="<?= (int) $r['id'] ?>">
      <input type="hidden" name="method" value="mobile_money">
      <div class="pay-body stack">
        <div class="chips">
          <?php if ((int) $r['amount_paid'] < deposit_amount($r) && deposit_amount($r) - (int) $r['amount_paid'] < $bal): ?>
          <button type="button" class="chip-amt" data-amount="<?= deposit_amount($r) - (int) $r['amount_paid'] ?>"><b><?= e(format_ugx(deposit_amount($r) - (int) $r['amount_paid'])) ?></b><small>Secure place (<?= fees()['deposit_pct'] ?>%)</small></button>
          <?php endif; ?>
          <button type="button" class="chip-amt active" data-amount="<?= $bal ?>"><b><?= e(format_ugx($bal)) ?></b><small>Full balance</small></button>
        </div>
        <label class="field">Amount (UGX)<input name="amount" type="text" inputmode="numeric" value="<?= number_format($bal) ?>" data-min="500" data-max="<?= $bal ?>"><span class="err" data-err="amount"></span></label>
        <label class="field">Payer's Mobile Money number<input name="pay_phone" type="tel" value="<?= e($r['phone']) ?>" placeholder="e.g. 0772 123 456"><span class="err" data-err="pay_phone"></span></label>
        <div class="form-alert" hidden></div>
        <button class="btn btn-primary js-pay-btn" type="submit"><span class="btn-label"><i class="fa-solid fa-paper-plane"></i> Send prompt for <span class="js-amt"><?= e(format_ugx($bal)) ?></span></span><span class="btn-loading"><span class="spinner"></span> Sending prompt…</span></button>
      </div>
      <div class="pay-state" hidden></div>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-cash-register"></i> Record a payment received</h3></div>
    <p class="small muted">Use this for cash, bank deposits or Mobile Money sent directly to Kakebe.</p>
    <form method="post" class="stack" data-confirm="Record this payment for <?= e($r['full_name']) ?>?">
      <?= csrf_field() ?><input type="hidden" name="action" value="manual"><input type="hidden" name="registration_id" value="<?= (int) $r['id'] ?>">
      <div class="row-2">
        <label>Amount (UGX)<input type="text" inputmode="numeric" name="amount" value="<?= number_format($bal) ?>" required></label>
        <label>Method<select name="method"><?php foreach (['cash', 'mobile_money', 'bank', 'other'] as $m): ?><option value="<?= $m ?>"><?= e(payment_methods()[$m]) ?></option><?php endforeach; ?></select></label>
      </div>
      <label>Transaction / receipt reference<input type="text" name="ref" maxlength="64" placeholder="e.g. MoMo ID or bank slip number"></label>
      <label>Note<input type="text" name="notes" maxlength="200" placeholder="Optional"></label>
      <label class="check-inline"><input type="checkbox" name="notify" value="1" checked> Email the PDF receipt to the participant</label>
      <button class="btn btn-navy" type="submit"><i class="fa-solid fa-plus"></i> Record payment</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Recent payments for <?= e($r['full_name']) ?></h3></div>
  <?php if ($recent): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Receipt</th><th>Date</th><th>Amount</th><th>Method</th><th>Status</th><th>By</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $p): ?>
        <tr>
          <td><b><?= e(receipt_no($p)) ?></b></td>
          <td class="muted nowrap"><?= e(date('j M Y, g:i a', strtotime($p['completed_at'] ?: $p['created_at']))) ?></td>
          <td><?= money_cell((int) $p['amount'], $p['currency']) ?></td>
          <td><?= e(payment_methods()[$p['method']] ?? $p['method']) ?></td>
          <td><?= txn_badge($p['status']) ?></td>
          <td class="small muted"><?= $p['admin_name'] ? e($p['admin_name']) : 'Participant' ?></td>
          <td><?php if ($p['status'] === 'success'): ?><a class="icon-btn" href="../receipt.php?id=<?= (int) $p['id'] ?>" target="_blank" title="PDF receipt"><i class="fa-solid fa-file-pdf"></i></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?><p class="empty-note">No payments yet.</p><?php endif; ?>
</div>
<script src="../assets/js/payment.js?v=<?= filemtime(ROOT . '/assets/js/payment.js') ?>"></script>
<?php endif; ?>
<?php admin_footer();
