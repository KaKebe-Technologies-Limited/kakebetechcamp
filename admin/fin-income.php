<?php
require __DIR__ . '/_init.php';
$admin = require_finance();
$project = fin_project();
$pid = (int) $project['id'];
$me = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $str = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['received_on'] ?? '')) ? $_POST['received_on'] : '';
        if ($amount <= 0 || $date === '' || $str('source', 150) === '') {
            flash('Enter the date, who sent the money and the amount.', 'error');
            redirect('fin-income.php');
        }
        $method = isset(fin_methods()[$_POST['method'] ?? '']) ? $_POST['method'] : 'bank';
        $vals = [$date, $str('source', 150), $str('description', 255) ?: null, $amount, $method, $str('reference', 120) ?: null];
        if ($id) {
            $old = q('SELECT * FROM fin_income WHERE id = ? AND project_id = ?', [$id, $pid])->fetch();
            if ($old) {
                q('UPDATE fin_income SET received_on = ?, source = ?, description = ?, amount = ?, method = ?, reference = ? WHERE id = ?', array_merge($vals, [$id]));
                fin_log($pid, 'income', $id, 'edited', ugx($old['amount']) . ' → ' . ugx($amount), $me);
            }
        } else {
            q('INSERT INTO fin_income (received_on, source, description, amount, method, reference, project_id, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$pid, $me, now()]));
            $id = (int) db()->lastInsertId();
            fin_log($pid, 'income', $id, 'recorded', ugx($amount) . ' from ' . $str('source', 150), $me);
        }
        [, $problems] = fin_store_files('files', 'income', $id, $me);
        flash('Funds saved.' . ($problems ? ' Not attached: ' . implode('; ', $problems) : ''), $problems ? 'error' : 'success');
    } elseif ($action === 'delete') {
        $i = q('SELECT * FROM fin_income WHERE id = ? AND project_id = ?', [(int) ($_POST['id'] ?? 0), $pid])->fetch();
        if ($i) {
            q('DELETE FROM fin_income WHERE id = ?', [$i['id']]);
            fin_log($pid, 'income', (int) $i['id'], 'deleted', ugx($i['amount']) . ' from ' . $i['source'], $me);
            flash('Entry deleted (recorded in the history).');
        }
    }
    redirect('fin-income.php');
}

$rows = q('SELECT i.*, a.name recorder FROM fin_income i LEFT JOIN admins a ON a.id = i.recorded_by WHERE i.project_id = ? ORDER BY i.received_on DESC, i.id DESC', [$pid])->fetchAll();
$received = array_sum(array_column($rows, 'amount'));
$budget = fin_cluster_stats($pid)['total'];
$edit = isset($_GET['edit']) ? q('SELECT * FROM fin_income WHERE id = ? AND project_id = ?', [(int) $_GET['edit'], $pid])->fetch() : null;
$i0 = $edit ?: ['id' => 0, 'received_on' => date('Y-m-d'), 'source' => $project['donor'] ?: 'UNDP', 'description' => 'Tranche ' . (count($rows) + 1), 'amount' => '', 'method' => 'bank', 'reference' => ''];

admin_header('Funds received', 'fin-income', 'Money received for ' . $project['name']);
?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-sack-dollar"></i></span><div><small>Approved budget</small><b><?= e(ugx($budget['budget'])) ?></b><em><?= usd_of($budget['budget'], $project) ?></em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-building-columns"></i></span><div><small>Received</small><b><?= e(ugx($received)) ?></b><em><?= $budget['budget'] ? round($received / $budget['budget'] * 100, 1) : 0 ?>% of budget · <?= count($rows) ?> payment<?= count($rows) === 1 ? '' : 's' ?></em></div></div>
  <div class="kpi"><span class="kpi-icon amber"><i class="fa-solid fa-hourglass-half"></i></span><div><small>Still to receive</small><b><?= e(ugx(max(0, $budget['budget'] - $received))) ?></b><em>budget − received</em></div></div>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-wallet"></i></span><div><small>Cash at hand</small><b><?= e(ugx($received - $budget['spent'] - $budget['advances'])) ?></b><em>received − spent − advances out</em></div></div>
</div>

<div class="grid-3-1">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-list"></i> Payments received</h3></div>
    <?php if ($rows): ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Date</th><th>From</th><th>Description</th><th>Method · ref</th><th class="r">Amount</th><th>Documents</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i): ?>
        <tr>
          <td class="nowrap"><?= e(date('j M Y', strtotime($i['received_on']))) ?></td>
          <td><b><?= e($i['source']) ?></b><small class="block muted">recorded by <?= e((string) $i['recorder']) ?></small></td>
          <td><?= e((string) $i['description']) ?></td>
          <td class="small"><?= e(fin_methods()[$i['method']] ?? '') ?><small class="block muted"><?= e((string) $i['reference']) ?></small></td>
          <td class="r nowrap"><b><?= number_format((int) $i['amount']) ?></b><small class="block muted"><?= usd_of((int) $i['amount'], $project) ?></small></td>
          <td><?= fin_files_html(fin_files('income', (int) $i['id'])) ?></td>
          <td class="nowrap actions"><a class="icon-btn" href="fin-income.php?edit=<?= (int) $i['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a><form method="post" class="inline" data-confirm="Delete this entry?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><button class="icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total"><td colspan="4" class="r">Total received</td><td class="r nowrap"><?= number_format($received) ?></td><td colspan="2"></td></tr></tfoot>
    </table></div>
    <?php else: ?><div class="empty-state"><i class="fa-solid fa-building-columns"></i><p>No funds recorded yet. Record each tranche from the donor as it arrives.</p></div><?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-plus"></i> <?= $edit ? 'Edit entry' : 'Record funds received' ?></h3><?php if ($edit): ?><a class="link" href="fin-income.php">Cancel</a><?php endif; ?></div>
    <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $i0['id'] ?>">
      <label>Date received<input type="date" name="received_on" value="<?= e($i0['received_on']) ?>" required></label>
      <label>From<input type="text" name="source" value="<?= e($i0['source']) ?>" maxlength="150" required></label>
      <label>Description<input type="text" name="description" value="<?= e((string) $i0['description']) ?>" maxlength="255"></label>
      <label>Amount (UGX)<input type="text" name="amount" value="<?= $i0['amount'] ? number_format((int) $i0['amount']) : '' ?>" class="js-money" inputmode="numeric" required></label>
      <label>Received by<select name="method"><?php foreach (fin_methods() as $k => $m): ?><option value="<?= e($k) ?>"<?= $i0['method'] === $k ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
      <label>Reference <small class="muted">(bank ref)</small><input type="text" name="reference" value="<?= e((string) $i0['reference']) ?>" maxlength="120"></label>
      <label>Documents <small class="muted">(bank advice, agreement — optional)</small><input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"></label>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    </form>
  </section>
</div>
<?php admin_footer(['finance.js']);
