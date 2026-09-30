<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

/* ---------- Remind one participant (the row button): emails them; the browser opens WhatsApp ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind_one') {
    require_csrf();
    $r = find_registration((int) ($_POST['id'] ?? 0));
    if (!$r || !can_remind($r)) {
        json_response(['ok' => false, 'message' => 'This participant has nothing left to pay.'], 422);
    }
    $sent = send_balance_reminder($r);
    json_response(['ok' => true, 'emailed' => $sent, 'message' => $sent
        ? 'Reminder emailed to ' . $r['email'] . '.' . mail_note()
        : 'The reminder email to ' . $r['email'] . ' could not be sent — check Settings → Email.']);
}

/* ---------- Bulk actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $action = (string) ($_POST['bulk_action'] ?? '');
    $back = 'registrations.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : '');
    if (!$ids || $action === '') {
        flash('Select at least one participant and an action.', 'error');
        redirect($back);
    }
    $done = 0;
    $mailed = 0;
    foreach ($ids as $id) {
        $r = find_registration($id);
        if (!$r) continue;
        switch ($action) {
            case 'approve':
                if ($r['status'] === 'review') {
                    $mailed += approve_sponsorship($r, true) ? 1 : 0;
                    $done++;
                }
                break;
            case 'remind':
                if (can_remind($r)) {
                    $mailed += send_balance_reminder($r) ? 1 : 0;
                    $done++;
                }
                break;
            case 'waitlisted':
            case 'cancelled':
            case 'auto':
                set_registration_status($r, $action);
                $done++;
                break;
            case 'delete':
                delete_registration($r);
                $done++;
                break;
        }
    }
    $labels = ['approve' => 'approved (sponsorship)', 'remind' => 'reminded', 'waitlisted' => 'waitlisted', 'cancelled' => 'cancelled', 'auto' => 'restored', 'delete' => 'deleted'];
    flash($done . ' participant' . ($done === 1 ? '' : 's') . ' ' . ($labels[$action] ?? 'updated') . ($mailed ? " · $mailed email" . ($mailed === 1 ? '' : 's') . ' sent' . mail_note() : '') . '.');
    redirect($back);
}

[$where, $params, $f] = registration_filters($_GET);
$perPage = 30;
$total = (int) q("SELECT COUNT(*) FROM registrations $where", $params)->fetchColumn();
$sums = q("SELECT COALESCE(SUM(total_amount),0) t, COALESCE(SUM(amount_paid),0) p FROM registrations $where", $params)->fetch();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$list = q("SELECT * FROM registrations $where ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$qs = array_filter($f, fn($v) => $v !== '');
$qsString = $qs ? '?' . http_build_query($qs) : '';
$pageUrl = fn(int $p) => 'registrations.php?' . http_build_query($qs + ['page' => $p]);
$tabs = ['' => 'All', 'review' => 'Awaiting approval', 'pending' => 'Registered', 'confirmed' => 'Confirmed', 'waitlisted' => 'Waitlisted', 'cancelled' => 'Cancelled'];

admin_header('Participants', 'registrations', number_format($total) . ' matching · package value ' . format_ugx($sums['t']) . ' · paid ' . format_ugx($sums['p']));
?>
<div class="tabs big">
  <?php foreach ($tabs as $k => $label): $n = (int) q('SELECT COUNT(*) FROM registrations' . ($k ? ' WHERE status = ?' : ''), $k ? [$k] : [])->fetchColumn(); ?>
  <a href="registrations.php<?= $k ? '?status=' . $k : '' ?>" class="<?= $f['status'] === $k ? 'active' : '' ?>"><?= e($label) ?> <em><?= $n ?></em></a>
  <?php endforeach; ?>
</div>

<form class="card filters" method="get">
  <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, email, phone, reference, district, referrer…"></div>
  <select name="payment"><option value="">Any payment</option><option value="balance" <?= $f['payment'] === 'balance' ? 'selected' : '' ?>>Has a balance</option><?php foreach (payment_statuses() as $k => $l): ?><option value="<?= $k ?>" <?= $f['payment'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="funding"><option value="">Self &amp; sponsored</option><option value="self" <?= $f['funding'] === 'self' ? 'selected' : '' ?>>Self-funded</option><option value="sponsored" <?= $f['funding'] === 'sponsored' ? 'selected' : '' ?>>Sponsored</option></select>
  <select name="park"><option value="">Aruu Falls: any</option><option value="yes" <?= $f['park'] === 'yes' ? 'selected' : '' ?>>Park visit: yes</option><option value="no" <?= $f['park'] === 'no' ? 'selected' : '' ?>>Park visit: no</option></select>
  <select name="interest"><option value="">Any track</option><?php foreach (interests() as $t): ?><option <?= $f['interest'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <select name="jersey"><option value="">Any size</option><?php foreach (jersey_sizes() as $s): ?><option <?= $f['jersey'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
  <select name="source"><option value="">Any source</option><?php foreach (sources() as $s): ?><option <?= $f['source'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
  <label class="date-field">From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label class="date-field">To <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($qs): ?><a href="registrations.php" class="btn btn-light">Clear</a><?php endif; ?>
</form>

<form method="post" id="bulkForm" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="return" value="<?= e($qsString) ?>">
  <div class="card-head list-head">
    <h3><?= number_format($total) ?> participant<?= $total === 1 ? '' : 's' ?></h3>
    <div class="bulk">
      <select name="bulk_action" id="bulkAction">
        <option value="">Bulk action…</option>
        <option value="approve">Approve sponsorship</option>
        <option value="remind">Email balance reminder</option>
        <option value="waitlisted">Move to waitlist</option>
        <option value="cancelled">Cancel registration</option>
        <option value="auto">Restore (status from payments)</option>
        <option value="delete">Delete</option>
      </select>
      <button class="btn btn-primary btn-sm" type="submit" id="bulkApply" disabled>Apply</button>
      <a href="export.php?type=participants&amp;<?= e(http_build_query($qs)) ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <?php if ($list): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th class="w-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><th>Participant</th><th>Reference</th><th>Phone</th><th>District</th><th>Tracks</th><th>Size</th><th>Park</th><th>Funding</th><th>Paid / package</th><th>Balance</th><th>Status</th><th>Registered</th><th class="row-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): ?>
        <tr class="row-link" data-href="view.php?id=<?= (int) $r['id'] ?>">
          <td class="w-check"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="row-check" aria-label="Select <?= e($r['full_name']) ?>"></td>
          <td><div class="person"><?= avatar_html($r) ?><div><b><?= e($r['full_name']) ?><?= $r['auth_provider'] === 'google' ? ' <i class="fa-brands fa-google g-mark" title="Signed up with Google"></i>' : '' ?></b><small><?= e($r['email']) ?></small></div></div></td>
          <td><a class="ref" href="view.php?id=<?= (int) $r['id'] ?>"><?= e($r['reference']) ?></a></td>
          <td class="nowrap"><a href="<?= e(tel_link($r['phone'])) ?>"><?= e($r['phone']) ?></a></td>
          <td><?= e($r['district']) ?></td>
          <td class="tracks-cell"><?php foreach (array_filter(array_map('trim', explode(',', (string) $r['interests']))) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></td>
          <td><b><?= e($r['jersey_size'] ?: '—') ?></b></td>
          <td><?= $r['park_visit'] ? '<span class="badge st-booked"><i class="fa-solid fa-water"></i> Yes</span>' : '<span class="muted">—</span>' ?></td>
          <td><?= funding_badge($r) ?><?php if (is_sponsored($r)): ?><small class="block muted"><?= e(mb_strimwidth((string) $r['sponsor_name'], 0, 26, '…')) ?></small><?php endif; ?></td>
          <td style="min-width:150px;"><span class="money"><b><?= number_format((int) $r['amount_paid']) ?></b> / <?= number_format((int) $r['total_amount']) ?></span><?= progress_bar($r) ?></td>
          <td><?= balance($r) ? '<b class="due">' . number_format(balance($r)) . '</b>' : '<b class="ok-text">0</b>' ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="nowrap muted" title="<?= e($r['created_at']) ?>"><?= e(date('j M, g:i a', strtotime($r['created_at']))) ?></td>
          <td class="nowrap row-actions">
            <?php if (can_remind($r)): ?><button type="button" class="btn btn-sm btn-remind js-remind" data-id="<?= (int) $r['id'] ?>" data-wa="<?= e(participant_whatsapp_reminder_link($r)) ?>" title="Emails a payment reminder and opens WhatsApp with the message ready to send"><i class="fa-solid fa-bell"></i> Remind</button><?php endif; ?>
            <a class="btn btn-light btn-sm" href="view.php?id=<?= (int) $r['id'] ?>"><i class="fa-regular fa-eye"></i> View</a>
            <?php if (!empty($r['reminded_at']) && can_remind($r)): ?><small class="block muted reminded-note">Reminded <?= e(time_ago($r['reminded_at'])) ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pagination">
    <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
    <div>
      <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><a href="<?= e($pageUrl($p)) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($pageUrl($page + 1)) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>
  <?php else: ?>
    <div class="empty-state"><i class="fa-regular fa-folder-open"></i><p><?= $qs ? 'No participants match your filters.' : 'No registrations yet.' ?></p></div>
  <?php endif; ?>
</form>
<?php admin_footer();
