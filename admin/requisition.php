<?php
require __DIR__ . '/_init.php';
$admin = require_admin();
$role = $admin['role'];
$me = (int) $admin['id'];

$r = find_requisition((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$r || (!role_can($role, 'all_reqs') && (int) $r['requested_by'] !== $me)) {
    flash('That requisition was not found.', 'error');
    redirect('requisitions.php');
}
$pid = (int) $r['project_id'];
$project = fin_project($pid);
$own = (int) $r['requested_by'] === $me;
$statuses = req_statuses();

// Who can act now. Nobody reviews or approves their own requisition, except the team lead (there is no one above them).
$canReview = $r['status'] === 'submitted' && role_can($role, 'review') && (!$own || $role === 'admin');
$canApprove = in_array($r['status'], ['submitted', 'reviewed'], true) && role_can($role, 'approve');
$canDisburse = $r['status'] === 'approved' && role_can($role, 'disburse');
$canAccount = $r['status'] === 'disbursed' && ($own || role_can($role, 'disburse'));
$canVerify = $r['status'] === 'accounted' && role_can($role, 'disburse');
$canCancel = in_array($r['status'], ['submitted', 'reviewed'], true) && ($own || role_can($role, 'approve'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 2000);
    $self = 'requisition.php?id=' . (int) $r['id'];
    $requester = [$r['requester_email']];

    if ($action === 'review' && $canReview) {
        if (($_POST['decision'] ?? '') === 'reject') {
            if ($comment === '') {
                flash('Say why it is rejected, so the requester knows what to change.', 'error');
                redirect($self);
            }
            q("UPDATE fin_requisitions SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?", [$me, now(), now(), $r['id']]);
            fin_log($pid, 'requisition', (int) $r['id'], 'rejected (review)', $comment, $me);
            [$s, $h] = tpl_requisition($r, 'Requisition rejected', 'Your requisition was not recommended by ' . e($admin['name']) . ': <i>' . e($comment) . '</i>');
            send_mail($requester, $s, $h);
            flash($r['reference'] . ' rejected.');
        } else {
            q("UPDATE fin_requisitions SET status = 'reviewed', reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ?", [$me, now(), now(), $r['id']]);
            fin_log($pid, 'requisition', (int) $r['id'], 'reviewed', $comment ?: 'Recommended for approval', $me);
            [$s, $h] = tpl_requisition($r, 'Requisition to approve', e($admin['name']) . ' (operations) has reviewed and recommended this requisition. Please approve or reject it.');
            fin_notify_roles(['admin'], $s, $h, $me);
            flash($r['reference'] . ' recommended — sent to the team lead for approval.' . mail_note());
        }
        redirect($self);
    }

    if ($action === 'approve' && $canApprove) {
        if (($_POST['decision'] ?? '') === 'reject') {
            if ($comment === '') {
                flash('Say why it is rejected.', 'error');
                redirect($self);
            }
            q("UPDATE fin_requisitions SET status = 'rejected', approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ?", [$me, now(), now(), $r['id']]);
            fin_log($pid, 'requisition', (int) $r['id'], 'rejected (approval)', $comment, $me);
            [$s, $h] = tpl_requisition($r, 'Requisition rejected', 'Your requisition was not approved: <i>' . e($comment) . '</i>');
            send_mail($requester, $s, $h);
            flash($r['reference'] . ' rejected.');
        } else {
            $skipped = $r['status'] === 'submitted';
            // The team lead can change quantities and unit costs before approving (0 removes an item from the payment)
            $changes = [];
            $newTotal = 0;
            foreach (req_items((int) $r['id']) as $it) {
                $qty = isset($_POST['qty'][$it['id']]) ? max(0, (float) str_replace(',', '', (string) $_POST['qty'][$it['id']])) : (float) $it['qty'];
                $cost = isset($_POST['unit_cost'][$it['id']]) ? (int) preg_replace('/\D/', '', (string) $_POST['unit_cost'][$it['id']]) : (int) $it['unit_cost'];
                $amount = (int) round($qty * $cost);
                if ($amount !== (int) $it['amount']) {
                    q('UPDATE fin_req_items SET qty = ?, unit_cost = ?, amount = ? WHERE id = ?', [$qty, $cost, $amount, $it['id']]);
                    $changes[] = $it['description'] . ': ' . number_format((int) $it['amount']) . ' → ' . number_format($amount);
                }
                $newTotal += $amount;
            }
            if ($newTotal <= 0) {
                flash('The approved amount cannot be zero — reject the requisition instead.', 'error');
                redirect($self);
            }
            $adjustNote = mb_substr(trim((string) ($_POST['adjust_note'] ?? '')), 0, 1000);
            if ($changes) {
                q('UPDATE fin_requisitions SET original_total = COALESCE(original_total, total), total = ?, adjust_note = ? WHERE id = ?', [$newTotal, ($adjustNote ?: 'Amounts adjusted by the team lead') . ' — ' . implode('; ', $changes), $r['id']]);
                $r['total'] = $newTotal;
            }
            q("UPDATE fin_requisitions SET status = 'approved', approved_by = ?, approved_at = ?, sig_approver = ?, reviewed_by = COALESCE(reviewed_by, ?), reviewed_at = COALESCE(reviewed_at, ?), updated_at = ? WHERE id = ?", [$me, now(), signature_snapshot($me), $me, now(), now(), $r['id']]);
            fin_log($pid, 'requisition', (int) $r['id'], 'approved', ($skipped ? 'Reviewed and approved by the team lead. ' : '') . ($changes ? 'Adjusted to ' . ugx($newTotal) . ' (' . implode('; ', $changes) . ')' . ($adjustNote ? ' — ' . $adjustNote : '') . '. ' : '') . $comment, $me);
            [$s, $h] = tpl_requisition($r, 'Requisition approved — please pay', 'The team lead has approved this requisition. Please pay ' . e(ugx($r['total'])) . ' to ' . e((string) $r['requester_name']) . '.');
            fin_notify_roles(['finance'], $s, $h, $me);
            [$s2, $h2] = tpl_requisition($r, 'Your requisition is approved', ($changes ? 'The team lead adjusted the amount to <b>' . e(ugx($newTotal)) . '</b>' . ($adjustNote ? ': <i>' . e($adjustNote) . '</i>' : '') . '. ' : '') . 'Finance will now pay the money. Keep every receipt — you will attach them to your accountability.');
            send_mail($requester, $s2, $h2);
            flash($r['reference'] . ' approved — finance has been told to pay.' . mail_note());
        }
        redirect($self);
    }

    if ($action === 'disburse' && $canDisburse) {
        $method = isset(fin_methods()[$_POST['method'] ?? '']) ? $_POST['method'] : 'cash';
        $ref = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 120);
        $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['paid_on'] ?? '')) ? $_POST['paid_on'] . ' ' . date('H:i:s') : now();
        q("UPDATE fin_requisitions SET status = 'disbursed', disbursed_by = ?, disbursed_at = ?, disbursed_amount = total, disburse_method = ?, disburse_ref = ?, sig_payer = ?, updated_at = ? WHERE id = ?", [$me, $paidOn, $method, $ref ?: null, signature_snapshot($me), now(), $r['id']]);
        [, $problems] = fin_store_files('proof', 'req_payment', (int) $r['id'], $me);
        fin_log($pid, 'requisition', (int) $r['id'], 'paid', ugx($r['total']) . ' by ' . fin_methods()[$method] . ($ref ? ' · ref ' . $ref : '') . ($comment ? ' — ' . $comment : ''), $me);
        [$s, $h] = tpl_requisition($r, 'Money sent — accountability due', 'Finance has paid ' . e(ugx($r['total'])) . ' (' . e(fin_methods()[$method]) . ($ref ? ', ref ' . e($ref) : '') . '). Within ' . req_accountability_days() . ' days, open the requisition and submit your accountability: what you actually spent on each item, with photos or scans of every receipt.');
        send_mail($requester, $s, $h);
        flash($r['reference'] . ' marked as paid.' . ($problems ? ' Some files were not attached: ' . implode('; ', $problems) : ''), $problems ? 'error' : 'success');
        redirect($self);
    }

    if ($action === 'account' && $canAccount) {
        $items = req_items((int) $r['id']);
        $actual = 0;
        $rows = [];
        $missing = [];
        foreach ($items as $it) {
            $a = (int) preg_replace('/\D/', '', (string) ($_POST['actual'][$it['id']] ?? '0'));
            $payee = mb_substr(trim((string) ($_POST['payee'][$it['id']] ?? '')), 0, 150);
            $explain = mb_substr(trim((string) ($_POST['note'][$it['id']] ?? '')), 0, 1000);
            if ($a > 0 && mb_strlen($explain) < 5) {
                $missing[] = $it['description'];
            }
            $rows[] = [$a, $payee ?: null, $explain ?: null, $it['id']];
            $actual += $a;
        }
        // Keep what was typed, even if something is missing
        foreach ($rows as $row) {
            q('UPDATE fin_req_items SET actual_amount = ?, payee = ?, note = ? WHERE id = ?', $row);
        }
        if ($missing) {
            flash('Explain what the money was spent on for: ' . implode(', ', $missing) . '.', 'error');
            redirect($self . '#account');
        }
        // Signature: the saved one, or one drawn now (which is saved to the account)
        $signer = $own ? $me : (int) $r['requested_by'];
        if (!empty($_POST['signature_data']) && $signer === $me) {
            if ($err = save_signature($me, (string) $_POST['signature_data'])) {
                flash($err, 'error');
                redirect($self . '#account');
            }
        }
        if (empty($_POST['confirm_sign'])) {
            flash('Tick the box to confirm and sign your accountability.', 'error');
            redirect($self . '#account');
        }
        if ($signer === $me && !admin_signature_path((string) q('SELECT signature_file FROM admins WHERE id = ?', [$me])->fetchColumn())) {
            flash('Draw your signature in the box to sign the accountability.', 'error');
            redirect($self . '#account');
        }
        [$saved, $problems] = fin_store_files('receipts', 'req_receipt', (int) $r['id'], $me);
        $hasReceipts = $saved || fin_files('req_receipt', (int) $r['id']);
        if (!$hasReceipts) {
            flash('Attach at least one receipt (a photo or scan) — accountability is not complete without receipts.', 'error');
            redirect($self . '#account');
        }
        q('UPDATE fin_requisitions SET signed_at = ?, sig_requester = ? WHERE id = ?', [now(), signature_snapshot($signer), $r['id']]);
        $spentOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['spent_on'] ?? '')) ? $_POST['spent_on'] : date('Y-m-d');
        $note = mb_substr(trim((string) ($_POST['accountability'] ?? '')), 0, 3000);
        q("UPDATE fin_requisitions SET status = 'accounted', accounted_at = ?, actual_total = ?, accountability = ?, balance_note = ?, updated_at = ? WHERE id = ?", [$spentOn . ' ' . date('H:i:s'), $actual, $note ?: null, null, now(), $r['id']]);
        $bal = (int) $r['disbursed_amount'] - $actual;
        fin_log($pid, 'requisition', (int) $r['id'], 'accountability', 'Spent ' . ugx($actual) . ' of ' . ugx($r['disbursed_amount']) . ($bal > 0 ? ' · ' . ugx($bal) . ' to return' : ($bal < 0 ? ' · ' . ugx(-$bal) . ' overspent' : '')) . ($note ? ' — ' . $note : ''), $me);
        [$s, $h] = tpl_requisition($r, 'Accountability to verify', e($admin['name']) . ' has submitted accountability: spent ' . e(ugx($actual)) . ' of ' . e(ugx($r['disbursed_amount'])) . ', with receipts. Please check and verify it.');
        fin_notify_roles(['finance'], $s, $h, $me);
        flash('Accountability submitted — finance will check it.' . ($problems ? ' Some files were not attached: ' . implode('; ', $problems) : ''), $problems ? 'error' : 'success');
        redirect($self);
    }

    if ($action === 'verify' && $canVerify) {
        if (($_POST['decision'] ?? '') === 'return') {
            if ($comment === '') {
                flash('Say what needs correcting.', 'error');
                redirect($self);
            }
            q("UPDATE fin_requisitions SET status = 'disbursed', updated_at = ? WHERE id = ?", [now(), $r['id']]);
            fin_log($pid, 'requisition', (int) $r['id'], 'accountability returned', $comment, $me);
            [$s, $h] = tpl_requisition($r, 'Accountability needs correcting', 'Finance returned your accountability: <i>' . e($comment) . '</i> Please correct it and submit again.');
            send_mail($requester, $s, $h);
            flash('Accountability returned to ' . $r['requester_name'] . '.');
            redirect($self);
        }
        // Verified: every item becomes an expense on its budget line (actual amounts), and the requisition is complete
        $items = req_items((int) $r['id']);
        $spentOn = date('Y-m-d', strtotime((string) ($r['accounted_at'] ?: now())));
        foreach ($items as $it) {
            if ((int) $it['actual_amount'] > 0) {
                q('INSERT INTO fin_expenses (project_id, line_id, spent_on, payee, description, amount, method, reference, location, spent_by, requisition_id, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $pid, $it['line_id'], $spentOn, $it['payee'] ?: (string) $r['requester_name'], mb_substr($it['description'] . ' — ' . $r['title'], 0, 500), (int) $it['actual_amount'],
                    $r['disburse_method'], $r['reference'], $r['location'], (string) $r['requester_name'], $r['id'], $me, now(),
                ]);
            }
        }
        $balNote = mb_substr(trim((string) ($_POST['balance_note'] ?? '')), 0, 500);
        q("UPDATE fin_requisitions SET status = 'closed', verified_by = ?, verified_at = ?, balance_note = ?, sig_verifier = ?, updated_at = ? WHERE id = ?", [$me, now(), $balNote ?: null, signature_snapshot($me), now(), $r['id']]);
        fin_log($pid, 'requisition', (int) $r['id'], 'verified', 'Accountability verified; ' . ugx($r['actual_total']) . ' recorded as spent.' . ($balNote ? ' ' . $balNote : '') . ($comment ? ' — ' . $comment : ''), $me);
        [$s, $h] = tpl_requisition($r, 'Requisition complete', 'Finance has verified your accountability. Thank you — this requisition is now complete.');
        send_mail($requester, $s, $h);
        flash($r['reference'] . ' verified and complete. The spending is recorded on its budget lines.');
        redirect($self);
    }

    if ($action === 'cancel' && $canCancel) {
        q("UPDATE fin_requisitions SET status = 'cancelled', updated_at = ? WHERE id = ?", [now(), $r['id']]);
        fin_log($pid, 'requisition', (int) $r['id'], 'cancelled', $comment ?: null, $me);
        flash($r['reference'] . ' cancelled.');
        redirect($self);
    }

    flash('That action is not available for this requisition right now.', 'error');
    redirect($self);
}

