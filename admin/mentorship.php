<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$tracks = mentorship_tracks();
$statuses = ['confirmed' => 'Confirmed', 'waitlist' => 'Waiting list', 'pending' => 'Awaiting email confirmation'];

/** WHERE clause for the filters (status, field, search). */
$filters = function (array $in) use ($tracks, $statuses): array {
    $f = ['status' => (string) ($in['status'] ?? ''), 'track' => (string) ($in['track'] ?? ''), 'q' => trim((string) ($in['q'] ?? ''))];
    $where = [];
    $params = [];
    if (isset($statuses[$f['status']])) {
        $where[] = 'status = ?';
        $params[] = $f['status'];
    } else {
        $f['status'] = '';
    }
    if (in_array($f['track'], $tracks, true)) {
        $where[] = 'FIND_IN_SET(?, tracks)';
        $params[] = $f['track'];
    } else {
        $f['track'] = '';
    }
    if ($f['q'] !== '') {
        $where[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR whatsapp LIKE ? OR location LIKE ? OR reference LIKE ? OR referred_by LIKE ?)';
        array_push($params, ...array_fill(0, 7, '%' . $f['q'] . '%'));
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params, $f];
};

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === '1') {
    [$where, $params] = $filters($_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-mentorship-dbip-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Mentorship number', 'Full name', 'Email', 'Phone', 'WhatsApp', 'Based in', 'Fields', 'Recommended by', 'Status', 'Registered', 'Confirmed']);
    foreach (q("SELECT * FROM mentorship_registrations$where ORDER BY id", $params) as $r) {
        fputcsv($out, [$r['reference'], $r['full_name'], $r['email'], $r['phone'], $r['whatsapp'], $r['location'], str_replace(',', '; ', $r['tracks']), $r['referred_by'], $statuses[$r['status']] ?? $r['status'], $r['created_at'], $r['confirmed_at']]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'mentorship.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : '');

    if ($action === 'settings') {
        setting_set('mentorship_open', !empty($_POST['mentorship_open']) ? '1' : '0');
        setting_set('mentorship_capacity', (string) max(1, min(10000, (int) ($_POST['mentorship_capacity'] ?? 50))));
        foreach (['mentorship_start_date', 'mentorship_end_date'] as $k) {
            $d = (string) ($_POST[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                setting_set($k, $d);
            }
        }
        foreach (['mentorship_session_link', 'mentorship_whatsapp_link'] as $k) {
            $u = trim((string) ($_POST[$k] ?? ''));
            setting_set($k, $u === '' || preg_match('~^https://~i', $u) ? mb_substr($u, 0, 300) : setting($k));
        }
        $list = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim(str_replace(',', ' ', $t)), 0, 60), preg_split('/\R/', (string) ($_POST['mentorship_tracks'] ?? '')) ?: []))));
        setting_set('mentorship_tracks', count($list) >= 2 ? implode("\n", $list) : '');
        flash('Mentorship settings saved.');
        redirect('mentorship.php');
    }

    if ($action === 'to_contacts') {
        $name = 'Mentorship & DBIP';
        $listId = (int) q('SELECT id FROM mk_lists WHERE name = ?', [$name])->fetchColumn();
        if (!$listId) {
            q('INSERT INTO mk_lists (name, created_at) VALUES (?, ?)', [$name, now()]);
            $listId = (int) db()->lastInsertId();
        }
        $added = 0;
        foreach (q("SELECT full_name, email FROM mentorship_registrations WHERE status = 'confirmed'")->fetchAll() as $r) {
            $added += q('INSERT IGNORE INTO mk_contacts (email, name, status, domain, domain_checked, token, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$r['email'], $r['full_name'], 'valid', substr((string) strrchr($r['email'], '@'), 1), mk_token(), now()])->rowCount();
            q('INSERT IGNORE INTO mk_list_contacts (list_id, contact_id) SELECT ?, id FROM mk_contacts WHERE email = ?', [$listId, $r['email']]);
        }
        flash('Confirmed mentees copied to the "' . $name . '" list in Email contacts (' . $added . ' new).');
        redirect('marketing-contacts.php?list=' . $listId);
    }

    if ($action === 'bulk') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $do = (string) ($_POST['bulk_action'] ?? '');
        if (!$ids || $do === '') {
            flash('Select at least one person and an action.', 'error');
            redirect($back);
        }
        $done = 0;
        $mailed = 0;
        foreach ($ids as $id) {
            $m = find_mentee($id);
            if (!$m) continue;
            if ($do === 'confirm' && $m['status'] !== 'confirmed') {
                confirm_mentee($m, true);   // an admin can give a place even when the program is full
                $done++;
                $mailed++;
            } elseif ($do === 'resend' && $m['status'] === 'pending') {
                $mailed += send_mentorship_link($m) ? 1 : 0;
                $done++;
            } elseif ($do === 'delete') {
                q('DELETE FROM mentorship_registrations WHERE id = ?', [$id]);
                $done++;
            }
        }
        $labels = ['confirm' => 'given a place (welcome email sent)', 'resend' => 'sent the confirmation link again', 'delete' => 'deleted'];
        flash($done . ' ' . ($done === 1 ? 'person' : 'people') . ' ' . ($labels[$do] ?? 'updated') . '.' . ($mailed ? mail_note() : ''));
        redirect($back);
    }
    redirect($back);
}

