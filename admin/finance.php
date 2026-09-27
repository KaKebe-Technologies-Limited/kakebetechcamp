<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'remind_all') {
        $rows = q("SELECT * FROM registrations WHERE status NOT IN ('cancelled','waitlisted') AND payment_status IN ('unpaid','partial')")->fetchAll();
        $sent = 0;
        foreach ($rows as $r) {
            [$s, $h] = tpl_balance_reminder($r);
            $sent += send_mail($r['email'], $s, $h, setting('contact_email') ?: null) ? 1 : 0;
        }
        flash("Balance reminders sent to $sent participant" . ($sent === 1 ? '' : 's') . '.' . mail_note());
    }
    redirect('finance.php');
}

$one = fn(string $sql, array $p = []) => q($sql, $p)->fetchColumn();
$all = fn(string $sql, array $p = []) => q($sql, $p)->fetchAll();
$f = fees();
$live = "status <> 'cancelled'";

$expected    = (int) $one("SELECT COALESCE(SUM(total_amount),0) FROM registrations WHERE $live AND payment_status <> 'waived'");
$paidRegs    = (int) $one("SELECT COALESCE(SUM(amount_paid),0) FROM registrations WHERE $live AND payment_status <> 'waived'");
$outstanding = max(0, $expected - $paidRegs);
$collected   = (int) $one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND purpose = 'camp'");
$sponsored   = (int) $one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND purpose = 'sponsorship'");
$waivedValue = (int) $one("SELECT COALESCE(SUM(total_amount - amount_paid),0) FROM registrations WHERE $live AND payment_status = 'waived'");
$counts      = $all("SELECT status, COUNT(*) c, COALESCE(SUM(amount),0) s FROM payments GROUP BY status");
$byStatus    = array_column($counts, null, 'status');
$methods     = $all("SELECT method, COUNT(*) c, COALESCE(SUM(amount),0) s FROM payments WHERE status = 'success' GROUP BY method ORDER BY s DESC");
$payState    = $all("SELECT payment_status, COUNT(*) c, COALESCE(SUM(total_amount),0) t, COALESCE(SUM(amount_paid),0) p FROM registrations WHERE $live GROUP BY payment_status");
$items = $one("SELECT COUNT(*) FROM registrations WHERE $live");
$itemRows = q("SELECT COALESCE(SUM(camp_amount),0) camp, COALESCE(SUM(jersey_amount),0) jersey, COALESCE(SUM(park_amount),0) park, SUM(park_visit) parks FROM registrations WHERE $live")->fetch();
$series = days_series("SELECT DATE(completed_at) d, SUM(amount) v FROM payments WHERE status = 'success' AND completed_at >= ? GROUP BY DATE(completed_at)");
$debtors = $all("SELECT * FROM registrations WHERE status NOT IN ('cancelled','waitlisted') AND payment_status IN ('unpaid','partial') ORDER BY (total_amount - amount_paid) DESC, id LIMIT 15");
$debtorCount = (int) $one("SELECT COUNT(*) FROM registrations WHERE status NOT IN ('cancelled','waitlisted') AND payment_status IN ('unpaid','partial')");
$rate = $expected ? round($paidRegs / $expected * 100) : 0;

admin_header('Finance overview', 'finance', 'Camp fees, balances, sponsorships and payment methods');
?>
<div class="kpis six">
  <div class="kpi"><span class="kpi-icon blue"><i class="fa-solid fa-file-invoice"></i></span><div><small>Expected (packages)</small><b><?= e(format_ugx($expected)) ?></b><em><?= (int) $items ?> active participants</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-sack-dollar"></i></span><div><small>Camp fees collected</small><b><?= e(format_ugx($collected)) ?></b><em><?= $rate ?>% collection rate</em></div></div>
  <div class="kpi"><span class="kpi-icon amber"><i class="fa-solid fa-scale-unbalanced"></i></span><div><small>Outstanding</small><b><?= e(format_ugx($outstanding)) ?></b><em><?= $debtorCount ?> with balances</em></div></div>
  <div class="kpi"><span class="kpi-icon purple"><i class="fa-solid fa-hand-holding-heart"></i></span><div><small>Sponsorship funds</small><b><?= e(format_ugx($sponsored)) ?></b><em>from sponsors</em></div></div>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-gift"></i></span><div><small>Waived (sponsored seats)</small><b><?= e(format_ugx($waivedValue)) ?></b><em>not collected</em></div></div>
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-receipt"></i></span><div><small>Transactions</small><b><?= (int) ($byStatus['success']['c'] ?? 0) ?></b><em><?= (int) ($byStatus['pending']['c'] ?? 0) ?> pending · <?= (int) ($byStatus['failed']['c'] ?? 0) ?> failed</em></div></div>
</div>

