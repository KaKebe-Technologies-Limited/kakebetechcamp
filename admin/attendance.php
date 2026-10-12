<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['day'] ?? '')) ? $_GET['day'] : date('Y-m-d');
$from = $day . ' 00:00:00';
$to = date('Y-m-d 00:00:00', strtotime($day . ' +1 day'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'open') {
        setting_set('attendance_open_until', date('Y-m-d H:i:s', time() + 2 * 3600));
        setting_set('attendance_opened_at', now());
        flash('Attendance is open until ' . date('g:i A', time() + 2 * 3600) . '.');
    } elseif ($action === 'extend' && attendance_is_open()) {
        setting_set('attendance_open_until', date('Y-m-d H:i:s', attendance_open_until() + 3600));
        flash('Attendance is now open until ' . date('g:i A', attendance_open_until()) . '.');
    } elseif ($action === 'close') {
        setting_set('attendance_open_until', now());
        flash('Attendance is closed.');
    } elseif ($action === 'per_ip') {
        setting_set('attendance_one_per_ip', !empty($_POST['one_per_ip']) ? '1' : '0');
        flash(!empty($_POST['one_per_ip']) ? 'Only one name per IP address from now on.' : 'Several names can now come from the same network.');
    }
    if ($action === 'delete') {
        q('DELETE FROM attendance WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        flash('Entry removed.');
    }
    redirect('attendance.php?day=' . $day);
}

/* ---------- CSV export (one day, or every day with ?all=1) ---------- */
if (($_GET['export'] ?? '') === '1') {
    $all = !empty($_GET['all']);
    $rows = $all ? q('SELECT * FROM attendance ORDER BY created_at')->fetchAll() : q('SELECT * FROM attendance WHERE created_at >= ? AND created_at < ? ORDER BY created_at', [$from, $to])->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-attendance-' . ($all ? 'all' : $day) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['#', 'Full name', 'Date', 'Time']);
    foreach ($rows as $i => $r) {
        fputcsv($out, [$i + 1, $r['full_name'], date('D j M Y', strtotime($r['created_at'])), date('g:i A', strtotime($r['created_at']))]);
    }
    exit;
}

$rows = q('SELECT * FROM attendance WHERE created_at >= ? AND created_at < ? ORDER BY created_at', [$from, $to])->fetchAll();
$days = q('SELECT DATE(created_at) d, COUNT(*) c FROM attendance GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 30')->fetchAll();
$total = (int) q('SELECT COUNT(*) FROM attendance')->fetchColumn();

admin_header('Attendance', 'attendance', 'Names entered at ' . base_url('attendance') . ' — date and time recorded automatically');
?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-clipboard-user"></i></span><div><small><?= $day === date('Y-m-d') ? 'Present today' : 'Present on ' . e(date('j M', strtotime($day))) ?></small><b><?= number_format(count($rows)) ?></b><em><?= e(date('l j F Y', strtotime($day))) ?></em></div></div>
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-calendar-days"></i></span><div><small>All entries</small><b><?= number_format($total) ?></b><em>across <?= count($days) ?> day<?= count($days) === 1 ? '' : 's' ?></em></div></div>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-link"></i></span><div><small>Attendance page</small><b class="small-b"><a href="<?= e(base_url('attendance')) ?>" target="_blank">/attendance <i class="fa-solid fa-arrow-up-right-from-square"></i></a></b><em>open it on a phone or share the link</em></div></div>
</div>

<?php $isOpen = attendance_is_open(); $perIp = setting('attendance_one_per_ip', '1') === '1'; ?>
<section class="card att-switch <?= $isOpen ? 'on' : 'off' ?>">
  <div>
    <h3><i class="fa-solid <?= $isOpen ? 'fa-lock-open' : 'fa-lock' ?>"></i> Attendance is <?= $isOpen ? 'OPEN' : 'CLOSED' ?></h3>
    <p class="small muted"><?= $isOpen ? 'People can mark attendance until <b>' . e(date('g:i A · D j M', attendance_open_until())) . '</b> (' . e(attendance_time_left()) . ' left).' : 'Open it at the start of a session — it stays open for 2 hours, then closes by itself.' ?></p>
  </div>
  <div class="actions">
    <?php if ($isOpen): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="extend"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-plus"></i> 1 more hour</button></form>
      <form method="post" class="inline" data-confirm="Close attendance now?"><?= csrf_field() ?><input type="hidden" name="action" value="close"><button class="btn btn-danger btn-sm" type="submit"><i class="fa-solid fa-lock"></i> Close now</button></form>
    <?php else: ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="open"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-lock-open"></i> Open attendance for 2 hours</button></form>
    <?php endif; ?>
  </div>
  <form method="post" class="att-ip"><?= csrf_field() ?><input type="hidden" name="action" value="per_ip">
    <label class="switch"><input type="checkbox" name="one_per_ip" value="1" <?= $perIp ? 'checked' : '' ?> onchange="this.form.submit()"><span class="slider"></span> Only one name per IP address</label>
    <span class="small muted">Stops one person marking others. Phones on the same Wi-Fi share one IP address — if people on the venue Wi-Fi are blocked, switch this off or ask them to use mobile data.</span>
  </form>
</section>

<form class="card filters" method="get">
  <label class="small">Day <input type="date" name="day" value="<?= e($day) ?>" onchange="this.form.submit()"></label>
  <?php foreach (array_slice($days, 0, 8) as $d): ?><a class="btn btn-sm <?= $d['d'] === $day ? 'btn-navy' : 'btn-light' ?>" href="attendance.php?day=<?= e($d['d']) ?>"><?= e(date('D j M', strtotime($d['d']))) ?> · <?= (int) $d['c'] ?></a><?php endforeach; ?>
  <a href="attendance.php?day=<?= e($day) ?>&amp;export=1" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export this day</a>
  <a href="attendance.php?export=1&amp;all=1" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export all</a>
</form>

<div class="card">
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>Full name</th><th>Time</th><th>Date</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="muted"><?= $i + 1 ?></td>
          <td><b><?= e($r['full_name']) ?></b></td>
          <td class="nowrap"><?= e(date('g:i A', strtotime($r['created_at']))) ?></td>
          <td class="nowrap muted"><?= e(date('D j M Y', strtotime($r['created_at']))) ?></td>
          <td class="nowrap actions"><form method="post" class="inline" data-confirm="Remove <?= e($r['full_name']) ?> from this list?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="icon-btn danger" title="Remove"><i class="fa-solid fa-trash"></i></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-clipboard-user"></i><p>No one has marked attendance on this day yet. Share <b><?= e(base_url('attendance')) ?></b>.</p></div>
  <?php endif; ?>
</div>
<?php admin_footer();
