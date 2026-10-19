<?php
require __DIR__ . '/_init.php';
$admin = require_admin();
$role = $admin['role'];
$project = fin_project();
if (!$project) {
    exit('No project has been set up yet.');
}
$pid = (int) $project['id'];
$statuses = req_statuses();
$seeBudget = role_can($role, 'all_reqs');   // availability per budget line: team lead, finance, operations
$clusters = fin_clusters($pid);
$lines = fin_lines($pid);
$lineStats = $seeBudget ? fin_line_stats($pid) : [];
$errors = [];
$old = ['items' => [['line_id' => '', 'description' => '', 'qty' => '1', 'unit_cost' => '']]];

/* ---------- New requisition ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    $clean = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
    $old = [
        'type'          => ($_POST['type'] ?? '') === 'refund' ? 'refund' : 'advance',
        'title'         => $clean('title', 200),
        'cluster_id'    => (int) ($_POST['cluster_id'] ?? 0),
        'location'      => $clean('location', 150),
        'needed_on'     => $clean('needed_on', 10),
        'justification' => mb_substr(trim((string) ($_POST['justification'] ?? '')), 0, 3000),
        'items'         => [],
    ];
    $validLines = array_column($lines, null, 'id');
    $total = 0;
    foreach ((array) ($_POST['items'] ?? []) as $it) {
        $row = [
            'line_id'     => (int) ($it['line_id'] ?? 0),
            'description' => mb_substr(trim((string) ($it['description'] ?? '')), 0, 300),
            'qty'         => max(0, (float) str_replace(',', '', (string) ($it['qty'] ?? '1'))),
            'unit_cost'   => (int) preg_replace('/\D/', '', (string) ($it['unit_cost'] ?? '0')),
            'payee'       => mb_substr(trim((string) ($it['payee'] ?? '')), 0, 150),
        ];
        if (!$row['line_id'] && $row['description'] === '' && !$row['unit_cost']) {
            continue;   // empty row
        }
        $row['amount'] = (int) round($row['qty'] * $row['unit_cost']);
        if (!isset($validLines[$row['line_id']])) {
            $errors['items'] = 'Choose the budget line for every item.';
        } elseif ($row['description'] === '' || $row['amount'] <= 0) {
            $errors['items'] = 'Every item needs a description, a quantity and a unit cost.';
        }
        $total += $row['amount'];
        $old['items'][] = $row;
    }
    if (mb_strlen($old['title']) < 5) {
        $errors['title'] = 'Say what the money is for (at least a few words).';
    }
    if (!$old['items']) {
        $errors['items'] = 'Add at least one item.';
        $old['items'] = [['line_id' => '', 'description' => '', 'qty' => '1', 'unit_cost' => '']];
    }
    if ($old['needed_on'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['needed_on'])) {
        $errors['needed_on'] = 'Choose a valid date.';
    }
    if (mb_strlen($old['location']) < 2) {
        $errors['location'] = $old['type'] === 'refund' ? 'Where was the money spent?' : 'Where will the money be used?';
    }
    $refund = $old['type'] === 'refund';
    if ($refund && !$errors) {
        // A refund is money already spent: receipts or proof and the person's signature come with the request
        $hasFile = false;
        foreach ((array) ($_FILES['receipts']['name'] ?? []) as $i => $n) {
            $hasFile = $hasFile || (($_FILES['receipts']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
        }
        if (!$hasFile) {
            $errors['receipts'] = 'Attach the receipt or proof of what you paid — a refund needs it.';
        }
        if (!empty($_POST['signature_data']) && ($err = save_signature((int) $admin['id'], (string) $_POST['signature_data']))) {
            $errors['signature'] = $err;
        } elseif (empty($_POST['confirm_sign'])) {
            $errors['signature'] = 'Tick the box to confirm and sign the refund request.';
        } elseif (!admin_signature_path((string) q('SELECT signature_file FROM admins WHERE id = ?', [$admin['id']])->fetchColumn())) {
            $errors['signature'] = 'Draw your signature to sign the refund request.';
        }
    }
    if (!$errors) {
        $cluster = $old['cluster_id'] ?: (int) $validLines[$old['items'][0]['line_id']]['cluster_id'];
        q('INSERT INTO fin_requisitions (project_id, requested_by, type, title, cluster_id, location, needed_on, justification, total, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $admin['id'], $old['type'], $old['title'], $cluster, $old['location'], $old['needed_on'] ?: null, $old['justification'] ?: null, $total, 'submitted', now(), now()]);
        $rid = (int) db()->lastInsertId();
        q('UPDATE fin_requisitions SET reference = ? WHERE id = ?', [req_reference($rid), $rid]);
        foreach ($old['items'] as $it) {
            // For a refund the amounts are what was actually spent, so they are the accountability too
            q('INSERT INTO fin_req_items (requisition_id, line_id, description, qty, unit_cost, amount, actual_amount, payee, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$rid, $it['line_id'], $it['description'], $it['qty'], $it['unit_cost'], $it['amount'], $refund ? $it['amount'] : null, $it['payee'] ?: null, $refund ? $it['description'] : null]);
        }
        [, $problems] = fin_store_files('support', 'req_support', $rid, (int) $admin['id']);
        if ($refund) {
            [, $p2] = fin_store_files('receipts', 'req_receipt', $rid, (int) $admin['id']);
            $problems = array_merge($problems, $p2);
            q('UPDATE fin_requisitions SET actual_total = total, accountability = justification, accounted_at = ?, signed_at = ?, sig_requester = ? WHERE id = ?',
                [($old['needed_on'] ?: date('Y-m-d')) . ' 12:00:00', now(), signature_snapshot((int) $admin['id']), $rid]);
        }
        fin_log($pid, 'requisition', $rid, 'submitted', ($refund ? 'Refund requested ' : 'Requested ') . ugx($total), (int) $admin['id']);
        $r = find_requisition($rid);
        $twoStep = req_approval_mode() === 'two_step';
        [$s, $h] = tpl_requisition($r, $refund ? 'New refund request' : 'New requisition', e($admin['name']) . ($refund ? ' paid from their own money and asks for a refund — receipts are attached.' : ' has requested money.')
            . ($twoStep ? ' Operations reviews it, then the team lead approves.' : ' Operations or the team lead can approve it (or adjust and approve).'));
        fin_notify_roles(['admin', 'operations'], $s, $h, (int) $admin['id'], $refund ? fin_file_attachments(fin_files('req_receipt', $rid)) : []);
        flash($r['reference'] . ($refund ? ' refund request sent for approval.' : ' submitted for approval.') . ($problems ? ' Some files were not attached: ' . implode('; ', $problems) : ''), $problems ? 'error' : 'success');
        redirect('requisition.php?id=' . $rid);
    }
}

/* ---------- List ---------- */
$tab = (string) ($_GET['tab'] ?? '');
$where = ['r.project_id = ?'];
$params = [$pid];
if (!role_can($role, 'all_reqs')) {
    $where[] = 'r.requested_by = ?';
    $params[] = $admin['id'];
}
$mine = array_merge(role_can($role, 'review') ? ['submitted'] : [], role_can($role, 'approve') ? ['reviewed'] : [], role_can($role, 'disburse') ? ['approved', 'accounted'] : []);
if ($tab === 'waiting') {
    $parts = [];
    if ($mine) {
        $parts[] = 'r.status IN (' . implode(',', array_fill(0, count($mine), '?')) . ')';
        array_push($params, ...$mine);
    }
    $parts[] = "(r.status = 'disbursed' AND r.requested_by = ?)";
    $params[] = $admin['id'];
    $where[] = '(' . implode(' OR ', $parts) . ')';
} elseif (isset($statuses[$tab])) {
    $where[] = 'r.status = ?';
    $params[] = $tab;
} elseif ($tab === 'overdue') {
    $where[] = "r.status = 'disbursed' AND r.disbursed_at < ?";
    $params[] = date('Y-m-d H:i:s', time() - req_accountability_days() * 86400);
}
$rows = q('SELECT r.*, a.name requester_name, c.code cluster_code, c.name cluster_name FROM fin_requisitions r LEFT JOIN admins a ON a.id = r.requested_by LEFT JOIN fin_clusters c ON c.id = r.cluster_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT 300', $params)->fetchAll();
$countWhere = role_can($role, 'all_reqs') ? 'project_id = ?' : 'project_id = ? AND requested_by = ' . (int) $admin['id'];
$counts = array_column(q("SELECT status, COUNT(*) c FROM fin_requisitions WHERE $countWhere GROUP BY status", [$pid])->fetchAll(), 'c', 'status');
$showForm = isset($_GET['new']) || $errors;

