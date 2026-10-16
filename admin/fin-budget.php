<?php
require __DIR__ . '/_init.php';
$admin = require_finance();
$project = fin_project();
$pid = (int) $project['id'];
$me = (int) $admin['id'];
$monthsAll = fin_months();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $str = static fn(string $k, int $max): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    $back = 'fin-budget.php';

    if ($action === 'line_save') {
        $id = (int) ($_POST['line_id'] ?? 0);
        $cid = (int) ($_POST['cluster_id'] ?? 0);
        $units = max(0, (float) str_replace(',', '', (string) ($_POST['units'] ?? '1')));
        $cost = (int) preg_replace('/\D/', '', (string) ($_POST['unit_cost'] ?? '0'));
        $amount = (int) round($units * $cost);
        $code = $str('code', 20);
        $item = $str('item', 200);
        if ($item === '' || $code === '') {
            flash('A budget line needs a code and an item name.', 'error');
            redirect($back);
        }
        if ($id) {
            $old = q('SELECT * FROM fin_lines WHERE id = ? AND project_id = ?', [$id, $pid])->fetch();
            if ($old) {
                q('UPDATE fin_lines SET code = ?, item = ?, description = ?, units = ?, unit_cost = ?, amount = ?, updated_at = ? WHERE id = ?', [$code, $item, $str('description', 1000) ?: null, $units, $cost, $amount, now(), $id]);
                fin_log($pid, 'line', $id, 'edited', $code . ' ' . $item . ($amount !== (int) $old['amount'] ? ': budget ' . ugx($old['amount']) . ' → ' . ugx($amount) : ''), $me);
                flash($code . ' updated.');
                $back .= '#line-' . $id;
            }
        } elseif (q('SELECT id FROM fin_clusters WHERE id = ? AND project_id = ?', [$cid, $pid])->fetchColumn()) {
            $sort = (int) q('SELECT COALESCE(MAX(sort), 0) + 10 FROM fin_lines WHERE cluster_id = ?', [$cid])->fetchColumn();
            q('INSERT INTO fin_lines (project_id, cluster_id, code, item, description, units, unit_cost, amount, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $cid, $code, $item, $str('description', 1000) ?: null, $units, $cost, $amount, $sort, now()]);
            $newId = (int) db()->lastInsertId();
            fin_log($pid, 'line', $newId, 'added', $code . ' ' . $item . ': ' . ugx($amount), $me);
            flash($code . ' added with a budget of ' . ugx($amount) . '.');
            $back .= '#line-' . $newId;
        }
        redirect($back);
    }

    if ($action === 'line_delete') {
        $id = (int) ($_POST['line_id'] ?? 0);
        $used = (int) q('SELECT COUNT(*) FROM fin_expenses WHERE line_id = ?', [$id])->fetchColumn() + (int) q('SELECT COUNT(*) FROM fin_req_items WHERE line_id = ?', [$id])->fetchColumn();
        $l = q('SELECT * FROM fin_lines WHERE id = ? AND project_id = ?', [$id, $pid])->fetch();
        if ($l && !$used) {
            q('DELETE FROM fin_lines WHERE id = ?', [$id]);
            fin_log($pid, 'line', $id, 'deleted', $l['code'] . ' ' . $l['item'] . ' (' . ugx($l['amount']) . ')', $me);
            flash($l['code'] . ' removed.');
        } else {
            flash('This line has spending or requisitions on it, so it cannot be removed. Set its budget to what is needed instead.', 'error');
        }
        redirect($back);
    }

    if ($action === 'progress') {
        $cid = (int) ($_POST['cluster_id'] ?? 0);
        $c = q('SELECT * FROM fin_clusters WHERE id = ? AND project_id = ?', [$cid, $pid])->fetch();
        if ($c) {
            $progress = max(0, min(100, (int) ($_POST['progress'] ?? 0)));
            $reached = $_POST['reached'] !== '' ? max(0, (int) $_POST['reached']) : null;
            q('UPDATE fin_clusters SET progress = ?, reached = COALESCE(?, reached) WHERE id = ?', [$progress, $reached, $cid]);
            q('INSERT INTO fin_updates (cluster_id, progress, reached, note, admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$cid, $progress, $reached, $str('note', 2000) ?: null, $me, now()]);
            flash('Progress updated for ' . $c['code'] . '.');
        }
        redirect($back . '#cluster-' . $cid);
    }

    if ($action === 'cluster_save') {
        $cid = (int) ($_POST['cluster_id'] ?? 0);
        $months = implode(',', array_values(array_intersect(array_keys($monthsAll), (array) ($_POST['months'] ?? []))));
        q('UPDATE fin_clusters SET code = ?, name = ?, description = ?, months = ?, responsible = ?, target_label = ?, target_value = ? WHERE id = ? AND project_id = ?', [
            $str('code', 20), $str('name', 200), $str('description', 3000) ?: null, $months, $str('responsible', 120) ?: null, $str('target_label', 120) ?: null, (int) ($_POST['target_value'] ?? 0) ?: null, $cid, $pid,
        ]);
        fin_log($pid, 'cluster', $cid, 'edited', $str('code', 20) . ' ' . $str('name', 200), $me);
        flash('Activity updated.');
        redirect($back . '#cluster-' . $cid);
    }

    if ($action === 'project_save') {
        $rate = (float) str_replace(',', '', (string) ($_POST['exchange_rate'] ?? '0'));
        q('UPDATE fin_projects SET name = ?, donor = ?, funding_source = ?, location = ?, exchange_rate = ?, starts_on = ?, ends_on = ? WHERE id = ?', [
            $str('name', 200), $str('donor', 120), $str('funding_source', 160), $str('location', 120), $rate > 0 ? $rate : $project['exchange_rate'],
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['starts_on'] ?? '')) ? $_POST['starts_on'] : $project['starts_on'],
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['ends_on'] ?? '')) ? $_POST['ends_on'] : $project['ends_on'], $pid,
        ]);
        fin_log($pid, 'project', $pid, 'edited', 'Project details updated', $me);
        flash('Project details saved.');
        redirect($back);
    }
    redirect($back);
}

