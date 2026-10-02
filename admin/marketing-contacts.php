<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$statuses = ['valid' => 'Ready', 'invalid' => 'Filtered out', 'unsubscribed' => 'Unsubscribed'];

/** WHERE clause for the contact filters (status, list, search). */
$filters = function (array $in) use ($statuses): array {
    $f = ['status' => (string) ($in['status'] ?? ''), 'list' => (int) ($in['list'] ?? 0), 'q' => trim((string) ($in['q'] ?? ''))];
    $join = '';
    $where = [];
    $params = [];
    if (isset($statuses[$f['status']])) {
        $where[] = 'c.status = ?';
        $params[] = $f['status'];
    } else {
        $f['status'] = '';
    }
    if ($f['list']) {
        $join = ' JOIN mk_list_contacts lc ON lc.contact_id = c.id AND lc.list_id = ' . $f['list'];
    }
    if ($f['q'] !== '') {
        $where[] = '(c.email LIKE ? OR c.name LIKE ?)';
        array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%');
    }
    return [$join . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params, $f];
};

/* ---------- CSV export of the filtered contacts ---------- */
if (($_GET['export'] ?? '') === '1') {
    [$sql, $params] = $filters($_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-email-contacts-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Email', 'Name', 'Status', 'Note', 'Added']);
    foreach (q("SELECT c.email, c.name, c.status, c.reason, c.created_at FROM mk_contacts c$sql ORDER BY c.id", $params) as $r) {
        fputcsv($out, [$r['email'], $r['name'], $statuses[$r['status']] ?? $r['status'], $r['reason'], $r['created_at']]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'marketing-contacts.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : '');

    if ($action === 'import') {
        @set_time_limit(300);
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $err = ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'The file is too big for the server (limit ' . ini_get('upload_max_filesize') . '). Split it or save it as CSV.' : 'Choose a CSV or Excel (.xlsx) file to import.';
            flash($err, 'error');
            redirect('marketing-contacts.php');
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            flash($ext === 'xls' ? 'Old Excel files (.xls) are not supported — open it in Excel and "Save as" .xlsx or CSV.' : 'Please upload a .csv or .xlsx file.', 'error');
            redirect('marketing-contacts.php');
        }
        // The list: an existing one, or a new one named after the file
        $listId = (int) ($_POST['list_id'] ?? 0);
        if (!$listId || !q('SELECT id FROM mk_lists WHERE id = ?', [$listId])->fetchColumn()) {
            $name = trim((string) ($_POST['list_name'] ?? '')) ?: ucfirst(trim(preg_replace('/[_-]+/', ' ', pathinfo((string) $f['name'], PATHINFO_FILENAME))));
            q('INSERT INTO mk_lists (name, created_at) VALUES (?, ?)', [mb_substr($name ?: 'Imported ' . date('j M Y'), 0, 120), now()]);
            $listId = (int) db()->lastInsertId();
        }
        try {
            $stats = mk_import($f['tmp_name'], (string) $f['name'], $listId);
        } catch (Throwable $ex) {
            flash('The file could not be read: ' . $ex->getMessage(), 'error');
            redirect('marketing-contacts.php');
        }
        if (!$stats['rows']) {
            flash('No email addresses were found in that file. Make sure one column holds the emails.', 'error');
            redirect('marketing-contacts.php');
        }
        $_SESSION['mk_import'] = $stats + ['file' => (string) $f['name'], 'list_id' => $listId];
        redirect('marketing-contacts.php?list=' . $listId);
    }

    if ($action === 'add') {
        [$email, $status, $reason] = mk_check_email((string) ($_POST['email'] ?? ''));
        if ($status !== 'valid') {
            flash(($email ?: 'That') . ' was not added: ' . ($reason ?: 'enter an email address') . '.', 'error');
            redirect($back);
        }
        q('INSERT IGNORE INTO mk_contacts (email, name, status, domain, token, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$email, mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150) ?: null, 'valid', substr((string) strrchr($email, '@'), 1), mk_token(), now()]);
        $cid = (int) q('SELECT id FROM mk_contacts WHERE email = ?', [$email])->fetchColumn();
        if ($listId = (int) ($_POST['list_id'] ?? 0)) {
            q('INSERT IGNORE INTO mk_list_contacts (list_id, contact_id) VALUES (?, ?)', [$listId, $cid]);
        }
        mk_mark_known_domains();
        flash($email . ' added.');
        redirect($back);
    }

    if ($action === 'bulk') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $do = (string) ($_POST['bulk_action'] ?? '');
        if (!$ids || $do === '') {
            flash('Select at least one contact and an action.', 'error');
            redirect($back);
        }
        $in = implode(',', $ids);
        switch ($do) {
            case 'delete':
                q("DELETE FROM mk_sends WHERE contact_id IN ($in) AND status = 'queued'");
                q("DELETE FROM mk_list_contacts WHERE contact_id IN ($in)");
                $n = q("DELETE FROM mk_contacts WHERE id IN ($in)")->rowCount();
                flash($n . ' contact' . ($n === 1 ? '' : 's') . ' deleted.');
                break;
            case 'valid':
                $n = q("UPDATE mk_contacts SET status = 'valid', reason = NULL, domain_checked = 1 WHERE id IN ($in) AND status = 'invalid'")->rowCount();
                flash($n . ' contact' . ($n === 1 ? '' : 's') . ' marked as good to email.');
                break;
            case 'invalid':
                $n = q("UPDATE mk_contacts SET status = 'invalid', reason = 'Removed by an admin' WHERE id IN ($in) AND status = 'valid'")->rowCount();
                flash($n . ' contact' . ($n === 1 ? '' : 's') . ' filtered out — they will not be emailed.');
                break;
            case 'unlist':
                $list = (int) ($_POST['list'] ?? 0);
                $n = $list ? q("DELETE FROM mk_list_contacts WHERE list_id = ? AND contact_id IN ($in)", [$list])->rowCount() : 0;
                flash($n . ' contact' . ($n === 1 ? '' : 's') . ' removed from the list.');
                break;
            default:
                if (preg_match('/^list:(\d+)$/', $do, $m)) {
                    q('INSERT IGNORE INTO mk_list_contacts (list_id, contact_id) VALUES ' . implode(',', array_map(fn($cid) => '(' . (int) $m[1] . ',' . $cid . ')', $ids)));
                    flash(count($ids) . ' contact' . (count($ids) === 1 ? '' : 's') . ' added to the list.');
                }
        }
        redirect($back);
    }

    if ($action === 'delete_invalid') {
        $ids = 'SELECT id FROM mk_contacts WHERE status = \'invalid\'';
        q("DELETE FROM mk_sends WHERE status = 'queued' AND contact_id IN ($ids)");
        q("DELETE FROM mk_list_contacts WHERE contact_id IN ($ids)");
        $n = q("DELETE FROM mk_contacts WHERE status = 'invalid'")->rowCount();
        flash(number_format($n) . ' filtered-out address' . ($n === 1 ? '' : 'es') . ' deleted.');
        redirect('marketing-contacts.php');
    }

    if ($action === 'rename_list' && ($lid = (int) ($_POST['list'] ?? 0))) {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        if ($name !== '') {
            q('UPDATE mk_lists SET name = ? WHERE id = ?', [$name, $lid]);
            flash('List renamed.');
        }
        redirect('marketing-contacts.php?list=' . $lid);
    }

    if ($action === 'delete_list' && ($lid = (int) ($_POST['list'] ?? 0))) {
        q('DELETE FROM mk_list_contacts WHERE list_id = ?', [$lid]);
        q('DELETE FROM mk_lists WHERE id = ?', [$lid]);
        q('UPDATE mk_campaigns SET list_id = NULL WHERE list_id = ?', [$lid]);
        flash('List deleted. Its contacts are still in "All contacts".');
        redirect('marketing-contacts.php');
    }
    redirect($back);
}

$import = $_SESSION['mk_import'] ?? null;
unset($_SESSION['mk_import']);

[$sql, $params, $f] = $filters($_GET);
$lists = mk_lists();
$currentList = null;
foreach ($lists as $l) {
    if ((int) $l['id'] === $f['list']) {
        $currentList = $l;
    }
}
if ($f['list'] && !$currentList) {
    $f['list'] = 0;
    [$sql, $params] = $filters(['status' => $f['status'], 'q' => $f['q']]);
}
$counts = mk_contact_stats($f['list'] ?: null);
$perPage = 50;
$total = (int) q("SELECT COUNT(*) FROM mk_contacts c$sql", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = q("SELECT c.* FROM mk_contacts c$sql ORDER BY c.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$qs = array_filter(['status' => $f['status'], 'list' => $f['list'] ?: '', 'q' => $f['q']], fn($v) => $v !== '');
$qsString = $qs ? '?' . http_build_query($qs) : '';
$url = fn(array $extra) => 'marketing-contacts.php?' . http_build_query(array_filter($extra + $qs, fn($v) => $v !== '' && $v !== null));
$domainsLeft = (int) q("SELECT COUNT(DISTINCT domain) FROM mk_contacts WHERE status = 'valid' AND domain_checked = 0 AND domain <> ''")->fetchColumn();
$badge = ['valid' => 'st-confirmed', 'invalid' => 'st-cancelled', 'unsubscribed' => 'st-waitlisted'];

admin_header('Email contacts', 'contacts', number_format(mk_contact_stats()['total']) . ' contacts · import CSV or Excel files, bad addresses are filtered out automatically');
?>
<?php if ($import): ?>
<div class="card mk-import-result">
  <div class="card-head"><h3><i class="fa-solid fa-file-circle-check"></i> Imported <?= e($import['file']) ?></h3><a href="#" class="small" onclick="this.closest('.card').remove();return false;">Close</a></div>
  <div class="mk-stats flat">
    <div><small>Emails found</small><b><?= number_format($import['rows']) ?></b></div>
    <div><small>New, ready to email</small><b class="ok-text"><?= number_format($import['added_valid']) ?></b></div>
    <div><small>Already saved</small><b><?= number_format($import['existing']) ?></b></div>
    <div><small>Filtered out</small><b class="<?= $import['invalid'] ? 'due' : '' ?>"><?= number_format($import['invalid']) ?></b></div>
    <div><small>Duplicates in file</small><b><?= number_format($import['duplicates']) ?></b></div>
    <div><small>Rows with no email</small><b><?= number_format($import['skipped']) ?></b></div>
  </div>
  <p class="small muted">Filtered-out addresses (typos like gmial.com, fake or throw-away addresses) are kept aside and never emailed. <?php if ($domainsLeft): ?>Next, press <b>Check email domains</b> to catch addresses whose domain cannot receive mail.<?php endif; ?></p>
</div>
<?php endif; ?>

<div class="kpis">
  <a class="kpi" href="<?= e($url(['status' => null, 'page' => null])) ?>"><span class="kpi-icon navy"><i class="fa-solid fa-address-book"></i></span><div><small><?= $currentList ? 'In this list' : 'All contacts' ?></small><b><?= number_format($counts['total']) ?></b><em><?= $currentList ? e($currentList['name']) : count($lists) . ' list' . (count($lists) === 1 ? '' : 's') ?></em></div></a>
  <a class="kpi" href="<?= e($url(['status' => 'valid', 'page' => null])) ?>"><span class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></span><div><small>Ready to receive</small><b><?= number_format($counts['valid']) ?></b><em>good addresses</em></div></a>
  <a class="kpi" href="<?= e($url(['status' => 'invalid', 'page' => null])) ?>"><span class="kpi-icon red"><i class="fa-solid fa-filter-circle-xmark"></i></span><div><small>Filtered out</small><b><?= number_format($counts['invalid']) ?></b><em>wrong or fake emails</em></div></a>
  <a class="kpi" href="<?= e($url(['status' => 'unsubscribed', 'page' => null])) ?>"><span class="kpi-icon amber"><i class="fa-solid fa-user-slash"></i></span><div><small>Unsubscribed</small><b><?= number_format($counts['unsubscribed']) ?></b><em>never emailed again</em></div></a>
</div>

<div class="grid-2 align-start">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-file-import"></i> Import contacts</h3><span class="small muted">CSV or Excel (.xlsx) · up to <?= e(ini_get('upload_max_filesize')) ?></span></div>
    <form method="post" enctype="multipart/form-data" class="stack" id="mkImport">
      <?= csrf_field() ?><input type="hidden" name="action" value="import">
      <label class="mk-drop"><i class="fa-solid fa-cloud-arrow-up"></i><span>Choose a file — one column must hold the email addresses. A name column is picked up too.</span><input type="file" name="file" accept=".csv,.txt,.xlsx" required></label>
      <div class="row-2">
        <label>Add to list
          <select name="list_id" id="mkListSel">
            <option value="0">+ New list</option>
            <?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $f['list'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label id="mkListName">New list name <input type="text" name="list_name" maxlength="120" placeholder="e.g. Gulu university students"></label>
      </div>
      <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-file-import"></i> Import</button> <span class="small muted" id="mkImportNote" hidden><span class="spinner dark"></span> Reading the file — large files take a minute…</span></div>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-broom"></i> Clean the list</h3></div>
    <ul class="mini-stats">
      <li><span><i class="fa-solid fa-spell-check"></i> Wrong format, typos &amp; fake addresses</span><b>filtered on import</b></li>
      <li><span><i class="fa-solid fa-globe"></i> Domains not checked yet</span><b id="mkDomLeft"><?= number_format($domainsLeft) ?></b></li>
      <li><span><i class="fa-solid fa-envelope-circle-check"></i> Bounced when sending</span><b>filtered automatically</b></li>
    </ul>
    <div class="mk-clean-actions">
      <button type="button" class="btn btn-navy btn-sm" id="mkDomains" <?= $domainsLeft ? '' : 'disabled' ?>><i class="fa-solid fa-globe"></i> Check email domains</button>
      <?php if ($counts['invalid'] && !$currentList): ?>
      <form method="post" class="inline-form" data-confirm="Delete all <?= number_format($counts['invalid']) ?> filtered-out addresses for good?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_invalid"><button class="btn btn-danger btn-sm" type="submit"><i class="fa-regular fa-trash-can"></i> Delete filtered-out</button></form>
      <?php endif; ?>
      <a class="btn btn-light btn-sm" href="<?= e($url(['export' => 1, 'page' => null])) ?>"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
    <p class="small muted" id="mkDomMsg">Checks that each email domain (like <i>gmail.com</i> or <i>mak.ac.ug</i>) can receive mail. Addresses at dead domains are filtered out.</p>
    <form method="post" class="mk-add"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="list_id" value="<?= (int) $f['list'] ?>"><input type="hidden" name="return" value="<?= e($qsString) ?>">
      <input type="email" name="email" placeholder="Add one email" required><input type="text" name="name" placeholder="Name (optional)" maxlength="150"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-plus"></i> Add</button>
    </form>
  </section>
</div>

<?php if ($lists): ?>
<div class="tabs big mk-lists">
  <a href="<?= e('marketing-contacts.php' . ($f['status'] ? '?status=' . $f['status'] : '')) ?>" class="<?= !$f['list'] ? 'active' : '' ?>">All contacts</a>
  <?php foreach ($lists as $l): ?><a href="<?= e($url(['list' => (int) $l['id'], 'page' => null])) ?>" class="<?= $f['list'] === (int) $l['id'] ? 'active' : '' ?>"><?= e($l['name']) ?> <em><?= number_format((int) $l['valid']) ?></em></a><?php endforeach; ?>
</div>
<?php endif; ?>

<form class="card filters" method="get">
  <?php if ($f['list']): ?><input type="hidden" name="list" value="<?= (int) $f['list'] ?>"><?php endif; ?>
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search email or name"></div>
  <select name="status"><option value="">Any status</option><?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($f['q'] !== '' || $f['status']): ?><a href="<?= e($f['list'] ? 'marketing-contacts.php?list=' . $f['list'] : 'marketing-contacts.php') ?>" class="btn btn-light">Clear</a><?php endif; ?>
  <?php if ($currentList): ?>
  <span class="mk-list-tools">
    <button type="button" class="btn btn-light btn-sm" data-toggle="#mkRename"><i class="fa-regular fa-pen-to-square"></i> Rename list</button>
  </span>
  <?php endif; ?>
</form>
<?php if ($currentList): ?>
<div class="card mk-rename" id="mkRename" hidden>
  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="rename_list"><input type="hidden" name="list" value="<?= (int) $currentList['id'] ?>"><input type="text" name="name" value="<?= e($currentList['name']) ?>" maxlength="120" required> <button class="btn btn-primary btn-sm" type="submit">Save name</button></form>
  <form method="post" class="inline-form" data-confirm="Delete the list &quot;<?= e($currentList['name']) ?>&quot;? The contacts stay in All contacts."><?= csrf_field() ?><input type="hidden" name="action" value="delete_list"><input type="hidden" name="list" value="<?= (int) $currentList['id'] ?>"><button class="btn btn-danger btn-sm" type="submit"><i class="fa-regular fa-trash-can"></i> Delete list</button></form>
</div>
<?php endif; ?>

<form method="post" id="bulkForm" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">
  <input type="hidden" name="return" value="<?= e($qsString) ?>">
  <input type="hidden" name="list" value="<?= (int) $f['list'] ?>">
  <div class="card-head list-head">
    <h3><?= number_format($total) ?> contact<?= $total === 1 ? '' : 's' ?></h3>
    <div class="bulk">
      <select name="bulk_action" id="bulkAction">
        <option value="">With selected…</option>
        <option value="valid">Mark as good to email</option>
        <option value="invalid">Filter out (don't email)</option>
        <?php foreach ($lists as $l): if ((int) $l['id'] === $f['list']) continue; ?><option value="list:<?= (int) $l['id'] ?>">Add to list: <?= e($l['name']) ?></option><?php endforeach; ?>
        <?php if ($currentList): ?><option value="unlist">Remove from this list</option><?php endif; ?>
        <option value="delete">Delete</option>
      </select>
      <button class="btn btn-primary btn-sm" type="submit" id="bulkApply" disabled>Apply</button>
    </div>
  </div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th class="w-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><th>Email</th><th>Name</th><th>Status</th><th>Added</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="w-check"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="row-check" aria-label="Select <?= e($r['email']) ?>"></td>
          <td><b><?= e($r['email']) ?></b></td>
          <td><?= e((string) $r['name']) ?: '<span class="muted">—</span>' ?></td>
          <td><span class="badge <?= $badge[$r['status']] ?? '' ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span><?php if ($r['reason'] && $r['status'] !== 'valid'): ?><small class="block muted"><?= e($r['reason']) ?></small><?php endif; ?></td>
          <td class="nowrap muted"><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pagination">
    <span class="muted">Page <?= $page ?> of <?= number_format($pages) ?></span>
    <div>
      <?php if ($page > 1): ?><a href="<?= e($url(['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><a href="<?= e($url(['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>
  <?php else: ?>
    <div class="empty-state"><i class="fa-regular fa-address-book"></i><p><?= $qs ? 'No contacts match.' : 'No contacts yet — import a CSV or Excel file above.' ?></p></div>
  <?php endif; ?>
</form>
<script>window.MK = { csrf: <?= json_encode(csrf_token()) ?>, api: 'marketing-api.php' };</script>
<?php admin_footer(['marketing.js']);
