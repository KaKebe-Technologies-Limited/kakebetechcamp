<?php
/**
 * admin/pick.php — Volunteer Scoring Dashboard.
 * One-click AI scoring · top-30 leaderboard · focus-area breakdown · manual selection.
 */
require __DIR__ . '/_init.php';
$admin = require_admin();

$KEY_FIELDS = ['AI Skills', 'Entrepreneurship', 'Robotics', 'Personal Branding', 'Digital Content Marketing'];
$PER_FIELD  = 6;
$TOP_N      = 30;
$AREA_COLOR = [
    'AI Skills'                 => '#2563eb',
    'Entrepreneurship'          => '#16a34a',
    'Robotics'                  => '#7c3aed',
    'Personal Branding'         => '#ea580c',
    'Digital Content Marketing' => '#0891b2',
];

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'accept') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $in = implode(',', $ids);
            q("UPDATE volunteers SET status = 'shortlisted', ai_pick = 2, updated_at = ? WHERE id IN ($in) AND status NOT IN ('accepted','declined')", [now()]);
            q("UPDATE volunteers SET status = 'new', ai_pick = 0, updated_at = ? WHERE ai_pick = 2 AND status = 'shortlisted' AND id NOT IN ($in)", [now()]);
            flash(count($ids) . ' volunteer' . (count($ids) === 1 ? '' : 's') . ' shortlisted successfully.');
        } else {
            flash('No one was selected — nobody was changed.', 'info');
        }
        $qs = http_build_query(array_intersect_key($_POST, array_flip(['field','exp','qual','rec','q','tab'])));
        redirect('pick.php' . ($qs ? '?' . $qs : ''));
    }
    json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}

/* ---------- Filters ---------- */
$fField  = trim($_GET['field'] ?? '');
$fExp    = trim($_GET['exp'] ?? '');
$fQual   = trim($_GET['qual'] ?? '');
$fRec    = trim($_GET['rec'] ?? '');
$fSearch = trim($_GET['q'] ?? '');
$tab     = in_array($_GET['tab'] ?? '', ['area'], true) ? 'area' : 'all';
$hasFilter = $fField || $fExp || $fQual || $fRec || $fSearch;

$baseWhere = "ai_scored_at IS NOT NULL AND ai_meets = 1 AND COALESCE(grad_year,9999) <= 2024 AND status <> 'declined'";
$fWhere    = $baseWhere;
$fParams   = [];
if ($fField)  { $fWhere .= " AND ai_field = ?";         $fParams[] = $fField; }
if ($fExp)    { $fWhere .= " AND experience = ?";        $fParams[] = $fExp; }
if ($fQual)   { $fWhere .= " AND qualification = ?";     $fParams[] = $fQual; }
if ($fRec)    { $fWhere .= " AND ai_recommendation = ?"; $fParams[] = $fRec; }
if ($fSearch) { $fWhere .= " AND full_name LIKE ?";      $fParams[] = '%' . $fSearch . '%'; }

