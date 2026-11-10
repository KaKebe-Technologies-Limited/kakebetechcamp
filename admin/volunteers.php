<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$fields = array_keys(volunteer_fields());
$statuses = volunteer_statuses();

/** WHERE clause for the filters (status, field, search). */
$filters = function (array $in) use ($fields, $statuses): array {
    $f = ['status' => (string) ($in['status'] ?? ''), 'field' => (string) ($in['field'] ?? ''), 'q' => trim((string) ($in['q'] ?? '')),
        'ai' => in_array($in['ai'] ?? '', ['picks', 'unmet', 'nocv'], true) ? $in['ai'] : '', 'sort' => ($in['sort'] ?? '') === 'score' ? 'score' : ''];
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
    if ($f['ai'] === 'picks') {
        $where[] = 'ai_rank IS NOT NULL';
    } elseif ($f['ai'] === 'unmet') {
        $where[] = 'ai_scored_at IS NOT NULL AND ai_meets = 0';
    } elseif ($f['ai'] === 'nocv') {
        $where[] = "ai_scored_at IS NOT NULL AND ai_notes LIKE '%CV could not be read%'";
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
    [$where, $params, $f] = $filters($_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-volunteer-trainers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Application', 'Full name', 'Email', 'Phone', 'Gender', 'Age', 'Nationality', 'Based in', 'Qualification', 'Course', 'Institution', 'Graduated', 'Fields', 'Experience', 'Current role', 'LinkedIn / portfolio', 'Availability', 'Recommended by', 'About', 'Status', 'Applied',
        'AI score', 'AI shortlist rank', 'Best field (AI)', 'AI summary', 'AI strengths', 'AI concerns', 'AI flags']);
    foreach (q("SELECT * FROM volunteers$where ORDER BY " . ($f['ai'] === 'picks' ? 'ai_rank' : 'id'), $params) as $v) {
        $n = vol_ai_notes($v);
        fputcsv($out, [$v['reference'], $v['full_name'], $v['email'], $v['phone'], $v['gender'], volunteer_age($v['dob']), $v['nationality'], $v['location'], $v['qualification'], $v['course'], $v['institution'], $v['grad_year'],
            str_replace(',', '; ', $v['fields']), $v['experience'], $v['job_role'], $v['portfolio_url'], volunteer_availability_options()[$v['availability']] ?? $v['availability'], $v['referred_by'], $v['bio'], $statuses[$v['status']] ?? $v['status'], $v['created_at'],
            $v['ai_score'], $v['ai_rank'], $v['ai_field'], $v['ai_summary'], implode('; ', $n['strengths']), implode('; ', $n['concerns']), implode('; ', $n['flags'])]);
    }
    exit;
}

