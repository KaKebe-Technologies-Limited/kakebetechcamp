<?php
require __DIR__ . '/_init.php';
$admin = require_finance();
$project = fin_project();
$pid = (int) $project['id'];

$lineStats = fin_line_stats($pid);
$cs = fin_cluster_stats($pid, $lineStats);
$t = $cs['total'];
$clusters = fin_clusters($pid);
$income = fin_income_total($pid);
$cash = $income - $t['spent'] - $t['advances'];
$usd = fn(int $n) => usd_of($n, $project);

$reqCounts = array_column(q("SELECT status, COUNT(*) c, COALESCE(SUM(total), 0) s FROM fin_requisitions WHERE project_id = ? GROUP BY status", [$pid])->fetchAll(), null, 'status');
$overdue = (int) q("SELECT COUNT(*) FROM fin_requisitions WHERE project_id = ? AND status = 'disbursed' AND disbursed_at < ?", [$pid, date('Y-m-d H:i:s', time() - req_accountability_days() * 86400)])->fetchColumn();
$overLines = q('SELECT id, code, item FROM fin_lines WHERE project_id = ?', [$pid])->fetchAll();
$overLines = array_values(array_filter($overLines, fn($l) => ($lineStats[(int) $l['id']]['available'] ?? 0) < 0));
$noReceipt = (int) q("SELECT COUNT(*) FROM fin_expenses e WHERE e.project_id = ? AND e.requisition_id IS NULL AND NOT EXISTS (SELECT 1 FROM fin_files f WHERE f.owner_type = 'expense' AND f.owner_id = e.id)", [$pid])->fetchColumn();
$recent = q('SELECT e.*, l.code, l.item FROM fin_expenses e JOIN fin_lines l ON l.id = e.line_id WHERE e.project_id = ? ORDER BY e.spent_on DESC, e.id DESC LIMIT 8', [$pid])->fetchAll();
$recentIncome = q('SELECT * FROM fin_income WHERE project_id = ? ORDER BY received_on DESC, id DESC LIMIT 5', [$pid])->fetchAll();
$months = ['Sep', 'Oct', 'Nov', 'Dec'];
$thisMonth = date('M');

admin_header('Finance dashboard', 'fin', $project['name'] . ' · ' . $project['donor']);
?>
<?php if (role_can($admin['role'], 'disburse')): ?>
<div class="btn-row fin-actions"><a class="btn btn-primary" href="fin-expenses.php?new=1#form"><i class="fa-solid fa-money-bill-wave"></i> Pay someone / record spending</a><a class="btn btn-light" href="requisitions.php?tab=waiting"><i class="fa-solid fa-file-signature"></i> Requisitions waiting</a><a class="btn btn-light" href="fin-income.php"><i class="fa-solid fa-building-columns"></i> Record funds received</a></div>
<?php endif; ?>
<section class="card fin-project">
  <div><small>Project</small><b><?= e($project['name']) ?></b></div>
  <div><small>Donor / funding</small><b><?= e($project['funding_source'] ?: $project['donor']) ?></b></div>
  <div><small>Location</small><b><?= e($project['location']) ?></b></div>
  <div><small>Period</small><b><?= e(date('M Y', strtotime($project['starts_on']))) ?> – <?= e(date('M Y', strtotime($project['ends_on']))) ?></b></div>
  <div><small>Exchange rate</small><b>UGX <?= number_format((float) $project['exchange_rate'], 2) ?> / $1</b></div>
</section>

<div class="kpis fin-kpis">
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-sack-dollar"></i></span><div><small>Approved budget</small><b><?= e(ugx($t['budget'])) ?></b><em><?= $usd($t['budget']) ?> · <?= count($clusters) ?> activities</em></div></div>
  <a class="kpi" href="fin-income.php"><span class="kpi-icon green"><i class="fa-solid fa-building-columns"></i></span><div><small>Funds received</small><b><?= e(ugx($income)) ?></b><em><?= $t['budget'] ? round($income / $t['budget'] * 100) : 0 ?>% of budget · <?= e(ugx(max(0, $t['budget'] - $income))) ?> to come</em></div></a>
  <a class="kpi" href="fin-expenses.php"><span class="kpi-icon red"><i class="fa-solid fa-receipt"></i></span><div><small>Spent (verified)</small><b><?= e(ugx($t['spent'])) ?></b><em><?= $t['budget'] ? round($t['spent'] / $t['budget'] * 100, 1) : 0 ?>% of budget</em></div></a>
  <a class="kpi" href="requisitions.php?tab=disbursed"><span class="kpi-icon amber"><i class="fa-solid fa-hand-holding-dollar"></i></span><div><small>Advances out</small><b><?= e(ugx($t['advances'])) ?></b><em>paid, accountability not verified</em></div></a>
