<?php
/**
 * Ticket verification for staff — the ticket QR code opens this page. Only logged-in admins see anything:
 * everyone else is sent to the admin login. Shows whether the seat is confirmed, the participant's details,
 * the package price and payments, and lets staff check the participant in at camp.
 */
require __DIR__ . '/_init.php';
$admin = require_admin();

$code = strtoupper(trim((string) ($_GET['c'] ?? $_POST['c'] ?? '')));
$r = $code !== '' ? find_registration_by_ref($code) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $r) {
    require_csrf();
    if (($_POST['action'] ?? '') === 'check_in' && ticket_valid($r) && !$r['checked_in_at']) {
        q('UPDATE registrations SET checked_in_at = ?, checked_in_by = ? WHERE id = ?', [now(), $admin['id'], $r['id']]);
        flash($r['full_name'] . ' is checked in.');
    } elseif (($_POST['action'] ?? '') === 'undo_check_in') {
        q('UPDATE registrations SET checked_in_at = NULL, checked_in_by = NULL WHERE id = ?', [$r['id']]);
        flash('Check-in undone for ' . $r['full_name'] . '.');
    }
    redirect('verify.php?c=' . rawurlencode($r['reference']));
}

$confirmedTotal = (int) q("SELECT COUNT(*) FROM registrations WHERE status = 'confirmed'")->fetchColumn();
$checkedIn = (int) q('SELECT COUNT(*) FROM registrations WHERE checked_in_at IS NOT NULL')->fetchColumn();

admin_header('Verify ticket', 'verify', 'Scan a camp ticket QR code, or type the code number');
?>
<form class="card verify-search" method="get">
  <div class="filter-search"><i class="fa-solid fa-qrcode"></i><input type="search" name="c" value="<?= e($code) ?>" placeholder="Code number, e.g. KTC26-0123" autocomplete="off" autocapitalize="characters"></div>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Check ticket</button>
  <span class="muted small"><b><?= number_format($checkedIn) ?></b> of <?= number_format($confirmedTotal) ?> confirmed participants checked in</span>
</form>

<?php if ($code !== '' && !$r): ?>
  <div class="verify-banner bad"><i class="fa-solid fa-circle-xmark"></i><div><b>Ticket not found</b><span>No participant has the code <?= e($code) ?>. Check the code, or the ticket may not be genuine.</span></div></div>
<?php elseif ($r):
    $valid = ticket_valid($r);
    $pays = q("SELECT * FROM payments WHERE registration_id = ? AND status = 'success' ORDER BY COALESCE(completed_at, created_at)", [$r['id']])->fetchAll();
