<?php
/**
 * admin/nasa.php — NASA Space Apps Challenge 2026 registrations and group management.
 */
require __DIR__ . '/_init.php';
$admin = require_admin();

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_whatsapp') {
        setting_set('nasa_whatsapp_url', trim((string) ($_POST['nasa_whatsapp_url'] ?? '')));
        flash('WhatsApp group link saved.');
        redirect('nasa.php#settings');
    }

    if ($action === 'save_cap') {
        $cap = max(1, min(500, (int) ($_POST['nasa_total_cap'] ?? 48)));
        setting_set('nasa_total_cap', (string) $cap);
        flash('Total capacity saved.');
        redirect('nasa.php#settings');
    }

    if ($action === 'save_group') {
        $id   = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 1000);
        $cap  = max(1, min(50, (int) ($_POST['capacity'] ?? 4)));
        $sort = max(0, min(255, (int) ($_POST['sort_order'] ?? 0)));
        if (!$name) {
            flash('Group name is required.', 'error');
            redirect('nasa.php#groups');
        }
        if ($id) {
            q('UPDATE nasa_groups SET name = ?, description = ?, capacity = ?, sort_order = ? WHERE id = ?',
              [$name, $desc ?: null, $cap, $sort, $id]);
            flash("Group \"{$name}\" updated.");
        } else {
            q('INSERT INTO nasa_groups (name, description, capacity, sort_order, created_at) VALUES (?, ?, ?, ?, ?)',
              [$name, $desc ?: null, $cap, $sort, now()]);
            flash("Group \"{$name}\" added.");
        }
        redirect('nasa.php#groups');
    }

    if ($action === 'delete_group') {
        $id = (int) ($_POST['id'] ?? 0);
        $count = (int) q('SELECT COUNT(*) FROM nasa_registrations WHERE group_id = ?', [$id])->fetchColumn();
        if ($count > 0) {
            flash("Cannot delete — $count registration(s) are in this group.", 'error');
        } else {
            q('DELETE FROM nasa_groups WHERE id = ?', [$id]);
            flash('Group deleted.');
        }
        redirect('nasa.php#groups');
    }

    if ($action === 'delete_reg') {
        $id = (int) ($_POST['id'] ?? 0);
        $r  = q('SELECT * FROM nasa_registrations WHERE id = ?', [$id])->fetch();
        if ($r) {
            q('DELETE FROM nasa_registrations WHERE id = ?', [$id]);
            flash("Registration {$r['reference']} ({$r['full_name']}) deleted.");
        }
        redirect('nasa.php');
    }
}