</div>
<div class="kpis fin-kpis">
  <a class="kpi" href="requisitions.php"><span class="kpi-icon blue"><i class="fa-solid fa-file-signature"></i></span><div><small>Committed &amp; waiting</small><b><?= e(ugx($t['committed'] + $t['pending'])) ?></b><em><?= e(ugx($t['committed'])) ?> approved · <?= e(ugx($t['pending'])) ?> waiting</em></div></a>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-piggy-bank"></i></span><div><small>Budget still available</small><b class="<?= $t['available'] < 0 ? 'due' : '' ?>"><?= e(ugx($t['available'])) ?></b><em><?= $t['budget'] ? round($t['available'] / $t['budget'] * 100, 1) : 0 ?>% of budget not yet used</em></div></div>
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-wallet"></i></span><div><small>Cash at hand</small><b class="<?= $cash < 0 ? 'due' : '' ?>"><?= e(ugx($cash)) ?></b><em>received − spent − advances</em></div></div>
  <a class="kpi" href="fin-reports.php"><span class="kpi-icon red"><i class="fa-solid fa-file-invoice-dollar"></i></span><div><small>Budget used</small><b><?= $t['pct'] ?>%</b><?= fin_bar((float) $t['pct']) ?></div></a>
</div>

<?php if ($overLines || $overdue || $noReceipt || !empty($reqCounts['approved']) || !empty($reqCounts['accounted'])): ?>
<section class="card fin-alerts">
  <div class="card-head"><h3><i class="fa-solid fa-bell"></i> Needs attention</h3></div>
  <ul>
    <?php if (!empty($reqCounts['approved'])): ?><li><a href="requisitions.php?tab=approved"><i class="fa-solid fa-money-bill-transfer"></i> <b><?= (int) $reqCounts['approved']['c'] ?></b> approved requisition<?= (int) $reqCounts['approved']['c'] === 1 ? '' : 's' ?> to pay (<?= e(ugx($reqCounts['approved']['s'])) ?>)</a></li><?php endif; ?>
    <?php if (!empty($reqCounts['accounted'])): ?><li><a href="requisitions.php?tab=accounted"><i class="fa-solid fa-clipboard-check"></i> <b><?= (int) $reqCounts['accounted']['c'] ?></b> accountabilit<?= (int) $reqCounts['accounted']['c'] === 1 ? 'y' : 'ies' ?> to verify</a></li><?php endif; ?>
    <?php if ($overdue): ?><li class="bad"><a href="requisitions.php?tab=overdue"><i class="fa-solid fa-hourglass-end"></i> <b><?= $overdue ?></b> accountabilit<?= $overdue === 1 ? 'y is' : 'ies are' ?> overdue (more than <?= req_accountability_days() ?> days after payment)</a></li><?php endif; ?>
    <?php foreach ($overLines as $l): ?><li class="bad"><a href="fin-budget.php#line-<?= (int) $l['id'] ?>"><i class="fa-solid fa-triangle-exclamation"></i> <b><?= e($l['code']) ?> <?= e($l['item']) ?></b> is over budget by <?= e(ugx(-$lineStats[(int) $l['id']]['available'])) ?></a></li><?php endforeach; ?>
    <?php if ($noReceipt): ?><li><a href="fin-expenses.php?receipts=missing"><i class="fa-solid fa-file-circle-question"></i> <b><?= $noReceipt ?></b> expense<?= $noReceipt === 1 ? '' : 's' ?> without a receipt attached</a></li><?php endif; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-layer-group"></i> Budget vs spending by activity</h3><a class="link" href="fin-reports.php">Detailed report →</a></div>
  <div class="table-wrap">
    <table class="table fin-table">
      <thead><tr><th>Activity</th><th class="r">Budget</th><th class="r">Spent</th><th class="r">Advances</th><th class="r">Committed &amp; waiting</th><th class="r">Available</th><th style="min-width:140px">Budget used</th><th>Progress</th></tr></thead>
      <tbody>
      <?php foreach ($clusters as $c): $s = $cs[(int) $c['id']] ?? ['budget' => 0, 'spent' => 0, 'advances' => 0, 'committed' => 0, 'pending' => 0, 'available' => 0, 'pct' => 0]; ?>
        <tr class="row-link" data-href="fin-budget.php#cluster-<?= (int) $c['id'] ?>">
          <td class="fin-name"><b><?= e($c['code']) ?></b> <?= e($c['name']) ?></td>
          <td class="r nowrap"><?= number_format($s['budget']) ?></td>
          <td class="r nowrap"><?= number_format($s['spent']) ?></td>
          <td class="r nowrap muted"><?= number_format($s['advances']) ?></td>
          <td class="r nowrap muted"><?= number_format($s['committed'] + $s['pending']) ?></td>
          <td class="r nowrap <?= $s['available'] < 0 ? 'due' : '' ?>"><b><?= number_format($s['available']) ?></b></td>
          <td><?= fin_bar((float) $s['pct']) ?><small class="muted"><?= $s['pct'] ?>%</small></td>
          <td class="small nowrap"><?= (int) $c['progress'] ?>%<?php if ($c['target_value']): ?><small class="block muted"><?= number_format((int) $c['reached']) ?> / <?= number_format((int) $c['target_value']) ?> <?= e($c['target_label']) ?></small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total"><td>Total (UGX)</td><td class="r nowrap"><?= number_format($t['budget']) ?></td><td class="r nowrap"><?= number_format($t['spent']) ?></td><td class="r nowrap"><?= number_format($t['advances']) ?></td><td class="r nowrap"><?= number_format($t['committed'] + $t['pending']) ?></td><td class="r nowrap"><?= number_format($t['available']) ?></td><td><?= fin_bar((float) $t['pct']) ?><small><?= $t['pct'] ?>%</small></td><td></td></tr></tfoot>
    </table>
  </div>