admin_header(role_can($role, 'all_reqs') ? 'Requisitions' : 'My requisitions', 'requisitions', 'Request money for field work · review → approval → payment → accountability with receipts');
?>
<div class="req-flow">
  <span><i class="fa-solid fa-file-signature"></i> Request</span><i class="fa-solid fa-chevron-right"></i>
  <?php if (req_approval_mode() === 'two_step'): ?>
  <span><i class="fa-solid fa-magnifying-glass"></i> Operations review</span><i class="fa-solid fa-chevron-right"></i>
  <span><i class="fa-solid fa-stamp"></i> Team lead approval</span><i class="fa-solid fa-chevron-right"></i>
  <?php else: ?>
  <span><i class="fa-solid fa-stamp"></i> Approval (operations or team lead)</span><i class="fa-solid fa-chevron-right"></i>
  <?php endif; ?>
  <span><i class="fa-solid fa-money-bill-transfer"></i> Finance pays</span><i class="fa-solid fa-chevron-right"></i>
  <span><i class="fa-solid fa-receipt"></i> Accountability &amp; receipts</span><i class="fa-solid fa-chevron-right"></i>
  <span><i class="fa-solid fa-circle-check"></i> Verified</span>
</div>

<?php if ($showForm): ?>
<section class="card" id="new">
  <div class="card-head"><h3><i class="fa-solid fa-file-circle-plus"></i> New requisition</h3><a href="requisitions.php" class="link">Cancel</a></div>
  <?php if ($errors): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>
  <?php $isRefund = ($old['type'] ?? ($_GET['type'] ?? '')) === 'refund'; ?>
  <form method="post" enctype="multipart/form-data" class="stack js-sig-form" id="reqForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="req-type">
      <label class="req-type-opt"><input type="radio" name="type" value="advance"<?= $isRefund ? '' : ' checked' ?>><span><i class="fa-solid fa-hand-holding-dollar"></i><b>Request money for work</b><small>Get money before an activity, then account with receipts</small></span></label>
      <label class="req-type-opt"><input type="radio" name="type" value="refund"<?= $isRefund ? ' checked' : '' ?>><span><i class="fa-solid fa-rotate-left"></i><b>Refund — I already paid</b><small>You used your own money; attach the receipt or proof now</small></span></label>
    </div>
    <label><span class="js-adv">What is the money for?</span><span class="js-ref">What did you pay for?</span> <input type="text" name="title" value="<?= e($old['title'] ?? '') ?>" maxlength="200" placeholder="e.g. Tree planting mobilisation in Kitgum schools — week 2" required></label>
    <div class="row-3">
      <label>Workplan activity
        <select name="cluster_id" id="reqCluster"><option value="0">Choose…</option><?php foreach ($clusters as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($old['cluster_id'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['code'] . ' · ' . $c['name']) ?></option><?php endforeach; ?></select>
      </label>
      <label>Where (location) <input type="text" name="location" value="<?= e($old['location'] ?? '') ?>" maxlength="150" placeholder="e.g. Kitgum Municipality" required></label>
      <label><span class="js-adv">Needed on</span><span class="js-ref">Date you paid</span> <input type="date" name="needed_on" value="<?= e($old['needed_on'] ?? '') ?>"></label>
    </div>

    <div class="req-items-wrap">
      <table class="table req-items" id="reqItems">
        <thead><tr><th>Budget line</th><th>Item / description</th><th class="r">Qty</th><th class="r">Unit cost (UGX)</th><th class="r">Amount</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($old['items'] as $i => $it): ?>
          <tr class="req-row">
            <td><select name="items[<?= $i ?>][line_id]" class="js-line" required><option value="">Choose a budget line…</option>
              <?php foreach ($clusters as $c): ?><optgroup label="<?= e($c['code'] . ' · ' . $c['name']) ?>" data-cluster="<?= (int) $c['id'] ?>">
                <?php foreach ($lines as $l): if ((int) $l['cluster_id'] !== (int) $c['id']) continue; $av = $lineStats[(int) $l['id']]['available'] ?? null; ?>
                <option value="<?= (int) $l['id'] ?>" data-cluster="<?= (int) $c['id'] ?>"<?= $av !== null ? ' data-available="' . $av . '"' : '' ?><?= (int) ($it['line_id'] ?? 0) === (int) $l['id'] ? ' selected' : '' ?>><?= e($l['code'] . ' ' . $l['item']) ?><?= $av !== null ? ' — ' . e(ugx($av)) . ' left' : '' ?></option>
                <?php endforeach; ?></optgroup><?php endforeach; ?>
            </select></td>
            <td><input type="text" name="items[<?= $i ?>][description]" value="<?= e($it['description'] ?? '') ?>" maxlength="300" placeholder="e.g. Transport for 2 staff × 4 days" required><input type="text" name="items[<?= $i ?>][payee]" value="<?= e($it['payee'] ?? '') ?>" maxlength="150" class="js-ref item-payee" placeholder="Paid to (shop / person)"></td>
            <td class="r"><input type="number" name="items[<?= $i ?>][qty]" value="<?= e((string) ($it['qty'] ?? '1')) ?>" min="0" step="0.5" class="js-qty" required></td>
            <td class="r"><input type="text" name="items[<?= $i ?>][unit_cost]" value="<?= !empty($it['unit_cost']) ? number_format((int) $it['unit_cost']) : '' ?>" inputmode="numeric" class="js-cost" placeholder="0" required></td>
            <td class="r nowrap"><b class="js-amount">0</b></td>
            <td><button type="button" class="icon-btn danger js-del" title="Remove item"><i class="fa-solid fa-xmark"></i></button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="total"><td colspan="4" class="r">Total requested</td><td class="r nowrap"><b id="reqTotal">UGX 0</b></td><td></td></tr></tfoot>
      </table>
      <button type="button" class="btn btn-light btn-sm" id="reqAdd"><i class="fa-solid fa-plus"></i> Add item</button>
      <?php if ($seeBudget): ?><p class="small muted">Each budget line shows the money still available. An item larger than what's left is highlighted.</p><?php endif; ?>
    </div>
    <label>Justification / details <small class="muted">(optional)</small><textarea name="justification" rows="3" maxlength="3000" placeholder="Who is going, how many days, which schools or groups…"><?= e($old['justification'] ?? '') ?></textarea></label>
    <label class="js-adv">Supporting documents <small class="muted">(optional — quotations, invoices, activity plan · photos, PDF, Word or Excel, up to 10 MB each)</small><input type="file" name="support[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.docx,.xlsx"></label>
    <div class="js-ref refund-box">
      <label>Receipts / proof of payment <small class="muted">(required — photos or PDF of what you paid, several at once)</small><input type="file" name="receipts[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"></label>
      <?php $hasSig = (bool) admin_signature_path((string) q('SELECT signature_file FROM admins WHERE id = ?', [$admin['id']])->fetchColumn()); ?>
      <div class="sign-box">
        <h4><i class="fa-solid fa-signature"></i> Sign your refund request</h4>
        <?php if ($hasSig): ?>
          <div class="sig-current"><img src="signature.php" alt="Your saved signature"></div>
          <p class="small muted">Your saved signature will be used. <a href="users.php#signature">Change it</a></p>
        <?php else: ?>
          <p class="small">Draw your signature — it is saved to your account for next time.</p>
          <div class="sig-pad"><canvas width="600" height="200" class="js-sig-canvas" aria-label="Draw your signature"></canvas><span class="sig-hint">Sign here with your finger or mouse</span></div>
          <input type="hidden" name="signature_data" class="js-sig-data">
          <div><button type="button" class="btn btn-light btn-sm js-sig-clear"><i class="fa-solid fa-eraser"></i> Clear</button></div>
        <?php endif; ?>
        <label class="check-inline"><input type="checkbox" name="confirm_sign" value="1"> I confirm I paid this from my own money, the receipts are true, and I sign this request.</label>
      </div>
    </div>
    <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i> <span class="js-adv">Submit for approval</span><span class="js-ref">Send refund request</span></button></div>
  </form>
</section>
<?php else: ?>
<div class="req-new-bar btn-row"><a class="btn btn-primary" href="requisitions.php?new=1#new"><i class="fa-solid fa-file-circle-plus"></i> New requisition</a><a class="btn btn-light" href="requisitions.php?new=1&amp;type=refund#new"><i class="fa-solid fa-rotate-left"></i> Ask for a refund</a></div>
<?php endif; ?>

<div class="tabs big">
  <a href="requisitions.php?tab=waiting" class="<?= $tab === 'waiting' ? 'active' : '' ?>">Waiting for me</a>
  <a href="requisitions.php" class="<?= $tab === '' ? 'active' : '' ?>">All <em><?= array_sum($counts) ?></em></a>
  <?php foreach ($statuses as $k => [$label]): if (empty($counts[$k])) continue; ?><a href="requisitions.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e(explode(' — ', $label)[0]) ?> <em><?= (int) $counts[$k] ?></em></a><?php endforeach; ?>
  <a href="requisitions.php?tab=overdue" class="<?= $tab === 'overdue' ? 'active' : '' ?>">Accountability overdue</a>
</div>

<div class="card">
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Requisition</th><th>Requested by</th><th>Activity</th><th class="r">Amount</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="row-link" data-href="requisition.php?id=<?= (int) $r['id'] ?>">
          <td><b><?= e($r['reference']) ?></b><small class="block muted"><?= e(mb_strimwidth($r['title'], 0, 70, '…')) ?></small></td>
          <td><?= e($r['requester_name'] ?? '—') ?><small class="block muted"><?= e($r['location']) ?></small></td>
          <td class="small"><?= e(($r['cluster_code'] ?? '') . ' ' . mb_strimwidth((string) ($r['cluster_name'] ?? ''), 0, 34, '…')) ?></td>
          <td class="r nowrap"><b><?= e(ugx($r['total'])) ?></b><?php if ($r['actual_total'] !== null): ?><small class="block muted">spent <?= e(ugx($r['actual_total'])) ?></small><?php endif; ?></td>
          <td><span class="badge <?= $statuses[$r['status']][1] ?? '' ?>"><?= e($statuses[$r['status']][0] ?? $r['status']) ?></span><?php if (req_overdue($r)): ?> <span class="badge st-cancelled">Overdue</span><?php endif; ?></td>
          <td class="nowrap muted"><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
          <td class="row-actions"><a class="btn btn-light btn-sm" href="requisition.php?id=<?= (int) $r['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-file-signature"></i><p><?= $tab ? 'Nothing here.' : 'No requisitions yet. Press "New requisition" to request money for field work.' ?></p></div>
  <?php endif; ?>
</div>
<?php admin_footer(['finance.js']);
