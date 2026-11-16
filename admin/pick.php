<?php
/**
 * admin/pick.php — Manual volunteer selection.
 * Shows every scored, eligible applicant ranked by AI score. The team checks who they want and confirms.
 */
require __DIR__ . '/_init.php';
$admin = require_admin();

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    // Accept a batch of checked IDs
    if ($action === 'accept') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $in = implode(',', $ids);
            // Mark checked ones as shortlisted (team override — ai_pick = 2 so the AI won't unset them)
            q("UPDATE volunteers SET status = 'shortlisted', ai_pick = 2, updated_at = ? WHERE id IN ($in) AND status NOT IN ('accepted','declined')", [now()]);
            // Reset previously team-shortlisted ones that were NOT in this batch
            q("UPDATE volunteers SET status = 'new', ai_pick = 0, updated_at = ? WHERE ai_pick = 2 AND status = 'shortlisted' AND id NOT IN ($in)", [now()]);
            flash(count($ids) . ' volunteer' . (count($ids) === 1 ? '' : 's') . ' selected. Review them in the Shortlist.');
        } else {
            flash('No one was selected — nobody was changed.', 'info');
        }
        redirect('pick.php');
    }

    json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}

/* ---------- Data ---------- */
$rows = q("SELECT id, full_name, grad_year, experience, fields, ai_field, ai_score, ai_recommendation,
                  ai_summary, ai_meets, status, ai_pick, ai_rank,
                  COALESCE(ai_score,0) +
                  COALESCE(JSON_EXTRACT(ai_breakdown,'$.technical_expertise'),0) * 0.01 AS sort_key
           FROM volunteers
           WHERE ai_scored_at IS NOT NULL
             AND ai_meets = 1
             AND COALESCE(grad_year,9999) <= 2024
             AND status <> 'declined'
           ORDER BY ai_score DESC, id ASC")->fetchAll();

$notScored  = (int) q("SELECT COUNT(*) FROM volunteers WHERE ai_scored_at IS NULL AND status <> 'declined'")->fetchColumn();
$disqualified = (int) q("SELECT COUNT(*) FROM volunteers WHERE ai_meets = 0")->fetchColumn();
$totalApps  = (int) q("SELECT COUNT(*) FROM volunteers WHERE status <> 'declined'")->fetchColumn();

$recBadge = fn(string $r) => match ($r) {
    'Highly Recommended'    => 'st-confirmed',
    'Recommended'           => 'st-booked',
    'Further Review Required' => 'st-pending',
    default                 => 'st-cancelled',
};

admin_header('Pick volunteers', 'volunteers', 'Review every scored applicant and choose who you want');
?>
<a href="volunteers.php" class="back"><i class="fa-solid fa-arrow-left"></i> All volunteer trainers</a>

<?php if ($notScored > 0): ?>
<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><span>
    <b><?= $notScored ?> applicant<?= $notScored === 1 ? '' : 's' ?> not yet scored.</b>
    <a href="volunteers.php#ai">Run AI scoring first</a> to include them here.
</span></div>
<?php endif; ?>

<section class="card" id="pickCard">
  <div class="card-head">
    <div>
      <h3><i class="fa-solid fa-check-to-slot"></i> Pick your volunteers</h3>
      <p class="small muted">
        <?= count($rows) ?> eligible applicants · <?= $disqualified ?> disqualified (graduated after 2024) · <?= $notScored ?> not yet scored.
        Pre-ticked: Highly Recommended &amp; Recommended by AI. Tick anyone else you want to add.
      </p>
    </div>
    <div class="pick-actions-top">
      <label class="pick-select-all small">
        <input type="checkbox" id="pickSelectAll"> Select all
      </label>
    </div>
  </div>

  <?php if (!$rows): ?>
  <div class="empty-state">
    <i class="fa-solid fa-wand-magic-sparkles"></i>
    <p>No scored applicants yet. <a href="volunteers.php#ai">Run AI scoring</a> first.</p>
  </div>
  <?php else: ?>

  <form method="post" id="pickForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="accept">

    <div class="pick-submit-row">
      <button type="submit" class="btn btn-primary js-pick-submit" disabled>
        <i class="fa-solid fa-check"></i> Accept <span class="js-pick-count">0</span> selected
      </button>
      <a href="shortlist.php" class="btn btn-light">View shortlist</a>
    </div>

    <div class="table-wrap">
      <table class="table pick-table">
        <thead>
          <tr>
            <th class="pick-col-check"></th>
            <th>#</th>
            <th>Applicant</th>
            <th>Field</th>
            <th>Experience</th>
            <th>Grad year</th>
            <th>AI score</th>
            <th>Recommendation</th>
            <th>Summary</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $v):
            $precheck = in_array($v['ai_recommendation'], ['Highly Recommended', 'Recommended'], true)
                        || $v['status'] === 'shortlisted';
            $isShortlisted = $v['status'] === 'shortlisted';
            $isAccepted    = $v['status'] === 'accepted';
        ?>
          <tr class="pick-row <?= $isAccepted ? 'pick-accepted' : '' ?><?= $isShortlisted ? ' pick-shortlisted' : '' ?>">
            <td class="pick-col-check">
              <?php if ($isAccepted): ?>
                <i class="fa-solid fa-circle-check green-text" title="Already accepted"></i>
              <?php else: ?>
                <input type="checkbox" name="ids[]" value="<?= (int) $v['id'] ?>"
                       class="pick-cb" <?= $precheck ? 'checked' : '' ?>>
              <?php endif; ?>
            </td>
            <td class="muted small"><?= $i + 1 ?></td>
            <td>
              <a href="volunteer.php?id=<?= (int) $v['id'] ?>" target="_blank" class="pick-name">
                <?= e($v['full_name']) ?>
              </a>
              <?php if ($isShortlisted): ?><span class="badge st-pending small">Shortlisted</span><?php endif; ?>
              <?php if ($isAccepted): ?><span class="badge st-confirmed small">Accepted</span><?php endif; ?>
            </td>
            <td class="small nowrap"><?= e($v['ai_field'] ?? '—') ?></td>
            <td class="small nowrap"><?= e($v['experience'] ?? '—') ?></td>
            <td class="small nowrap <?= (int) $v['grad_year'] < 2021 ? 'green-text' : '' ?>"><?= (int) $v['grad_year'] ?: '—' ?></td>
            <td class="nowrap">
              <b class="ai-score-<?= vol_ai_band((int) $v['ai_score']) ?>"><?= (int) $v['ai_score'] ?></b>
              <span class="muted small">/100</span>
            </td>
            <td class="small nowrap">
              <span class="badge <?= $recBadge((string) $v['ai_recommendation']) ?>">
                <?= e($v['ai_recommendation'] ?? '—') ?>
              </span>
            </td>
            <td class="small pick-summary"><?= e(mb_substr((string) ($v['ai_summary'] ?? ''), 0, 160)) ?><?= mb_strlen((string) $v['ai_summary']) > 160 ? '…' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="pick-submit-row pick-submit-bottom">
      <button type="submit" class="btn btn-primary js-pick-submit" disabled>
        <i class="fa-solid fa-check"></i> Accept <span class="js-pick-count">0</span> selected
      </button>
    </div>
  </form>
  <?php endif; ?>
