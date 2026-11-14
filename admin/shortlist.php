<?php
/**
 * admin/shortlist.php — Dedicated review dashboard for AI-shortlisted volunteer trainers.
 * The team works through the list, accepting or declining each person.
 */
require __DIR__ . '/_init.php';
$admin = require_admin();

$statuses = volunteer_statuses();
$fields   = array_keys(volunteer_fields());

/* ---------- AJAX actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'status' && $id) {
        $to = (string) ($_POST['status'] ?? '');
        if (!isset($statuses[$to])) json_response(['ok' => false, 'error' => 'Invalid status.'], 400);
        q('UPDATE volunteers SET status = ?, ai_pick = 2, updated_at = ? WHERE id = ? AND ai_rank IS NOT NULL', [$to, now(), $id]);
        $bmap = ['new' => 'st-booked', 'shortlisted' => 'st-pending', 'accepted' => 'st-confirmed', 'declined' => 'st-cancelled'];
        // Return fresh totals
        $t = q("SELECT SUM(status='shortlisted') pending, SUM(status='accepted') accepted, SUM(status='declined') declined FROM volunteers WHERE ai_rank IS NOT NULL")->fetch();
        json_response(['ok' => true, 'label' => $statuses[$to], 'badge' => $bmap[$to] ?? '', 'totals' => $t]);
    }

    if ($action === 'notes' && $id) {
        $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 3000);
        q('UPDATE volunteers SET admin_notes = ?, updated_at = ? WHERE id = ?', [$notes ?: null, now(), $id]);
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}

/* ---------- Filters ---------- */
$fField  = in_array($_GET['field'] ?? '', $fields, true) ? (string) $_GET['field'] : '';
$fStatus = isset($statuses[$_GET['status'] ?? '']) ? (string) $_GET['status'] : '';

$where  = ['ai_rank IS NOT NULL'];
$params = [];
if ($fField !== '') {
    $where[]  = 'FIND_IN_SET(?, fields)';
    $params[] = $fField;
}
if ($fStatus !== '') {
    $where[]  = 'status = ?';
    $params[] = $fStatus;
}
$sql  = ' WHERE ' . implode(' AND ', $where);
$rows = q("SELECT * FROM volunteers$sql ORDER BY ai_rank ASC", $params)->fetchAll();