<div class="card">
  <div class="card-head"><h3><i class="fa-solid fa-money-bill-trend-up"></i> Collections — last 30 days</h3><span class="muted"><?= e(format_ugx(array_sum($series))) ?></span></div>
  <?= bar_chart($series, ' UGX', 'green tall') ?>
</div>

<div class="grid-3">
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-boxes-stacked"></i> Revenue by item</h3></div>
    <table class="table compact">
      <thead><tr><th>Item</th><th>Qty</th><th class="r">Value</th></tr></thead>
      <tbody>
        <tr><td>Camp fee</td><td><?= (int) $items ?></td><td class="r"><?= e(format_ugx($itemRows['camp'])) ?></td></tr>
        <tr><td>Sports jersey vest</td><td><?= (int) $items ?></td><td class="r"><?= e(format_ugx($itemRows['jersey'])) ?></td></tr>
        <tr><td><?= e($f['park_name']) ?> visit</td><td><?= (int) $itemRows['parks'] ?></td><td class="r"><?= e(format_ugx($itemRows['park'])) ?></td></tr>
        <tr><td>Camp shirt (free)</td><td><?= (int) $items ?></td><td class="r">FREE</td></tr>
        <tr class="total"><td>Total</td><td></td><td class="r"><?= e(format_ugx($itemRows['camp'] + $itemRows['jersey'] + $itemRows['park'])) ?></td></tr>
      </tbody>
    </table>
  </div>
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-wallet"></i> Payment methods</h3></div>
    <?php if ($methods): ?>
    <table class="table compact">
      <thead><tr><th>Method</th><th>Count</th><th class="r">Amount</th></tr></thead>
      <tbody><?php foreach ($methods as $m): ?><tr><td><?= e(payment_methods()[$m['method']] ?? $m['method']) ?></td><td><?= (int) $m['c'] ?></td><td class="r"><?= e(format_ugx($m['s'])) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php else: ?><p class="empty-note">No payments yet.</p><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-layer-group"></i> Participants by payment</h3></div>
    <table class="table compact">
      <thead><tr><th>State</th><th>People</th><th class="r">Paid / value</th></tr></thead>
      <tbody><?php foreach ($payState as $s): ?><tr><td><?= payment_badge($s['payment_status']) ?></td><td><a href="registrations.php?payment=<?= e($s['payment_status']) ?>"><?= (int) $s['c'] ?></a></td><td class="r small"><?= number_format((int) $s['p']) ?> / <?= number_format((int) $s['t']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head list-head">
    <h3><i class="fa-solid fa-scale-unbalanced"></i> Outstanding balances</h3>
    <div class="bulk">
      <a href="registrations.php?payment=balance" class="btn btn-light btn-sm">View all <?= $debtorCount ?></a>
      <?php if ($debtorCount): ?>
      <form method="post" data-confirm="Email a balance reminder to all <?= $debtorCount ?> participants with an outstanding balance?"><?= csrf_field() ?><input type="hidden" name="action" value="remind_all"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-bell"></i> Remind everyone</button></form>
      <?php endif; ?>
      <a href="export.php?type=participants&amp;payment=balance" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <?php if ($debtors): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Participant</th><th>Phone</th><th>Package</th><th>Paid</th><th>Balance</th><th>Status</th><th>Registered</th></tr></thead>
      <tbody>
      <?php foreach ($debtors as $r): ?>
        <tr class="row-link" data-href="view.php?id=<?= (int) $r['id'] ?>">
          <td><div class="person"><?= avatar_html($r) ?><div><b><?= e($r['full_name']) ?></b><small><?= e($r['reference']) ?></small></div></div></td>
          <td class="nowrap"><a href="<?= e(tel_link($r['phone'])) ?>"><?= e($r['phone']) ?></a></td>
          <td><?= money_cell((int) $r['total_amount']) ?></td>
          <td style="min-width:130px;"><?= money_cell((int) $r['amount_paid']) ?><?= progress_bar($r) ?></td>
          <td><b class="due"><?= e(format_ugx(balance($r))) ?></b></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="muted nowrap"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?><p class="empty-note">No outstanding balances. 🎉</p><?php endif; ?>
</div>
<?php admin_footer();