$items = req_items((int) $r['id']);
$log = q('SELECT l.*, a.name admin_name FROM fin_log l LEFT JOIN admins a ON a.id = l.admin_id WHERE l.entity = ? AND l.entity_id = ? ORDER BY l.id', ['requisition', $r['id']])->fetchAll();
$names = array_column(q('SELECT id, name FROM admins')->fetchAll(), 'name', 'id');
$filesSupport = fin_files('req_support', (int) $r['id']);
$filesPayment = fin_files('req_payment', (int) $r['id']);
$filesReceipts = fin_files('req_receipt', (int) $r['id']);
$lineStats = role_can($role, 'all_reqs') ? fin_line_stats($pid) : [];
$cluster = $r['cluster_id'] ? q('SELECT code, name FROM fin_clusters WHERE id = ?', [$r['cluster_id']])->fetch() : null;
$balance = $r['actual_total'] !== null ? (int) $r['disbursed_amount'] - (int) $r['actual_total'] : null;

/* ---------- A4 PDF: requisition, accountability, signatures and receipt photos ---------- */
if (isset($_GET['pdf'])) {
    $bytes = requisition_pdf(find_requisition((int) $r['id']));
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $r['reference'] . '-accountability.pdf"');
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

/* ---------- Printable voucher ---------- */
if (isset($_GET['print'])) {
    $sig = fn($label, $id, $at) => '<div class="sig"><small>' . e($label) . '</small><b>' . e($id ? ($names[$id] ?? '') : '') . '</b><span>' . ($at ? e(date('j M Y', strtotime($at))) : '') . '</span><i>Signature</i></div>';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title><?= e($r['reference']) ?> — Requisition</title>
<style>body{font-family:Arial,Helvetica,sans-serif;color:#101935;margin:30px;font-size:13px}h1{font-size:20px;margin:0}table{width:100%;border-collapse:collapse;margin:14px 0}th,td{border:1px solid #ccd;padding:7px;text-align:left}th{background:#f2f4f9;font-size:11px;text-transform:uppercase}.r{text-align:right}.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #E11D2A;padding-bottom:10px;margin-bottom:12px}.meta td{border:0;padding:3px 8px 3px 0}.sigs{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-top:30px}.sig{border-top:1px solid #101935;padding-top:6px}.sig small{display:block;color:#667;text-transform:uppercase;font-size:10px}.sig b{display:block;min-height:16px}.sig i{display:block;margin-top:28px;border-top:1px dashed #99a;font-style:normal;font-size:10px;color:#667;padding-top:3px}@media print{button{display:none}}</style></head>
<body><button onclick="window.print()">Print / save as PDF</button>
<div class="head"><div><h1>Requisition <?= e($r['reference']) ?></h1><div><?= e($project['name']) ?> · <?= e($project['donor']) ?></div></div><div><b>Kakebe Technologies Limited</b><br>Status: <?= e($statuses[$r['status']][0] ?? $r['status']) ?></div></div>
<table class="meta"><tr><td><b>Purpose:</b> <?= e($r['title']) ?></td><td><b>Activity:</b> <?= e($cluster ? $cluster['code'] . ' ' . $cluster['name'] : '—') ?></td></tr>
<tr><td><b>Requested by:</b> <?= e((string) $r['requester_name']) ?> · <?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td><b>Location:</b> <?= e((string) $r['location']) ?> · <b>Needed:</b> <?= $r['needed_on'] ? e(date('j M Y', strtotime($r['needed_on']))) : '—' ?></td></tr></table>
<table><thead><tr><th>Budget line</th><th>Description</th><th class="r">Qty</th><th class="r">Unit cost</th><th class="r">Amount</th><th class="r">Actual spent</th><th>Paid to</th></tr></thead><tbody>
<?php foreach ($items as $it): ?><tr><td><?= e($it['line_code'] . ' ' . $it['line_item']) ?></td><td><?= e($it['description']) ?></td><td class="r"><?= e(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?></td><td class="r"><?= number_format((int) $it['unit_cost']) ?></td><td class="r"><?= number_format((int) $it['amount']) ?></td><td class="r"><?= $it['actual_amount'] !== null ? number_format((int) $it['actual_amount']) : '' ?></td><td><?= e((string) $it['payee']) ?></td></tr><?php endforeach; ?>
<tr><th colspan="4" class="r">Total (UGX)</th><th class="r"><?= number_format((int) $r['total']) ?></th><th class="r"><?= $r['actual_total'] !== null ? number_format((int) $r['actual_total']) : '' ?></th><th><?= $balance !== null ? ($balance >= 0 ? 'Balance returned: ' . number_format($balance) : 'Overspent: ' . number_format(-$balance)) : '' ?></th></tr></tbody></table>
<?php if ($r['disbursed_at']): ?><p><b>Paid:</b> <?= e(ugx($r['disbursed_amount'])) ?> on <?= e(date('j M Y', strtotime($r['disbursed_at']))) ?> by <?= e(fin_methods()[$r['disburse_method']] ?? (string) $r['disburse_method']) ?><?= $r['disburse_ref'] ? ' · ref ' . e($r['disburse_ref']) : '' ?></p><?php endif; ?>
<?php if ($r['justification']): ?><p><b>Justification:</b> <?= nl2br(e($r['justification'])) ?></p><?php endif; ?>
<?php if ($r['accountability']): ?><p><b>Accountability notes:</b> <?= nl2br(e($r['accountability'])) ?></p><?php endif; ?>
<p><b>Receipts attached:</b> <?= count($filesReceipts) ?></p>
<div class="sigs"><?= $sig('Requested by', $r['requested_by'], $r['created_at']) . $sig('Reviewed by (Operations)', $r['reviewed_by'], $r['reviewed_at']) . $sig('Approved by (Team lead)', $r['approved_by'], $r['approved_at']) . $sig('Paid by (Finance)', $r['disbursed_by'], $r['disbursed_at']) . $sig('Accountability verified by', $r['verified_by'], $r['verified_at']) ?></div>
</body></html><?php
    exit;
}

admin_header($r['reference'], 'requisitions', $r['title']);
$st = $statuses[$r['status']] ?? [$r['status'], ''];
$steps = ['submitted' => 'Requested', 'reviewed' => 'Reviewed', 'approved' => 'Approved', 'disbursed' => 'Paid', 'accounted' => 'Accountability', 'closed' => 'Verified'];
$order = array_keys($steps);
$at = array_search($r['status'], $order, true);
?>
<a href="requisitions.php" class="back"><i class="fa-solid fa-arrow-left"></i> All requisitions</a>

<section class="card req-head">
  <div>
    <h2><?= e($r['reference']) ?> <span class="badge <?= $st[1] ?>"><?= e($st[0]) ?></span><?php if (req_overdue($r)): ?> <span class="badge st-cancelled">Accountability overdue</span><?php endif; ?></h2>
    <p class="muted"><?= e($r['title']) ?></p>
  </div>
  <div class="req-amt"><small>Requested</small><b><?= e(ugx($r['total'])) ?></b><?php if ($r['actual_total'] !== null): ?><small>Spent <?= e(ugx($r['actual_total'])) ?></small><?php endif; ?></div>
  <div class="btn-row">
    <a class="btn btn-primary btn-sm" href="requisition.php?id=<?= (int) $r['id'] ?>&amp;pdf=1" target="_blank"><i class="fa-solid fa-file-pdf"></i> <?= in_array($r['status'], ['accounted', 'closed'], true) ? 'Accountability PDF' : 'Requisition PDF' ?></a>
    <a class="btn btn-light btn-sm" href="requisition.php?id=<?= (int) $r['id'] ?>&amp;print=1" target="_blank"><i class="fa-solid fa-print"></i> Print voucher</a>
  </div>
  <?php if (!in_array($r['status'], ['rejected', 'cancelled'], true)): ?>
  <ol class="req-steps">
    <?php foreach ($steps as $k => $label): $i = array_search($k, $order, true); ?><li class="<?= $at !== false && $i < $at ? 'done' : ($i === $at ? 'now' : '') ?><?= $k === 'closed' && $r['status'] === 'closed' ? ' done' : '' ?>"><?= e($label) ?></li><?php endforeach; ?>
  </ol>
  <?php endif; ?>
</section>

<div class="grid-3-1">
  <div class="stack-cards">
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-list"></i> Items</h3></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Item · budget line</th><th class="r">Qty × unit cost</th><th class="r">Amount</th><?php if ($r['actual_total'] !== null): ?><th class="r">Actual</th><th>Paid to</th><?php endif; ?><?php if ($lineStats && !in_array($r['status'], ['closed', 'rejected', 'cancelled'], true)): ?><th class="r">Line still available</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($items as $it): $ls = $lineStats[(int) $it['line_id']] ?? null; ?>
            <tr>
              <td class="fin-name"><b><?= e($it['description']) ?></b><small class="block muted"><?= e($it['line_code'] . ' ' . $it['line_item']) ?></small><?php if ($it['note']): ?><small class="block item-note"><i class="fa-solid fa-comment-dots"></i> <?= e($it['note']) ?></small><?php endif; ?></td>
              <td class="r nowrap muted"><?= e(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?> × <?= number_format((int) $it['unit_cost']) ?></td>
              <td class="r nowrap"><b><?= number_format((int) $it['amount']) ?></b></td>
              <?php if ($r['actual_total'] !== null): ?><td class="r nowrap"><?= number_format((int) $it['actual_amount']) ?></td><td class="small"><?= e((string) $it['payee']) ?></td><?php endif; ?>
              <?php if ($lineStats && !in_array($r['status'], ['closed', 'rejected', 'cancelled'], true)): ?><td class="r nowrap <?= $ls && $ls['available'] < 0 ? 'due' : 'muted' ?>"><?= $ls ? e(ugx($ls['available'])) : '—' ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="total"><td colspan="2" class="r">Total</td><td class="r nowrap"><?= e(ugx($r['total'])) ?></td><?php if ($r['actual_total'] !== null): ?><td class="r nowrap"><?= e(ugx($r['actual_total'])) ?></td><td class="small"><?= $balance > 0 ? e(ugx($balance)) . ' to return' : ($balance < 0 ? e(ugx(-$balance)) . ' overspent' : 'Balanced') ?></td><?php endif; ?><?php if ($lineStats && !in_array($r['status'], ['closed', 'rejected', 'cancelled'], true)): ?><td></td><?php endif; ?></tr></tfoot>
        </table>
      </div>
      <?php if ($lineStats && in_array($r['status'], ['submitted', 'reviewed', 'approved'], true)): ?><p class="small muted">"Line still available" already counts this requisition (it is reserved while it waits). A negative figure means the line would go over budget.</p><?php endif; ?>
    </section>

    <?php /* ---------- Actions ---------- */ ?>
    <?php if ($canReview): ?>
    <section class="card req-action">
      <div class="card-head"><h3><i class="fa-solid fa-magnifying-glass"></i> Operations review</h3></div>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <label>Comment <small class="muted">(required to reject)</small><textarea name="comment" rows="2" maxlength="2000"></textarea></label>
        <div class="btn-row"><button class="btn btn-primary" name="decision" value="recommend" type="submit"><i class="fa-solid fa-thumbs-up"></i> Recommend for approval</button><button class="btn btn-danger" name="decision" value="reject" type="submit" data-confirm-click="Reject this requisition?"><i class="fa-solid fa-xmark"></i> Reject</button></div>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($canApprove): ?>
    <section class="card req-action">
      <div class="card-head"><h3><i class="fa-solid fa-stamp"></i> Team lead approval</h3></div>
      <?php if ($r['status'] === 'submitted'): ?><p class="small muted">Operations has not reviewed this yet. You can review and approve it in one step.</p><?php endif; ?>
      <form method="post" class="stack js-adjust"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <p class="small muted">Approve as requested, or change a quantity or unit cost first (0 leaves an item out). The requester is told about any change.</p>
        <div class="table-wrap"><table class="table adjust-table">
          <thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Unit cost (UGX)</th><th class="r">Amount</th></tr></thead>
          <tbody><?php foreach ($items as $it): ?><tr class="adj-row"><td><?= e($it['description']) ?><small class="block muted"><?= e($it['line_code'] . ' ' . $it['line_item']) ?></small></td>
            <td class="r"><input type="number" name="qty[<?= (int) $it['id'] ?>]" value="<?= e(rtrim(rtrim((string) $it['qty'], '0'), '.')) ?>" min="0" step="0.5" class="js-adj-qty"></td>
            <td class="r"><input type="text" name="unit_cost[<?= (int) $it['id'] ?>]" value="<?= number_format((int) $it['unit_cost']) ?>" inputmode="numeric" class="js-money js-adj-cost"></td>
            <td class="r nowrap"><b class="js-adj-amount"><?= number_format((int) $it['amount']) ?></b></td></tr><?php endforeach; ?></tbody>
          <tfoot><tr class="total"><td colspan="3" class="r">Total to approve</td><td class="r nowrap"><b class="js-adj-total" data-original="<?= (int) $r['total'] ?>"><?= e(ugx($r['total'])) ?></b></td></tr></tfoot>
        </table></div>
        <label>Reason for any change <small class="muted">(shown to the requester)</small><input type="text" name="adjust_note" maxlength="1000" placeholder="e.g. Transport reduced to 3 days"></label>
        <label>Comment <small class="muted">(required to reject)</small><textarea name="comment" rows="2" maxlength="2000"></textarea></label>
        <div class="btn-row"><button class="btn btn-primary js-adj-btn" name="decision" value="approve" type="submit"><i class="fa-solid fa-check"></i> <span>Approve <?= e(ugx($r['total'])) ?></span></button><button class="btn btn-danger" name="decision" value="reject" type="submit" data-confirm-click="Reject this requisition?"><i class="fa-solid fa-xmark"></i> Reject</button></div>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($canDisburse): ?>
    <section class="card req-action">
      <div class="card-head"><h3><i class="fa-solid fa-money-bill-transfer"></i> Pay the requisition</h3></div>
      <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="disburse"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <div class="row-3">
          <label>Amount<input type="text" value="<?= e(ugx($r['total'])) ?>" readonly></label>
          <label>Paid by<select name="method"><?php foreach (fin_methods() as $k => $m): ?><option value="<?= e($k) ?>"><?= e($m) ?></option><?php endforeach; ?></select></label>
          <label>Date paid<input type="date" name="paid_on" value="<?= date('Y-m-d') ?>"></label>
        </div>
        <label>Transaction reference <small class="muted">(MoMo / bank ref, cheque no.)</small><input type="text" name="reference" maxlength="120"></label>
        <label>Proof of payment <small class="muted">(optional — screenshot or slip)</small><input type="file" name="proof[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"></label>
        <label>Comment <small class="muted">(optional)</small><input type="text" name="comment" maxlength="300"></label>
        <div><button class="btn btn-primary" type="submit" data-confirm-click="Confirm you have paid <?= e(ugx($r['total'])) ?> to <?= e((string) $r['requester_name']) ?>?"><i class="fa-solid fa-check"></i> Mark as paid</button></div>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($canAccount): ?>
    <section class="card req-action" id="account">
      <div class="card-head"><h3><i class="fa-solid fa-receipt"></i> Accountability</h3><span class="muted small">due <?= $r['disbursed_at'] ? e(date('j M', strtotime($r['disbursed_at'] . ' +' . req_accountability_days() . ' days'))) : '' ?></span></div>
      <p class="small">For each item, enter what you actually spent, who you paid and <b>what exactly the money was spent on</b>. Attach a photo or scan of <b>every receipt</b>, then sign. Any money not spent is returned to finance. A signed A4 document with your receipts is created automatically.</p>
      <form method="post" enctype="multipart/form-data" class="stack js-sig-form"><?= csrf_field() ?><input type="hidden" name="action" value="account"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Item</th><th class="r">Given</th><th class="r">Actually spent (UGX)</th><th>Paid to (shop / person)</th></tr></thead>
          <tbody><?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?><small class="block muted"><?= e($it['line_code'] . ' ' . $it['line_item']) ?></small></td><td class="r nowrap"><?= number_format((int) $it['amount']) ?></td><td class="r"><input type="text" name="actual[<?= (int) $it['id'] ?>]" value="<?= number_format((int) ($it['actual_amount'] ?? $it['amount'])) ?>" inputmode="numeric" class="js-money" required></td><td><input type="text" name="payee[<?= (int) $it['id'] ?>]" value="<?= e((string) $it['payee']) ?>" maxlength="150"></td></tr>
            <tr class="note-row"><td colspan="4"><label class="item-note-input">What exactly was this money spent on? <small class="muted">(required — e.g. "Boda transport Kitgum–Akwang return, 2 staff × 2 days at 25,000")</small><textarea name="note[<?= (int) $it['id'] ?>]" rows="2" maxlength="1000"><?= e((string) $it['note']) ?></textarea></label></td></tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="row-2">
          <label>Date the money was spent<input type="date" name="spent_on" value="<?= e(date('Y-m-d', strtotime((string) ($r['accounted_at'] ?: 'today')))) ?>"></label>
          <label>Receipts <small class="muted">(required — photos or PDF, several at once)</small><input type="file" name="receipts[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"<?= $filesReceipts ? '' : ' required' ?>></label>
        </div>
        <?php if ($filesReceipts): ?><p class="small muted">Already attached: <?= count($filesReceipts) ?> receipt<?= count($filesReceipts) === 1 ? '' : 's' ?> — add more if needed.</p><?php endif; ?>
        <label>Report / notes <small class="muted">(what was done, any balance returned)</small><textarea name="accountability" rows="3" maxlength="3000"><?= e((string) $r['accountability']) ?></textarea></label>
        <?php $signerId = $own ? $me : (int) $r['requested_by']; $hasSig = (bool) admin_signature_path((string) q('SELECT signature_file FROM admins WHERE id = ?', [$signerId])->fetchColumn()); ?>
        <div class="sign-box">
          <h4><i class="fa-solid fa-signature"></i> Sign your accountability</h4>
          <?php if ($own && $hasSig): ?>
            <div class="sig-current"><img src="signature.php" alt="Your saved signature"></div>
            <p class="small muted">Your saved signature will be added to the accountability document. <a href="users.php#signature">Change it</a></p>
          <?php elseif ($own): ?>
            <p class="small">You have no saved signature yet — draw it below. It is saved to your account for next time.</p>
            <div class="sig-pad"><canvas width="600" height="200" class="js-sig-canvas" aria-label="Draw your signature"></canvas><span class="sig-hint">Sign here with your finger or mouse</span></div>
            <input type="hidden" name="signature_data" class="js-sig-data">
            <div><button type="button" class="btn btn-light btn-sm js-sig-clear"><i class="fa-solid fa-eraser"></i> Clear</button></div>
          <?php else: ?>
            <p class="small muted">You are submitting this for <?= e((string) $r['requester_name']) ?>. <?= $hasSig ? 'Their saved signature will be used.' : 'They have no saved signature, so the document will show their name without a signature.' ?></p>
          <?php endif; ?>
          <label class="check-inline"><input type="checkbox" name="confirm_sign" value="1" required> I confirm this accountability and the receipts are true and correct, and I sign it.</label>
        </div>
        <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-file-signature"></i> Sign &amp; submit accountability</button></div>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($canVerify): ?>
    <section class="card req-action">
      <div class="card-head"><h3><i class="fa-solid fa-clipboard-check"></i> Verify accountability</h3></div>
      <p class="small">Spent <b><?= e(ugx($r['actual_total'])) ?></b> of <?= e(ugx($r['disbursed_amount'])) ?> paid · <?= $balance > 0 ? '<b>' . e(ugx($balance)) . '</b> should be returned' : ($balance < 0 ? '<b>' . e(ugx(-$balance)) . '</b> overspent — owed to the requester' : 'balanced') ?> · <?= count($filesReceipts) ?> receipt<?= count($filesReceipts) === 1 ? '' : 's' ?>.</p>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <label>Balance settled <small class="muted">(e.g. "UGX 20,000 returned in cash on 3 Nov")</small><input type="text" name="balance_note" maxlength="500"></label>
        <label>Comment <small class="muted">(required to return for correction)</small><textarea name="comment" rows="2" maxlength="2000"></textarea></label>
        <div class="btn-row"><button class="btn btn-primary" name="decision" value="verify" type="submit" data-confirm-click="Verify and record <?= e(ugx($r['actual_total'])) ?> as spent?"><i class="fa-solid fa-circle-check"></i> Verify &amp; complete</button><button class="btn btn-light" name="decision" value="return" type="submit"><i class="fa-solid fa-rotate-left"></i> Return for correction</button></div>
      </form>
    </section>
    <?php endif; ?>
  </div>

  <div class="stack-cards">
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-circle-info"></i> Details</h3></div>
      <dl class="details">
        <div><dt>Requested by</dt><dd><?= e((string) $r['requester_name']) ?></dd></div>
        <div><dt>Activity</dt><dd><?= e($cluster ? $cluster['code'] . ' · ' . $cluster['name'] : '—') ?></dd></div>
        <div><dt>Where</dt><dd><?= e((string) $r['location']) ?></dd></div>
        <div><dt>Needed on</dt><dd><?= $r['needed_on'] ? e(date('D j M Y', strtotime($r['needed_on']))) : '—' ?></dd></div>
        <?php if ($r['disbursed_at']): ?><div><dt>Paid</dt><dd><?= e(date('j M Y', strtotime($r['disbursed_at']))) ?> · <?= e(fin_methods()[$r['disburse_method']] ?? '') ?><?= $r['disburse_ref'] ? ' · ' . e($r['disburse_ref']) : '' ?></dd></div><?php endif; ?>
        <?php if ($r['balance_note']): ?><div><dt>Balance</dt><dd><?= e($r['balance_note']) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($r['justification']): ?><h4 class="sub-title">Justification</h4><p class="small"><?= nl2br(e($r['justification'])) ?></p><?php endif; ?>
      <?php if ($r['accountability']): ?><h4 class="sub-title">Accountability report</h4><p class="small"><?= nl2br(e($r['accountability'])) ?></p><?php endif; ?>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-paperclip"></i> Documents</h3></div>
      <h4 class="sub-title">Supporting</h4><?= fin_files_html($filesSupport) ?>
      <h4 class="sub-title">Proof of payment</h4><?= fin_files_html($filesPayment) ?>
      <h4 class="sub-title">Receipts</h4><?= fin_files_html($filesReceipts) ?>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> History</h3></div>
      <ul class="feed req-log">
        <?php foreach ($log as $l): ?><li><b><?= e(ucfirst($l['action'])) ?></b> · <?= e($l['admin_name'] ?? '') ?><small class="block muted"><?= e(date('j M Y, g:i a', strtotime($l['created_at']))) ?></small><?php if ($l['comment']): ?><span class="small"><?= e($l['comment']) ?></span><?php endif; ?></li><?php endforeach; ?>
      </ul>
    </section>
    <?php if ($canCancel): ?>
    <form method="post" data-confirm="Cancel this requisition?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel requisition</button></form>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer(['finance.js']);
