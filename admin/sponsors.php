<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $d = q('SELECT * FROM donations WHERE id = ?', [(int) ($_POST['id'] ?? 0)])->fetch();
    switch ($_POST['action'] ?? '') {
        case 'resend':
            $p = $d ? q("SELECT * FROM payments WHERE donation_id = ? AND status = 'success' ORDER BY id DESC LIMIT 1", [$d['id']])->fetch() : null;
            if ($p) {
                [$s, $h] = tpl_donation_thanks($d, $p);
                $ok = send_mail($d['email'], $s, $h, setting('contact_email') ?: null, $err, [['name' => 'Kakebe-Receipt-' . receipt_no($p) . '.pdf', 'type' => 'application/pdf', 'data' => receipt_pdf($p)]]);
                flash($ok ? 'Thank-you email & receipt sent to ' . $d['email'] . '.' . mail_note() : 'Email failed: ' . $err, $ok ? 'success' : 'error');
            }
            break;
        case 'recheck':
            foreach (q("SELECT id FROM payments WHERE donation_id = ? AND status = 'pending'", [(int) $d['id']])->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                sync_payment((int) $pid, true);
            }
            flash('Payment status re-checked with ioTec.');
            break;
        case 'delete':
            if ($d && $d['status'] !== 'paid') {
                q('DELETE FROM payments WHERE donation_id = ? AND status <> ?', [$d['id'], 'success']);
                q('DELETE FROM donations WHERE id = ?', [$d['id']]);
                flash('Unpaid pledge removed.');
            } else {
                flash('Paid sponsorships cannot be deleted.', 'error');
            }
            break;
    }
    redirect('sponsors.php');
}

$qStr = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['paid', 'pending', 'failed'], true) ? $_GET['status'] : '';
$where = [];
$params = [];
if ($qStr !== '') {
    $where[] = '(donor_name LIKE ? OR email LIKE ? OR phone LIKE ? OR organization LIKE ? OR reference LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $qStr . '%'));
}
if ($status) {
    $where[] = 'status = ?';
    $params[] = $status;
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$list = q("SELECT * FROM donations $w ORDER BY id DESC LIMIT 300", $params)->fetchAll();
$tot = q("SELECT COUNT(*) n, COALESCE(SUM(amount_paid),0) paid, COALESCE(SUM(CASE WHEN status='paid' THEN children ELSE 0 END),0) kids, COUNT(DISTINCT CASE WHEN status='paid' THEN email END) donors FROM donations")->fetch();

admin_header('Sponsorships', 'sponsors', 'Sponsor-a-child pledges and donations');
?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon purple"><i class="fa-solid fa-hand-holding-heart"></i></span><div><small>Total received</small><b><?= e(format_ugx($tot['paid'])) ?></b><em>from sponsors</em></div></div>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-child"></i></span><div><small>Children sponsored</small><b><?= (int) $tot['kids'] ?></b><em>at <?= e(format_ugx(fees()['sponsor_child'])) ?> each</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-users"></i></span><div><small>Sponsors</small><b><?= (int) $tot['donors'] ?></b><em>paid</em></div></div>
  <div class="kpi"><span class="kpi-icon blue"><i class="fa-solid fa-list"></i></span><div><small>All pledges</small><b><?= (int) $tot['n'] ?></b><em>including unpaid</em></div></div>
</div>

<form class="card filters" method="get">
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($qStr) ?>" placeholder="Name, email, phone, organisation, reference…"></div>
  <select name="status"><option value="">Any status</option><?php foreach (['paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <a href="export.php?type=sponsors" class="btn btn-light"><i class="fa-solid fa-file-csv"></i> Export</a>
</form>

<div class="card">
  <?php if ($list): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Sponsor</th><th>Reference</th><th>Children</th><th>Pledged</th><th>Received</th><th>Status</th><th>Message</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $d): ?>
        <tr>
          <td><b><?= e($d['donor_name']) ?></b><?= $d['is_anonymous'] ? ' <span class="tag">Anonymous</span>' : '' ?><small class="block muted"><?= e($d['organization'] ? $d['organization'] . ' · ' : '') ?><?= e($d['email']) ?> · <?= e($d['phone']) ?></small></td>
          <td><span class="ref"><?= e($d['reference']) ?></span></td>
          <td><?= $d['children'] ? (int) $d['children'] : '<span class="muted">General</span>' ?></td>
          <td><?= money_cell((int) $d['amount']) ?></td>
          <td><?= money_cell((int) $d['amount_paid']) ?></td>
          <td><?= txn_badge($d['status'] === 'paid' ? 'success' : $d['status']) ?></td>
          <td class="small"><?= e(mb_strimwidth((string) $d['message'], 0, 70, '…')) ?></td>
          <td class="nowrap muted"><?= e(date('j M Y', strtotime($d['created_at']))) ?></td>
          <td class="nowrap actions">
            <?php if ($d['status'] === 'paid'): ?>
              <form method="post" class="inline" data-confirm="Resend thank-you email and receipt to <?= e($d['email']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="icon-btn" title="Resend receipt"><i class="fa-solid fa-paper-plane"></i></button></form>
            <?php else: ?>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="icon-btn" title="Re-check payment"><i class="fa-solid fa-rotate"></i></button></form>
              <form method="post" class="inline" data-confirm="Remove this unpaid pledge?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button class="icon-btn danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?><div class="empty-state"><i class="fa-solid fa-hand-holding-heart"></i><p>No sponsorships yet. Share the "Sponsor a child" section of the website.</p><a class="btn btn-primary" href="../#sponsor" target="_blank">Open sponsor section</a></div><?php endif; ?>
</div>
<?php admin_footer();