?>
  <?php if (!$valid): ?>
    <div class="verify-banner bad"><i class="fa-solid fa-triangle-exclamation"></i><div><b>Not valid — do not admit</b><span><?= $r['status'] === 'cancelled' ? 'This registration was cancelled.' : 'Seat not confirmed: balance ' . e(format_ugx(balance($r))) . ' (' . e(payment_statuses()[$r['payment_status']] ?? $r['payment_status']) . ').' ?></span></div></div>
  <?php elseif ($r['checked_in_at']): ?>
    <div class="verify-banner warn"><i class="fa-solid fa-circle-info"></i><div><b>Valid — already checked in</b><span>Checked in <?= e(date('D j M, g:i a', strtotime($r['checked_in_at']))) ?>. Make sure this is the same person.</span></div></div>
  <?php else: ?>
    <div class="verify-banner ok"><i class="fa-solid fa-circle-check"></i><div><b>Valid ticket — seat confirmed</b><span>Compare the photo with the person, then check them in.</span></div></div>
  <?php endif; ?>

  <div class="verify-grid">
    <section class="card verify-person">
      <div class="verify-photo"><?php if (photo_path($r['photo'])): ?><img src="../photo.php?id=<?= (int) $r['id'] ?>" alt="Photo of <?= e($r['full_name']) ?>"><?php else: ?><span><?= e(initials($r['full_name'])) ?></span><?php endif; ?></div>
      <h2><?= e($r['full_name']) ?></h2>
      <p class="verify-code"><?= e($r['reference']) ?></p>
      <p class="muted"><i class="fa-solid fa-location-dot"></i> <?= e(ticket_location($r)) ?></p>
      <?php if ($valid && !$r['checked_in_at']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="c" value="<?= e($r['reference']) ?>"><input type="hidden" name="action" value="check_in"><button class="btn btn-primary btn-block btn-checkin" type="submit"><i class="fa-solid fa-user-check"></i> Check in</button></form>
      <?php elseif ($r['checked_in_at']): ?>
        <form method="post" data-confirm="Undo the check-in for <?= e($r['full_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="c" value="<?= e($r['reference']) ?>"><input type="hidden" name="action" value="undo_check_in"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-rotate-left"></i> Undo check-in</button></form>
      <?php endif; ?>
      <div class="verify-links">
        <a href="view.php?id=<?= (int) $r['id'] ?>"><i class="fa-regular fa-id-card"></i> Full record</a>
        <a href="../ticket.php?id=<?= (int) $r['id'] ?>&amp;format=pdf&amp;download=1"><i class="fa-solid fa-download"></i> Ticket PDF</a>
      </div>
    </section>

    <div class="verify-details">
      <section class="card">
        <div class="card-head"><h3><i class="fa-regular fa-user"></i> Bio data</h3></div>
        <dl class="details">
          <div><dt>Age</dt><dd><?= (int) $r['age'] ?></dd></div>
          <div><dt>Gender</dt><dd><?= e($r['gender'] ?: '—') ?></dd></div>
          <div><dt>District</dt><dd><?= e($r['district'] ?: '—') ?></dd></div>
          <div><dt>Country</dt><dd><?= e($r['country'] ?: '—') ?></dd></div>
          <div><dt>Phone</dt><dd><a href="<?= e(tel_link($r['phone'])) ?>"><?= e($r['phone']) ?></a></dd></div>
          <div><dt>Email</dt><dd><?= e($r['email']) ?></dd></div>
          <div><dt>Learning tracks</dt><dd><?= e($r['interests'] ?: '—') ?></dd></div>
          <div><dt>Jersey size</dt><dd><?= e($r['jersey_size'] ?: '—') ?> + camp shirt</dd></div>
          <div><dt><?= e(fees()['park_name']) ?> visit</dt><dd><?= $r['park_visit'] ? 'Yes' : 'No' ?></dd></div>
          <div><dt>Recommended by</dt><dd><?= e($r['referred_by'] ?: '—') ?></dd></div>
        </dl>
      </section>
      <section class="card">
        <div class="card-head"><h3><i class="fa-solid fa-receipt"></i> Package &amp; payments</h3><?= payment_badge($r['payment_status']) ?></div>
        <dl class="details">
          <div><dt>Package price</dt><dd><?= e(format_ugx($r['total_amount'])) ?></dd></div>
          <div><dt>Paid</dt><dd><?= e(format_ugx($r['amount_paid'])) ?></dd></div>
          <div><dt>Balance</dt><dd class="<?= balance($r) ? 'due' : 'ok-text' ?>"><?= e(format_ugx(balance($r))) ?></dd></div>
          <div><dt>Funding</dt><dd><?= is_sponsored($r) ? 'Sponsored' . ($r['sponsor_name'] ? ' by ' . e($r['sponsor_name']) : '') : 'Self-funded' ?></dd></div>
        </dl>
        <?php if ($pays): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Amount</th></tr></thead>
          <tbody><?php foreach ($pays as $p): ?><tr><td><b><?= e(receipt_no($p)) ?></b></td><td class="nowrap muted"><?= e(date('j M Y', strtotime($p['completed_at'] ?: $p['created_at']))) ?></td><td><?= e(payment_methods()[$p['method']] ?? $p['method']) ?><small class="block muted"><?= e(payment_channel_detail($p)) ?></small></td><td><?= money_cell((int) $p['amount'], $p['currency']) ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
        <?php else: ?><p class="empty-note"><?= is_covered($r) ? 'No payments — the package is covered.' : 'No payments yet.' ?></p><?php endif; ?>
      </section>
    </div>
  </div>
<?php else: ?>
  <div class="card empty-state"><i class="fa-solid fa-qrcode"></i><p>Scan the QR code on a participant's ticket with your phone camera while logged in here, or type their code number above.</p></div>
<?php endif; ?>
<?php admin_footer();
