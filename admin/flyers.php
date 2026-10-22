<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$dir = STORAGE . '/uploads/flyers/';
$slug = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
$fileName = fn(array $f) => 'i-will-be-there-' . ($slug($f['reference']) ?: 'kakebe') . ($slug($f['name']) ? '-' . $slug($f['name']) : '') . '.png';

// The words posted with each flyer on the camp's X account ({name} = the person's name)
$xDefault = 'I am {name} and I am happy that I will be part of #KakebeTechCamp2026! 🎉 Ten days of tech, innovation and mentorship with young innovators from across Uganda — 14th–23rd December 2026 in Kitgum. #IWillBeThere';
$xTemplate = (string) setting('flyer_x_text', '') ?: $xDefault;
$xText = fn(array $f) => strtr($xTemplate, ['{name}' => mk_display_name($f['name'] ?: (string) ($f['reg_name'] ?? '')) ?: 'a young innovator']);

/* ---------- One image: view, preview or download ---------- */
if (isset($_GET['img'])) {
    $f = q('SELECT * FROM flyers WHERE id = ?', [(int) $_GET['img']])->fetch();
    $thumb = isset($_GET['thumb']) && $f && $f['thumb'];
    $path = $f ? $dir . ($thumb ? $f['thumb'] : $f['file']) : '';
    if (!$f || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: ' . ($thumb ? 'image/jpeg' : 'image/png'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $fileName($f) . '"');
    readfile($path);
    exit;
}

/* ---------- Everything (or the search results) as one ZIP — stored, since PNGs are already compressed ---------- */
if (isset($_GET['zip'])) {
    @set_time_limit(300);
    $q = trim((string) ($_GET['q'] ?? ''));
    $rows = q('SELECT * FROM flyers' . ($q !== '' ? ' WHERE name LIKE ? OR reference LIKE ?' : '') . ' ORDER BY id', $q !== '' ? ["%$q%", "%$q%"] : [])->fetchAll();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="i-will-be-there-flyers-' . date('Y-m-d') . '.zip"');
    $offset = 0;
    $central = '';
    $count = 0;
    $used = [];
    foreach ($rows as $f) {
        $path = $dir . $f['file'];
        if (!is_file($path)) continue;
        $data = (string) file_get_contents($path);
        $name = $fileName($f);
        $n = 2;
        while (isset($used[$name])) {
            $name = preg_replace('/(-\d+)?\.png$/', '-' . $n++ . '.png', $name);
        }
        $used[$name] = true;
        $crc = crc32($data);
        $len = strlen($data);
        $t = strtotime($f['updated_at']);
        $dosTime = (date('H', $t) << 11) | (date('i', $t) << 5) | intdiv((int) date('s', $t), 2);
        $dosDate = ((date('Y', $t) - 1980) << 9) | (date('n', $t) << 5) | (int) date('j', $t);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0) . $name;
        echo $header, $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $offset += strlen($header) + $len;
        $count++;
        flush();
    }
    echo $central, pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    // Copied for posting on X (or marked by hand) — and undo
    if ($action === 'x_mark' && ($f = q('SELECT * FROM flyers WHERE id = ?', [(int) ($_POST['id'] ?? 0)])->fetch())) {
        $posted = !empty($_POST['posted']);
        if ($posted && !$f['x_posted_at']) {
            q('UPDATE flyers SET x_posted_at = ?, x_posted_by = ? WHERE id = ?', [now(), $admin['id'], $f['id']]);
        } elseif (!$posted) {
            q('UPDATE flyers SET x_posted_at = NULL, x_posted_by = NULL WHERE id = ?', [$f['id']]);
        }
        if (!empty($_POST['ajax'])) {
            $left = (int) q('SELECT COUNT(*) FROM flyers WHERE x_posted_at IS NULL')->fetchColumn();
            json_response(['ok' => true, 'posted' => $posted, 'by' => $admin['name'], 'left' => $left, 'done' => (int) q('SELECT COUNT(*) FROM flyers')->fetchColumn() - $left]);
        }
        flash($posted ? 'Marked as posted on X.' : 'Marked as not posted yet.');
    }
    if ($action === 'x_text') {
        $text = trim(preg_replace('/[ \t]+/u', ' ', (string) ($_POST['x_text'] ?? '')));
        if ($text === '' || !str_contains($text, '{name}')) {
            flash('Keep {name} in the post — it is replaced by each person\'s name.', 'error');
        } else {
            setting_set('flyer_x_text', $text === $xDefault ? '' : mb_substr($text, 0, 500));
            flash('Post wording saved.');
        }
    }
    if ($action === 'delete' && ($f = q('SELECT * FROM flyers WHERE id = ?', [(int) ($_POST['id'] ?? 0)])->fetch())) {
        foreach ([$f['file'], $f['thumb']] as $file) {
            if ($file && is_file($dir . $file)) {
                @unlink($dir . $file);
            }
        }
        q('DELETE FROM flyers WHERE id = ?', [$f['id']]);
        flash('Flyer deleted.');
    }
    redirect('flyers.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : ''));
}

$search = trim((string) ($_GET['q'] ?? ''));
$x = in_array($_GET['x'] ?? '', ['todo', 'done'], true) ? $_GET['x'] : '';
$conds = $search !== '' ? ['(f.name LIKE ? OR f.reference LIKE ?)'] : [];
$params = $search !== '' ? ["%$search%", "%$search%"] : [];
$tabWhere = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
if ($x !== '') {
    $conds[] = $x === 'todo' ? 'f.x_posted_at IS NULL' : 'f.x_posted_at IS NOT NULL';
}
$where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
// Not posted yet: oldest first, so nobody waits at the back of the queue
$order = ['todo' => 'f.created_at ASC, f.id ASC', 'done' => 'f.x_posted_at DESC'][$x] ?? 'f.updated_at DESC';
$perPage = 48;
$total = (int) q("SELECT COUNT(*) FROM flyers f$where", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = q("SELECT f.*, r.full_name reg_name, r.status reg_status, a.name x_by_name FROM flyers f LEFT JOIN registrations r ON r.id = f.registration_id LEFT JOIN admins a ON a.id = f.x_posted_by$where ORDER BY $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$all = (int) q('SELECT COUNT(*) FROM flyers')->fetchColumn();
$registered = (int) q('SELECT COUNT(*) FROM flyers WHERE registration_id IS NOT NULL')->fetchColumn();
$week = (int) q('SELECT COUNT(*) FROM flyers WHERE created_at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))])->fetchColumn();
$xDone = (int) q('SELECT COUNT(*) FROM flyers WHERE x_posted_at IS NOT NULL')->fetchColumn();
$tabCounts = q("SELECT COUNT(*) n, SUM(f.x_posted_at IS NULL) todo FROM flyers f$tabWhere", $search !== '' ? ["%$search%", "%$search%"] : [])->fetch();
$qs = array_filter(['q' => $search, 'x' => $x]);
$pageUrl = fn(int $p) => 'flyers.php?' . http_build_query($qs + ['page' => $p]);

admin_header('“I will be there” flyers', 'flyers', 'Every flyer people made with the flyer maker — view, download and reuse them');
?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-image-portrait"></i></span><div><small>Flyers made</small><b><?= number_format($all) ?></b><em>+<?= $week ?> in 7 days</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-id-badge"></i></span><div><small>By registered campers</small><b><?= number_format($registered) ?></b><em>matched to a code number</em></div></div>
  <div class="kpi"><span class="kpi-icon blue"><i class="fa-brands fa-x-twitter"></i></span><div><small>Posted on X</small><b><span class="js-x-done"><?= number_format($xDone) ?></span> <span class="kpi-of">of <?= number_format($all) ?></span></b><em><span class="js-x-left"><?= number_format($all - $xDone) ?></span> still to post</em></div></div>
  <div class="kpi"><span class="kpi-icon purple"><i class="fa-solid fa-file-zipper"></i></span><div><small>Download</small><b class="small-b"><?php if ($total): ?><a href="flyers.php?<?= e(http_build_query($qs + ['zip' => 1])) ?>">All <?= number_format($total) ?> as ZIP</a><?php else: ?>—<?php endif; ?></b><em>original PNG files</em></div></div>
</div>

<form class="card filters" method="get">
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name or code number"></div>
  <?php if ($x !== ''): ?><input type="hidden" name="x" value="<?= e($x) ?>"><?php endif; ?>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Search</button>
  <?php if ($search !== ''): ?><a href="flyers.php<?= $x !== '' ? '?x=' . e($x) : '' ?>" class="btn btn-light">Clear</a><?php endif; ?>
  <a href="../flyer.php" target="_blank" class="btn btn-light"><i class="fa-solid fa-arrow-up-right-from-square"></i> Flyer maker</a>
</form>

<div class="x-bar">
  <nav class="tabs">
    <?php foreach (['' => ['All', (int) $tabCounts['n']], 'todo' => ['Not posted yet', (int) $tabCounts['todo']], 'done' => ['Posted on X', (int) $tabCounts['n'] - (int) $tabCounts['todo']]] as $k => [$label, $n]): ?>
    <a href="flyers.php?<?= e(http_build_query(array_filter(['q' => $search, 'x' => $k]))) ?>" class="<?= $x === $k ? 'active' : '' ?>"><?= e($label) ?> <em><?= number_format($n) ?></em></a>
    <?php endforeach; ?>
  </nav>
  <details class="x-wording">
    <summary><i class="fa-solid fa-pen"></i> Post wording</summary>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="x_text">
      <textarea name="x_text" rows="4" maxlength="500" required><?= e($xTemplate) ?></textarea>
      <small class="muted">{name} becomes each person's name. X allows 280 characters (emojis count as 2) — the count under each flyer turns red if a post is too long.</small>
      <div><button class="btn btn-navy btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save wording</button></div>
    </form>
  </details>
</div>

<?php if ($rows): ?>
<div class="flyer-gallery">
  <?php foreach ($rows as $f): ?>
  <article class="card flyer-item js-xpost<?= $f['x_posted_at'] ? ' is-posted' : '' ?>" data-id="<?= (int) $f['id'] ?>">
    <a href="flyers.php?img=<?= (int) $f['id'] ?>" target="_blank" class="flyer-thumb" title="View full size">
      <img src="flyers.php?img=<?= (int) $f['id'] ?>&amp;thumb=1&amp;v=<?= e(strtotime($f['updated_at'])) ?>" alt="Flyer of <?= e($f['name']) ?>" loading="lazy">
      <span class="x-ribbon"><i class="fa-solid fa-check"></i> Posted on X</span>
    </a>
    <div class="flyer-meta">
      <b title="<?= e($f['name']) ?>"><?= e($f['name'] ?: ($f['reg_name'] ?? 'No name')) ?></b>
      <span>
        <?php if ($f['registration_id']): ?><a class="ref" href="view.php?id=<?= (int) $f['registration_id'] ?>"><?= e($f['reference']) ?></a>
        <?php elseif ($f['reference'] !== ''): ?><span class="muted" title="This code did not match a registration"><?= e($f['reference']) ?> ?</span>
        <?php else: ?><span class="muted">No code</span><?php endif; ?>
        · <span class="muted" title="<?= e($f['updated_at']) ?>"><?= e(time_ago($f['updated_at'])) ?></span>
      </span>
    </div>
    <div class="x-post">
      <textarea class="js-xtext" rows="4" readonly aria-label="Post for X"><?= e($xText($f)) ?></textarea>
      <small class="x-count js-xcount"></small>
    </div>
    <a class="btn btn-primary btn-sm btn-block js-xcopy" href="flyers.php?img=<?= (int) $f['id'] ?>&amp;dl=1" data-download="1"><i class="fa-brands fa-x-twitter"></i> Copy post &amp; download</a>
    <div class="flyer-btns">
      <a class="btn btn-light btn-sm" href="flyers.php?img=<?= (int) $f['id'] ?>" target="_blank" title="View full size"><i class="fa-regular fa-eye"></i> View</a>
      <a class="btn btn-light btn-sm" href="flyers.php?img=<?= (int) $f['id'] ?>&amp;dl=1" title="Download the flyer only"><i class="fa-solid fa-download"></i> Image</a>
      <button class="btn btn-light btn-sm js-xcopy" type="button" title="Copy the post only"><i class="fa-regular fa-copy"></i> Text</button>
      <form method="post" class="inline-form" data-confirm="Delete this flyer?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="return" value="<?= e($qs ? '?' . http_build_query($qs) : '') ?>"><button class="btn btn-light btn-sm icon-only" type="submit" title="Delete"><i class="fa-regular fa-trash-can"></i></button></form>
    </div>
    <form method="post" class="x-state js-xmark">
      <?= csrf_field() ?><input type="hidden" name="action" value="x_mark"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
      <span class="x-done"><i class="fa-solid fa-circle-check"></i> Copied for X<span class="js-xwho"><?= $f['x_posted_at'] ? ' by ' . e($f['x_by_name'] ?: 'the team') . ' · ' . e(time_ago($f['x_posted_at'])) : '' ?></span> <button type="submit" name="posted" value="" class="link-btn">Undo</button></span>
      <span class="x-todo"><i class="fa-regular fa-circle"></i> Not posted yet <button type="submit" name="posted" value="1" class="link-btn">Mark as posted</button></span>
    </form>
  </article>
  <?php endforeach; ?>
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
<div class="card"><div class="empty-state"><i class="fa-regular fa-image"></i><p><?= $search !== '' ? 'No flyers match your search.' : 'No flyers yet. They appear here when people download or share their “I will be there” flyer.' ?></p><a class="btn btn-primary" href="../flyer.php" target="_blank">Open the flyer maker</a></div></div>
<?php endif; ?>
<?php admin_footer(['flyers.js']);
