<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$dir = STORAGE . '/uploads/flyers/';
$slug = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
$fileName = fn(array $f) => 'i-will-be-there-' . ($slug($f['reference']) ?: 'kakebe') . ($slug($f['name']) ? '-' . $slug($f['name']) : '') . '.png';

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
    if (($_POST['action'] ?? '') === 'delete' && ($f = q('SELECT * FROM flyers WHERE id = ?', [(int) ($_POST['id'] ?? 0)])->fetch())) {
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
$where = $search !== '' ? ' WHERE f.name LIKE ? OR f.reference LIKE ?' : '';
$params = $search !== '' ? ["%$search%", "%$search%"] : [];
$perPage = 48;
$total = (int) q("SELECT COUNT(*) FROM flyers f$where", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = q("SELECT f.*, r.full_name reg_name, r.status reg_status FROM flyers f LEFT JOIN registrations r ON r.id = f.registration_id$where ORDER BY f.updated_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$all = (int) q('SELECT COUNT(*) FROM flyers')->fetchColumn();
$registered = (int) q('SELECT COUNT(*) FROM flyers WHERE registration_id IS NOT NULL')->fetchColumn();
$week = (int) q('SELECT COUNT(*) FROM flyers WHERE created_at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))])->fetchColumn();
$qs = $search !== '' ? ['q' => $search] : [];
$pageUrl = fn(int $p) => 'flyers.php?' . http_build_query($qs + ['page' => $p]);

admin_header('“I will be there” flyers', 'flyers', 'Every flyer people made with the flyer maker — view, download and reuse them');
?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-image-portrait"></i></span><div><small>Flyers made</small><b><?= number_format($all) ?></b><em>+<?= $week ?> in 7 days</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-id-badge"></i></span><div><small>By registered campers</small><b><?= number_format($registered) ?></b><em>matched to a code number</em></div></div>
  <div class="kpi"><span class="kpi-icon blue"><i class="fa-solid fa-link"></i></span><div><small>Flyer maker</small><b class="small-b"><a href="../flyer.php" target="_blank">Open <i class="fa-solid fa-arrow-up-right-from-square"></i></a></b><em>also linked in participants' emails</em></div></div>
  <div class="kpi"><span class="kpi-icon purple"><i class="fa-solid fa-file-zipper"></i></span><div><small>Download</small><b class="small-b"><?php if ($total): ?><a href="flyers.php?<?= e(http_build_query($qs + ['zip' => 1])) ?>">All <?= number_format($total) ?> as ZIP</a><?php else: ?>—<?php endif; ?></b><em>original PNG files</em></div></div>
</div>

<form class="card filters" method="get">
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by name or code number"></div>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Search</button>
  <?php if ($search !== ''): ?><a href="flyers.php" class="btn btn-light">Clear</a><?php endif; ?>
</form>

<?php if ($rows): ?>
<div class="flyer-gallery">
  <?php foreach ($rows as $f): ?>
  <article class="card flyer-item">
    <a href="flyers.php?img=<?= (int) $f['id'] ?>" target="_blank" class="flyer-thumb" title="View full size">
      <img src="flyers.php?img=<?= (int) $f['id'] ?>&amp;thumb=1&amp;v=<?= e(strtotime($f['updated_at'])) ?>" alt="Flyer of <?= e($f['name']) ?>" loading="lazy">
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
    <div class="flyer-btns">
      <a class="btn btn-light btn-sm" href="flyers.php?img=<?= (int) $f['id'] ?>" target="_blank"><i class="fa-regular fa-eye"></i> View</a>
      <a class="btn btn-primary btn-sm" href="flyers.php?img=<?= (int) $f['id'] ?>&amp;dl=1"><i class="fa-solid fa-download"></i> Download</a>
      <form method="post" class="inline-form" data-confirm="Delete this flyer?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><input type="hidden" name="return" value="<?= e($qs ? '?' . http_build_query($qs) : '') ?>"><button class="btn btn-light btn-sm icon-only" type="submit" title="Delete"><i class="fa-regular fa-trash-can"></i></button></form>
    </div>
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
<?php admin_footer();