$clusters = fin_clusters($pid);
$lines = fin_lines($pid);
$ls = fin_line_stats($pid);
$cs = fin_cluster_stats($pid, $ls);
$editId = (int) ($_GET['edit'] ?? 0);
$updates = [];
foreach (q('SELECT u.*, a.name admin_name FROM fin_updates u LEFT JOIN admins a ON a.id = u.admin_id JOIN fin_clusters c ON c.id = u.cluster_id WHERE c.project_id = ? ORDER BY u.id DESC', [$pid])->fetchAll() as $u) {
    $updates[(int) $u['cluster_id']][] = $u;
}
$changes = q("SELECT l.*, a.name admin_name FROM fin_log l LEFT JOIN admins a ON a.id = l.admin_id WHERE l.project_id = ? AND l.entity IN ('line', 'cluster', 'project') ORDER BY l.id DESC LIMIT 15", [$pid])->fetchAll();

admin_header('Workplan & budget', 'fin-budget', $project['name'] . ' · ' . ugx($cs['total']['budget']) . ' (' . usd_of($cs['total']['budget'], $project) . ')');
?>
<details class="card fin-outcome">
  <summary><i class="fa-solid fa-bullseye"></i> Project outcome, output &amp; details</summary>
  <p><?= e((string) $project['outcome']) ?></p>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="project_save">
    <div class="row-3">
      <label>Project name<input type="text" name="name" value="<?= e($project['name']) ?>" maxlength="200"></label>
      <label>Donor<input type="text" name="donor" value="<?= e($project['donor']) ?>" maxlength="120"></label>
      <label>Funding source<input type="text" name="funding_source" value="<?= e($project['funding_source']) ?>" maxlength="160"></label>
    </div>
    <div class="row-3">
      <label>Location<input type="text" name="location" value="<?= e($project['location']) ?>" maxlength="120"></label>
      <label>Starts<input type="date" name="starts_on" value="<?= e($project['starts_on']) ?>"></label>
      <label>Ends<input type="date" name="ends_on" value="<?= e($project['ends_on']) ?>"></label>
    </div>
    <label>Exchange rate (UGX per $1)<input type="text" name="exchange_rate" value="<?= e((string) (float) $project['exchange_rate']) ?>"></label>
    <div><button class="btn btn-navy btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save project details</button></div>
  </form>
