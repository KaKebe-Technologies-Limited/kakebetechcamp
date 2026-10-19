<?php
require __DIR__ . '/_init.php';
$admin = require_admin();
$me = (int) $admin['id'];
$manage = call_can_manage($admin);
$outcomes = call_outcomes();
$responses = call_responses();
$payStatuses = payment_statuses();
$callers = call_callers();

/** WHERE for the filters; callers only ever see the people given to them. */
$filters = function (array $in) use ($manage, $me, $payStatuses, $callers): array {
    $f = [
        'pay'    => isset($payStatuses[$in['pay'] ?? '']) || ($in['pay'] ?? '') === 'owing' ? (string) $in['pay'] : '',
        'call'   => in_array($in['call'] ?? '', ['todo', 'never', 'no_answer', 'callback', 'answered', 'unreachable', 'promised'], true) ? (string) $in['call'] : '',
        'caller' => $manage ? (string) ($in['caller'] ?? '') : '',
        'q'      => trim((string) ($in['q'] ?? '')),
    ];
    $w = ["r.status <> 'cancelled'"];
    $p = [];
    if (!$manage) {
        $w[] = 'c.assigned_to = ?';
        $p[] = $me;
    } elseif ($f['caller'] === 'none') {
        $w[] = 'c.assigned_to IS NULL';
    } elseif (isset($callers[(int) $f['caller']])) {
        $w[] = 'c.assigned_to = ?';
        $p[] = (int) $f['caller'];
    } else {
        $f['caller'] = '';
    }
    if ($f['pay'] === 'owing') {
        $w[] = "r.payment_status IN ('unpaid', 'partial')";
    } elseif ($f['pay']) {
        $w[] = 'r.payment_status = ?';
        $p[] = $f['pay'];
    }
    $w[] = match ($f['call']) {
        'todo'        => "(c.last_outcome IS NULL OR c.last_outcome IN ('no_answer', 'callback', 'off'))",
        'never'       => '(c.attempts IS NULL OR c.attempts = 0)',
        'no_answer'   => "c.last_outcome = 'no_answer'",
        'callback'    => "c.last_outcome = 'callback'",
        'answered'    => "c.last_outcome = 'answered'",
        'unreachable' => "c.last_outcome IN ('off', 'wrong')",
        'promised'    => 'c.promise_date IS NOT NULL',
        default       => '1 = 1',
    };
    if ($f['q'] !== '') {
        $w[] = '(r.full_name LIKE ? OR r.phone LIKE ? OR r.reference LIKE ? OR r.district LIKE ? OR r.email LIKE ?)';
        array_push($p, ...array_fill(0, 5, '%' . $f['q'] . '%'));
    }
    return [' WHERE ' . implode(' AND ', $w), $p, $f];
};
$from = ' FROM registrations r LEFT JOIN call_list c ON c.registration_id = r.id LEFT JOIN admins a ON a.id = c.assigned_to LEFT JOIN admins lb ON lb.id = c.last_called_by';

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === '1' && $manage) {
    [$where, $params] = $filters($_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-phone-calls-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference', 'Name', 'Phone', 'District', 'Payment status', 'Total (UGX)', 'Paid', 'Balance', 'Assigned to', 'Calls made', 'Last result', 'What they said', 'Promised date', 'Last comment', 'Last called', 'Called by']);
    foreach (q("SELECT r.*, c.*, a.name caller_name, lb.name last_by$from$where ORDER BY r.id", $params) as $r) {
        fputcsv($out, [$r['reference'], $r['full_name'], $r['phone'], $r['district'], $payStatuses[$r['payment_status']] ?? $r['payment_status'], $r['total_amount'], $r['amount_paid'], balance($r),
            $r['caller_name'], (int) $r['attempts'], $outcomes[$r['last_outcome']][0] ?? '', $responses[$r['last_response']] ?? '', $r['promise_date'], $r['last_comment'], $r['last_called_at'], $r['last_by']]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'calls.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : '');

    if ($action === 'log') {
        $rid = (int) ($_POST['registration_id'] ?? 0);
        $outcome = (string) ($_POST['outcome'] ?? '');
        $assigned = q('SELECT assigned_to FROM call_list WHERE registration_id = ?', [$rid])->fetchColumn();
        $exists = (bool) q("SELECT id FROM registrations WHERE id = ? AND status <> 'cancelled'", [$rid])->fetchColumn();
        if (!$exists || !isset($outcomes[$outcome]) || (!$manage && (int) $assigned !== $me)) {
            flash('That call could not be saved.', 'error');
            redirect($back);
        }
        $response = isset($responses[$_POST['response'] ?? '']) ? $_POST['response'] : null;
        $promise = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['promise_date'] ?? '')) ? $_POST['promise_date'] : null;
        $comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 1000) ?: null;
        call_record($rid, $me, $outcome, $outcome === 'answered' ? $response : null, $outcome === 'answered' ? $promise : null, $comment);
        $name = (string) q('SELECT full_name FROM registrations WHERE id = ?', [$rid])->fetchColumn();
        flash($name . ': ' . $outcomes[$outcome][0] . ($response && $outcome === 'answered' ? ' · ' . $responses[$response] : '') . ' — saved.');
        redirect($back . '#r-' . $rid);
    }

    if (!$manage) {
        redirect($back);
    }

    if ($action === 'assign') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $to = (string) ($_POST['assign_to'] ?? '');
        if (!$ids || ($to !== 'none' && !isset($callers[(int) $to]))) {
            flash('Tick some people and choose who will call them.', 'error');
            redirect($back);
        }
        $stmt = db()->prepare('INSERT INTO call_list (registration_id, assigned_to, assigned_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE assigned_to = VALUES(assigned_to), assigned_at = VALUES(assigned_at)');
        foreach ($ids as $id) {
            $stmt->execute([$id, $to === 'none' ? null : (int) $to, now()]);
        }
        flash(count($ids) . ' ' . (count($ids) === 1 ? 'person' : 'people') . ($to === 'none' ? ' unassigned.' : ' given to ' . $callers[(int) $to]['name'] . '.'));
        redirect($back);
    }

    if ($action === 'share') {
        // Share the people in the current view who have no caller yet evenly among the chosen callers
        $chosen = array_values(array_filter(array_map('intval', (array) ($_POST['callers'] ?? [])), fn($id) => isset($callers[$id])));
        parse_str(ltrim((string) ($_POST['return'] ?? ''), '?'), $view);
        [$where, $params] = $filters($view);
        $ids = q("SELECT r.id$from$where AND c.assigned_to IS NULL ORDER BY r.id", $params)->fetchAll(PDO::FETCH_COLUMN);
        if (!$chosen || !$ids) {
            flash(!$chosen ? 'Choose at least one caller.' : 'Everyone in this view already has a caller.', 'error');
            redirect($back);
        }
        $stmt = db()->prepare('INSERT INTO call_list (registration_id, assigned_to, assigned_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE assigned_to = VALUES(assigned_to), assigned_at = VALUES(assigned_at)');
        $per = [];
        foreach ($ids as $i => $id) {
            $to = $chosen[$i % count($chosen)];
            $stmt->execute([(int) $id, $to, now()]);
            $per[$to] = ($per[$to] ?? 0) + 1;
        }
        flash('Shared ' . count($ids) . ' people: ' . implode(', ', array_map(fn($id, $n) => $callers[$id]['name'] . ' ' . $n, array_keys($per), $per)) . '.');
        redirect($back);
    }
    redirect($back);
}