/* ---------- Bulk status / settings ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (str_starts_with($action, 'ai_') && !can('users')) {
        $action === 'ai_score' || $action === 'ai_queue' ? json_response(['ok' => false, 'error' => 'Only admins can run the AI review.', 'fatal' => true], 403) : flash('Only admins can run the AI review.', 'error');
        redirect('volunteers.php');
    }
    if ($action === 'ai_key') {
        $key = trim((string) ($_POST['anthropic_api_key'] ?? ''));
        if (env('ANTHROPIC_API_KEY') !== '') {
            flash('The key comes from ANTHROPIC_API_KEY in the .env file — change or remove it there.', 'error');
        } elseif (!empty($_POST['remove'])) {
            setting_set('anthropic_api_key', '');
            flash('The Anthropic API key was removed.');
        } elseif (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $key)) {
            flash('That does not look like an Anthropic API key (it starts with sk-ant-).', 'error');
        } else {
            setting_set('anthropic_api_key', encrypt_secret($key));
            flash('Anthropic API key saved (stored encrypted).');
        }
        redirect('volunteers.php#ai');
    }
    // The applications still to score (or all of them again), for the page to send one by one
    if ($action === 'ai_queue') {
        session_write_close();
        $ids = q("SELECT id FROM volunteers WHERE status <> 'declined'" . (empty($_POST['rescore']) ? ' AND ai_scored_at IS NULL' : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        json_response(['ok' => vol_ai_ready(), 'ids' => array_map('intval', $ids), 'error' => vol_ai_ready() ? null : 'Add the Anthropic API key first.']);
    }
    if ($action === 'ai_score') {
        session_write_close();   // the Claude call can take a minute; don't hold the session (and the admin's other pages) meanwhile
        @set_time_limit(400);
        $v = find_volunteer((int) ($_POST['id'] ?? 0));
        if (!$v || !vol_ai_ready()) {
            json_response(['ok' => false, 'error' => $v ? 'Add the Anthropic API key first.' : 'Application not found.', 'fatal' => (bool) $v]);
        }
        $r = vol_ai_score($v);
        $done = q('SELECT COUNT(*) scored, COALESCE(SUM(ai_cost), 0) cost FROM volunteers WHERE ai_scored_at IS NOT NULL')->fetch();
        json_response($r + ['name' => $v['full_name'], 'scored' => (int) $done['scored'], 'cost' => '$' . number_format((float) $done['cost'], 2)]);
    }
    if ($action === 'ai_shortlist') {
        $size = max(1, min(300, (int) ($_POST['size'] ?? 50)));
        $per = max(0, min(20, (int) ($_POST['per_field'] ?? 3)));
        setting_set('vol_ai_size', (string) $size);
        setting_set('vol_ai_per_field', (string) $per);
        [$picked, $newly, $dropped] = vol_ai_shortlist($size, $per);
        flash($picked ? "The AI shortlist has $picked applicants" . ($newly ? " ($newly moved to Shortlisted)" : '') . ($dropped ? ", $dropped earlier pick" . ($dropped === 1 ? '' : 's') . ' moved back to New' : '') . '. Review them below — no emails were sent.' : 'No scored applications to shortlist yet — score them with AI first.', $picked ? 'success' : 'error');
        redirect($picked ? 'volunteers.php?ai=picks' : 'volunteers.php#ai');
    }
    // Thank one applicant (the row / page button): emails them; the browser opens WhatsApp
    if (($_POST['action'] ?? '') === 'thank_one') {
        $v = find_volunteer((int) ($_POST['id'] ?? 0));
        if (!$v) {
            json_response(['ok' => false, 'message' => 'Volunteer not found.'], 404);
        }
        $ok = send_volunteer_thanks($v, $err);
        json_response(['ok' => true, 'emailed' => $ok, 'message' => $ok ? 'Thank-you emailed to ' . $v['email'] . '.' . mail_note() : 'The thank-you email was not sent: ' . $err]);
    }
    if (($_POST['action'] ?? '') === 'thanks_message') {
        $msg = trim((string) ($_POST['volunteer_thanks_message'] ?? ''));
        setting_set('volunteer_thanks_message', $msg === trim(volunteer_thanks_default()) ? '' : mb_substr($msg, 0, 2000));
        flash('Thank-you message saved.');
        redirect('volunteers.php#thanks');
    }
    $back = 'volunteers.php' . (!empty($_POST['return']) && str_starts_with((string) $_POST['return'], '?') ? $_POST['return'] : '');
    if (($_POST['action'] ?? '') === 'open') {
        setting_set('volunteers_open', !empty($_POST['volunteers_open']) ? '1' : '0');
        flash('Volunteer applications are now ' . (!empty($_POST['volunteers_open']) ? 'open.' : 'closed.'));
        redirect('volunteers.php');
    }
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $to = (string) ($_POST['bulk_action'] ?? '');
    if ($ids && $to === 'thank') {
        $sent = 0;
        foreach ($ids as $id) {
            if ($v = find_volunteer($id)) {
                $sent += send_volunteer_thanks($v) ? 1 : 0;
            }
        }
        flash('Thank-you emailed to ' . $sent . ' volunteer' . ($sent === 1 ? '' : 's') . '.' . mail_note());
        redirect($back);
    }
    if (!$ids || !isset($statuses[$to])) {
        flash('Select at least one volunteer and a status.', 'error');
        redirect($back);
    }
    $in = implode(',', $ids);
    $n = q("UPDATE volunteers SET status = ?, ai_pick = 2, updated_at = ? WHERE id IN ($in)", [$to, now()])->rowCount();   // ai_pick 2: the team decided, the AI shortlist leaves them alone
    flash($n . ' volunteer' . ($n === 1 ? '' : 's') . ' marked "' . $statuses[$to] . '".');
    redirect($back);
}

[$where, $params, $f] = $filters($_GET);
$perPage = 40;
$total = (int) q("SELECT COUNT(*) FROM volunteers$where", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$order = $f['ai'] === 'picks' ? 'ai_rank ASC' : ($f['sort'] === 'score' ? 'ai_score IS NULL, ai_score DESC, id DESC' : 'id DESC');
$rows = q("SELECT * FROM volunteers$where ORDER BY $order LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
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
// AI review
$aiAdmin = can('users');
$aiReady = vol_ai_ready();
$ai = q("SELECT SUM(ai_scored_at IS NOT NULL) scored, SUM(status <> 'declined' AND ai_scored_at IS NULL) todo, SUM(ai_rank IS NOT NULL) picks,
                COALESCE(SUM(ai_cost), 0) cost, SUM(ai_scored_at IS NOT NULL AND ai_meets = 0) unmet, SUM(ai_scored_at IS NULL AND ai_error IS NOT NULL) errors,
                SUM(ai_scored_at IS NOT NULL AND ai_notes LIKE '%CV could not be read%') nocv FROM volunteers")->fetch();
$aiPct = $all ? (int) floor((int) $ai['scored'] / $all * 100) : 0;
$pickFields = q('SELECT ai_field label, COUNT(*) c FROM volunteers WHERE ai_rank IS NOT NULL GROUP BY ai_field ORDER BY c DESC')->fetchAll();
$pickGender = q("SELECT COALESCE(NULLIF(gender, ''), 'Not given') g, COUNT(*) c FROM volunteers WHERE ai_rank IS NOT NULL GROUP BY g ORDER BY c DESC")->fetchAll();
$aiSize = (int) setting('vol_ai_size', '50') ?: 50;
$aiPer = (int) setting('vol_ai_per_field', '3');

admin_header('Volunteer trainers', 'volunteers', 'People offering their expertise as trainers during Kakebe Tech Camp 2026');
?>
<section class="card att-switch <?= $open ? 'on' : 'off' ?>">
  <div>
    <h3><i class="fa-solid <?= $open ? 'fa-lock-open' : 'fa-lock' ?>"></i> Volunteer applications are <?= $open ? 'OPEN' : 'CLOSED' ?></h3>
    <p class="small muted"><?= $open ? 'People can apply at <a href="' . e(base_url('volunteers')) . '" target="_blank">/volunteers</a>.' : 'The page apologises (high numbers) and invites people to register for the camp instead.' ?></p>
  </div>
  <form method="post" class="inline"<?= $open ? ' data-confirm="Close volunteer applications? The page will show the apology and point people to camp registration."' : ' data-confirm="Open volunteer applications again?"' ?>><?= csrf_field() ?><input type="hidden" name="action" value="open"><?php if (!$open): ?><input type="hidden" name="volunteers_open" value="1"><?php endif; ?>
    <button class="btn <?= $open ? 'btn-danger' : 'btn-primary' ?>" type="submit"><i class="fa-solid <?= $open ? 'fa-lock' : 'fa-lock-open' ?>"></i> <?= $open ? 'Close applications' : 'Open applications' ?></button>
  </form>
</section>

<div class="kpis">
  <a class="kpi" href="volunteers.php"><span class="kpi-icon navy"><i class="fa-solid fa-person-chalkboard"></i></span><div><small>Applications</small><b><?= number_format($all) ?></b><em><?= number_format((int) ($counts['new'] ?? 0)) ?> new to review</em></div></a>
  <a class="kpi" href="volunteers.php?status=shortlisted"><span class="kpi-icon amber"><i class="fa-solid fa-list-check"></i></span><div><small>Shortlisted</small><b><?= number_format((int) ($counts['shortlisted'] ?? 0)) ?></b><em>to interview or confirm</em></div></a>
  <a class="kpi" href="volunteers.php?status=accepted"><span class="kpi-icon green"><i class="fa-solid fa-user-check"></i></span><div><small>Accepted</small><b><?= number_format((int) ($counts['accepted'] ?? 0)) ?></b><em>trainers for camp</em></div></a>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-link"></i></span><div><small>Application page</small><b class="small-b"><a href="<?= e(base_url('volunteers')) ?>" target="_blank">/volunteers <i class="fa-solid fa-arrow-up-right-from-square"></i></a></b><em><?= $open ? 'Open for applications' : 'Closed' ?></em></div></div>
</div>

<section class="card ai-card" id="ai" data-csrf="<?= e(csrf_token()) ?>">
  <div class="card-head"><h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI shortlist</h3><span class="muted small">Claude reads every application and CV and scores how well each person fits the camp</span></div>
  <div class="ai-progress">
    <div><b class="js-ai-scored"><?= number_format((int) $ai['scored']) ?></b> of <b><?= number_format($all) ?></b> applications scored<?php if ((float) $ai['cost'] > 0): ?> · cost so far <b class="js-ai-cost">$<?= number_format((float) $ai['cost'], 2) ?></b><?php endif; ?><?php if ((int) $ai['picks']): ?> · <a href="volunteers.php?ai=picks"><b><?= (int) $ai['picks'] ?></b> on the AI shortlist</a><?php endif; ?></div>
    <div class="bar"><i class="js-ai-bar" style="width: <?= $aiPct ?>%"></i></div>
    <?php if ((int) $ai['unmet']): ?><small class="muted"><?= (int) $ai['unmet'] ?> scored applicant<?= (int) $ai['unmet'] === 1 ? '' : 's' ?> may not meet the requirements (age 22 – 40, at least a Diploma, a CV that is really theirs), so the AI shortlist leaves them out — <a href="volunteers.php?ai=unmet">check them</a>.</small><?php endif; ?>
    <?php if ((int) $ai['nocv']): ?><small class="muted"><?= (int) $ai['nocv'] ?> applicant<?= (int) $ai['nocv'] === 1 ? ' was' : 's were' ?> scored without reading the CV (old Word files or damaged PDFs) — <a href="volunteers.php?ai=nocv">review them yourself</a>.</small><?php endif; ?>
  </div>
  <?php if (!$aiAdmin): ?>
    <p class="small muted">Only admins can run the AI review. Open an application to see its AI assessment.</p>
  <?php elseif (!$aiReady): ?>
    <form method="post" class="ai-key"><?= csrf_field() ?><input type="hidden" name="action" value="ai_key">
      <label>Anthropic API key <small class="muted">needed once — from console.anthropic.com → API keys. Saved encrypted; ANTHROPIC_API_KEY in the .env file works too.</small>
        <span class="ai-key-row"><input type="password" name="anthropic_api_key" autocomplete="off" placeholder="sk-ant-…" required><button class="btn btn-primary" type="submit"><i class="fa-solid fa-key"></i> Save key</button></span></label>
    </form>
  <?php else: ?>
    <div class="ai-steps">
      <div class="ai-step">
        <span>1</span>
        <div>
          <b>Score the applications</b>
          <p class="small muted">Each application and CV is read by Claude Opus 5.5 (Anthropic) against the camp's needs: expertise in the field, experience teaching young people, fit with a residential camp in Kitgum, commitment and the quality of the application. About 3 at a time; you can stop and continue later.</p>
          <div class="ai-run">
            <button type="button" class="btn btn-primary js-ai-run"<?= (int) $ai['todo'] || (int) $ai['scored'] ? '' : ' disabled' ?>><i class="fa-solid fa-wand-magic-sparkles"></i> <?= (int) $ai['todo'] ? 'Score ' . number_format((int) $ai['todo']) . ' application' . ((int) $ai['todo'] === 1 ? '' : 's') : 'All scored' ?></button>
            <button type="button" class="btn btn-light js-ai-stop" hidden><i class="fa-solid fa-stop"></i> Stop</button>
            <label class="check-inline small"><input type="checkbox" class="js-ai-rescore"> Score everyone again</label>
          </div>
          <p class="small js-ai-status" aria-live="polite"><?= (int) $ai['errors'] ? (int) $ai['errors'] . ' application' . ((int) $ai['errors'] === 1 ? '' : 's') . ' could not be scored last time — press Score to try again.' : '' ?></p>
          <ul class="ai-log js-ai-log" hidden></ul>
        </div>
      </div>
      <div class="ai-step">
        <span>2</span>
        <div>
          <b>Shortlist the best for your review</b>
          <form method="post" class="ai-short" data-confirm="Shortlist the best applicants? Those still New move to Shortlisted. No emails are sent."><?= csrf_field() ?><input type="hidden" name="action" value="ai_shortlist">
            Shortlist the best <input type="number" name="size" value="<?= $aiSize ?>" min="1" max="300"> · at least <input type="number" name="per_field" value="<?= $aiPer ?>" min="0" max="20"> per training field
            <button class="btn btn-navy btn-sm" type="submit"<?= (int) $ai['scored'] ? '' : ' disabled' ?>><i class="fa-solid fa-list-check"></i> Shortlist</button>
          </form>
          <p class="small muted">Applicants still "New" move to <b>Shortlisted</b>, ranked by score. Anyone whose status you set yourself is never changed. Run it again after more scoring to refresh the list — earlier AI picks that are no longer in the top move back to New. You make the final decisions.</p>
          <?php if ($pickFields): ?>
            <div class="ai-mix small"><span class="muted">Shortlist by field:</span> <?php foreach ($pickFields as $pf): ?><span class="tag"><?= e($pf['label']) ?> <b><?= (int) $pf['c'] ?></b></span><?php endforeach; ?></div>
            <div class="ai-mix small"><span class="muted">By gender:</span> <?php foreach ($pickGender as $pg): ?><span class="tag"><?= e($pg['g']) ?> <b><?= (int) $pg['c'] ?></b></span><?php endforeach; ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php if (env('ANTHROPIC_API_KEY') !== ''): ?>
    <p class="ai-key-change small">The Anthropic API key comes from ANTHROPIC_API_KEY in the .env file — change or remove it there.</p>
    <?php else: ?>
    <details class="ai-key-change small"><summary>Anthropic API key saved · change</summary>
      <form method="post" class="ai-key"><?= csrf_field() ?><input type="hidden" name="action" value="ai_key">
        <span class="ai-key-row"><input type="password" name="anthropic_api_key" autocomplete="off" placeholder="New key: sk-ant-…"><button class="btn btn-light btn-sm" type="submit">Save new key</button><button class="btn btn-light btn-sm" type="submit" name="remove" value="1" formnovalidate>Remove saved key</button></span>
      </form>
    </details>
    <?php endif; ?>
  <?php endif; ?>
</section>

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

<section class="card" id="thanks">
  <div class="card-head"><h3><i class="fa-solid fa-hands-praying"></i> Thank-you message</h3><span class="muted small">sent by email and WhatsApp with the Thank button</span></div>
  <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="thanks_message">
    <label>Message from Moses Komakech, Head of Comms <small class="muted">use {full_name}, {first_name}, {reference}, {fields}</small><textarea name="volunteer_thanks_message" rows="9"><?= e(trim((string) setting('volunteer_thanks_message')) ?: volunteer_thanks_default()) ?></textarea></label>
    <div><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save message</button></div>
  </form>
</section>

<div class="tabs big">
  <a href="<?= e($url(['status' => null, 'page' => null, 'ai' => null])) ?>" class="<?= $f['status'] === '' && $f['ai'] === '' ? 'active' : '' ?>">All <em><?= number_format($all) ?></em></a>
  <?php foreach ($statuses as $k => $label): ?><a href="<?= e($url(['status' => $k, 'page' => null, 'ai' => null])) ?>" class="<?= $f['status'] === $k && $f['ai'] === '' ? 'active' : '' ?>"><?= e($label) ?> <em><?= number_format((int) ($counts[$k] ?? 0)) ?></em></a><?php endforeach; ?>
  <?php if ((int) $ai['picks']): ?><a href="volunteers.php?ai=picks" class="<?= $f['ai'] === 'picks' ? 'active' : '' ?>"><i class="fa-solid fa-wand-magic-sparkles"></i> AI shortlist <em><?= (int) $ai['picks'] ?></em></a><?php endif; ?>
</div>

<form class="card filters" method="get">
  <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
  <?php if ($f['ai']): ?><input type="hidden" name="ai" value="<?= e($f['ai']) ?>"><?php endif; ?>
  <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, email, phone, course, university, place…"></div>
  <?php if ($f['ai'] !== 'picks'): ?><select name="sort"><option value="">Newest first</option><option value="score" <?= $f['sort'] === 'score' ? 'selected' : '' ?>>Highest AI score first</option></select><?php endif; ?>
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
        <option value="">Choose an action…</option>
        <option value="thank">Send the thank-you email</option>
        <?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>">Mark as: <?= e($label) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit" id="bulkApply" disabled>Apply</button>
      <a href="<?= e($url(['export' => 1, 'page' => null])) ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <?php if ($rows): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th class="w-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th><?php if ($f['ai'] === 'picks'): ?><th>Rank</th><?php endif; ?><th>Volunteer</th><th>AI score</th><th>Fields</th><th>Qualification</th><th>Experience</th><th>Age</th><th>Based in</th><th>Status</th><th>Applied</th><th class="row-actions"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $v): ?>
        <tr class="row-link" data-href="volunteer.php?id=<?= (int) $v['id'] ?>">
          <td class="w-check"><input type="checkbox" name="ids[]" value="<?= (int) $v['id'] ?>" class="row-check" aria-label="Select <?= e($v['full_name']) ?>"></td>
          <?php if ($f['ai'] === 'picks'): ?><td class="ai-rank">#<?= (int) $v['ai_rank'] ?></td><?php endif; ?>
          <td><div class="person"><span class="av ini"><?= e(initials($v['full_name'])) ?></span><div><b><?= e($v['full_name']) ?></b><small><?= e($v['email']) ?> · <?= e($v['reference']) ?></small></div></div></td>
          <td class="ai-cell"><?php if ($v['ai_scored_at']): $flags = vol_ai_notes($v)['flags']; ?><span class="ai-score ai-<?= vol_ai_band((int) $v['ai_score']) ?>" title="<?= e((string) $v['ai_summary']) ?>"><?= (int) $v['ai_score'] ?></span><?= $v['ai_meets'] ? '' : ' <span class="badge st-cancelled" title="May not meet the requirements — left out of the AI shortlist">requirements?</span>' ?><small class="block muted"><?= e((string) $v['ai_field']) ?><?= $flags ? ' · <i class="fa-solid fa-flag" title="' . e(implode(' · ', $flags)) . '"></i>' : '' ?></small><?php elseif ($v['ai_error']): ?><span class="muted small" title="<?= e($v['ai_error']) ?>"><i class="fa-solid fa-triangle-exclamation"></i> not scored</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td class="tracks-cell"><?php foreach (array_filter(explode(',', (string) $v['fields'])) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></td>
          <td><b><?= e($v['qualification']) ?></b><small class="block muted"><?= e($v['course']) ?> · <?= e($v['institution']) ?></small></td>
          <td class="nowrap"><?= e($v['experience']) ?></td>
          <td><?= volunteer_age($v['dob']) ?></td>
          <td><?= e($v['location']) ?></td>
          <td><span class="badge <?= $badge[$v['status']] ?? '' ?>"><?= e($statuses[$v['status']] ?? $v['status']) ?></span></td>
          <td class="nowrap muted"><?= e(date('j M, g:i a', strtotime($v['created_at']))) ?></td>
          <td class="nowrap row-actions">
            <button type="button" class="btn btn-sm btn-ticket js-vol-thank<?= $v['thanked_at'] ? ' done' : '' ?>" data-id="<?= (int) $v['id'] ?>" data-wa="<?= e(volunteer_whatsapp_thanks_link($v)) ?>" title="Emails the thank-you from Moses Komakech and opens WhatsApp with it typed"><i class="fa-solid fa-hands-praying"></i> <?= $v['thanked_at'] ? 'Thanked' : 'Thank' ?></button>
            <?php if (volunteer_cv_path($v)): ?><a class="btn btn-light btn-sm" href="volunteers.php?cv=<?= (int) $v['id'] ?>"><i class="fa-solid fa-file-arrow-down"></i> CV</a><?php endif; ?>
            <a class="btn btn-light btn-sm" href="volunteer.php?id=<?= (int) $v['id'] ?>"><i class="fa-regular fa-eye"></i> View</a>
            <?php if ($v['thanked_at']): ?><small class="block muted reminded-note">Thanked <?= e(time_ago($v['thanked_at'])) ?></small><?php endif; ?>
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
<?php admin_footer(['volunteers-ai.js']);