</section>

<style>
.pick-table th, .pick-table td { vertical-align: middle; }
.pick-col-check { width: 36px; text-align: center; }
.pick-name { font-weight: 600; color: inherit; text-decoration: none; }
.pick-name:hover { text-decoration: underline; }
.pick-summary { max-width: 320px; color: var(--muted); line-height: 1.4; }
.pick-row.pick-accepted { opacity: 0.55; }
.pick-row:has(.pick-cb:checked) { background: var(--highlight-bg, #f0f7ff); }
.pick-actions-top { display: flex; align-items: center; gap: 10px; }
.pick-select-all { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: .85rem; }
.pick-submit-row { display: flex; align-items: center; gap: 10px; padding: 12px 0 8px; }
.pick-submit-bottom { padding: 16px 0 4px; border-top: 1px solid var(--border); margin-top: 8px; }
.ai-score-top    { color: #16a34a; font-size: 1.05em; }
.ai-score-strong { color: #2563eb; font-size: 1.05em; }
.ai-score-possible { color: #d97706; }
.ai-score-weak   { color: #dc2626; }
</style>

<script>
(() => {
  const form   = document.getElementById('pickForm');
  if (!form) return;
  const cbs    = () => Array.from(form.querySelectorAll('.pick-cb'));
  const counts = () => form.querySelectorAll('.js-pick-count');
  const btns   = () => form.querySelectorAll('.js-pick-submit');
  const all    = document.getElementById('pickSelectAll');

  const refresh = () => {
    const n = cbs().filter(c => c.checked).length;
    counts().forEach(el => el.textContent = n);
    btns().forEach(b => b.disabled = n === 0);
    if (all) all.indeterminate = n > 0 && n < cbs().length;
    if (all) all.checked = n === cbs().length && n > 0;
  };

  form.addEventListener('change', e => { if (e.target.classList.contains('pick-cb')) refresh(); });

  if (all) {
    all.addEventListener('change', () => {
      cbs().forEach(c => { c.checked = all.checked; });
      refresh();
    });
  }

  refresh();
})();
</script>