</section>

<div class="grid-2 align-start">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-calendar-days"></i> Workplan timeframe</h3></div>
    <div class="table-wrap">
      <table class="table fin-timeline">
        <thead><tr><th>Activity</th><?php foreach ($months as $m): ?><th class="center<?= $m === $thisMonth ? ' now' : '' ?>"><?= $m ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($clusters as $c): $on = array_map('trim', explode(',', (string) $c['months'])); ?>
          <tr><td class="small"><b><?= e($c['code']) ?></b> <?= e(mb_strimwidth($c['name'], 0, 40, '…')) ?></td><?php foreach ($months as $m): ?><td class="center<?= $m === $thisMonth ? ' now' : '' ?>"><?= in_array($m, $on, true) ? '<i class="fa-solid fa-circle tl-on"></i>' : '' ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-receipt"></i> Latest spending</h3><a class="link" href="fin-expenses.php">All expenses →</a></div>
    <?php if ($recent): ?>
    <ul class="feed">
      <?php foreach ($recent as $e): ?><li><span class="feed-icon"><i class="fa-solid fa-receipt"></i></span><div><b><?= e(ugx($e['amount'])) ?></b> · <?= e(mb_strimwidth($e['description'], 0, 60, '…')) ?><small class="block muted"><?= e($e['code'] . ' ' . $e['item']) ?> · <?= e(date('j M Y', strtotime($e['spent_on']))) ?><?= $e['requisition_id'] ? ' · ' . e($e['reference']) : '' ?></small></div></li><?php endforeach; ?>
    </ul>
    <?php else: ?><p class="empty-note">No spending recorded yet.</p><?php endif; ?>
    <h4 class="sub-title">Funds received</h4>
    <?php if ($recentIncome): ?>
    <ul class="feed">
      <?php foreach ($recentIncome as $i): ?><li><span class="feed-icon green"><i class="fa-solid fa-building-columns"></i></span><div><b><?= e(ugx($i['amount'])) ?></b> · <?= e($i['source']) ?><?= $i['description'] ? ' — ' . e($i['description']) : '' ?><small class="block muted"><?= e(date('j M Y', strtotime($i['received_on']))) ?></small></div></li><?php endforeach; ?>
    </ul>
    <?php else: ?><p class="empty-note">No funds recorded yet. <a href="fin-income.php">Record funds received →</a></p><?php endif; ?>
  </section>
</div>
<?php admin_footer();