</details>

<?php foreach ($clusters as $c): $s = $cs[(int) $c['id']] ?? ['budget' => 0, 'spent' => 0, 'advances' => 0, 'committed' => 0, 'pending' => 0, 'available' => 0, 'pct' => 0]; $on = array_filter(explode(',', (string) $c['months'])); ?>
<section class="card fin-cluster" id="cluster-<?= (int) $c['id'] ?>">
  <div class="fin-cluster-head">
    <div>
      <span class="fin-code"><?= e($c['code']) ?></span>
      <h3><?= e($c['name']) ?></h3>
      <div class="fin-meta">
        <?php foreach ($on as $m): ?><span class="tag"><?= e($m) ?></span><?php endforeach; ?>
        <span class="muted small"><i class="fa-solid fa-user"></i> <?= e($c['responsible'] ?: '—') ?> · <?= e($c['budget_category'] ?: '') ?></span>
      </div>
    </div>
    <div class="fin-cluster-money">
      <div><small>Budget</small><b><?= e(ugx($s['budget'])) ?></b><span class="muted small"><?= usd_of($s['budget'], $project) ?></span></div>
      <div><small>Used</small><b><?= e(ugx($s['spent'] + $s['advances'])) ?></b><span class="muted small"><?= $s['pct'] ?>%</span></div>
      <div><small>Available</small><b class="<?= $s['available'] < 0 ? 'due' : 'ok-text' ?>"><?= e(ugx($s['available'])) ?></b></div>
    </div>
  </div>
  <?= fin_bar((float) $s['pct']) ?>
  <div class="fin-progress">
    <span><b><?= (int) $c['progress'] ?>%</b> activity progress</span>
    <?php if ($c['target_value']): ?><span><b><?= number_format((int) $c['reached']) ?></b> of <?= number_format((int) $c['target_value']) ?> <?= e($c['target_label']) ?></span><?php endif; ?>
    <?php if (!empty($updates[(int) $c['id']])): $u = $updates[(int) $c['id']][0]; ?><span class="muted small">Last update <?= e(time_ago($u['created_at'])) ?> by <?= e((string) $u['admin_name']) ?></span><?php endif; ?>
  </div>
  <details class="fin-desc"><summary>Activity description &amp; output</summary><p><?= e((string) $c['description']) ?></p><p class="muted small"><?= e((string) $c['output']) ?></p></details>

  <div class="table-wrap">
    <table class="table fin-table">
      <thead><tr><th>Code</th><th>Budget line</th><th class="r">Units × unit cost</th><th class="r">Budget</th><th class="r">Spent</th><th class="r">Advances</th><th class="r">Committed &amp; waiting</th><th class="r">Available</th><th style="min-width:110px">Used</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): if ((int) $l['cluster_id'] !== (int) $c['id']) continue; $x = $ls[(int) $l['id']]; ?>
        <?php if ($editId === (int) $l['id']): ?>
        <tr id="line-<?= (int) $l['id'] ?>" class="fin-edit-row"><td colspan="10">
          <form method="post" class="fin-line-form"><?= csrf_field() ?><input type="hidden" name="action" value="line_save"><input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>">
            <input type="text" name="code" value="<?= e($l['code']) ?>" maxlength="20" aria-label="Code" required>
            <input type="text" name="item" value="<?= e($l['item']) ?>" maxlength="200" aria-label="Item" required>
            <input type="text" name="description" value="<?= e((string) $l['description']) ?>" maxlength="1000" aria-label="Description" placeholder="Description">
            <input type="number" name="units" value="<?= e((string) (float) $l['units']) ?>" step="0.01" min="0" aria-label="Units">
            <input type="text" name="unit_cost" value="<?= number_format((int) $l['unit_cost']) ?>" class="js-money" inputmode="numeric" aria-label="Unit cost (UGX)">
            <button class="btn btn-primary btn-sm" type="submit">Save</button><a class="btn btn-light btn-sm" href="fin-budget.php#line-<?= (int) $l['id'] ?>">Cancel</a>
          </form>
        </td></tr>
        <?php else: ?>
        <tr id="line-<?= (int) $l['id'] ?>" class="<?= $x['available'] < 0 ? 'over' : '' ?>">
          <td class="nowrap"><b><?= e($l['code']) ?></b></td>
          <td class="fin-name"><?= e($l['item']) ?><small class="block muted"><?= e(mb_strimwidth((string) $l['description'], 0, 110, '…')) ?></small></td>
          <td class="r nowrap muted"><?= e(rtrim(rtrim((string) $l['units'], '0'), '.')) ?> × <?= number_format((int) $l['unit_cost']) ?></td>
          <td class="r nowrap"><b><?= number_format($x['budget']) ?></b></td>
          <td class="r nowrap"><?= number_format($x['spent']) ?></td>
          <td class="r nowrap muted"><?= number_format($x['advances']) ?></td>
          <td class="r nowrap muted"><?= number_format($x['committed'] + $x['pending']) ?></td>
          <td class="r nowrap <?= $x['available'] < 0 ? 'due' : '' ?>"><b><?= number_format($x['available']) ?></b></td>
          <td><?= fin_bar((float) $x['pct']) ?><small class="muted"><?= $x['pct'] ?>%</small></td>
          <td class="nowrap actions"><a class="icon-btn" href="fin-budget.php?edit=<?= (int) $l['id'] ?>#line-<?= (int) $l['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a><a class="icon-btn" href="fin-reports.php?view=expenses&amp;line=<?= (int) $l['id'] ?>" title="Spending on this line"><i class="fa-solid fa-receipt"></i></a></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total"><td colspan="3">Sub-total</td><td class="r nowrap"><?= number_format($s['budget']) ?></td><td class="r nowrap"><?= number_format($s['spent']) ?></td><td class="r nowrap"><?= number_format($s['advances']) ?></td><td class="r nowrap"><?= number_format($s['committed'] + $s['pending']) ?></td><td class="r nowrap"><?= number_format($s['available']) ?></td><td><?= $s['pct'] ?>%</td><td></td></tr></tfoot>
    </table>
  </div>

  <div class="fin-cluster-tools">
    <details><summary><i class="fa-solid fa-chart-line"></i> Update progress</summary>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="progress"><input type="hidden" name="cluster_id" value="<?= (int) $c['id'] ?>">
        <div class="row-2">
          <label>Activity progress (%)<input type="number" name="progress" min="0" max="100" value="<?= (int) $c['progress'] ?>"></label>
          <label><?= $c['target_label'] ? 'Reached so far (' . e($c['target_label']) . ')' : 'Reached so far' ?><input type="number" name="reached" min="0" value="<?= (int) $c['reached'] ?>"></label>
        </div>
        <label>What was done<textarea name="note" rows="2" maxlength="2000" placeholder="e.g. Week 3 mentorship session held online with 84 participants"></textarea></label>
        <div><button class="btn btn-primary btn-sm" type="submit">Save update</button></div>
      </form>
      <?php if (!empty($updates[(int) $c['id']])): ?><ul class="fin-updates"><?php foreach (array_slice($updates[(int) $c['id']], 0, 6) as $u): ?><li><b><?= (int) $u['progress'] ?>%</b><?= $u['reached'] !== null ? ' · ' . number_format((int) $u['reached']) . ' reached' : '' ?> — <?= e((string) $u['note']) ?> <small class="muted"><?= e(date('j M Y', strtotime($u['created_at']))) ?> · <?= e((string) $u['admin_name']) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
    </details>
    <details><summary><i class="fa-solid fa-plus"></i> Add a budget line</summary>
      <form method="post" class="fin-line-form"><?= csrf_field() ?><input type="hidden" name="action" value="line_save"><input type="hidden" name="cluster_id" value="<?= (int) $c['id'] ?>">
        <input type="text" name="code" maxlength="20" placeholder="Code" required>
        <input type="text" name="item" maxlength="200" placeholder="Item" required>
        <input type="text" name="description" maxlength="1000" placeholder="Description">
        <input type="number" name="units" value="1" step="0.01" min="0" aria-label="Units">
        <input type="text" name="unit_cost" class="js-money" inputmode="numeric" placeholder="Unit cost (UGX)">
        <button class="btn btn-primary btn-sm" type="submit">Add line</button>
      </form>
    </details>
    <details><summary><i class="fa-solid fa-pen"></i> Edit activity</summary>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="cluster_save"><input type="hidden" name="cluster_id" value="<?= (int) $c['id'] ?>">
        <div class="row-3"><label>Code<input type="text" name="code" value="<?= e($c['code']) ?>" maxlength="20"></label><label>Name<input type="text" name="name" value="<?= e($c['name']) ?>" maxlength="200"></label><label>Responsible<input type="text" name="responsible" value="<?= e((string) $c['responsible']) ?>" maxlength="120"></label></div>
        <label>Description<textarea name="description" rows="3"><?= e((string) $c['description']) ?></textarea></label>
        <div class="row-2"><label>Target (number)<input type="number" name="target_value" min="0" value="<?= (int) $c['target_value'] ?>"></label><label>Target of what<input type="text" name="target_label" value="<?= e((string) $c['target_label']) ?>" maxlength="120" placeholder="e.g. young people mentored"></label></div>
        <div class="btn-row"><?php foreach ($monthsAll as $k => $label): ?><label class="check-inline"><input type="checkbox" name="months[]" value="<?= $k ?>"<?= in_array($k, $on, true) ? ' checked' : '' ?>> <?= $k ?></label><?php endforeach; ?></div>
        <div><button class="btn btn-navy btn-sm" type="submit">Save activity</button></div>
      </form>
    </details>
  </div>
