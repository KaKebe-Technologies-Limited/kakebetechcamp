<?php
require __DIR__ . '/_init.php';
$admin = require_finance();
$project = fin_project();
$pid = (int) $project['id'];
$me = (int) $admin['id'];
$clusters = fin_clusters($pid);
$lines = fin_lines($pid);
$lineById = array_column($lines, null, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $str = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $lineId = (int) ($_POST['line_id'] ?? 0);
        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['spent_on'] ?? '')) ? $_POST['spent_on'] : '';
        if (!isset($lineById[$lineId]) || $amount <= 0 || $date === '' || $str('payee', 150) === '' || $str('description', 500) === '') {
            flash('Choose the budget line and enter the date, who was paid, what for and the amount.', 'error');
            redirect('fin-expenses.php?new=1');
        }
        $method = isset(fin_methods()[$_POST['method'] ?? '']) ? $_POST['method'] : null;
        $vals = [$lineId, $date, $str('payee', 150), $str('description', 500), $amount, $method, $str('reference', 120) ?: null, $str('invoice_no', 80) ?: null, $str('location', 150) ?: null, $str('spent_by', 150) ?: null];
        if ($id) {
            $old = q('SELECT * FROM fin_expenses WHERE id = ? AND project_id = ? AND requisition_id IS NULL', [$id, $pid])->fetch();
            if (!$old) {
                redirect('fin-expenses.php');
            }
            q('UPDATE fin_expenses SET line_id = ?, spent_on = ?, payee = ?, description = ?, amount = ?, method = ?, reference = ?, invoice_no = ?, location = ?, spent_by = ? WHERE id = ?', array_merge($vals, [$id]));
            fin_log($pid, 'expense', $id, 'edited', ugx($old['amount']) . ' → ' . ugx($amount) . ' · ' . $str('description', 200), $me);
        } else {
            q('INSERT INTO fin_expenses (line_id, spent_on, payee, description, amount, method, reference, invoice_no, location, spent_by, project_id, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [$pid, $me, now()]));
            $id = (int) db()->lastInsertId();
            fin_log($pid, 'expense', $id, 'recorded', ugx($amount) . ' to ' . $str('payee', 150) . ' on ' . $lineById[$lineId]['code'], $me);
        }
        [$saved, $problems] = fin_store_files('receipts', 'expense', $id, $me);
        flash('Expense saved' . ($saved ? ' with ' . $saved . ' file' . ($saved === 1 ? '' : 's') : (fin_files('expense', $id) ? '' : ' — remember to attach the receipt')) . '.' . ($problems ? ' Not attached: ' . implode('; ', $problems) : ''), $problems ? 'error' : 'success');
        redirect('fin-expenses.php');
    }

    if ($action === 'attach') {
        $id = (int) ($_POST['id'] ?? 0);
        if (q('SELECT id FROM fin_expenses WHERE id = ? AND project_id = ?', [$id, $pid])->fetchColumn()) {
            [$saved, $problems] = fin_store_files('receipts', 'expense', $id, $me);
            flash($saved ? $saved . ' file' . ($saved === 1 ? '' : 's') . ' attached.' : 'Choose a file to attach.' . ($problems ? ' ' . implode('; ', $problems) : ''), $saved ? 'success' : 'error');
        }
        redirect('fin-expenses.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : ''));
    }

    if ($action === 'delete') {
        $e = q('SELECT * FROM fin_expenses WHERE id = ? AND project_id = ? AND requisition_id IS NULL', [(int) ($_POST['id'] ?? 0), $pid])->fetch();
        if ($e) {
            q('DELETE FROM fin_expenses WHERE id = ?', [$e['id']]);
            fin_log($pid, 'expense', (int) $e['id'], 'deleted', ugx($e['amount']) . ' to ' . $e['payee'] . ' — ' . $e['description'], $me);
            flash('Expense deleted (the deletion is recorded in the history).');
        }
        redirect('fin-expenses.php');
    }
    redirect('fin-expenses.php');
}

