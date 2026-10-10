<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$fields = array_keys(volunteer_fields());
$statuses = volunteer_statuses();

/** WHERE clause for the filters (status, field, search). */
$filters = function (array $in) use ($fields, $statuses): array {
    $f = ['status' => (string) ($in['status'] ?? ''), 'field' => (string) ($in['field'] ?? ''), 'q' => trim((string) ($in['q'] ?? ''))];
    $where = [];
    $params = [];
    if (isset($statuses[$f['status']])) {
        $where[] = 'status = ?';
        $params[] = $f['status'];
    } else {
        $f['status'] = '';
    }
    if (in_array($f['field'], $fields, true)) {
        $where[] = 'FIND_IN_SET(?, fields)';
        $params[] = $f['field'];
    } else {
        $f['field'] = '';
    }
    if ($f['q'] !== '') {
        $where[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR location LIKE ? OR course LIKE ? OR institution LIKE ? OR reference LIKE ?)';
        array_push($params, ...array_fill(0, 7, '%' . $f['q'] . '%'));
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params, $f];
};

/* ---------- CV download ---------- */
if (isset($_GET['cv'])) {
    $v = find_volunteer((int) $_GET['cv']);
    $path = $v ? volunteer_cv_path($v) : null;
    if (!$path) {
        http_response_code(404);
        exit('CV not found');
    }
    header('Content-Type: ' . volunteer_cv_mime($v['cv_file']));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . (isset($_GET['view']) && str_ends_with($v['cv_file'], '.pdf') ? 'inline' : 'attachment') . '; filename="' . volunteer_cv_name($v) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === '1') {
    [$where, $params] = $filters($_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-volunteer-trainers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Application', 'Full name', 'Email', 'Phone', 'Gender', 'Age', 'Nationality', 'Based in', 'Qualification', 'Course', 'Institution', 'Graduated', 'Fields', 'Experience', 'Current role', 'LinkedIn / portfolio', 'Availability', 'Recommended by', 'About', 'Status', 'Applied']);
    foreach (q("SELECT * FROM volunteers$where ORDER BY id", $params) as $v) {
        fputcsv($out, [$v['reference'], $v['full_name'], $v['email'], $v['phone'], $v['gender'], volunteer_age($v['dob']), $v['nationality'], $v['location'], $v['qualification'], $v['course'], $v['institution'], $v['grad_year'],
            str_replace(',', '; ', $v['fields']), $v['experience'], $v['job_role'], $v['portfolio_url'], volunteer_availability_options()[$v['availability']] ?? $v['availability'], $v['referred_by'], $v['bio'], $statuses[$v['status']] ?? $v['status'], $v['created_at']]);
    }
    exit;
}

/* ---------- Bulk status / settings ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $back = 'volunteers.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : '');
    if (($_POST['action'] ?? '') === 'open') {
        setting_set('volunteers_open', !empty($_POST['volunteers_open']) ? '1' : '0');
        flash('Volunteer applications are now ' . (!empty($_POST['volunteers_open']) ? 'open.' : 'closed.'));
        redirect('volunteers.php');
    }
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $to = (string) ($_POST['bulk_action'] ?? '');
    if (!$ids || !isset($statuses[$to])) {
        flash('Select at least one volunteer and a status.', 'error');
        redirect($back);
    }
    $in = implode(',', $ids);
    $n = q("UPDATE volunteers SET status = ?, updated_at = ? WHERE id IN ($in)", [$to, now()])->rowCount();
    flash($n . ' volunteer' . ($n === 1 ? '' : 's') . ' marked "' . $statuses[$to] . '".');
    redirect($back);
}

[$where, $params, $f] = $filters($_GET);
$perPage = 40;
$total = (int) q("SELECT COUNT(*) FROM volunteers$where", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = q("SELECT * FROM volunteers$where ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$qs = array_filter($f, fn($v) => $v !== '');
$qsString = $qs ? '?' . http_build_query($qs) : '';
$url = fn(array $extra) => 'volunteers.php?' . http_build_query(array_filter($extra + $qs, fn($v) => $v !== '' && $v !== null));
$counts = array_column(q('SELECT status, COUNT(*) c FROM volunteers GROUP BY status')->fetchAll(), 'c', 'status');
$all = array_sum($counts);
$fieldCounts = [];
foreach ($fields as $fld) {
    $fieldCounts[] = ['label' => $fld, 'c' => (int) q('SELECT COUNT(*) FROM volunteers WHERE FIND_IN_SET(?, fields)', [$fld])->fetchColumn()];
}
usort($fieldCounts, fn($a, $b) => $b['c'] <=> $a['c']);
$open = setting('volunteers_open', '1') === '1';
$badge = ['new' => 'st-booked', 'shortlisted' => 'st-pending', 'accepted' => 'st-confirmed', 'declined' => 'st-cancelled'];

admin_header('Volunteer trainers', 'volunteers', 'People offering their expertise as trainers during Kakebe Tech Camp 2026');
?>
<div class="kpis">
  <a class="kpi" href="volunteers.php"><span class="kpi-icon navy"><i class="fa-solid fa-person-chalkboard"></i></span><div><small>Applications</small><b><?= number_format($all) ?></b><em><?= number_format((int) ($counts['new'] ?? 0)) ?> new to review</em></div></a>
  <a class="kpi" href="volunteers.php?status=shortlisted"><span class="kpi-icon amber"><i class="fa-solid fa-list-check"></i></span><div><small>Shortlisted</small><b><?= number_format((int) ($counts['shortlisted'] ?? 0)) ?></b><em>to interview or confirm</em></div></a>
  <a class="kpi" href="volunteers.php?status=accepted"><span class="kpi-icon green"><i class="fa-solid fa-user-check"></i></span><div><small>Accepted</small><b><?= number_format((int) ($counts['accepted'] ?? 0)) ?></b><em>trainers for camp</em></div></a>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-link"></i></span><div><small>Application page</small><b class="small-b"><a href="<?= e(base_url('volunteers')) ?>" target="_blank">/volunteers <i class="fa-solid fa-arrow-up-right-from-square"></i></a></b><em><?= $open ? 'Open for applications' : 'Closed' ?></em></div></div>
</div>

<div class="grid-2 align-start">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-bullseye"></i> Fields</h3><span class="muted small">applicants per field</span></div>
    <?= bar_list($fieldCounts, max(1, $all), 'navy') ?>
  </section>
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-share-nodes"></i> Share &amp; settings</h3></div>
    <div class="copy-field"><input type="text" value="<?= e(base_url('volunteers')) ?>" readonly onclick="this.select()"><a class="btn btn-light btn-sm" href="<?= e(base_url('volunteers')) ?>" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
    <p class="small muted">Requirements on the page: ages 22 – 40, at least a Diploma in the field, CV attached. Accommodation, travel and allowances are provided.</p>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="open">
      <label class="switch"><input type="checkbox" name="volunteers_open" value="1" <?= $open ? 'checked' : '' ?> onchange="this.form.submit()"><span class="slider"></span> Applications are open</label>
    </form>
  </section>
</div>

<div class="tabs big">
  <a href="<?= e($url(['status' => null, 'page' => null])) ?>" class="<?= $f['status'] === '' ? 'active' : '' ?>">All <em><?= number_format($all) ?></em></a>
  <?php foreach ($statuses as $k => $label): ?><a href="<?= e($url(['status' => $k, 'page' => null])) ?>" class="<?= $f['status'] === $k ? 'active' : '' ?>"><?= e($label) ?> <em><?= number_format((int) ($counts[$k] ?? 0)) ?></em></a><?php endforeach; ?>
</div>

<form class="card filters" method="get">
  <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, email, phone, course, university, place…"></div>
  <select name="field"><option value="">Any field</option><?php foreach ($fields as $fld): ?><option <?= $f['field'] === $fld ? 'selected' : '' ?>><?= e($fld) ?></option><?php endforeach; ?></select>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($f['q'] !== '' || $f['field']): ?><a href="<?= e($f['status'] ? 'volunteers.php?status=' . $f['status'] : 'volunteers.php') ?>" class="btn btn-light">Clear</a><?php endif; ?>
</form>

<form method="post" id="bulkForm" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="return" value="<?= e($qsString) ?>">
  <div class="card-head list-head">
    <h3><?= number_format($total) ?> volunteer<?= $total === 1 ? '' : 's' ?></h3>
    <div class="bulk">
      <select name="bulk_action" id="bulkAction">
        <option value="">Mark selected as…</option>
        <?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit" id="bulkApply" disabled>Apply</button>
      <a href="<?= e($url(['export' => 1, 'page' => null])) ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th class="w-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><th>Volunteer</th><th>Fields</th><th>Qualification</th><th>Experience</th><th>Age</th><th>Based in</th><th>Status</th><th>Applied</th><th class="row-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $v): ?>
        <tr class="row-link" data-href="volunteer.php?id=<?= (int) $v['id'] ?>">
          <td class="w-check"><input type="checkbox" name="ids[]" value="<?= (int) $v['id'] ?>" class="row-check" aria-label="Select <?= e($v['full_name']) ?>"></td>
          <td><div class="person"><span class="av ini"><?= e(initials($v['full_name'])) ?></span><div><b><?= e($v['full_name']) ?></b><small><?= e($v['email']) ?> · <?= e($v['reference']) ?></small></div></div></td>
          <td class="tracks-cell"><?php foreach (array_filter(explode(',', (string) $v['fields'])) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></td>
          <td><b><?= e($v['qualification']) ?></b><small class="block muted"><?= e($v['course']) ?> · <?= e($v['institution']) ?></small></td>
          <td class="nowrap"><?= e($v['experience']) ?></td>
          <td><?= volunteer_age($v['dob']) ?></td>
          <td><?= e($v['location']) ?></td>
          <td><span class="badge <?= $badge[$v['status']] ?? '' ?>"><?= e($statuses[$v['status']] ?? $v['status']) ?></span></td>
          <td class="nowrap muted"><?= e(date('j M, g:i a', strtotime($v['created_at']))) ?></td>
          <td class="nowrap row-actions">
            <?php if (volunteer_cv_path($v)): ?><a class="btn btn-light btn-sm" href="volunteers.php?cv=<?= (int) $v['id'] ?>"><i class="fa-solid fa-file-arrow-down"></i> CV</a><?php endif; ?>
            <a class="btn btn-light btn-sm" href="volunteer.php?id=<?= (int) $v['id'] ?>"><i class="fa-regular fa-eye"></i> View</a>
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
      <?php if ($page > 1): ?><a href="<?= e($url(['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><a href="<?= e($url(['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-person-chalkboard"></i><p><?= $qs ? 'No volunteers match your filters.' : 'No volunteer applications yet. Share the /volunteers link to start receiving them.' ?></p></div>
  <?php endif; ?>
</form>
<?php admin_footer();
