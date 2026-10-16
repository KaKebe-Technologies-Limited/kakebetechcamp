<?php
require __DIR__ . '/_init.php';
$admin = require_finance();
$project = fin_project();
$pid = (int) $project['id'];
$clusters = fin_clusters($pid);
$lines = fin_lines($pid);
$statuses = req_statuses();

$f = [
    'cluster' => (int) ($_GET['cluster'] ?? 0),
    'line'    => (int) ($_GET['line'] ?? 0),
    'from'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '',
    'to'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '',
    'q'       => trim((string) ($_GET['q'] ?? '')),
];
$view = in_array($_GET['view'] ?? '', ['summary', 'expenses', 'requisitions', 'income'], true) ? $_GET['view'] : 'summary';

/** Expenses matching the filters. */
$expenses = function () use ($pid, $f): array {
    $where = ['e.project_id = ?'];
    $p = [$pid];
    if ($f['line']) { $where[] = 'e.line_id = ?'; $p[] = $f['line']; }
    elseif ($f['cluster']) { $where[] = 'l.cluster_id = ?'; $p[] = $f['cluster']; }
    if ($f['from']) { $where[] = 'e.spent_on >= ?'; $p[] = $f['from']; }
    if ($f['to']) { $where[] = 'e.spent_on <= ?'; $p[] = $f['to']; }
    if ($f['q'] !== '') { $where[] = '(e.payee LIKE ? OR e.description LIKE ? OR e.reference LIKE ? OR e.invoice_no LIKE ? OR e.spent_by LIKE ?)'; array_push($p, ...array_fill(0, 5, '%' . $f['q'] . '%')); }
    return q('SELECT e.*, l.code, l.item, c.code cluster_code, c.name cluster_name, r.reference req_ref, a.name recorder,
        (SELECT COUNT(*) FROM fin_files ff WHERE (ff.owner_type = \'expense\' AND ff.owner_id = e.id) OR (e.requisition_id IS NOT NULL AND ff.owner_type = \'req_receipt\' AND ff.owner_id = e.requisition_id)) receipts
        FROM fin_expenses e JOIN fin_lines l ON l.id = e.line_id JOIN fin_clusters c ON c.id = l.cluster_id LEFT JOIN fin_requisitions r ON r.id = e.requisition_id LEFT JOIN admins a ON a.id = e.recorded_by
        WHERE ' . implode(' AND ', $where) . ' ORDER BY e.spent_on, e.id', $p)->fetchAll();
};
$requisitions = function () use ($pid, $f): array {
    $where = ['r.project_id = ?'];
    $p = [$pid];
    if ($f['cluster']) { $where[] = 'r.cluster_id = ?'; $p[] = $f['cluster']; }
    if ($f['from']) { $where[] = 'r.created_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if ($f['to']) { $where[] = 'r.created_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
    return q("SELECT r.*, a.name requester, c.code cluster_code, rv.name reviewer, ap.name approver, ds.name payer, vf.name verifier,
        (SELECT COUNT(*) FROM fin_files ff WHERE ff.owner_type = 'req_receipt' AND ff.owner_id = r.id) receipts
        FROM fin_requisitions r LEFT JOIN admins a ON a.id = r.requested_by LEFT JOIN fin_clusters c ON c.id = r.cluster_id
        LEFT JOIN admins rv ON rv.id = r.reviewed_by LEFT JOIN admins ap ON ap.id = r.approved_by LEFT JOIN admins ds ON ds.id = r.disbursed_by LEFT JOIN admins vf ON vf.id = r.verified_by
        WHERE " . implode(' AND ', $where) . ' ORDER BY r.id', $p)->fetchAll();
};

// Budget vs actual: when a date range is chosen, "spent" counts only spending in that period
$ls = fin_line_stats($pid);
if ($f['from'] || $f['to']) {
    foreach ($ls as &$x) { $x['spent'] = 0; }
    unset($x);
    $p = [$pid];
    $w = '';
    if ($f['from']) { $w .= ' AND spent_on >= ?'; $p[] = $f['from']; }
    if ($f['to']) { $w .= ' AND spent_on <= ?'; $p[] = $f['to']; }
    foreach (q("SELECT line_id, SUM(amount) s FROM fin_expenses WHERE project_id = ?$w GROUP BY line_id", $p)->fetchAll() as $row) {
        if (isset($ls[(int) $row['line_id']])) { $ls[(int) $row['line_id']]['spent'] = (int) $row['s']; }
    }
    foreach ($ls as &$x) { $x['used'] = $x['spent'] + $x['advances']; $x['pct'] = $x['budget'] ? round($x['used'] / $x['budget'] * 100, 1) : 0; }
    unset($x);
}
$cs = fin_cluster_stats($pid, $ls);

/* ---------- CSV exports ---------- */
if ($export = (string) ($_GET['export'] ?? '')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-' . $export . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($export === 'clusters') {
        fputcsv($out, ['Code', 'Activity', 'Months', 'Budget (UGX)', 'Spent', 'Advances (not yet accounted)', 'Committed (approved)', 'Waiting approval', 'Available', 'Budget used %', 'Remaining after spending (budget − spent)', 'Activity progress %', 'Target', 'Reached']);
        foreach ($clusters as $c) { $s = $cs[(int) $c['id']]; fputcsv($out, [$c['code'], $c['name'], $c['months'], $s['budget'], $s['spent'], $s['advances'], $s['committed'], $s['pending'], $s['available'], $s['pct'], $s['budget'] - $s['spent'], $c['progress'], $c['target_value'] ? $c['target_value'] . ' ' . $c['target_label'] : '', $c['reached']]); }
        $t = $cs['total'];
        fputcsv($out, ['', 'TOTAL', '', $t['budget'], $t['spent'], $t['advances'], $t['committed'], $t['pending'], $t['available'], $t['pct'], $t['budget'] - $t['spent']]);
    } elseif ($export === 'lines') {
        fputcsv($out, ['Activity', 'Code', 'Budget line', 'Description', 'Units', 'Unit cost (UGX)', 'Budget', 'Spent', 'Advances', 'Committed', 'Waiting', 'Available', 'Used %', 'Variance (budget − spent)']);
        foreach ($lines as $l) { $x = $ls[(int) $l['id']]; fputcsv($out, [$l['cluster_code'] . ' ' . $l['cluster_name'], $l['code'], $l['item'], $l['description'], (float) $l['units'], $l['unit_cost'], $x['budget'], $x['spent'], $x['advances'], $x['committed'], $x['pending'], $x['available'], $x['pct'], $x['budget'] - $x['spent']]); }
    } elseif ($export === 'expenses') {
        fputcsv($out, ['Date', 'Activity', 'Budget line code', 'Budget line', 'Paid to', 'Description', 'Amount (UGX)', 'Method', 'Reference', 'Invoice / receipt no.', 'Where', 'Spent by', 'Requisition', 'Receipts attached', 'Recorded by']);
        foreach ($expenses() as $e) { fputcsv($out, [$e['spent_on'], $e['cluster_code'] . ' ' . $e['cluster_name'], $e['code'], $e['item'], $e['payee'], $e['description'], $e['amount'], fin_methods()[$e['method']] ?? $e['method'], $e['reference'], $e['invoice_no'], $e['location'], $e['spent_by'], $e['req_ref'], $e['receipts'], $e['recorder']]); }
    } elseif ($export === 'requisitions') {
        fputcsv($out, ['Requisition', 'Requested on', 'Requested by', 'Purpose', 'Activity', 'Where', 'Requested (UGX)', 'Paid', 'Paid on', 'Method', 'Reference', 'Actually spent', 'Balance (paid − spent)', 'Status', 'Reviewed by', 'Approved by', 'Paid by', 'Verified by', 'Receipts', 'Balance note']);
        foreach ($requisitions() as $r) { fputcsv($out, [$r['reference'], substr($r['created_at'], 0, 10), $r['requester'], $r['title'], $r['cluster_code'], $r['location'], $r['total'], $r['disbursed_amount'], $r['disbursed_at'] ? substr($r['disbursed_at'], 0, 10) : '', fin_methods()[$r['disburse_method']] ?? '', $r['disburse_ref'], $r['actual_total'], $r['actual_total'] !== null ? (int) $r['disbursed_amount'] - (int) $r['actual_total'] : '', $statuses[$r['status']][0] ?? $r['status'], $r['reviewer'], $r['approver'], $r['payer'], $r['verifier'], $r['receipts'], $r['balance_note']]); }
    } elseif ($export === 'income') {
        fputcsv($out, ['Date', 'From', 'Description', 'Amount (UGX)', 'Method', 'Reference']);
        foreach (q('SELECT * FROM fin_income WHERE project_id = ? ORDER BY received_on', [$pid])->fetchAll() as $i) { fputcsv($out, [$i['received_on'], $i['source'], $i['description'], $i['amount'], fin_methods()[$i['method']] ?? $i['method'], $i['reference']]); }
    }
    exit;
}

$qs = array_filter($f);
$link = fn(array $extra) => 'fin-reports.php?' . http_build_query(array_filter($extra + $qs + ['view' => $view]));
$period = $f['from'] || $f['to'] ? ' · spending ' . ($f['from'] ? 'from ' . date('j M Y', strtotime($f['from'])) . ' ' : '') . ($f['to'] ? 'to ' . date('j M Y', strtotime($f['to'])) : '') : ' · to date';
$t = $cs['total'];
$income = fin_income_total($pid);

admin_header('Budget reports', 'fin-reports', $project['name'] . $period);
?>
<div class="print-head"><b><?= e($project['name']) ?></b> · <?= e($project['donor']) ?> · Kakebe Technologies Limited · printed <?= e(date('j M Y, g:i a')) ?> by <?= e($admin['name']) ?><?= e($period) ?></div>

<div class="tabs big no-print">
  <?php foreach (['summary' => 'Budget vs actual', 'expenses' => 'Expense register', 'requisitions' => 'Requisition register', 'income' => 'Funds received'] as $k => $label): ?><a href="<?= e($link(['view' => $k])) ?>" class="<?= $view === $k ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>

<form class="card filters no-print" method="get">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <select name="cluster"><option value="0">All activities</option><?php foreach ($clusters as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $f['cluster'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['code'] . ' ' . mb_strimwidth($c['name'], 0, 34, '…')) ?></option><?php endforeach; ?></select>
  <?php if ($view === 'expenses'): ?><div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Payee, description, reference…"></div><?php endif; ?>
  <label class="small">From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label class="small">To <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
  <?php if ($qs): ?><a href="fin-reports.php?view=<?= e($view) ?>" class="btn btn-light">Clear</a><?php endif; ?>
  <button type="button" class="btn btn-light" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / PDF</button>
</form>

<?php if ($view === 'summary'): ?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-sack-dollar"></i></span><div><small>Budget</small><b><?= e(ugx($t['budget'])) ?></b><em><?= usd_of($t['budget'], $project) ?></em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-building-columns"></i></span><div><small>Received</small><b><?= e(ugx($income)) ?></b><em><?= $t['budget'] ? round($income / $t['budget'] * 100, 1) : 0 ?>% of budget</em></div></div>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-receipt"></i></span><div><small>Spent<?= $f['from'] || $f['to'] ? ' in period' : '' ?></small><b><?= e(ugx($t['spent'])) ?></b><em>+ <?= e(ugx($t['advances'])) ?> advances out</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-piggy-bank"></i></span><div><small>Remaining (budget − spent)</small><b><?= e(ugx($t['budget'] - $t['spent'])) ?></b><em><?= e(ugx($t['available'])) ?> after advances &amp; commitments</em></div></div>
</div>

<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-layer-group"></i> By activity (cluster)</h3><a class="link no-print" href="<?= e($link(['export' => 'clusters'])) ?>"><i class="fa-solid fa-file-csv"></i> Export</a></div>
  <div class="table-wrap"><table class="table fin-table">
    <thead><tr><th>Activity</th><th class="r">Budget</th><th class="r">Spent</th><th class="r">Advances</th><th class="r">Committed</th><th class="r">Waiting</th><th class="r">Available</th><th class="r">Budget − spent</th><th style="min-width:120px">Used</th></tr></thead>
    <tbody><?php foreach ($clusters as $c): if ($f['cluster'] && $f['cluster'] !== (int) $c['id']) continue; $s = $cs[(int) $c['id']]; ?>
      <tr><td class="fin-name"><b><?= e($c['code']) ?></b> <?= e($c['name']) ?></td><td class="r nowrap"><?= number_format($s['budget']) ?></td><td class="r nowrap"><?= number_format($s['spent']) ?></td><td class="r nowrap"><?= number_format($s['advances']) ?></td><td class="r nowrap"><?= number_format($s['committed']) ?></td><td class="r nowrap"><?= number_format($s['pending']) ?></td><td class="r nowrap <?= $s['available'] < 0 ? 'due' : '' ?>"><b><?= number_format($s['available']) ?></b></td><td class="r nowrap"><?= number_format($s['budget'] - $s['spent']) ?></td><td><?= fin_bar((float) $s['pct']) ?><small><?= $s['pct'] ?>%</small></td></tr>
    <?php endforeach; ?></tbody>
    <?php if (!$f['cluster']): ?><tfoot><tr class="total"><td>Total (UGX)</td><td class="r nowrap"><?= number_format($t['budget']) ?></td><td class="r nowrap"><?= number_format($t['spent']) ?></td><td class="r nowrap"><?= number_format($t['advances']) ?></td><td class="r nowrap"><?= number_format($t['committed']) ?></td><td class="r nowrap"><?= number_format($t['pending']) ?></td><td class="r nowrap"><?= number_format($t['available']) ?></td><td class="r nowrap"><?= number_format($t['budget'] - $t['spent']) ?></td><td><?= $t['pct'] ?>%</td></tr></tfoot><?php endif; ?>
  </table></div>
</section>

<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-list-ol"></i> By budget line</h3><a class="link no-print" href="<?= e($link(['export' => 'lines'])) ?>"><i class="fa-solid fa-file-csv"></i> Export</a></div>
  <div class="table-wrap"><table class="table fin-table">
    <thead><tr><th>Code</th><th>Budget line</th><th class="r">Budget</th><th class="r">Spent</th><th class="r">Advances</th><th class="r">Committed &amp; waiting</th><th class="r">Available</th><th class="r">Variance</th><th>Used</th></tr></thead>
    <tbody>
    <?php foreach ($clusters as $c): if ($f['cluster'] && $f['cluster'] !== (int) $c['id']) continue; ?>
      <tr class="fin-group"><td colspan="9"><b><?= e($c['code']) ?> · <?= e($c['name']) ?></b></td></tr>
      <?php foreach ($lines as $l): if ((int) $l['cluster_id'] !== (int) $c['id']) continue; $x = $ls[(int) $l['id']]; ?>
      <tr class="<?= $x['available'] < 0 ? 'over' : '' ?>"><td class="nowrap"><a href="<?= e($link(['view' => 'expenses', 'line' => (int) $l['id']])) ?>"><?= e($l['code']) ?></a></td><td class="fin-name"><?= e($l['item']) ?></td><td class="r nowrap"><?= number_format($x['budget']) ?></td><td class="r nowrap"><?= number_format($x['spent']) ?></td><td class="r nowrap muted"><?= number_format($x['advances']) ?></td><td class="r nowrap muted"><?= number_format($x['committed'] + $x['pending']) ?></td><td class="r nowrap <?= $x['available'] < 0 ? 'due' : '' ?>"><b><?= number_format($x['available']) ?></b></td><td class="r nowrap"><?= number_format($x['budget'] - $x['spent']) ?></td><td class="nowrap"><?= $x['pct'] ?>%</td></tr>
      <?php endforeach; ?>
      <tr class="total"><td colspan="2">Sub-total <?= e($c['code']) ?></td><td class="r nowrap"><?= number_format($cs[(int) $c['id']]['budget']) ?></td><td class="r nowrap"><?= number_format($cs[(int) $c['id']]['spent']) ?></td><td class="r nowrap"><?= number_format($cs[(int) $c['id']]['advances']) ?></td><td class="r nowrap"><?= number_format($cs[(int) $c['id']]['committed'] + $cs[(int) $c['id']]['pending']) ?></td><td class="r nowrap"><?= number_format($cs[(int) $c['id']]['available']) ?></td><td class="r nowrap"><?= number_format($cs[(int) $c['id']]['budget'] - $cs[(int) $c['id']]['spent']) ?></td><td><?= $cs[(int) $c['id']]['pct'] ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="small muted">Spent = verified spending (direct payments and verified requisition accountability). Advances = money paid on requisitions whose accountability is not verified yet. Committed = approved but not paid. Waiting = requested, not yet approved. Available = budget minus all of these. Variance = budget − spent.</p>
</section>

<?php elseif ($view === 'expenses'): $rows = $expenses(); $sum = array_sum(array_column($rows, 'amount')); ?>
<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-receipt"></i> Expense register · <?= count($rows) ?> entries · <?= e(ugx($sum)) ?></h3><a class="link no-print" href="<?= e($link(['export' => 'expenses'])) ?>"><i class="fa-solid fa-file-csv"></i> Export</a></div>
  <?php if ($f['line'] && ($ln = array_values(array_filter($lines, fn($l) => (int) $l['id'] === $f['line'])))): ?><p class="small">Budget line <b><?= e($ln[0]['code'] . ' ' . $ln[0]['item']) ?></b> · budget <?= e(ugx($ls[$f['line']]['budget'])) ?> · <a class="no-print" href="<?= e($link(['line' => null])) ?>">show all lines</a></p><?php endif; ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Line</th><th>Paid to</th><th>Description</th><th>Spent by</th><th>Where</th><th>Method · ref</th><th class="r">Amount</th><th>Receipts</th><th>Source</th></tr></thead>
    <tbody><?php foreach ($rows as $e): ?><tr><td class="nowrap"><?= e(date('j M Y', strtotime($e['spent_on']))) ?></td><td class="nowrap small"><b><?= e($e['code']) ?></b></td><td><?= e($e['payee']) ?></td><td class="small"><?= e($e['description']) ?></td><td class="small"><?= e((string) $e['spent_by']) ?></td><td class="small"><?= e((string) $e['location']) ?></td><td class="small nowrap"><?= e(fin_methods()[$e['method']] ?? '') ?> <?= e((string) $e['reference']) ?></td><td class="r nowrap"><b><?= number_format((int) $e['amount']) ?></b></td><td class="<?= $e['receipts'] ? '' : 'due' ?>"><?= (int) $e['receipts'] ?: 'none' ?></td><td class="small"><?= $e['req_ref'] ? '<a href="requisition.php?id=' . (int) $e['requisition_id'] . '">' . e($e['req_ref']) . '</a>' : 'Direct' ?></td></tr><?php endforeach; ?></tbody>
    <tfoot><tr class="total"><td colspan="7" class="r">Total</td><td class="r nowrap"><?= number_format($sum) ?></td><td colspan="2"></td></tr></tfoot>
  </table></div>
</section>

<?php elseif ($view === 'requisitions'): $rows = $requisitions(); ?>
<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-file-signature"></i> Requisition register · <?= count($rows) ?></h3><a class="link no-print" href="<?= e($link(['export' => 'requisitions'])) ?>"><i class="fa-solid fa-file-csv"></i> Export</a></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Ref</th><th>Requested</th><th>By</th><th>Purpose</th><th class="r">Requested</th><th class="r">Paid</th><th class="r">Spent</th><th class="r">Balance</th><th>Status</th><th>Approvals</th><th>Receipts</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr class="row-link" data-href="requisition.php?id=<?= (int) $r['id'] ?>"><td><b><?= e($r['reference']) ?></b></td><td class="nowrap small"><?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td class="small"><?= e((string) $r['requester']) ?></td><td class="small"><?= e(mb_strimwidth($r['title'], 0, 60, '…')) ?><small class="block muted"><?= e($r['cluster_code'] . ' · ' . $r['location']) ?></small></td><td class="r nowrap"><?= number_format((int) $r['total']) ?></td><td class="r nowrap"><?= $r['disbursed_amount'] !== null ? number_format((int) $r['disbursed_amount']) : '—' ?></td><td class="r nowrap"><?= $r['actual_total'] !== null ? number_format((int) $r['actual_total']) : '—' ?></td><td class="r nowrap"><?= $r['actual_total'] !== null ? number_format((int) $r['disbursed_amount'] - (int) $r['actual_total']) : '—' ?></td><td><span class="badge <?= $statuses[$r['status']][1] ?? '' ?>"><?= e(explode(' — ', $statuses[$r['status']][0] ?? $r['status'])[0]) ?></span></td><td class="small muted"><?= e(implode(' → ', array_filter([$r['reviewer'] ? 'R: ' . $r['reviewer'] : '', $r['approver'] ? 'A: ' . $r['approver'] : '', $r['payer'] ? 'P: ' . $r['payer'] : '', $r['verifier'] ? 'V: ' . $r['verifier'] : '']))) ?></td><td class="<?= in_array($r['status'], ['accounted', 'closed'], true) && !$r['receipts'] ? 'due' : '' ?>"><?= (int) $r['receipts'] ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="small muted">Approvals: R = reviewed (operations), A = approved (team lead), P = paid (finance), V = accountability verified (finance).</p>
</section>

<?php else: $rows = q('SELECT * FROM fin_income WHERE project_id = ? ORDER BY received_on', [$pid])->fetchAll(); ?>
<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-building-columns"></i> Funds received · <?= e(ugx($income)) ?></h3><a class="link no-print" href="<?= e($link(['export' => 'income'])) ?>"><i class="fa-solid fa-file-csv"></i> Export</a></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>From</th><th>Description</th><th>Reference</th><th class="r">Amount</th></tr></thead>
    <tbody><?php foreach ($rows as $i): ?><tr><td><?= e(date('j M Y', strtotime($i['received_on']))) ?></td><td><?= e($i['source']) ?></td><td><?= e((string) $i['description']) ?></td><td><?= e((string) $i['reference']) ?></td><td class="r"><b><?= number_format((int) $i['amount']) ?></b></td></tr><?php endforeach; ?></tbody>
    <tfoot><tr class="total"><td colspan="4" class="r">Total received</td><td class="r"><?= number_format($income) ?></td></tr></tfoot></table></div>
</section>
<?php endif; ?>
<?php admin_footer();