/* ---------- Totals (always unfiltered) ---------- */
$tot = q("SELECT
    COUNT(*) total,
    SUM(status = 'shortlisted') pending,
    SUM(status = 'accepted')   accepted,
    SUM(status = 'declined')   declined
    FROM volunteers WHERE ai_rank IS NOT NULL")->fetch();

$fieldRows = q("SELECT ai_field lbl, COUNT(*) c, SUM(status = 'accepted') acc, SUM(status = 'declined') `dec`
                FROM volunteers WHERE ai_rank IS NOT NULL GROUP BY ai_field ORDER BY c DESC")->fetchAll();

$reviewed    = (int) $tot['accepted'] + (int) $tot['declined'];
$reviewedPct = (int) $tot['total'] ? round($reviewed / (int) $tot['total'] * 100) : 0;
$bmap        = ['new' => 'st-booked', 'shortlisted' => 'st-pending', 'accepted' => 'st-confirmed', 'declined' => 'st-cancelled'];

$qs = function (array $extra = []) use ($fField, $fStatus): string {
    $p = array_filter(['status' => $fStatus, 'field' => $fField] + $extra, fn($v) => $v !== '' && $v !== null);
    return $p ? '?' . http_build_query($p) : '';
};

admin_header('Shortlist review', 'volunteers', 'Review the AI-shortlisted applicants and confirm your volunteer trainers');
?>
<a href="volunteers.php" class="back"><i class="fa-solid fa-arrow-left"></i> All volunteer trainers</a>

<!-- Progress overview -->
<section class="card sl-progress" id="slProgress"
         data-csrf="<?= e(csrf_token()) ?>"
         data-pending="<?= (int) $tot['pending'] ?>"
         data-accepted="<?= (int) $tot['accepted'] ?>"
         data-declined="<?= (int) $tot['declined'] ?>"
         data-total="<?= (int) $tot['total'] ?>">
  <div class="sl-prog-head">
    <div>
      <h3><i class="fa-solid fa-wand-magic-sparkles"></i> AI Shortlist &mdash; <b><?= (int) $tot['total'] ?></b> applicant<?= (int) $tot['total'] === 1 ? '' : 's' ?> to review</h3>
      <p class="small sl-prog-sub">
        <span class="js-reviewed"><?= $reviewed ?></span> of <span class="js-total"><?= (int) $tot['total'] ?></span> reviewed
        (<span class="js-reviewedpct"><?= $reviewedPct ?></span>%)
        &nbsp;·&nbsp; <b class="green-text js-accepted"><?= (int) $tot['accepted'] ?></b> accepted
        &nbsp;·&nbsp; <b class="red-text js-declined"><?= (int) $tot['declined'] ?></b> declined
        &nbsp;·&nbsp; <span class="js-pending"><?= (int) $tot['pending'] ?></span> pending
      </p>
    </div>
    <div class="sl-prog-actions">
      <a href="volunteers.php?ai=picks" class="btn btn-light btn-sm"><i class="fa-solid fa-list"></i> Table view</a>
      <a href="volunteers.php?export=1&amp;ai=picks" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export</a>
    </div>
  </div>
  <div class="bar sl-bar">
    <i class="js-bar" style="width:<?= $reviewedPct ?>%;background:var(--green, #34c66b)"></i>
  </div>
  <?php if ($fieldRows): ?>
  <div class="ai-mix small sl-fields">
    <span class="muted">By field:</span>
    <?php foreach ($fieldRows as $fr): ?>
      <span class="tag">
        <?= e((string) $fr['lbl']) ?> <b><?= (int) $fr['c'] ?></b>
        <?php if ((int) $fr['acc']): ?><span class="sl-tick"><?= (int) $fr['acc'] ?> ✓</span><?php endif; ?>
        <?php if ((int) ($fr['dec'] ?? 0)): ?><span class="sl-cross"><?= (int) $fr['dec'] ?> ✗</span><?php endif; ?>
      </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- Status tabs -->
<div class="tabs">
  <a href="shortlist.php<?= e($qs(['status' => null])) ?>" class="<?= $fStatus === '' ? 'active' : '' ?>">All <em><?= (int) $tot['total'] ?></em></a>
  <a href="shortlist.php<?= e($qs(['status' => 'shortlisted'])) ?>" class="<?= $fStatus === 'shortlisted' ? 'active' : '' ?>">Pending review <em><?= (int) $tot['pending'] ?></em></a>
  <a href="shortlist.php<?= e($qs(['status' => 'accepted'])) ?>" class="<?= $fStatus === 'accepted' ? 'active' : '' ?>">Accepted <em><?= (int) $tot['accepted'] ?></em></a>
  <a href="shortlist.php<?= e($qs(['status' => 'declined'])) ?>" class="<?= $fStatus === 'declined' ? 'active' : '' ?>">Declined <em><?= (int) $tot['declined'] ?></em></a>
</div>

<!-- Field filter -->
<form class="card filters sl-filter" method="get">
  <?php if ($fStatus): ?><input type="hidden" name="status" value="<?= e($fStatus) ?>"><?php endif; ?>
  <select name="field" onchange="this.form.submit()">
    <option value="">All training fields</option>
    <?php foreach ($fields as $fld): ?><option <?= $fField === $fld ? 'selected' : '' ?> value="<?= e($fld) ?>"><?= e($fld) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-navy btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  <?php if ($fField): ?><a href="shortlist.php<?= e($qs(['field' => null])) ?>" class="btn btn-light btn-sm">Clear field filter</a><?php endif; ?>
  <span class="muted small"><?= count($rows) ?> shown</span>
</form>

<!-- Review cards -->
<div class="sl-cards" id="slCards">
  <?php if (!$rows): ?>
    <div class="empty-state card"><i class="fa-solid fa-wand-magic-sparkles"></i>
      <p><?= $fStatus || $fField ? 'No shortlisted volunteers match your filters.' : 'No shortlisted volunteers yet — run the AI shortlist on the <a href="volunteers.php#ai">Volunteer trainers</a> page first.' ?></p>
    </div>
  <?php endif; ?>

  <?php foreach ($rows as $v):
    $n          = vol_ai_notes($v);
    $band       = vol_ai_band((int) $v['ai_score']);
    $isAccepted = $v['status'] === 'accepted';
    $isDeclined = $v['status'] === 'declined';
    $hasCv      = (bool) volunteer_cv_path($v);
    $wa         = 'https://wa.me/' . intl_digits($v['phone']) . '?text=' . rawurlencode('Hello ' . explode(' ', $v['full_name'])[0] . ', this is the Kakebe Tech Camp 2026 team about your volunteer trainer application (' . $v['reference'] . ').');
    $avail      = volunteer_availability_options()[$v['availability']] ?? $v['availability'];
  ?>
  <section class="card sl-card<?= $isAccepted ? ' sl-accepted' : ($isDeclined ? ' sl-declined' : '') ?>"
           data-id="<?= (int) $v['id'] ?>" data-status="<?= e($v['status']) ?>">

    <!-- Top row: rank · person · status · score -->
    <div class="sl-card-top">
      <div class="sl-rank">#<?= (int) $v['ai_rank'] ?></div>
      <div class="person sl-person">
        <span class="av ini"><?= e(initials($v['full_name'])) ?></span>
        <div>
          <b><?= e($v['full_name']) ?></b>
          <small><?= e($v['reference']) ?><?php if ($v['location']): ?> · <?= e($v['location']) ?><?php endif; ?></small>
        </div>
      </div>
      <span class="badge <?= $bmap[$v['status']] ?? '' ?> js-badge"><?= e($statuses[$v['status']] ?? $v['status']) ?></span>
      <span class="ai-score big ai-<?= $band ?>"><?= (int) $v['ai_score'] ?><small>/100</small></span>
    </div>

    <!-- AI assessment body -->
    <div class="sl-card-body">
      <?php if ($v['ai_field']): ?>
        <p class="sl-ai-label small muted"><i class="fa-solid fa-wand-magic-sparkles"></i> Best training field: <b><?= e((string) $v['ai_field']) ?></b>
        <?php if (!$v['ai_meets']): ?> &nbsp;<span class="badge st-cancelled" title="May not meet the requirements (age, qualification or CV)">requirements?</span><?php endif; ?>
        </p>
      <?php endif; ?>
      <?php if ($v['ai_summary']): ?>
        <p class="sl-summary"><?= e((string) $v['ai_summary']) ?></p>
      <?php endif; ?>

      <?php if ($n['strengths'] || $n['concerns']): ?>
      <div class="ai-points sl-points">
        <?php if ($n['strengths']): ?>
        <div>
          <h4><i class="fa-solid fa-circle-plus"></i> Strengths</h4>
          <ul><?php foreach ($n['strengths'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>
        <?php if ($n['concerns']): ?>
        <div>
          <h4><i class="fa-solid fa-circle-minus"></i> Concerns</h4>
          <ul><?php foreach ($n['concerns'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($n['flags']): ?>
        <p class="ai-flags"><?php foreach ($n['flags'] as $fl): ?><span class="tag"><i class="fa-solid fa-flag"></i> <?= e($fl) ?></span><?php endforeach; ?></p>
      <?php endif; ?>

      <!-- Quick details row -->
      <div class="sl-meta small">
        <?php foreach (array_filter(explode(',', (string) $v['fields'])) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?>
        <span class="muted">· <?= e($v['qualification']) ?><?php if ($v['course']): ?> in <?= e($v['course']) ?><?php endif; ?><?php if ($v['institution']): ?> · <?= e($v['institution']) ?><?php endif; ?> · <?= volunteer_age($v['dob']) ?> yrs · <?= e($avail) ?></span>
      </div>

      <?php if ($v['admin_notes']): ?>
        <div class="sl-existing-notes small"><i class="fa-regular fa-note-sticky"></i> <?= nl2br(e((string) $v['admin_notes'])) ?></div>
      <?php endif; ?>
    </div>

    <!-- Footer: actions + notes -->
    <div class="sl-card-foot">
      <div class="sl-actions">
        <?php if (!$isAccepted): ?>
          <button type="button" class="btn btn-sm btn-primary js-sl-btn" data-to="accepted"><i class="fa-solid fa-check"></i> Accept</button>
        <?php else: ?>
          <button type="button" class="btn btn-sm btn-light js-sl-btn" data-to="shortlisted"><i class="fa-solid fa-rotate-left"></i> Undo accept</button>
        <?php endif; ?>
        <?php if (!$isDeclined): ?>
          <button type="button" class="btn btn-sm btn-danger js-sl-btn" data-to="declined"><i class="fa-solid fa-xmark"></i> Decline</button>
        <?php else: ?>
          <button type="button" class="btn btn-sm btn-light js-sl-btn" data-to="shortlisted"><i class="fa-solid fa-rotate-left"></i> Undo decline</button>
        <?php endif; ?>
        <a class="btn btn-light btn-sm" href="volunteer.php?id=<?= (int) $v['id'] ?>" target="_blank"><i class="fa-regular fa-eye"></i> Full application</a>
        <?php if ($hasCv): ?>
          <a class="btn btn-light btn-sm" href="volunteers.php?cv=<?= (int) $v['id'] ?>"><i class="fa-solid fa-file-arrow-down"></i> CV</a>
        <?php endif; ?>
        <a class="btn btn-light btn-sm" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
        <a class="btn btn-light btn-sm" href="mailto:<?= e($v['email']) ?>"><i class="fa-regular fa-envelope"></i> Email</a>
      </div>
      <details class="sl-notes-wrap">
        <summary class="btn btn-light btn-sm"><i class="fa-regular fa-note-sticky"></i> <?= $v['admin_notes'] ? 'Edit notes' : 'Add notes' ?></summary>
        <div class="sl-notes">
          <textarea class="js-notes-text" rows="3" placeholder="Interview notes, session topics assigned, travel arrangements, questions to ask…"><?= e((string) $v['admin_notes']) ?></textarea>
          <div class="sl-notes-row">
            <button type="button" class="btn btn-primary btn-sm js-notes-save"><i class="fa-solid fa-floppy-disk"></i> Save notes</button>
            <span class="js-notes-status small muted"></span>
          </div>
        </div>
      </details>
    </div>
  </section>
  <?php endforeach; ?>
</div>

<style>
/* ---- Shortlist review dashboard styles ---- */
.sl-progress{margin-bottom:0}
.sl-prog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.sl-prog-sub{margin-top:4px;color:var(--text-muted)}
.sl-prog-actions{display:flex;gap:8px;flex-shrink:0}
.green-text{color:#1a6b35}
.red-text{color:#c4121e}
.sl-bar{margin:12px 0 8px;height:10px}
.sl-fields{margin-top:4px;flex-wrap:wrap;gap:6px}
.sl-tick{background:#d4f5df;color:#1a6b35;border-radius:999px;padding:1px 5px;font-weight:700}
.sl-cross{background:#ffe4e4;color:#c4121e;border-radius:999px;padding:1px 5px;font-weight:700}
.sl-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;margin-bottom:0}
/* Cards */
.sl-cards{display:flex;flex-direction:column;gap:14px;margin-top:14px}
.sl-card{transition:border-color .2s,opacity .2s;border:2px solid var(--border, #e1e4ed)}
.sl-accepted{border-color:#34c66b!important;background:#f6fef9}
.sl-declined{border-color:var(--border, #e1e4ed);opacity:.62}
/* Top row */
.sl-card-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.sl-rank{min-width:38px;height:38px;border-radius:50%;background:var(--navy,#0F2557);color:#fff;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;letter-spacing:-.3px}
.sl-person{flex:1;min-width:0}
.sl-card-top .badge{margin-left:auto}
.ai-score.big{font-size:30px;font-weight:800;min-width:60px;text-align:center;flex-shrink:0}
/* Body */
.sl-card-body{padding-top:12px;border-top:1px solid var(--border,#e1e4ed);margin-top:12px}
.sl-ai-label{margin:0 0 6px}
.sl-summary{margin:0 0 10px;font-size:15px;line-height:1.55;color:var(--text,#1B2340)}
.sl-points{margin-bottom:8px}
.sl-points h4{font-size:12px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.sl-meta{margin-top:10px;line-height:2.0;color:var(--text-muted,#4A5372)}
.sl-existing-notes{margin-top:10px;padding:8px 12px;background:#fafafa;border-radius:8px;border:1px solid var(--border,#e1e4ed);color:var(--text-muted,#4A5372);line-height:1.5}
/* Footer */
.sl-card-foot{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;padding-top:12px;border-top:1px solid var(--border,#e1e4ed);margin-top:12px}
.sl-actions{display:flex;gap:8px;flex-wrap:wrap;flex:1}
/* Notes */
.sl-notes-wrap{flex-shrink:0}
.sl-notes-wrap summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:6px}
.sl-notes-wrap summary::-webkit-details-marker{display:none}
.sl-notes{margin-top:8px;display:flex;flex-direction:column;gap:8px;min-width:260px}
.sl-notes textarea{width:100%;min-height:64px;resize:vertical;border:1px solid var(--border,#e1e4ed);border-radius:8px;padding:8px 10px;font:inherit;font-size:14px;box-sizing:border-box}
.sl-notes-row{display:flex;align-items:center;gap:10px}
/* Spinner on buttons while saving */
.sl-saving{opacity:.6;pointer-events:none}
@media(max-width:600px){
  .sl-card-top{gap:10px}
  .ai-score.big{font-size:24px;min-width:50px}
  .sl-actions{gap:6px}
}
</style>

<script>
/* Shortlist review — AJAX status updates and notes saves */
(function () {
  const prog   = document.getElementById('slProgress');
  const cards  = document.getElementById('slCards');
  if (!prog || !cards) return;
  const csrf   = prog.dataset.csrf;

  const post = async (data) => {
    const fd = new FormData();
    fd.set('csrf', csrf);
    for (const [k, v] of Object.entries(data)) fd.set(k, v);
    const r = await fetch('shortlist.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  };

  /* Update progress bar + counts */
  const updateProg = (t) => {
    const reviewed = +t.accepted + +t.declined;
    const total    = +prog.dataset.total;
    const pct      = total ? Math.round(reviewed / total * 100) : 0;
    prog.querySelector('.js-reviewed').textContent    = reviewed;
    prog.querySelector('.js-reviewedpct').textContent = pct;
    prog.querySelector('.js-accepted').textContent    = t.accepted;
    prog.querySelector('.js-declined').textContent    = t.declined;
    prog.querySelector('.js-pending').textContent     = t.pending;
    const bar = prog.querySelector('.js-bar');
    if (bar) bar.style.width = pct + '%';
  };

  const badgeMap = {
    new: 'st-booked', shortlisted: 'st-pending', accepted: 'st-confirmed', declined: 'st-cancelled'
  };
  const labelMap = <?= json_encode($statuses, JSON_HEX_TAG) ?>;

  /* Accept / Decline / Undo buttons */
  cards.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-sl-btn');
    if (!btn) return;
    const card   = btn.closest('.sl-card');
    const id     = card.dataset.id;
    const to     = btn.dataset.to;
    const others = [...card.querySelectorAll('.js-sl-btn')];
    others.forEach(b => b.classList.add('sl-saving'));
    try {
      const d = await post({ action: 'status', id, status: to });
      if (!d.ok) { alert(d.error || 'Could not update — please try again.'); others.forEach(b => b.classList.remove('sl-saving')); return; }
      /* Update card state */
      card.dataset.status = to;
      card.classList.toggle('sl-accepted', to === 'accepted');
      card.classList.toggle('sl-declined', to === 'declined');
      const badge = card.querySelector('.js-badge');
      if (badge) { badge.textContent = d.label || labelMap[to] || to; badge.className = 'badge ' + (d.badge || badgeMap[to] || '') + ' js-badge'; }
      /* Rebuild action buttons */
      const actBox  = card.querySelector('.sl-actions');
      const isAcc   = to === 'accepted';
      const isDec   = to === 'declined';
      const viewBtn = [...actBox.querySelectorAll('a')].map(a => a.outerHTML).join('');
      actBox.innerHTML =
        (isAcc
          ? `<button type="button" class="btn btn-sm btn-light js-sl-btn" data-to="shortlisted"><i class="fa-solid fa-rotate-left"></i> Undo accept</button>`
          : `<button type="button" class="btn btn-sm btn-primary js-sl-btn" data-to="accepted"><i class="fa-solid fa-check"></i> Accept</button>`) +
        (isDec
          ? `<button type="button" class="btn btn-sm btn-light js-sl-btn" data-to="shortlisted"><i class="fa-solid fa-rotate-left"></i> Undo decline</button>`
          : `<button type="button" class="btn btn-sm btn-danger js-sl-btn" data-to="declined"><i class="fa-solid fa-xmark"></i> Decline</button>`) +
        viewBtn;
      /* Update progress */
      if (d.totals) updateProg(d.totals);
    } catch (_) {
      alert('Could not update — check your connection and try again.');
      others.forEach(b => b.classList.remove('sl-saving'));
    }
  });

  /* Notes save */
  cards.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-notes-save');
    if (!btn) return;
    btn.disabled = true;
    const wrap    = btn.closest('.sl-notes');
    const text    = wrap.querySelector('.js-notes-text');
    const status  = wrap.querySelector('.js-notes-status');
    try {
      const id = btn.closest('.sl-card').dataset.id;
      const d  = await post({ action: 'notes', id, notes: text.value });
      if (status) status.textContent = d.ok ? 'Saved.' : (d.error || 'Error saving.');
      /* Update the notes preview in the body if one exists */
      const preview = btn.closest('.sl-card').querySelector('.sl-existing-notes');
      if (text.value.trim()) {
        if (preview) {
          preview.innerHTML = '<i class="fa-regular fa-note-sticky"></i> ' + text.value.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
        } else {
          const body = btn.closest('.sl-card').querySelector('.sl-card-body');
          if (body) {
            const div = document.createElement('div');
            div.className = 'sl-existing-notes small';
            div.innerHTML = '<i class="fa-regular fa-note-sticky"></i> ' + text.value.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
            body.appendChild(div);
          }
        }
      } else if (preview) {
        preview.remove();
      }
    } catch (_) { if (status) status.textContent = 'Error — try again.'; }
    btn.disabled = false;
  });
})();
</script>
<?php admin_footer();
