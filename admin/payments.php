<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'recheck') {
        $p = sync_payment((int) ($_POST['id'] ?? 0), true);
        flash($p ? receipt_no($p) . ' is now ' . $p['status'] . ($p['provider_status'] ? ' (' . ($p['provider'] === 'pesapal' ? 'Pesapal' : 'ioTec') . ': ' . $p['provider_status'] . ')' : '') . '.' : 'Payment not found.', $p ? 'success' : 'error');
    } elseif (($_POST['action'] ?? '') === 'recheck_all') {
        $ids = q("SELECT id FROM payments WHERE status = 'pending' AND provider IN ('iotec','pesapal') AND created_at > ?", [date('Y-m-d H:i:s', strtotime('-3 days'))])->fetchAll(PDO::FETCH_COLUMN);
        $res = ['success' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($ids as $pid) {
            $p = sync_payment((int) $pid, true);
            $res[$p['status'] ?? 'pending']++;
        }
        flash('Checked ' . count($ids) . " pending payment(s): {$res['success']} succeeded, {$res['failed']} failed, {$res['pending']} still pending.");
    }
    redirect('payments.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : ''));
}

$f = [
    'q'       => trim((string) ($_GET['q'] ?? '')),
    'status'  => in_array($_GET['status'] ?? '', ['success', 'pending', 'failed'], true) ? $_GET['status'] : '',
    'purpose' => in_array($_GET['purpose'] ?? '', ['camp', 'sponsorship'], true) ? $_GET['purpose'] : '',
    'method'  => array_key_exists($_GET['method'] ?? '', payment_methods()) ? $_GET['method'] : '',
    'from'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
];
$where = [];
$params = [];
if ($f['q'] !== '') {
    $where[] = '(p.payer_name LIKE ? OR p.payer_email LIKE ? OR p.payer_phone LIKE ? OR p.provider_txn_id LIKE ? OR p.external_id LIKE ? OR r.reference LIKE ? OR d.reference LIKE ?)';
    array_push($params, ...array_fill(0, 7, '%' . $f['q'] . '%'));
}
foreach (['status', 'purpose', 'method'] as $k) {
    if ($f[$k] !== '') {
        $where[] = "p.$k = ?";
        $params[] = $f[$k];
    }
}
if ($f['from']) { $where[] = 'p.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if ($f['to']) { $where[] = 'p.created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$from = 'FROM payments p LEFT JOIN registrations r ON r.id = p.registration_id LEFT JOIN donations d ON d.id = p.donation_id';

$total = (int) q("SELECT COUNT(*) $from $w", $params)->fetchColumn();
$sum = (int) q("SELECT COALESCE(SUM(CASE WHEN p.status = 'success' THEN p.amount ELSE 0 END),0) $from $w", $params)->fetchColumn();
$perPage = 40;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$list = q("SELECT p.*, r.full_name, r.reference AS rref, d.donor_name, d.reference AS dref $from $w ORDER BY p.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$qs = array_filter($f, fn($v) => $v !== '');
$qsString = $qs ? '?' . http_build_query($qs) : '';
$pageUrl = fn(int $p) => 'payments.php?' . http_build_query($qs + ['page' => $p]);
$pendingCount = (int) q("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();

admin_header('Payments', 'payments', number_format($total) . ' transactions · ' . format_ugx($sum) . ' successful in this view');
?>
<form class="card filters" method="get">
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Payer, phone, email, reference, ioTec transaction…"></div>
  <select name="status"><option value="">Any status</option><?php foreach (['success' => 'Successful', 'pending' => 'Pending', 'failed' => 'Failed'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <select name="purpose"><option value="">Camp &amp; sponsorship</option><option value="camp" <?= $f['purpose'] === 'camp' ? 'selected' : '' ?>>Camp fees</option><option value="sponsorship" <?= $f['purpose'] === 'sponsorship' ? 'selected' : '' ?>>Sponsorships</option></select>
  <select name="method"><option value="">Any method</option><?php foreach (payment_methods() as $k => $l): ?><option value="<?= $k ?>" <?= $f['method'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <label class="date-field">From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label class="date-field">To <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($qs): ?><a href="payments.php" class="btn btn-light">Clear</a><?php endif; ?>
</form>

<div class="card">
  <div class="card-head list-head">
    <h3><?= number_format($total) ?> transaction<?= $total === 1 ? '' : 's' ?></h3>
    <div class="bulk">
      <?php if ($pendingCount): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="recheck_all"><input type="hidden" name="return" value="<?= e($qsString) ?>"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-rotate"></i> Re-check <?= $pendingCount ?> pending payments</button></form><?php endif; ?>
      <a href="export.php?type=payments&amp;<?= e(http_build_query($qs)) ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <?php if ($list): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Receipt</th><th>Payer</th><th>For</th><th>Amount</th><th>Method</th><th>Status</th><th>ioTec / ref</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $p): ?>
        <tr>
          <td><b><?= e(receipt_no($p)) ?></b></td>
          <td><b><?= e($p['full_name'] ?? $p['donor_name'] ?? $p['payer_name']) ?></b><small class="block muted"><?= e($p['payer_phone']) ?></small></td>
          <td><?php if ($p['registration_id']): ?><a class="ref" href="view.php?id=<?= (int) $p['registration_id'] ?>"><?= e($p['rref']) ?></a><?php elseif ($p['donation_id']): ?><a class="ref" href="sponsors.php?q=<?= e(rawurlencode((string) $p['dref'])) ?>"><?= e($p['dref']) ?></a> <span class="tag">Sponsor</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td><?= money_cell((int) $p['amount'], $p['currency']) ?></td>
          <td><?= e(payment_methods()[$p['method']] ?? $p['method']) ?><small class="block muted"><?= $p['provider'] === 'manual' ? 'Manual' : 'ioTec' ?></small></td>
          <td><?= txn_badge($p['status']) ?><?php if ($p['status'] !== 'success' && $p['message']): ?><small class="block muted"><?= e(mb_strimwidth($p['message'], 0, 50, '…')) ?></small><?php endif; ?></td>
          <td class="small muted"><?= e(mb_strimwidth((string) ($p['provider_txn_id'] ?: $p['notes'] ?: '—'), 0, 26, '…')) ?></td>
          <td class="nowrap muted"><?= e(date('j M, g:i a', strtotime($p['completed_at'] ?: $p['created_at']))) ?></td>
          <td class="nowrap actions">
            <?php if ($p['status'] === 'success'): ?><a class="icon-btn" href="../receipt.php?id=<?= (int) $p['id'] ?>" target="_blank" title="PDF receipt"><i class="fa-solid fa-file-pdf"></i></a><?php endif; ?>
            <?php if ($p['status'] === 'pending' && in_array($p['provider'], ['iotec', 'pesapal'], true)): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="return" value="<?= e($qsString) ?>"><button class="icon-btn" title="Re-check with <?= $p['provider'] === 'pesapal' ? 'Pesapal' : 'ioTec' ?>"><i class="fa-solid fa-rotate"></i></button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pagination"><span class="muted">Page <?= $page ?> of <?= $pages ?></span><div>
    <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?><a href="<?= e($pageUrl($i)) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a><?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= e($pageUrl($page + 1)) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
  </div></nav>
  <?php endif; ?>
  <?php else: ?><div class="empty-state"><i class="fa-solid fa-receipt"></i><p>No payments found.</p></div><?php endif; ?>
</div>
<?php admin_footer();