[$where, $params, $f] = $filters($_GET);
$perPage = 30;
$total = (int) q("SELECT COUNT(*)$from$where", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
// Not called yet first, then those to try again, then the rest
$rows = q("SELECT r.*, c.assigned_to, c.last_outcome, c.last_response, c.promise_date, c.last_comment, c.attempts, c.last_called_at, a.name caller_name, lb.name last_by$from$where
    ORDER BY CASE WHEN c.last_outcome IS NULL THEN 0 WHEN c.last_outcome IN ('callback', 'no_answer', 'off') THEN 1 ELSE 2 END, r.id LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$history = [];
if ($rows) {
    $in = implode(',', array_map(fn($r) => (int) $r['id'], $rows));
    foreach (q("SELECT l.*, a.name admin_name FROM call_logs l LEFT JOIN admins a ON a.id = l.admin_id WHERE l.registration_id IN ($in) ORDER BY l.id DESC")->fetchAll() as $l) {
        $history[(int) $l['registration_id']][] = $l;
    }
}
$qs = array_filter($f, fn($v) => $v !== '');
$qsString = $qs ? '?' . http_build_query($qs) . ($page > 1 ? '&page=' . $page : '') : ($page > 1 ? '?page=' . $page : '');
$url = fn(array $extra) => 'calls.php?' . http_build_query(array_filter($extra + $qs, fn($v) => $v !== '' && $v !== null));

// Progress (for this caller, or everyone for managers)
$scope = $manage ? '' : ' AND c.assigned_to = ' . $me;
$st = q("SELECT COUNT(*) total, SUM(c.attempts > 0) called, SUM(c.last_outcome = 'answered') answered, SUM(c.last_outcome IN ('no_answer', 'callback', 'off')) retry,
    SUM(c.last_outcome = 'wrong') wrong, SUM(c.attempts IS NULL OR c.attempts = 0) never, SUM(c.assigned_to IS NULL) unassigned
    FROM registrations r LEFT JOIN call_list c ON c.registration_id = r.id WHERE r.status <> 'cancelled'$scope")->fetch();
$today = (int) q('SELECT COUNT(*) FROM call_logs WHERE created_at >= ?' . ($manage ? '' : ' AND admin_id = ' . $me), [date('Y-m-d 00:00:00')])->fetchColumn();
$byCaller = $manage ? q("SELECT c.assigned_to id, COUNT(*) assigned, SUM(c.attempts > 0) called, SUM(c.last_outcome = 'answered') answered FROM call_list c JOIN registrations r ON r.id = c.registration_id
    WHERE r.status <> 'cancelled' AND c.assigned_to IS NOT NULL GROUP BY c.assigned_to")->fetchAll() : [];
$callsToday = $manage ? array_column(q('SELECT admin_id, COUNT(*) n FROM call_logs WHERE created_at >= ? GROUP BY admin_id', [date('Y-m-d 00:00:00')])->fetchAll(), 'n', 'admin_id') : [];
$pct = $st['total'] ? round($st['called'] / $st['total'] * 100) : 0;

admin_header($manage ? 'Phone calls' : 'My calls', 'calls', 'Calling registered participants about the camp and their payment');
?>
<div class="kpis calls-kpis">
  <a class="kpi" href="<?= e($url(['call' => null, 'page' => null])) ?>"><span class="kpi-icon navy"><i class="fa-solid fa-users"></i></span><div><small><?= $manage ? 'People to call' : 'Given to me' ?></small><b><?= number_format((int) $st['total']) ?></b><em><?= $pct ?>% called at least once</em><div class="fin-bar ok"><i style="width:<?= $pct ?>%"></i></div></div></a>
  <a class="kpi" href="<?= e($url(['call' => 'never', 'page' => null])) ?>"><span class="kpi-icon red"><i class="fa-solid fa-phone-volume"></i></span><div><small>Not called yet</small><b><?= number_format((int) $st['never']) ?></b><em><?= $manage ? number_format((int) $st['unassigned']) . ' without a caller' : 'start here' ?></em></div></a>
  <a class="kpi" href="<?= e($url(['call' => 'answered', 'page' => null])) ?>"><span class="kpi-icon green"><i class="fa-solid fa-phone"></i></span><div><small>Answered</small><b><?= number_format((int) $st['answered']) ?></b><em><?= $today ?> call<?= $today === 1 ? '' : 's' ?> made today</em></div></a>
  <a class="kpi" href="<?= e($url(['call' => 'todo', 'page' => null])) ?>"><span class="kpi-icon amber"><i class="fa-solid fa-rotate-right"></i></span><div><small>Try again</small><b><?= number_format((int) $st['retry']) ?></b><em>not picked up, call back, phone off · <?= (int) $st['wrong'] ?> wrong number<?= (int) $st['wrong'] === 1 ? '' : 's' ?></em></div></a>
</div>

<?php if ($manage): ?>
<div class="grid-2 align-start">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-headset"></i> Callers</h3><span class="muted small">give people "Phone calls" access (or the Caller role) in <a href="users.php">Team &amp; access</a></span></div>
    <?php if ($callers): ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Caller</th><th class="r">Given</th><th class="r">Called</th><th class="r">Answered</th><th class="r">Today</th></tr></thead>
      <tbody><?php $bc = array_column($byCaller, null, 'id'); foreach ($callers as $id => $c): $x = $bc[$id] ?? ['assigned' => 0, 'called' => 0, 'answered' => 0]; ?>
        <tr class="row-link" data-href="<?= e($url(['caller' => $id, 'page' => null])) ?>"><td><b><?= e($c['name']) ?></b><small class="block muted"><?= e(admin_role_label($c['role'])) ?></small></td><td class="r"><?= (int) $x['assigned'] ?></td><td class="r"><?= (int) $x['called'] ?></td><td class="r"><?= (int) $x['answered'] ?></td><td class="r"><?= (int) ($callsToday[$id] ?? 0) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="empty-note">Nobody has "Phone calls" access yet. Add callers in <a href="users.php">Team &amp; access</a> (role: Caller).</p><?php endif; ?>
  </section>
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-shuffle"></i> Share people among callers</h3></div>
    <p class="small muted">Shares everyone in the current view (<b><?= number_format($total) ?></b> people<?= $qs ? ', filtered' : '' ?>) who has no caller yet, evenly among the callers you tick. Or tick people in the list below and give them to one caller.</p>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="share"><input type="hidden" name="return" value="<?= e($qsString) ?>">
      <div class="btn-row"><?php foreach ($callers as $id => $c): ?><label class="check-inline"><input type="checkbox" name="callers[]" value="<?= $id ?>"> <?= e($c['name']) ?></label><?php endforeach; ?></div>
      <div><button class="btn btn-navy btn-sm" type="submit"<?= $callers ? '' : ' disabled' ?>><i class="fa-solid fa-shuffle"></i> Share people without a caller</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="tabs big">
  <?php foreach (['' => 'Everyone', 'todo' => 'To call', 'never' => 'Not called yet', 'no_answer' => 'Not picked up', 'callback' => 'Call back', 'answered' => 'Answered', 'promised' => 'Promised to pay', 'unreachable' => 'Off / wrong number'] as $k => $label): ?>
  <a href="<?= e($url(['call' => $k, 'page' => null])) ?>" class="<?= $f['call'] === $k ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<form class="card filters" method="get">
  <?php if ($f['call']): ?><input type="hidden" name="call" value="<?= e($f['call']) ?>"><?php endif; ?>
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, phone, reference, district…"></div>
  <select name="pay"><option value="">Any payment status</option><option value="owing"<?= $f['pay'] === 'owing' ? ' selected' : '' ?>>Still owing (unpaid or part paid)</option><?php foreach ($payStatuses as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['pay'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if ($manage): ?><select name="caller"><option value="">Any caller</option><option value="none"<?= $f['caller'] === 'none' ? ' selected' : '' ?>>No caller yet</option><?php foreach ($callers as $id => $c): ?><option value="<?= $id ?>"<?= $f['caller'] === (string) $id ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn btn-navy" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($qs): ?><a href="calls.php" class="btn btn-light">Clear</a><?php endif; ?>
  <?php if ($manage): ?><a href="<?= e($url(['export' => 1, 'page' => null])) ?>" class="btn btn-light"><i class="fa-solid fa-file-csv"></i> Export</a><?php endif; ?>
</form>

<?php if ($manage): ?>
<form method="post" id="assignForm" class="card calls-assign"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="return" value="<?= e($qsString) ?>">
  <label class="check-inline"><input type="checkbox" id="checkAllCalls"> Tick all on this page</label>
  <span class="muted small"><span id="callsTicked">0</span> ticked →</span>
  <select name="assign_to"><option value="">Give to…</option><?php foreach ($callers as $id => $c): ?><option value="<?= $id ?>"><?= e($c['name']) ?></option><?php endforeach; ?><option value="none">— Remove caller</option></select>
  <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-user-check"></i> Assign</button>
</form>
<?php endif; ?>

<div class="calls-list">
  <?php if (!$rows): ?>
    <div class="card empty-state"><i class="fa-solid fa-phone"></i><p><?= $manage ? 'Nobody matches these filters.' : ($qs ? 'Nobody here.' : 'No calls have been given to you yet. The team lead or operations will assign people to you.') ?></p></div>
  <?php endif; ?>
  <?php foreach ($rows as $r): $o = $outcomes[$r['last_outcome']] ?? null; $bal = balance($r); $h = $history[(int) $r['id']] ?? []; ?>
  <section class="card call-card<?= $o ? ' oc-' . $o[2] : '' ?>" id="r-<?= (int) $r['id'] ?>">
    <div class="call-top">
      <?php if ($manage): ?><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="assignForm" class="call-check" aria-label="Tick <?= e($r['full_name']) ?>"><?php endif; ?>
      <div class="call-who">
        <b><?= e($r['full_name']) ?></b> <span class="muted small"><?= e($r['reference']) ?> · <?= e(trim($r['district'] . ', ' . $r['country'], ', ')) ?> · age <?= (int) $r['age'] ?></span>
        <div class="call-pay"><?= payment_badge($r['payment_status']) ?> <span class="small"><?= $bal ? 'Balance <b>' . e(format_ugx($bal)) . '</b> of ' . e(format_ugx($r['total_amount'])) : e(format_ugx($r['amount_paid'])) . ' paid' ?></span><?php if ($manage): ?> <span class="small muted">· <?= $r['caller_name'] ? 'Caller: <b>' . e($r['caller_name']) . '</b>' : 'No caller yet' ?></span><?php endif; ?></div>
      </div>
      <div class="call-phone">
        <a class="btn btn-primary call-dial" href="<?= e(tel_link((string) $r['phone'])) ?>"><i class="fa-solid fa-phone"></i> <?= e($r['phone']) ?></a>
        <a class="btn btn-light btn-sm call-wa" href="<?= e(participant_whatsapp_reminder_link($r)) ?>" target="_blank" rel="noopener" title="WhatsApp with the payment reminder typed"><i class="fa-brands fa-whatsapp"></i></a>
      </div>
    </div>

    <?php if ($o): ?>
    <div class="call-last">
      <span class="call-badge oc-<?= $o[2] ?>"><i class="fa-solid <?= $o[1] ?>"></i> <?= e($o[0]) ?></span>
      <?php if ($r['last_response']): ?><span class="call-resp"><?= e($responses[$r['last_response']] ?? '') ?></span><?php endif; ?>
      <?php if ($r['promise_date']): ?><span class="call-resp"><i class="fa-regular fa-calendar"></i> promised <?= e(date('D j M', strtotime($r['promise_date']))) ?></span><?php endif; ?>
      <span class="muted small"><?= (int) $r['attempts'] ?> call<?= (int) $r['attempts'] === 1 ? '' : 's' ?> · last <?= e(time_ago($r['last_called_at'])) ?> by <?= e((string) $r['last_by']) ?></span>
      <?php if ($r['last_comment']): ?><p class="call-comment"><i class="fa-solid fa-quote-left"></i> <?= e($r['last_comment']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($manage || (int) $r['assigned_to'] === $me): ?>
    <form method="post" class="call-form"><?= csrf_field() ?><input type="hidden" name="action" value="log"><input type="hidden" name="registration_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="return" value="<?= e($qsString) ?>">
      <div class="call-fields">
        <label>What did they say about paying? <small class="muted">(when answered)</small>
          <select name="response"><option value="">—</option><?php foreach ($responses as $k => $l): ?><option value="<?= e($k) ?>"<?= $r['last_response'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        </label>
        <label>Promised date <small class="muted">(if any)</small><input type="date" name="promise_date" value="<?= e((string) $r['promise_date']) ?>" min="<?= date('Y-m-d') ?>"></label>
        <label class="call-note">Comment <small class="muted">(what they said, payments, questions)</small><textarea name="comment" rows="2" maxlength="1000" placeholder="e.g. Parent will send the balance on Friday via MoMo"></textarea></label>
      </div>
      <div class="call-buttons">
        <?php foreach ($outcomes as $k => [$label, $icon, $colour]): ?>
        <button type="submit" name="outcome" value="<?= e($k) ?>" class="call-btn oc-<?= $colour ?>"><i class="fa-solid <?= $icon ?>"></i> <?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($h): ?>
    <details class="call-history"><summary>Call history (<?= count($h) ?>)</summary>
      <ul><?php foreach ($h as $l): $lo = $outcomes[$l['outcome']] ?? ['?', 'fa-phone', '']; ?><li><span class="call-badge oc-<?= $lo[2] ?>"><i class="fa-solid <?= $lo[1] ?>"></i> <?= e($lo[0]) ?></span> <?= $l['response'] ? e($responses[$l['response']] ?? '') . ' · ' : '' ?><?= $l['promise_date'] ? 'promised ' . e(date('j M', strtotime($l['promise_date']))) . ' · ' : '' ?><?= e((string) $l['comment']) ?> <small class="muted"><?= e(date('D j M, g:i a', strtotime($l['created_at']))) ?> · <?= e((string) $l['admin_name']) ?></small></li><?php endforeach; ?></ul>
    </details>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="pagination">
  <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> people</span>
  <div>
    <?php if ($page > 1): ?><a href="<?= e($url(['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
    <?php for ($n = max(1, $page - 2); $n <= min($pages, $page + 2); $n++): ?><a href="<?= e($url(['page' => $n])) ?>" class="<?= $n === $page ? 'active' : '' ?>"><?= $n ?></a><?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
  </div>
</nav>
<?php endif; ?>
<script>
  (function () {
    var all = document.getElementById('checkAllCalls');
    var count = document.getElementById('callsTicked');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.call-check'));
    var sync = function () { if (count) count.textContent = boxes.filter(function (b) { return b.checked; }).length; };
    if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); });
    boxes.forEach(function (b) { b.addEventListener('change', sync); });
  })();
</script>
<?php admin_footer();
