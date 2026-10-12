<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$ways = array_keys(partner_ways());
$statuses = partner_statuses();

/** WHERE clause for the filters (status, way, search). */
$filters = function (array $in) use ($ways, $statuses): array {
    $f = ['status' => (string) ($in['status'] ?? ''), 'way' => (string) ($in['way'] ?? ''), 'q' => trim((string) ($in['q'] ?? ''))];
    $where = [];
    $params = [];
    if (isset($statuses[$f['status']])) {
        $where[] = 'status = ?';
        $params[] = $f['status'];
    } else {
        $f['status'] = '';
    }
    if (in_array($f['way'], $ways, true)) {
        $where[] = 'FIND_IN_SET(?, ways)';
        $params[] = $f['way'];
    } else {
        $f['way'] = '';
    }
    if ($f['q'] !== '') {
        $where[] = '(organization LIKE ? OR contact_name LIKE ? OR email LIKE ? OR phone LIKE ? OR location LIKE ? OR contribution LIKE ? OR reference LIKE ?)';
        array_push($params, ...array_fill(0, 7, '%' . $f['q'] . '%'));
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params, $f];
};

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === '1') {
    [$where, $params] = $filters($_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-partners-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference', 'Organisation', 'Type', 'Contact person', 'Role', 'Email', 'Phone', 'Based in', 'Website', 'Ways to partner', 'Contribution', 'Estimated value', 'Prefers', 'Heard about us', 'Status', 'Team notes', 'Submitted']);
    foreach (q("SELECT * FROM partners$where ORDER BY id", $params) as $p) {
        fputcsv($out, [$p['reference'], $p['organization'], $p['org_type'], $p['contact_name'], $p['job_title'], $p['email'], $p['phone'], $p['location'], $p['website'],
            str_replace(',', '; ', $p['ways']), $p['contribution'], $p['budget'], $p['contact_pref'], $p['referred_by'], $statuses[$p['status']] ?? $p['status'], $p['admin_notes'], $p['created_at']]);
    }
    exit;
}

/* ---------- Bulk status ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $back = 'partners.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : '');
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $to = (string) ($_POST['bulk_action'] ?? '');
    if (!$ids || !isset($statuses[$to])) {
        flash('Select at least one partner and a status.', 'error');
        redirect($back);
    }
    $in = implode(',', $ids);
    $n = q("UPDATE partners SET status = ?, updated_at = ? WHERE id IN ($in)", [$to, now()])->rowCount();
    flash($n . ' partner' . ($n === 1 ? '' : 's') . ' marked "' . $statuses[$to] . '".');
    redirect($back);
}

[$where, $params, $f] = $filters($_GET);
$perPage = 40;
$total = (int) q("SELECT COUNT(*) FROM partners$where", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = q("SELECT * FROM partners$where ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$qs = array_filter($f, fn($v) => $v !== '');
$qsString = $qs ? '?' . http_build_query($qs) : '';
$url = fn(array $extra) => 'partners.php?' . http_build_query(array_filter($extra + $qs, fn($v) => $v !== '' && $v !== null));
$counts = array_column(q('SELECT status, COUNT(*) c FROM partners GROUP BY status')->fetchAll(), 'c', 'status');
$all = array_sum($counts);
$wayCounts = [];
foreach ($ways as $w) {
    $wayCounts[] = ['label' => $w, 'c' => (int) q('SELECT COUNT(*) FROM partners WHERE FIND_IN_SET(?, ways)', [$w])->fetchColumn()];
}
usort($wayCounts, fn($a, $b) => $b['c'] <=> $a['c']);
$badge = ['new' => 'st-booked', 'contacted' => 'st-pending', 'talks' => 'st-waitlisted', 'confirmed' => 'st-confirmed', 'declined' => 'st-cancelled'];

admin_header('Partners', 'partners', 'Organisations and people who want to partner with or contribute to Kakebe Tech Camp 2026');
?>
<div class="kpis">
  <a class="kpi" href="partners.php"><span class="kpi-icon navy"><i class="fa-solid fa-handshake"></i></span><div><small>Partner interests</small><b><?= number_format($all) ?></b><em><?= number_format((int) ($counts['new'] ?? 0)) ?> new to contact</em></div></a>
  <a class="kpi" href="partners.php?status=talks"><span class="kpi-icon amber"><i class="fa-solid fa-comments"></i></span><div><small>In discussion</small><b><?= number_format((int) ($counts['talks'] ?? 0) + (int) ($counts['contacted'] ?? 0)) ?></b><em>contacted or talking</em></div></a>
  <a class="kpi" href="partners.php?status=confirmed"><span class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></span><div><small>Confirmed partners</small><b><?= number_format((int) ($counts['confirmed'] ?? 0)) ?></b><em>for camp 2026</em></div></a>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-link"></i></span><div><small>Partner page</small><b class="small-b"><a href="<?= e(base_url('partner')) ?>" target="_blank">/partner <i class="fa-solid fa-arrow-up-right-from-square"></i></a></b><em>share it with organisations</em></div></div>
</div>

<div class="grid-2 align-start">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-bullseye"></i> How they want to partner</h3><span class="muted small">partners per way</span></div>
    <?= bar_list(array_values(array_filter($wayCounts, fn($w) => $w['c'] > 0)), max(1, $all), 'navy') ?>
  </section>
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-share-nodes"></i> Share the partner page</h3></div>
    <div class="copy-field"><input type="text" value="<?= e(base_url('partner')) ?>" readonly onclick="this.select()"><a class="btn btn-light btn-sm" href="<?= e(base_url('partner')) ?>" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
    <p class="small muted">Organisations and individuals choose how they would like to partner (funding, sponsoring young people, in-kind support, trainers, internships, prizes, media, exhibiting or a first talk) and describe their contribution. The team gets an email for each one.</p>
  </section>
</div>

<div class="tabs big">
  <a href="<?= e($url(['status' => null, 'page' => null])) ?>" class="<?= $f['status'] === '' ? 'active' : '' ?>">All <em><?= number_format($all) ?></em></a>
  <?php foreach ($statuses as $k => $label): ?><a href="<?= e($url(['status' => $k, 'page' => null])) ?>" class="<?= $f['status'] === $k ? 'active' : '' ?>"><?= e($label) ?> <em><?= number_format((int) ($counts[$k] ?? 0)) ?></em></a><?php endforeach; ?>
</div>

<form class="card filters" method="get">
  <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Organisation, contact, email, phone, place, contribution…"></div>
  <select name="way"><option value="">Any way of partnering</option><?php foreach ($ways as $w): ?><option <?= $f['way'] === $w ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($f['q'] !== '' || $f['way']): ?><a href="<?= e($f['status'] ? 'partners.php?status=' . $f['status'] : 'partners.php') ?>" class="btn btn-light">Clear</a><?php endif; ?>
</form>

<form method="post" id="bulkForm" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="return" value="<?= e($qsString) ?>">
  <div class="card-head list-head">
    <h3><?= number_format($total) ?> partner<?= $total === 1 ? '' : 's' ?></h3>
    <div class="bulk">
      <select name="bulk_action" id="bulkAction">
        <option value="">Choose an action…</option>
        <?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>">Mark as: <?= e($label) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit" id="bulkApply" disabled>Apply</button>
      <a href="<?= e($url(['export' => 1, 'page' => null])) ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th class="w-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><th>Organisation</th><th>Contact</th><th>Ways to partner</th><th>Contribution</th><th>Value</th><th>Status</th><th>Submitted</th><th class="row-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr class="row-link" data-href="partner.php?id=<?= (int) $p['id'] ?>">
          <td class="w-check"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" class="row-check" aria-label="Select <?= e($p['organization']) ?>"></td>
          <td><div class="person"><span class="av ini"><?= e(initials($p['organization'])) ?></span><div><b><?= e($p['organization']) ?></b><small><?= e($p['org_type']) ?> · <?= e($p['reference']) ?></small></div></div></td>
          <td><b><?= e($p['contact_name']) ?></b><small class="block muted"><?= e($p['email']) ?> · <?= e($p['phone']) ?></small></td>
          <td class="tracks-cell"><?php foreach (array_filter(explode(',', (string) $p['ways'])) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></td>
          <td class="small"><?= e(mb_strimwidth((string) $p['contribution'], 0, 90, '…')) ?></td>
          <td class="small nowrap"><?= e($p['budget'] ?: '—') ?></td>
          <td><span class="badge <?= $badge[$p['status']] ?? '' ?>"><?= e($statuses[$p['status']] ?? $p['status']) ?></span></td>
          <td class="nowrap muted"><?= e(date('j M, g:i a', strtotime($p['created_at']))) ?></td>
          <td class="nowrap row-actions"><a class="btn btn-light btn-sm" href="partner.php?id=<?= (int) $p['id'] ?>"><i class="fa-regular fa-eye"></i> View</a></td>
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
      <?php for ($n = max(1, $page - 2); $n <= min($pages, $page + 2); $n++): ?><a href="<?= e($url(['page' => $n])) ?>" class="<?= $n === $page ? 'active' : '' ?>"><?= $n ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-handshake"></i><p><?= $qs ? 'No partners match your filters.' : 'No partner interests yet. Share the /partner link with organisations to start receiving them.' ?></p></div>
  <?php endif; ?>
</form>
<?php admin_footer();