/* ---------- Data ---------- */
$rows = q("SELECT id, full_name, grad_year, experience, qualification, ai_field,
                  ai_score, ai_recommendation, ai_summary, status, ai_pick, ai_rank
           FROM volunteers WHERE $fWhere ORDER BY ai_score DESC, id ASC", $fParams)->fetchAll();

$allElig = q("SELECT id, full_name, grad_year, experience, qualification,
                     ai_field, ai_score, ai_recommendation, status, ai_pick
              FROM volunteers WHERE $baseWhere ORDER BY ai_score DESC")->fetchAll();

$byArea = [];
foreach ($KEY_FIELDS as $f) {
    $aRows = [];
    foreach ($allElig as $v) {
        if ($v['ai_field'] === $f) { $aRows[] = $v; if (count($aRows) >= $PER_FIELD) break; }
    }
    $byArea[$f] = $aRows;
}

$stats = q("SELECT
    COUNT(*) total,
    SUM(ai_scored_at IS NOT NULL) scored,
    SUM(ai_meets = 1 AND COALESCE(grad_year,9999) <= 2024) eligible,
    SUM(ai_scored_at IS NULL) pending,
    SUM(status IN ('shortlisted','accepted') AND ai_scored_at IS NOT NULL) picked
FROM volunteers WHERE status <> 'declined'")->fetch();

$aiReady = can('users') && vol_ai_ready();
$recBadge = fn(string $r) => match ($r) {
    'Highly Recommended'      => 'st-confirmed',
    'Recommended'             => 'st-booked',
    'Further Review Required' => 'st-pending',
    default                   => 'st-cancelled',
};

admin_header('Volunteer Scorer', 'volunteers', 'AI scoring · top-' . $TOP_N . ' leaderboard · focus area breakdown');
?>
<a href="volunteers.php" class="back"><i class="fa-solid fa-arrow-left"></i> All volunteer trainers</a>

<!-- Stats bar -->
<div class="sc-stats">
  <div class="sc-stat"><b><?= (int) $stats['total'] ?></b><small>Applications</small></div>
  <div class="sc-stat sc-stat-blue"><b><?= (int) $stats['scored'] ?></b><small>Scored</small></div>
  <div class="sc-stat sc-stat-green"><b><?= count($allElig) ?></b><small>Eligible</small></div>
  <div class="sc-stat <?= (int) $stats['pending'] > 0 ? 'sc-stat-warn' : '' ?>"><b><?= (int) $stats['pending'] ?></b><small>Not scored yet</small></div>
  <div class="sc-stat sc-stat-teal"><b><?= (int) $stats['picked'] ?></b><small>Shortlisted</small></div>
</div>

<!-- Score All section -->
<section class="card sc-score-card" id="scoreCard" data-csrf="<?= e(csrf_token()) ?>">
  <div class="sc-score-head">
    <div>
      <h3><i class="fa-solid fa-wand-magic-sparkles"></i> Score All Applications</h3>
      <p class="small muted" style="margin:4px 0 0">AI reads every CV and application, scores out of 100 across 7 criteria, then ranks and shortlists the best candidates.</p>
    </div>
    <div class="sc-score-btns">
      <?php if ($aiReady): ?>
        <label class="check-inline small"><input type="checkbox" id="scoreRescore"> Re-score everyone</label>
        <button class="btn btn-navy" id="scoreAllBtn">
          <i class="fa-solid fa-wand-magic-sparkles"></i>
          <?= (int) $stats['pending'] > 0 ? 'Score ' . (int) $stats['pending'] . ' unscored' : 'Re-score all' ?>
        </button>
      <?php elseif (!can('users')): ?>
        <span class="small muted">Only admins can run scoring.</span>
      <?php else: ?>
        <a href="volunteers.php#ai" class="btn btn-light"><i class="fa-solid fa-key"></i> Add API key first</a>
      <?php endif; ?>
    </div>
  </div>
  <div id="scoreProgress" hidden style="margin-top:14px">
    <div class="sc-prog-wrap">
      <div class="sc-prog-bar"><i id="scoreBar" style="width:0%"></i></div>
      <span class="small muted" id="scorePct">0%</span>
      <span class="small muted" id="scoreCounts"></span>
    </div>
    <p class="small" id="scoreStatus" style="margin:6px 0 8px;min-height:18px;color:var(--muted)">Starting…</p>
    <button class="btn btn-light btn-sm" id="scoreStopBtn"><i class="fa-solid fa-stop"></i> Stop</button>
  </div>
</section>

<!-- Filters -->
<form method="get" class="sc-filter-bar" id="filterForm">
  <div class="sc-filter-row">
    <div class="sc-search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" name="q" placeholder="Search by name…" value="<?= e($fSearch) ?>" class="form-control-sm sc-search-input">
    </div>
    <select name="field" class="form-control-sm">
      <option value="">All fields</option>
      <?php foreach (array_keys(volunteer_fields()) as $f): ?>
      <option value="<?= e($f) ?>"<?= $fField === $f ? ' selected' : '' ?>><?= e($f) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="exp" class="form-control-sm">
      <option value="">All experience</option>
      <?php foreach (volunteer_experience_options() as $eo): ?>
      <option value="<?= e($eo) ?>"<?= $fExp === $eo ? ' selected' : '' ?>><?= e($eo) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="qual" class="form-control-sm">
      <option value="">All qualifications</option>
      <?php foreach (volunteer_qualifications() as $qo): ?>
      <option value="<?= e($qo) ?>"<?= $fQual === $qo ? ' selected' : '' ?>><?= e($qo) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="rec" class="form-control-sm">
      <option value="">All recommendations</option>
      <?php foreach (['Highly Recommended', 'Recommended', 'Further Review Required', 'Not Recommended'] as $ro): ?>
      <option value="<?= e($ro) ?>"<?= $fRec === $ro ? ' selected' : '' ?>><?= e($ro) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    <?php if ($hasFilter): ?>
    <a href="pick.php?tab=<?= e($tab) ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
    <?php endif; ?>
  </div>
</form>

<!-- Tabs -->
<div class="sc-tabs">
  <a href="pick.php?<?= e(http_build_query(array_merge(array_filter(['field'=>$fField,'exp'=>$fExp,'qual'=>$fQual,'rec'=>$fRec,'q'=>$fSearch]), ['tab'=>'all']))) ?>"
     class="sc-tab <?= $tab === 'all' ? 'active' : '' ?>">
    <i class="fa-solid fa-trophy"></i> Top <?= $TOP_N ?> Leaderboard
    <span class="sc-tab-badge"><?= min(count($rows), $TOP_N) ?></span>
  </a>
  <a href="pick.php?<?= e(http_build_query(array_merge(array_filter(['field'=>$fField,'exp'=>$fExp,'qual'=>$fQual,'rec'=>$fRec,'q'=>$fSearch]), ['tab'=>'area']))) ?>"
     class="sc-tab <?= $tab === 'area' ? 'active' : '' ?>">
    <i class="fa-solid fa-layer-group"></i> By Focus Area
    <span class="sc-tab-badge"><?= count($KEY_FIELDS) ?> areas · <?= $PER_FIELD ?> each</span>
  </a>
</div>

<?php if ($tab === 'all'): /* ===== LEADERBOARD TAB ===== */ ?>

<?php if (!$rows): ?>
<div class="empty-state">
  <i class="fa-solid fa-wand-magic-sparkles"></i>
  <p><?= $hasFilter ? 'No applicants match those filters.' : 'No scored applicants yet — run scoring above first.' ?></p>
</div>
<?php else: ?>

<form method="post" id="pickForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="accept">
  <?php foreach (['field'=>$fField,'exp'=>$fExp,'qual'=>$fQual,'rec'=>$fRec,'q'=>$fSearch,'tab'=>$tab] as $k => $v2): ?>
  <?php if ($v2 !== ''): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v2) ?>"><?php endif; ?>
  <?php endforeach; ?>

  <div class="sc-table-toolbar">
    <span class="small muted">
      <?= count($rows) ?> eligible applicant<?= count($rows) === 1 ? '' : 's' ?>
      <?= $hasFilter ? '(filtered)' : '' ?>
      <?php if (count($rows) > $TOP_N): ?>&nbsp;· showing top <?= $TOP_N ?><?php endif; ?>
    </span>
    <div class="sc-toolbar-right">
      <label class="check-inline small"><input type="checkbox" id="pickSelectAll"> Select all</label>
      <button type="submit" class="btn btn-primary js-pick-submit" disabled>
        <i class="fa-solid fa-check"></i> Shortlist <span class="js-pick-count">0</span> selected
      </button>
      <a href="shortlist.php" class="btn btn-light btn-sm"><i class="fa-solid fa-list-check"></i> View shortlist</a>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table sc-table">
      <thead>
        <tr>
          <th class="sc-col-cb"></th>
          <th class="sc-col-rank">#</th>
          <th>Applicant</th>
          <th>Field</th>
          <th>Experience</th>
          <th>Qualification</th>
          <th>Grad</th>
          <th>Score</th>
          <th>Recommendation</th>
          <th class="sc-col-sum">AI Summary</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_slice($rows, 0, $TOP_N) as $i => $v):
          $precheck = in_array($v['ai_recommendation'], ['Highly Recommended', 'Recommended'], true)
                      || $v['status'] === 'shortlisted';
          $isAccepted    = $v['status'] === 'accepted';
          $isShortlisted = $v['status'] === 'shortlisted';
          $isTop15       = $i < 15;
      ?>
        <tr class="sc-row<?= $isAccepted ? ' sc-row-accepted' : '' ?><?= $isTop15 ? ' sc-row-top' : '' ?>">
          <td class="sc-col-cb">
            <?php if ($isAccepted): ?>
              <i class="fa-solid fa-circle-check" style="color:#16a34a" title="Already accepted"></i>
            <?php else: ?>
              <input type="checkbox" name="ids[]" value="<?= (int) $v['id'] ?>" class="pick-cb"<?= $precheck ? ' checked' : '' ?>>
            <?php endif; ?>
          </td>
          <td class="sc-col-rank">
            <?php if ($i < 3): ?>
              <span class="sc-medal sc-medal-<?= $i + 1 ?>"><?= $i + 1 ?></span>
            <?php else: ?>
              <span class="muted small"><?= $i + 1 ?></span>
            <?php endif; ?>
          </td>
          <td>
            <a href="volunteer.php?id=<?= (int) $v['id'] ?>" target="_blank" class="sc-name"><?= e($v['full_name']) ?></a>
            <div style="display:flex;gap:4px;margin-top:3px;flex-wrap:wrap">
              <?php if ($isShortlisted): ?><span class="badge st-pending" style="font-size:.68rem">Shortlisted</span><?php endif; ?>
              <?php if ($isAccepted): ?><span class="badge st-confirmed" style="font-size:.68rem">Accepted</span><?php endif; ?>
            </div>
          </td>
          <td class="small">
            <?php if ($v['ai_field']): ?>
              <span class="sc-field-pill">
                <i class="fa-solid <?= e(volunteer_fields()[$v['ai_field']] ?? 'fa-star') ?>"></i>
                <?= e($v['ai_field']) ?>
              </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="small muted nowrap"><?= e($v['experience'] ?? '—') ?></td>
          <td class="small muted nowrap"><?= e($v['qualification'] ?? '—') ?></td>
          <td class="small nowrap <?= (int) $v['grad_year'] < 2021 ? 'sc-green' : 'muted' ?>"><?= (int) $v['grad_year'] ?: '—' ?></td>
          <td class="nowrap">
            <div class="sc-score-cell">
              <b class="ai-score-<?= vol_ai_band((int) $v['ai_score']) ?>"><?= (int) $v['ai_score'] ?></b>
              <div class="sc-score-mini-bar"><i style="width:<?= (int) $v['ai_score'] ?>%"></i></div>
            </div>
          </td>
          <td class="small nowrap">
            <span class="badge <?= $recBadge((string) $v['ai_recommendation']) ?>">
              <?= e($v['ai_recommendation'] ?? '—') ?>
            </span>
          </td>
          <td class="small muted sc-col-sum"><?= e(mb_substr((string) ($v['ai_summary'] ?? ''), 0, 120)) ?><?= mb_strlen((string) ($v['ai_summary'] ?? '')) > 120 ? '…' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (count($rows) > $TOP_N): ?>
  <p class="small muted" style="padding:6px 0">
    Showing top <?= $TOP_N ?> of <?= count($rows) ?> eligible. Use the filters above to narrow the list.
  </p>
  <?php endif; ?>

  <div class="sc-table-foot">
    <button type="submit" class="btn btn-primary js-pick-submit" disabled>
      <i class="fa-solid fa-check"></i> Shortlist <span class="js-pick-count">0</span> selected
    </button>
  </div>
</form>
<?php endif; ?>

<?php else: /* ===== FOCUS AREA TAB ===== */ ?>

<?php if (!$allElig): ?>
<div class="empty-state">
  <i class="fa-solid fa-wand-magic-sparkles"></i>
  <p>No scored applicants yet — run scoring above first.</p>
</div>
<?php else: ?>

<p class="small muted" style="margin-bottom:16px">
  Top <?= $PER_FIELD ?> applicants in each of the <?= count($KEY_FIELDS) ?> priority focus areas, ranked by AI score.
  Total from all areas: up to <?= count($KEY_FIELDS) * $PER_FIELD ?>.
</p>

<div class="sc-area-grid">
  <?php foreach ($KEY_FIELDS as $field):
      $color = $AREA_COLOR[$field] ?? '#2563eb';
      $icon  = volunteer_fields()[$field] ?? 'fa-star';
      $aRows = $byArea[$field];
  ?>
  <div class="sc-area-card">
    <div class="sc-area-head" style="border-bottom-color:<?= $color ?>">
      <span class="sc-area-icon" style="background:<?= $color ?>18;color:<?= $color ?>">
        <i class="fa-solid <?= e($icon) ?>"></i>
      </span>
      <div style="flex:1;min-width:0">
        <h4 style="margin:0;font-size:.93rem"><?= e($field) ?></h4>
        <span class="small muted">Top <?= $PER_FIELD ?> applicants</span>
      </div>
      <span class="sc-area-count" style="background:<?= $color ?>18;color:<?= $color ?>">
        <?= count($aRows) ?>/<?= $PER_FIELD ?>
      </span>
    </div>
    <?php if (!$aRows): ?>
    <p class="small muted" style="padding:16px;margin:0">No scored applicants in this field yet.</p>
    <?php else: ?>
    <ol class="sc-area-list">
      <?php foreach ($aRows as $j => $v): ?>
      <li class="sc-area-row<?= $v['status'] === 'shortlisted' ? ' sc-area-shortlisted' : '' ?><?= $v['status'] === 'accepted' ? ' sc-area-accepted' : '' ?>">
        <span class="sc-area-rank" style="background:<?= $color ?>20;color:<?= $color ?>"><?= $j + 1 ?></span>
        <div class="sc-area-info">
          <a href="volunteer.php?id=<?= (int) $v['id'] ?>" target="_blank" class="sc-name"><?= e($v['full_name']) ?></a>
          <span class="small muted">
            <?= e($v['experience'] ?? '') ?>
            <?= $v['qualification'] ? ' · ' . e($v['qualification']) : '' ?>
            <?= $v['grad_year'] ? ' · ' . (int) $v['grad_year'] : '' ?>
          </span>
        </div>
        <div class="sc-area-score">
          <b class="ai-score-<?= vol_ai_band((int) $v['ai_score']) ?>"><?= (int) $v['ai_score'] ?></b>
          <div class="sc-area-score-bar"><i style="width:<?= (int) $v['ai_score'] ?>%;background:<?= $color ?>"></i></div>
          <?php if ($v['status'] === 'shortlisted'): ?><i class="fa-solid fa-bookmark" style="color:#f59e0b;font-size:.8em;margin-left:2px" title="Shortlisted"></i><?php endif; ?>
          <?php if ($v['status'] === 'accepted'): ?><i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:.8em;margin-left:2px" title="Accepted"></i><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <div class="sc-area-foot">
      <a href="pick.php?field=<?= urlencode($field) ?>&tab=all" class="small" style="color:var(--muted);text-decoration:none">
        View all in this field <i class="fa-solid fa-arrow-right" style="font-size:.7em"></i>
      </a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
/* Stats */
.sc-stats{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.sc-stat{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:12px 18px;text-align:center;flex:1;min-width:110px}
.sc-stat b{display:block;font-size:1.55em;font-weight:700;color:var(--text);line-height:1.1}
.sc-stat small{color:var(--muted);font-size:.75rem;margin-top:3px;display:block}
.sc-stat-blue b{color:#2563eb}
.sc-stat-green b{color:#16a34a}
.sc-stat-teal b{color:#0891b2}
.sc-stat-warn b{color:#d97706}

/* Score card */
.sc-score-card{margin-bottom:14px}
.sc-score-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.sc-score-btns{display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap}
.sc-prog-wrap{display:flex;align-items:center;gap:10px}
.sc-prog-bar{flex:1;height:7px;border-radius:4px;background:var(--border);overflow:hidden}
.sc-prog-bar i{display:block;height:100%;background:#2563eb;border-radius:4px;transition:width .25s}

/* Filters */
.sc-filter-bar{background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:11px 14px;margin-bottom:14px}
.sc-filter-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.sc-search-wrap{position:relative;flex:1;min-width:150px}
.sc-search-wrap i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.78rem;pointer-events:none}
.sc-search-input{padding-left:28px!important;width:100%}

/* Tabs */
.sc-tabs{display:flex;gap:2px;border-bottom:2px solid var(--border);margin-bottom:16px}
.sc-tab{padding:9px 18px;font-size:.87rem;font-weight:500;color:var(--muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:7px;transition:color .15s}
.sc-tab-badge{background:var(--border);color:var(--muted);font-size:.7rem;padding:2px 7px;border-radius:12px;font-weight:500}
.sc-tab.active{color:var(--text);border-bottom-color:#2563eb}
.sc-tab.active .sc-tab-badge{background:#2563eb20;color:#2563eb}
.sc-tab:hover:not(.active){color:var(--text)}

/* Table */
.sc-table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:4px 0 10px;flex-wrap:wrap;gap:8px}
.sc-toolbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.sc-col-cb{width:34px;text-align:center}
.sc-col-rank{width:40px;text-align:center}
.sc-col-sum{max-width:240px}
.sc-name{font-weight:600;color:var(--text);text-decoration:none}
.sc-name:hover{text-decoration:underline}
.sc-row-top td:first-child{border-left:3px solid #2563eb}
.sc-row-accepted{opacity:.5}
.sc-row:has(.pick-cb:checked){background:var(--highlight-bg,#f0f7ff)}
.sc-score-cell{display:flex;align-items:center;gap:7px}
.sc-score-mini-bar{width:38px;height:4px;background:var(--border);border-radius:2px;overflow:hidden}
.sc-score-mini-bar i{display:block;height:100%;background:#2563eb;border-radius:2px}
.sc-field-pill{display:inline-flex;align-items:center;gap:5px;font-size:.75rem;background:var(--border);padding:2px 8px;border-radius:10px;white-space:nowrap}
.sc-green{color:#16a34a!important}
.sc-medal{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;font-size:.75rem;font-weight:700}
.sc-medal-1{background:#fef08a;color:#a16207}
.sc-medal-2{background:#e5e7eb;color:#374151}
.sc-medal-3{background:#fed7aa;color:#9a3412}
.sc-table-foot{padding:12px 0 4px;border-top:1px solid var(--border)}

/* Focus area grid */
.sc-area-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px}
.sc-area-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
.sc-area-head{display:flex;align-items:center;gap:11px;padding:14px 16px;border-bottom:3px solid transparent}
.sc-area-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1em;flex-shrink:0}
.sc-area-count{padding:3px 10px;border-radius:12px;font-size:.78rem;font-weight:600;white-space:nowrap;flex-shrink:0}
.sc-area-list{list-style:none;margin:0;padding:0;flex:1}
.sc-area-row{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)}
.sc-area-row:last-child{border-bottom:none}
.sc-area-shortlisted{background:var(--highlight-bg,#fffbeb)}
.sc-area-accepted{opacity:.55}
.sc-area-rank{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.77rem;font-weight:700;flex-shrink:0}
.sc-area-info{flex:1;min-width:0}
.sc-area-info a{font-weight:600;color:var(--text);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.88rem}
.sc-area-info a:hover{text-decoration:underline}
.sc-area-info span{display:block;font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.sc-area-score{display:flex;align-items:center;gap:5px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
.sc-area-score-bar{width:36px;height:4px;background:var(--border);border-radius:2px;overflow:hidden}
.sc-area-score-bar i{display:block;height:100%;border-radius:2px}
.sc-area-foot{padding:10px 16px;border-top:1px solid var(--border)}
.sc-area-foot a:hover{color:var(--text)!important;text-decoration:underline!important}
@media(max-width:600px){.sc-stats .sc-stat{min-width:calc(50% - 5px)}.sc-area-grid{grid-template-columns:1fr}}
</style>

<script>
/* ---- Score All ---- */
(() => {
  const card    = document.getElementById('scoreCard');
  if (!card) return;
  const btn     = document.getElementById('scoreAllBtn');
  if (!btn) return;
  const prog    = document.getElementById('scoreProgress');
  const bar     = document.getElementById('scoreBar');
  const pct     = document.getElementById('scorePct');
  const counts  = document.getElementById('scoreCounts');
  const status  = document.getElementById('scoreStatus');
  const stopBtn = document.getElementById('scoreStopBtn');
  const rescore = document.getElementById('scoreRescore');
  const csrf    = card.dataset.csrf;

  const post = (data) => {
    const fd = new FormData();
    fd.set('csrf', csrf);
    Object.entries(data).forEach(([k, v]) => fd.set(k, String(v)));
    return fetch('volunteers.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(r => r.json());
  };

  let stopped = false;

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    stopped = false;
    prog.hidden = false;
    status.textContent = 'Starting…';

    let start;
    try { start = await post({action: 'ai_run_start', rescore: rescore?.checked ? '1' : ''}); }
    catch (_) { btn.disabled = false; prog.hidden = true; alert('Could not connect — check your internet and try again.'); return; }
    if (!start.ok) {
      btn.disabled = false; prog.hidden = true;
      alert(start.error || 'Could not start scoring.');
      return;
    }
    if (!start.ids.length) {
      btn.disabled = false; prog.hidden = true;
      alert('All applications are already scored. Tick "Re-score everyone" to run again.');
      return;
    }

    const ids = [...start.ids];
    const runId = start.run_id;
    const total = ids.length;
    let done = 0, ok = 0, failed = 0;

    const setProgress = () => {
      const p = total ? Math.round(done / total * 100) : 0;
      bar.style.width = p + '%';
      pct.textContent = p + '%';
      counts.textContent = `${done} / ${total}${failed ? ' · ' + failed + ' failed' : ''}`;
      status.textContent = `Scoring application ${done} of ${total}…`;
    };
    setProgress();

    stopBtn.disabled = false;
    stopBtn.onclick = () => { stopped = true; stopBtn.disabled = true; };

    const queue = [...ids];
    const worker = async () => {
      while (queue.length && !stopped) {
        const id = queue.shift();
        try {
          const r = await post({action: 'ai_score', id});
          if (r.ok) ok++; else { failed++; if (r.fatal) stopped = true; }
        } catch { failed++; }
        done++;
        setProgress();
      }
    };

    await Promise.all([worker(), worker(), worker()]);

    status.textContent = stopped ? 'Stopped early. Wrapping up…' : 'All scored! Generating rankings…';
    bar.style.width = '100%'; pct.textContent = '100%';

    try { await post({action: 'ai_run_finish', run_id: runId, scored: ok, failed}); } catch {}

    status.textContent = 'Done! Reloading page…';
    setTimeout(() => { window.location.href = 'pick.php?tab=all'; }, 900);
  });
})();

/* ---- Checkbox select ---- */
(() => {
  const form = document.getElementById('pickForm');
  if (!form) return;
  const cbs   = () => Array.from(form.querySelectorAll('.pick-cb'));
  const cnts  = () => form.querySelectorAll('.js-pick-count');
  const btns  = () => form.querySelectorAll('.js-pick-submit');
  const all   = document.getElementById('pickSelectAll');

  const refresh = () => {
    const n = cbs().filter(c => c.checked).length;
    cnts().forEach(el => el.textContent = n);
    btns().forEach(b => b.disabled = n === 0);
    if (all) {
      all.indeterminate = n > 0 && n < cbs().length;
      all.checked = n === cbs().length && n > 0;
    }
  };

  form.addEventListener('change', e => { if (e.target.classList.contains('pick-cb')) refresh(); });
  all?.addEventListener('change', () => { cbs().forEach(c => { c.checked = all.checked; }); refresh(); });
  refresh();
})();

/* ---- Auto-submit filter form on dropdown change ---- */
(() => {
  const form = document.getElementById('filterForm');
  if (!form) return;
  form.querySelectorAll('select').forEach(s => s.addEventListener('change', () => form.submit()));
})();
</script>