</section>
<?php endforeach; ?>

<section class="card fin-grand">
  <div><small>Total budget</small><b><?= e(ugx($cs['total']['budget'])) ?></b><span class="muted"><?= usd_of($cs['total']['budget'], $project) ?></span></div>
  <div><small>Spent</small><b><?= e(ugx($cs['total']['spent'])) ?></b></div>
  <div><small>Advances</small><b><?= e(ugx($cs['total']['advances'])) ?></b></div>
  <div><small>Committed &amp; waiting</small><b><?= e(ugx($cs['total']['committed'] + $cs['total']['pending'])) ?></b></div>
  <div><small>Available</small><b class="<?= $cs['total']['available'] < 0 ? 'due' : 'ok-text' ?>"><?= e(ugx($cs['total']['available'])) ?></b></div>
</section>

<?php if ($changes): ?>
<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Budget changes</h3><span class="muted small">every edit is recorded</span></div>
  <ul class="feed req-log"><?php foreach ($changes as $l): ?><li><b><?= e(ucfirst($l['entity'])) ?> <?= e($l['action']) ?></b> · <?= e((string) $l['admin_name']) ?> · <span class="muted small"><?= e(date('j M Y, g:i a', strtotime($l['created_at']))) ?></span><span class="small block"><?= e((string) $l['comment']) ?></span></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php admin_footer(['finance.js']);
