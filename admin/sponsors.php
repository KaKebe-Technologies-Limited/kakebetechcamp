<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $d = q('SELECT * FROM donations WHERE id = ?', [(int) ($_POST['id'] ?? 0)])->fetch();
    switch ($_POST['action'] ?? '') {
        case 'sponsor_add':
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
            if (mb_strlen($name) < 2) {
                flash('Enter the name of the sponsor.', 'error');
                break;
            }
            $em = strtolower(trim((string) ($_POST['email'] ?? '')));
            q("INSERT INTO sponsors (name, organization, email, phone, seats, notes, source, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 'admin', 1, ?)", [
                $name, mb_substr(trim((string) ($_POST['organization'] ?? '')), 0, 150) ?: null, filter_var($em, FILTER_VALIDATE_EMAIL) ? $em : null,
                mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40) ?: null, max(0, (int) ($_POST['seats'] ?? 0)), mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 255) ?: null, now(),
            ]);
            flash("$name added. Participants can now choose them when registering as sponsored.");
            break;
        case 'sponsor_toggle':
            q('UPDATE sponsors SET is_active = 1 - is_active WHERE id = ?', [(int) ($_POST['sponsor_id'] ?? 0)]);
            flash('Sponsor visibility updated.');
            break;
        case 'sponsor_delete':
            $sid = (int) ($_POST['sponsor_id'] ?? 0);
            if ((int) q('SELECT COUNT(*) FROM registrations WHERE sponsor_id = ?', [$sid])->fetchColumn() > 0) {
                q('UPDATE sponsors SET is_active = 0 WHERE id = ?', [$sid]);
                flash('This sponsor has participants linked, so it was hidden instead of deleted.');
            } else {
                q('DELETE FROM sponsors WHERE id = ?', [$sid]);
                flash('Sponsor removed.');
            }
            break;
        case 'approve':
            $r = find_registration((int) ($_POST['registration_id'] ?? 0));
            if ($r && $r['status'] === 'review') {
                $sent = approve_sponsorship($r, true);
                flash('Sponsorship approved for ' . $r['full_name'] . '.' . ($sent ? ' Confirmation & ticket emailed.' . mail_note() : ''));
            }
            break;
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