[$where, $params, $f] = $filters($_GET);
$perPage = 40;
$total = (int) q("SELECT COUNT(*) FROM mentorship_registrations$where", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = q("SELECT * FROM mentorship_registrations$where ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$qs = array_filter($f, fn($v) => $v !== '');
$qsString = $qs ? '?' . http_build_query($qs) : '';
$url = fn(array $extra) => 'mentorship.php?' . http_build_query(array_filter($extra + $qs, fn($v) => $v !== '' && $v !== null));

$counts = array_column(q('SELECT status, COUNT(*) c FROM mentorship_registrations GROUP BY status')->fetchAll(), 'c', 'status');
$confirmed = (int) ($counts['confirmed'] ?? 0);
$pending = (int) ($counts['pending'] ?? 0);
$waitlist = (int) ($counts['waitlist'] ?? 0);
$capacity = mentorship_capacity();
$week = (int) q("SELECT COUNT(*) FROM mentorship_registrations WHERE status = 'confirmed' AND confirmed_at >= ?", [date('Y-m-d H:i:s', strtotime('-7 days'))])->fetchColumn();
$trackCounts = [];
foreach ($tracks as $t) {
    $trackCounts[] = ['label' => $t, 'c' => (int) q("SELECT COUNT(*) FROM mentorship_registrations WHERE status = 'confirmed' AND FIND_IN_SET(?, tracks)", [$t])->fetchColumn()];
}
usort($trackCounts, fn($a, $b) => $b['c'] <=> $a['c']);
$places = q("SELECT location label, COUNT(*) c FROM mentorship_registrations WHERE status = 'confirmed' GROUP BY location ORDER BY c DESC LIMIT 6")->fetchAll();
$recommenders = q("SELECT MIN(referred_by) label, COUNT(*) c FROM mentorship_registrations WHERE referred_by IS NOT NULL AND TRIM(referred_by) <> '' GROUP BY LOWER(TRIM(referred_by)) ORDER BY c DESC LIMIT 6")->fetchAll();
$daily = days_series("SELECT DATE(confirmed_at) d, COUNT(*) v FROM mentorship_registrations WHERE status = 'confirmed' AND confirmed_at >= ? GROUP BY DATE(confirmed_at)", 21);
$sched = mentorship_schedule();
$campers = (int) q("SELECT COUNT(*) FROM registrations WHERE status <> 'cancelled' AND mentorship = 1")->fetchColumn();
$publicUrl = base_url('mentorship');

admin_header('Mentorship & DBIP', 'mentorship', 'Mentorship Program & Digital Bridge Internship registry · online every Monday, 8:00 – 9:30 PM');
?>
<div class="kpis">
  <a class="kpi" href="mentorship.php?status=confirmed"><span class="kpi-icon green"><i class="fa-solid fa-user-check"></i></span><div><small>Places taken</small><b><?= number_format($confirmed) ?> / <?= number_format($capacity) ?></b><em><?= number_format(max(0, $capacity - $confirmed)) ?> left · +<?= $week ?> in 7 days</em></div></a>
  <a class="kpi" href="mentorship.php?status=pending"><span class="kpi-icon amber"><i class="fa-solid fa-envelope-circle-check"></i></span><div><small>Awaiting confirmation</small><b><?= number_format($pending) ?></b><em><?= $waitlist ? number_format($waitlist) . ' on the waiting list' : "haven't clicked the email link" ?></em></div></a>
  <div class="kpi"><span class="kpi-icon blue"><i class="fa-regular fa-calendar"></i></span><div><small><?= $sched['started'] ? 'Next session' : 'First session' ?></small><b><?= $sched['next'] ? e(date('D j M', $sched['next'])) : 'Ended' ?></b><em>Mondays, 8:00 – 9:30 PM</em></div></div>
  <a class="kpi" href="registrations.php"><span class="kpi-icon red"><i class="fa-solid fa-campground"></i></span><div><small>Tech Camp participants</small><b><?= number_format($campers) ?></b><em>also enrolled automatically</em></div></a>
</div>

<div class="grid-3">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-bullseye"></i> Fields chosen</h3></div>
    <?= bar_list($trackCounts, $confirmed) ?>
  </section>
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-location-dot"></i> Where they are</h3></div>
    <?= bar_list($places, $confirmed, 'navy') ?>
    <h4 class="ment-sub-h"><i class="fa-solid fa-trophy"></i> Top recommenders</h4>
    <?= $recommenders ? bar_list($recommenders, max(1, array_sum(array_column($recommenders, 'c'))), 'red') : '<p class="empty-note">No recommendations yet.</p>' ?>
  </section>
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-link"></i> Registration link</h3><span class="badge <?= mentorship_open() ? 'st-confirmed' : 'st-cancelled' ?>"><?= mentorship_open() ? 'Open' : 'Closed' ?></span></div>
    <div class="copy-field"><input type="text" value="<?= e($publicUrl) ?>" readonly onclick="this.select()"><a class="btn btn-light btn-sm" href="<?= e($publicUrl) ?>" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
    <p class="small muted">Share this link anywhere. People confirm by email before they appear as Registered.</p>
    <p class="small muted mk-chart-label">Registrations · last 21 days</p>
    <?= bar_chart($daily, '', 'mk-sent') ?>
    <div class="ment-admin-actions">
      <button type="button" class="btn btn-light btn-sm" data-toggle="#mentSettings"><i class="fa-solid fa-gear"></i> Settings</button>
      <?php if ($confirmed): ?>
      <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="to_contacts"><button class="btn btn-light btn-sm" type="submit" title="Adds everyone registered to a list you can email from Email campaigns"><i class="fa-solid fa-bullhorn"></i> Copy to email contacts</button></form>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="card" id="mentSettings" hidden>
  <div class="card-head"><h3><i class="fa-solid fa-gear"></i> Mentorship settings</h3></div>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <label class="switch"><input type="checkbox" name="mentorship_open" value="1" <?= mentorship_open() ? 'checked' : '' ?>><span class="slider"></span> Registration is open</label>
    <label style="max-width:260px;">Places <small class="muted">when full, new people go on the waiting list</small><input type="number" name="mentorship_capacity" min="1" max="10000" value="<?= (int) $capacity ?>"></label>
    <div class="row-2">
      <label>First session (a Monday)<input type="date" name="mentorship_start_date" value="<?= e(date('Y-m-d', $sched['start'])) ?>"></label>
      <label>Last session<input type="date" name="mentorship_end_date" value="<?= e(date('Y-m-d', $sched['end'])) ?>"></label>
    </div>
    <div class="row-2">
      <label>Online session link <small class="muted">Google Meet / Zoom — included in the welcome email</small><input type="url" name="mentorship_session_link" value="<?= e(setting('mentorship_session_link')) ?>" placeholder="https://meet.google.com/…"></label>
      <label>Mentorship WhatsApp group link <small class="muted">shown after confirming and in the welcome email</small><input type="url" name="mentorship_whatsapp_link" value="<?= e(setting('mentorship_whatsapp_link')) ?>" placeholder="https://chat.whatsapp.com/…"></label>
    </div>
    <label>Internship fields <small class="muted">one per line — people choose up to three</small><textarea name="mentorship_tracks" rows="6"><?= e(implode("\n", $tracks)) ?></textarea></label>
    <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save settings</button></div>
  </form>
</section>

<div class="tabs big">
  <a href="<?= e($url(['status' => null, 'page' => null])) ?>" class="<?= $f['status'] === '' ? 'active' : '' ?>">All <em><?= number_format($confirmed + $pending + $waitlist) ?></em></a>
  <a href="<?= e($url(['status' => 'confirmed', 'page' => null])) ?>" class="<?= $f['status'] === 'confirmed' ? 'active' : '' ?>">Registered <em><?= number_format($confirmed) ?></em></a>
  <a href="<?= e($url(['status' => 'waitlist', 'page' => null])) ?>" class="<?= $f['status'] === 'waitlist' ? 'active' : '' ?>">Waiting list <em><?= number_format($waitlist) ?></em></a>
  <a href="<?= e($url(['status' => 'pending', 'page' => null])) ?>" class="<?= $f['status'] === 'pending' ? 'active' : '' ?>">Awaiting confirmation <em><?= number_format($pending) ?></em></a>
</div>

<form class="card filters" method="get">
  <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, email, phone, place, number, recommender…"></div>
  <select name="track"><option value="">Any field</option><?php foreach ($tracks as $t): ?><option <?= $f['track'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($f['q'] !== '' || $f['track']): ?><a href="<?= e($f['status'] ? 'mentorship.php?status=' . $f['status'] : 'mentorship.php') ?>" class="btn btn-light">Clear</a><?php endif; ?>
</form>

<form method="post" id="bulkForm" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">
  <input type="hidden" name="return" value="<?= e($qsString) ?>">
  <div class="card-head list-head">
    <h3><?= number_format($total) ?> <?= $total === 1 ? 'person' : 'people' ?></h3>
    <div class="bulk">
      <select name="bulk_action" id="bulkAction">
        <option value="">Bulk action…</option>
        <option value="confirm">Give a place (sends the welcome email)</option>
        <option value="resend">Send the confirmation link again</option>
        <option value="delete">Delete</option>
      </select>
      <button class="btn btn-primary btn-sm" type="submit" id="bulkApply" disabled>Apply</button>
      <a href="<?= e($url(['export' => 1, 'page' => null])) ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th class="w-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><th>Name</th><th>Number</th><th>Phone</th><th>WhatsApp</th><th>Based in</th><th>Fields</th><th>Recommended by</th><th>Status</th><th>Registered</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="w-check"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="row-check" aria-label="Select <?= e($r['full_name']) ?>"></td>
          <td><div class="person"><span class="av ini"><?= e(initials($r['full_name'])) ?></span><div><b><?= e($r['full_name']) ?></b><small><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></small></div></div></td>
          <td class="nowrap"><?= $r['reference'] ? '<b class="ref">' . e($r['reference']) . '</b>' : '<span class="muted">—</span>' ?></td>
          <td class="nowrap"><a href="<?= e(tel_link($r['phone'])) ?>"><?= e($r['phone']) ?></a></td>
          <td class="nowrap"><a href="https://wa.me/<?= e(intl_digits($r['whatsapp'])) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> <?= e($r['whatsapp']) ?></a></td>
          <td><?= e($r['location']) ?></td>
          <td class="tracks-cell"><?php foreach (array_filter(explode(',', (string) $r['tracks'])) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></td>
          <td><?= $r['referred_by'] ? e($r['referred_by']) : '<span class="muted">—</span>' ?></td>
          <td><?= ['confirmed' => '<span class="badge st-confirmed">Registered</span>', 'waitlist' => '<span class="badge st-booked">Waiting list</span>'][$r['status']] ?? '<span class="badge st-pending" title="Link sent ' . e((string) $r['link_sent_at']) . '">Awaiting email</span>' ?></td>
          <td class="nowrap muted" title="<?= e($r['created_at']) ?>"><?= e(date('j M, g:i a', strtotime($r['confirmed_at'] ?: $r['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pagination">
    <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
    <div>
      <?php if ($page > 1): ?><a href="<?= e($url(['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><a href="<?= e($url(['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-handshake-angle"></i><p><?= $qs ? 'No one matches your filters.' : 'No mentorship registrations yet. Share the registration link to get started.' ?></p></div>
  <?php endif; ?>
</form>
<?php admin_footer();