/* ---------- Filters ---------- */
$f = [
    'cluster'  => (int) ($_GET['cluster'] ?? 0),
    'line'     => (int) ($_GET['line'] ?? 0),
    'from'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '',
    'to'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '',
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'receipts' => ($_GET['receipts'] ?? '') === 'missing' ? 'missing' : '',
];
$where = ['e.project_id = ?'];
$params = [$pid];
if ($f['line']) { $where[] = 'e.line_id = ?'; $params[] = $f['line']; }
elseif ($f['cluster']) { $where[] = 'l.cluster_id = ?'; $params[] = $f['cluster']; }
if ($f['from']) { $where[] = 'e.spent_on >= ?'; $params[] = $f['from']; }
if ($f['to']) { $where[] = 'e.spent_on <= ?'; $params[] = $f['to']; }
if ($f['q'] !== '') { $where[] = '(e.payee LIKE ? OR e.description LIKE ? OR e.reference LIKE ? OR e.invoice_no LIKE ? OR e.spent_by LIKE ?)'; array_push($params, ...array_fill(0, 5, '%' . $f['q'] . '%')); }
if ($f['receipts']) { $where[] = "e.requisition_id IS NULL AND NOT EXISTS (SELECT 1 FROM fin_files ff WHERE ff.owner_type = 'expense' AND ff.owner_id = e.id)"; }
$sqlWhere = implode(' AND ', $where);
$rows = q("SELECT e.*, l.code, l.item, c.code cluster_code, r.reference req_ref FROM fin_expenses e JOIN fin_lines l ON l.id = e.line_id JOIN fin_clusters c ON c.id = l.cluster_id LEFT JOIN fin_requisitions r ON r.id = e.requisition_id WHERE $sqlWhere ORDER BY e.spent_on DESC, e.id DESC LIMIT 500", $params)->fetchAll();
$sum = array_sum(array_column($rows, 'amount'));
$qs = array_filter($f);
$qsString = $qs ? '?' . http_build_query($qs) : '';
$edit = isset($_GET['edit']) ? q('SELECT * FROM fin_expenses WHERE id = ? AND project_id = ? AND requisition_id IS NULL', [(int) $_GET['edit'], $pid])->fetch() : null;
$showForm = isset($_GET['new']) || $edit;
$e0 = $edit ?: ['id' => 0, 'line_id' => $f['line'], 'spent_on' => date('Y-m-d'), 'payee' => '', 'description' => '', 'amount' => '', 'method' => 'mobile_money', 'reference' => '', 'invoice_no' => '', 'location' => '', 'spent_by' => ''];

admin_header('Expenses & receipts', 'fin-expenses', 'Every payment and invoice, attached to its budget line — with receipts');
?>
<?php if ($showForm): ?>
<section class="card" id="form">
  <div class="card-head"><h3><i class="fa-solid fa-receipt"></i> <?= $edit ? 'Edit expense' : 'Record a payment / invoice' ?></h3><a href="fin-expenses.php" class="link">Cancel</a></div>
  <p class="small muted">Use this for payments made directly by finance (suppliers, invoices, salaries). Field spending comes in automatically when a requisition's accountability is verified.</p>
  <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $e0['id'] ?>">
    <label>Budget line (what it was for)
      <select name="line_id" required><option value="">Choose…</option>
        <?php foreach ($clusters as $c): ?><optgroup label="<?= e($c['code'] . ' · ' . $c['name']) ?>"><?php foreach ($lines as $l): if ((int) $l['cluster_id'] !== (int) $c['id']) continue; ?><option value="<?= (int) $l['id'] ?>"<?= (int) $e0['line_id'] === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['code'] . ' ' . $l['item']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
      </select>
    </label>
    <div class="row-3">
      <label>Date<input type="date" name="spent_on" value="<?= e($e0['spent_on']) ?>" required></label>
      <label>Amount (UGX)<input type="text" name="amount" value="<?= $e0['amount'] ? number_format((int) $e0['amount']) : '' ?>" class="js-money" inputmode="numeric" required></label>
      <label>Paid by<select name="method"><?php foreach (fin_methods() as $k => $m): ?><option value="<?= e($k) ?>"<?= $e0['method'] === $k ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="row-2">
      <label>Paid to (supplier / person)<input type="text" name="payee" value="<?= e($e0['payee']) ?>" maxlength="150" required></label>
      <label>What for<input type="text" name="description" value="<?= e($e0['description']) ?>" maxlength="500" required></label>
    </div>
    <div class="row-2">
      <label>Invoice / receipt no. <small class="muted">(optional)</small><input type="text" name="invoice_no" value="<?= e((string) $e0['invoice_no']) ?>" maxlength="80"></label>
      <label>Transaction reference <small class="muted">(optional)</small><input type="text" name="reference" value="<?= e((string) $e0['reference']) ?>" maxlength="120"></label>
    </div>
    <div class="row-2">
      <label>Where <small class="muted">(optional)</small><input type="text" name="location" value="<?= e((string) $e0['location']) ?>" maxlength="150"></label>
      <label>Spent / handled by <small class="muted">(optional)</small><input type="text" name="spent_by" value="<?= e((string) ($e0['spent_by'] ?: '')) ?>" maxlength="150" placeholder="<?= e($admin['name']) ?>"></label>
    </div>
    <label>Receipt / invoice files <small class="muted">(photos or PDF, several at once)</small><input type="file" name="receipts[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.docx,.xlsx"></label>
    <?php if ($edit && ($ef = fin_files('expense', (int) $edit['id']))): ?><p class="small">Attached: <?= fin_files_html($ef) ?></p><?php endif; ?>
    <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save expense</button></div>
  </form>