/* ---------- Data ---------- */
$groups = q("SELECT g.*, COUNT(r.id) registered
             FROM nasa_groups g
             LEFT JOIN nasa_registrations r ON r.group_id = g.id
             GROUP BY g.id
             ORDER BY g.sort_order, g.id")->fetchAll();

$regs = q("SELECT r.*, g.name group_name
           FROM nasa_registrations r
           JOIN nasa_groups g ON g.id = r.group_id
           ORDER BY r.created_at DESC")->fetchAll();

$totalCap        = max(1, (int) setting('nasa_total_cap', '48'));
$totalRegistered = count($regs);
$waUrl           = setting('nasa_whatsapp_url', '');

$byGroup = [];
foreach ($regs as $r) {
    $byGroup[$r['group_id']][] = $r;
}

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kakebe-space-apps-2026-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reference', 'Full name', 'Email', 'Phone', 'Group', 'Registered at']);
    foreach ($regs as $r) {
        fputcsv($out, [$r['reference'], $r['full_name'], $r['email'], $r['phone'], $r['group_name'], $r['created_at']]);
    }
    exit;
}

admin_header('Kakebe Space Apps', 'nasa', 'NASA Space Apps Challenge 2026 — registrations and group management');
?>
<div class="kpis">
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-rocket"></i></span><div><small>Registered</small><b><?= number_format($totalRegistered) ?></b><em>of <?= $totalCap ?> spots</em></div></div>
  <div class="kpi"><span class="kpi-icon amber"><i class="fa-solid fa-layer-group"></i></span><div><small>Groups</small><b><?= count($groups) ?></b><em><?= count(array_filter($groups, fn($g) => (int) $g['registered'] >= (int) $g['capacity'])) ?> full</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-users"></i></span><div><small>Spots left</small><b><?= max(0, $totalCap - $totalRegistered) ?></b><em>out of <?= $totalCap ?></em></div></div>
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-link"></i></span><div><small>Public page</small><b class="small-b"><a href="<?= e(base_url('NASA26')) ?>" target="_blank">/NASA26 <i class="fa-solid fa-arrow-up-right-from-square"></i></a></b><em>Live registration</em></div></div>
</div>

<!-- Registrations by group -->
<section class="card">
  <div class="card-head">
    <h3><i class="fa-solid fa-list-check"></i> Registrations by group</h3>
    <a href="nasa.php?export=1" class="btn btn-light btn-sm"><i class="fa-solid fa-file-csv"></i> Export all</a>
  </div>
  <?php if (!$regs): ?>
    <div class="empty-state"><i class="fa-solid fa-rocket"></i><p>No registrations yet. Share <a href="<?= e(base_url('NASA26')) ?>" target="_blank">kakebetechcamp.com/NASA26</a> to get people signing up.</p></div>
  <?php endif; ?>
  <?php foreach ($groups as $g):
    $gRegs = $byGroup[(int) $g['id']] ?? [];
    if (!$gRegs) continue;
    $full = (int) $g['registered'] >= (int) $g['capacity'];
  ?>
  <div style="margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
      <h4 style="margin:0;font-size:1rem"><?= e($g['name']) ?></h4>
      <span class="badge <?= $full ? 'st-confirmed' : 'st-booked' ?>"><?= (int) $g['registered'] ?> / <?= (int) $g['capacity'] ?></span>
      <?php if ($full): ?><span class="badge st-cancelled">Full</span><?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Reference</th><th>Name</th><th>Email</th><th>Phone</th><th>Registered</th><th class="row-actions"></th></tr></thead>
        <tbody>
          <?php foreach ($gRegs as $r): ?>
          <tr>
            <td><code><?= e($r['reference']) ?></code></td>
            <td><b><?= e($r['full_name']) ?></b></td>
            <td><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td>
            <td><a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a></td>
            <td class="muted nowrap"><?= e(date('j M Y, g:i a', strtotime($r['created_at']))) ?></td>
            <td class="row-actions nowrap">
              <form method="post" class="inline" data-confirm="Delete registration <?= e($r['reference']) ?> for <?= e($r['full_name']) ?>?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_reg">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-light btn-sm" type="submit"><i class="fa-regular fa-trash-can"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Empty groups -->
  <?php $emptyGroups = array_filter($groups, fn($g) => !(int) $g['registered']); ?>
  <?php if ($emptyGroups && $regs): ?>
  <p class="small muted">Groups with no registrations yet: <?= implode(', ', array_map(fn($g) => e($g['name']), $emptyGroups)) ?>.</p>
  <?php endif; ?>
</section>

<!-- Group management -->
<section class="card" id="groups">
  <div class="card-head"><h3><i class="fa-solid fa-layer-group"></i> Challenge groups</h3><span class="muted small">manage the groups shown on the registration page</span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Group name</th><th>Description</th><th>Capacity</th><th>Registered</th><th class="row-actions"></th></tr></thead>
      <tbody>
        <?php foreach ($groups as $g): ?>
        <tr>
          <td><b><?= e($g['name']) ?></b></td>
          <td class="muted small" style="max-width:280px"><?= e(mb_strimwidth((string) $g['description'], 0, 90, '…')) ?></td>
          <td><?= (int) $g['capacity'] ?></td>
          <td><?= (int) $g['registered'] ?></td>
          <td class="row-actions nowrap">
            <button type="button" class="btn btn-light btn-sm js-edit-group"
                    data-id="<?= (int) $g['id'] ?>"
                    data-name="<?= e($g['name']) ?>"
                    data-desc="<?= e((string) $g['description']) ?>"
                    data-cap="<?= (int) $g['capacity'] ?>"
                    data-sort="<?= (int) $g['sort_order'] ?>">
              <i class="fa-solid fa-pen"></i> Edit
            </button>
            <form method="post" class="inline" data-confirm="Delete the group '<?= e($g['name']) ?>'? Only possible if nobody has registered yet."><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_group">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <button class="btn btn-light btn-sm" type="submit"><i class="fa-regular fa-trash-can"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <details class="mt-16" id="addGroupDetails">
    <summary class="btn btn-navy btn-sm" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;list-style:none"><i class="fa-solid fa-plus"></i> Add a group</summary>
    <form method="post" class="stack mt-16" id="groupForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_group">
      <input type="hidden" name="id" id="groupFormId" value="">
      <div class="grid-2">
        <label>Group name *<input type="text" name="name" id="groupFormName" required maxlength="150" placeholder="e.g. Earth &amp; Environment"></label>
        <div class="grid-2">
          <label>Max participants<input type="number" name="capacity" id="groupFormCap" value="4" min="1" max="50"></label>
          <label>Sort order<input type="number" name="sort_order" id="groupFormSort" value="0" min="0"></label>
        </div>
      </div>
      <label>Description<textarea name="description" id="groupFormDesc" rows="3" maxlength="1000" placeholder="What challenge does this group tackle?"></textarea></label>
      <div><button class="btn btn-primary btn-sm" type="submit" id="groupFormBtn"><i class="fa-solid fa-floppy-disk"></i> Save group</button>
        <button type="button" class="btn btn-light btn-sm" id="groupFormCancel" hidden>Cancel edit</button></div>
    </form>
  </details>
</section>

<!-- Settings -->
<section class="card" id="settings">
  <div class="card-head"><h3><i class="fa-solid fa-gear"></i> Settings</h3></div>
  <div class="grid-2 align-start">
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_whatsapp">
      <label>WhatsApp group link <small class="muted">shown after registration and in confirmation emails — paste the invite link here</small>
        <input type="url" name="nasa_whatsapp_url" value="<?= e($waUrl) ?>" placeholder="https://chat.whatsapp.com/…">
      </label>
      <div><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save link</button></div>
    </form>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_cap">
      <label>Total spots <small class="muted">maximum registrations across all groups</small>
        <input type="number" name="nasa_total_cap" value="<?= $totalCap ?>" min="1" max="500">
      </label>
      <div><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
    </form>
  </div>
</section>

<script>
document.querySelectorAll('.js-edit-group').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('groupFormId').value   = btn.dataset.id;
    document.getElementById('groupFormName').value = btn.dataset.name;
    document.getElementById('groupFormDesc').value = btn.dataset.desc;
    document.getElementById('groupFormCap').value  = btn.dataset.cap;
    document.getElementById('groupFormSort').value = btn.dataset.sort;
    document.getElementById('groupFormBtn').textContent = '💾 Update group';
    document.getElementById('groupFormCancel').hidden = false;
    const det = document.getElementById('addGroupDetails');
    det.open = true;
    det.querySelector('summary').textContent = 'Edit group';
    document.getElementById('groupFormName').focus();
  });
});
document.getElementById('groupFormCancel').addEventListener('click', () => {
  document.getElementById('groupFormId').value = '';
  document.getElementById('groupForm').reset();
  document.getElementById('groupFormBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save group';
  document.getElementById('groupFormCancel').hidden = true;
  document.getElementById('addGroupDetails').querySelector('summary').textContent = '+ Add a group';
  document.getElementById('addGroupDetails').open = false;
});
</script>
<?php admin_footer();