$sponsorRows = q("SELECT s.*, (SELECT COUNT(*) FROM registrations r WHERE r.sponsor_id = s.id AND r.status <> 'cancelled') AS linked,
    (SELECT COUNT(*) FROM registrations r WHERE r.sponsor_id = s.id AND r.payment_status = 'sponsored') AS approved FROM sponsors s ORDER BY s.is_active DESC, s.name")->fetchAll();
$awaiting = q("SELECT * FROM registrations WHERE status = 'review' ORDER BY id")->fetchAll();
$sponsoredCount = (int) q("SELECT COUNT(*) FROM registrations WHERE payment_status = 'sponsored' AND status <> 'cancelled'")->fetchColumn();

admin_header('Sponsorships', 'sponsors', 'Sponsors, sponsored participants and sponsor-an-innovator donations');
?>
<div class="card approval-card">
  <div class="card-head"><h3><i class="fa-solid fa-user-check"></i> Awaiting sponsorship approval</h3><span class="muted"><?= count($awaiting) ?> waiting · <?= $sponsoredCount ?> approved so far</span></div>
  <?php if ($awaiting): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Participant</th><th>Sponsor named</th><th>Phone</th><th>Registered</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($awaiting as $r): ?>
        <tr>
          <td><div class="person"><?= avatar_html($r) ?><div><b><?= e($r['full_name']) ?></b><small><?= e($r['reference']) ?> · <?= e($r['email']) ?></small></div></div></td>
          <td><b><?= e($r['sponsor_name'] ?: '—') ?></b><?= $r['sponsor_id'] ? '' : '<small class="block muted">Not on the sponsor list</small>' ?></td>
          <td class="nowrap"><a href="<?= e(tel_link($r['phone'])) ?>"><?= e($r['phone']) ?></a></td>
          <td class="muted nowrap"><?= e(time_ago($r['created_at'])) ?></td>
          <td class="nowrap actions">
            <a class="btn btn-light btn-sm" href="view.php?id=<?= (int) $r['id'] ?>"><i class="fa-regular fa-eye"></i> View</a>
            <form method="post" class="inline" data-confirm="Approve the sponsorship for <?= e($r['full_name']) ?> and email their ticket?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="registration_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-check"></i> Approve</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?><p class="empty-note">No sponsored registrations waiting for approval.</p><?php endif; ?>
</div>

<div class="grid-3-1">
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-building-columns"></i> Sponsor list</h3><span class="muted small">Shown in the registration form under "Who is sponsoring you?"</span></div>
    <?php if ($sponsorRows): ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Sponsor</th><th>Contact</th><th>Seats</th><th>Participants</th><th>Source</th><th>Visible</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sponsorRows as $sp): ?>
          <tr>
            <td><b><?= e($sp['name']) ?></b><?php if ($sp['organization']): ?><small class="block muted"><?= e($sp['organization']) ?></small><?php endif; ?></td>
            <td class="small"><?= e($sp['email'] ?: '—') ?><br><?= e($sp['phone'] ?: '') ?></td>
            <td><?= (int) $sp['seats'] ?: '—' ?></td>
            <td><a href="registrations.php?funding=sponsored&amp;q=<?= e(rawurlencode($sp['name'])) ?>"><?= (int) $sp['linked'] ?></a> <small class="muted">(<?= (int) $sp['approved'] ?> approved)</small></td>
            <td><span class="tag"><?= $sp['source'] === 'website' ? 'Website gift' : 'Added by admin' ?></span></td>
            <td><?= $sp['is_active'] ? '<span class="badge st-confirmed">Yes</span>' : '<span class="badge st-waitlisted">Hidden</span>' ?></td>
            <td class="nowrap actions">
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="sponsor_toggle"><input type="hidden" name="sponsor_id" value="<?= (int) $sp['id'] ?>"><button class="icon-btn" title="<?= $sp['is_active'] ? 'Hide from list' : 'Show in list' ?>"><i class="fa-regular <?= $sp['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
              <form method="post" class="inline" data-confirm="Remove <?= e($sp['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="sponsor_delete"><input type="hidden" name="sponsor_id" value="<?= (int) $sp['id'] ?>"><button class="icon-btn danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><p class="empty-note">No sponsors yet. Add the people and organisations sponsoring participants so applicants can select them.</p><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-plus"></i> Add a sponsor</h3></div>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="sponsor_add">
      <label>Name<input type="text" name="name" required placeholder="Person or organisation"></label>
      <label>Organisation <small class="muted">(optional)</small><input type="text" name="organization"></label>
      <label>Email<input type="email" name="email"></label>
      <label>Phone<input type="text" name="phone"></label>
      <label>Seats sponsored <small class="muted">(optional)</small><input type="number" name="seats" min="0" value="0"></label>
      <label>Notes<input type="text" name="notes" maxlength="250"></label>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus"></i> Add sponsor</button>
    </form>
  </div>
</div>

<h2 class="section-title">Sponsor-an-innovator donations</h2>
<?php
?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon purple"><i class="fa-solid fa-hand-holding-heart"></i></span><div><small>Total received</small><b><?= e(format_ugx($tot['paid'])) ?></b><em>from sponsors</em></div></div>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-user-graduate"></i></span><div><small>Innovators sponsored</small><b><?= (int) $tot['kids'] ?></b><em>at <?= e(format_ugx(fees()['sponsor_child'])) ?> each</em></div></div>
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
      <thead><tr><th>Sponsor</th><th>Reference</th><th>Innovators</th><th>Pledged</th><th>Received</th><th>Status</th><th>Message</th><th>Date</th><th></th></tr></thead>
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
  <?php else: ?><div class="empty-state"><i class="fa-solid fa-hand-holding-heart"></i><p>No sponsorships yet. Share the "Sponsor an innovator" section of the website.</p><a class="btn btn-primary" href="../#sponsor" target="_blank">Open sponsor section</a></div><?php endif; ?>
</div>
<?php admin_footer();