</section>
<?php else: ?>
<div class="req-new-bar"><a class="btn btn-primary" href="fin-expenses.php?new=1#form"><i class="fa-solid fa-plus"></i> Record a payment / invoice</a></div>
<?php endif; ?>

<form class="card filters" method="get">
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Payee, description, reference, invoice, who spent…"></div>
  <select name="cluster"><option value="0">All activities</option><?php foreach ($clusters as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $f['cluster'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['code'] . ' ' . mb_strimwidth($c['name'], 0, 32, '…')) ?></option><?php endforeach; ?></select>
  <label class="small">From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label class="small">To <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <label class="check-inline small"><input type="checkbox" name="receipts" value="missing"<?= $f['receipts'] ? ' checked' : '' ?>> No receipt</label>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($qs): ?><a href="fin-expenses.php" class="btn btn-light">Clear</a><?php endif; ?>
  <a href="fin-reports.php?export=expenses&amp;<?= e(http_build_query($qs)) ?>" class="btn btn-light"><i class="fa-solid fa-file-csv"></i> Export</a>
</form>

<div class="card">
  <div class="card-head list-head"><h3><?= number_format(count($rows)) ?> expense<?= count($rows) === 1 ? '' : 's' ?> · <?= e(ugx($sum)) ?></h3></div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Date</th><th>Budget line</th><th>Paid to · what for</th><th>Spent by · where</th><th>Method · ref</th><th class="r">Amount</th><th>Receipts</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $e): $files = $e['requisition_id'] ? fin_files('req_receipt', (int) $e['requisition_id']) : fin_files('expense', (int) $e['id']); ?>
        <tr>
          <td class="nowrap"><?= e(date('j M Y', strtotime($e['spent_on']))) ?></td>
          <td class="small"><b><?= e($e['code']) ?></b> <?= e(mb_strimwidth($e['item'], 0, 30, '…')) ?></td>
          <td><b><?= e($e['payee']) ?></b><small class="block muted"><?= e(mb_strimwidth($e['description'], 0, 90, '…')) ?></small></td>
          <td class="small"><?= e((string) $e['spent_by']) ?><small class="block muted"><?= e((string) $e['location']) ?></small></td>
          <td class="small nowrap"><?= e(fin_methods()[$e['method']] ?? '') ?><small class="block muted"><?= e(trim(($e['invoice_no'] ? 'Inv ' . $e['invoice_no'] . ' ' : '') . (string) $e['reference'])) ?></small></td>
          <td class="r nowrap"><b><?= number_format((int) $e['amount']) ?></b></td>
          <td><?= $files ? fin_files_html($files) : '<span class="badge st-cancelled">Missing</span>' ?></td>
          <td class="nowrap actions">
            <?php if ($e['requisition_id']): ?>
              <a class="btn btn-light btn-sm" href="requisition.php?id=<?= (int) $e['requisition_id'] ?>"><?= e($e['req_ref']) ?></a>
            <?php else: ?>
              <a class="icon-btn" href="fin-expenses.php?edit=<?= (int) $e['id'] ?>#form" title="Edit"><i class="fa-solid fa-pen"></i></a>
              <form method="post" enctype="multipart/form-data" class="inline fin-attach"><?= csrf_field() ?><input type="hidden" name="action" value="attach"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><input type="hidden" name="return" value="<?= e($qsString) ?>"><label class="icon-btn" title="Attach a receipt"><i class="fa-solid fa-paperclip"></i><input type="file" name="receipts[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" onchange="this.form.submit()" hidden></label></form>
              <form method="post" class="inline" data-confirm="Delete this expense? The deletion is recorded."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><button class="icon-btn danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total"><td colspan="5" class="r">Total</td><td class="r nowrap"><?= number_format($sum) ?></td><td colspan="2"></td></tr></tfoot>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-receipt"></i><p><?= $qs ? 'No expenses match these filters.' : 'No spending recorded yet.' ?></p></div>
  <?php endif; ?>
</div>
<?php admin_footer(['finance.js']);
