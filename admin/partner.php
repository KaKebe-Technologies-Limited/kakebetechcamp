<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$p = find_partner((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$p) {
    flash('That partner was not found.', 'error');
    redirect('partners.php');
}
$statuses = partner_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'status' && isset($statuses[$_POST['status'] ?? ''])) {
        q('UPDATE partners SET status = ?, updated_at = ? WHERE id = ?', [$_POST['status'], now(), $p['id']]);
        flash('Marked as "' . $statuses[$_POST['status']] . '".');
    } elseif ($action === 'notes') {
        q('UPDATE partners SET admin_notes = ?, updated_at = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 3000) ?: null, now(), $p['id']]);
        flash('Notes saved.');
    } elseif ($action === 'delete') {
        q('DELETE FROM partners WHERE id = ?', [$p['id']]);
        flash('Partner ' . $p['reference'] . ' deleted.');
        redirect('partners.php');
    }
    redirect('partner.php?id=' . (int) $p['id']);
}

$badge = ['new' => 'st-booked', 'contacted' => 'st-pending', 'talks' => 'st-waitlisted', 'confirmed' => 'st-confirmed', 'declined' => 'st-cancelled'];
$wa = 'https://wa.me/' . intl_digits($p['phone']) . '?text=' . rawurlencode('Hello ' . explode(' ', $p['contact_name'])[0] . ', this is the Kakebe Tech Camp team. Thank you for your interest in partnering with Kakebe Tech Camp 2026 (' . $p['reference'] . ').');
$wayInfo = partner_ways();

admin_header($p['organization'], 'partners', 'Partner interest ' . $p['reference'] . ' · submitted ' . date('j M Y', strtotime($p['created_at'])));
?>
<a href="partners.php" class="back"><i class="fa-solid fa-arrow-left"></i> All partners</a>

<section class="card vol-head">
  <div class="person"><span class="av ini"><?= e(initials($p['organization'])) ?></span><div><b><?= e($p['organization']) ?></b><small><?= e($p['reference']) ?> · <span class="badge <?= $badge[$p['status']] ?? '' ?>"><?= e($statuses[$p['status']] ?? $p['status']) ?></span></small></div></div>
  <div class="actions">
    <a class="btn btn-light btn-sm" href="mailto:<?= e($p['email']) ?>"><i class="fa-regular fa-envelope"></i> Email</a>
    <a class="btn btn-light btn-sm" href="<?= e(tel_link($p['phone'])) ?>"><i class="fa-solid fa-phone"></i> Call</a>
    <a class="btn btn-light btn-sm" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
    <?php if ($p['website']): ?><a class="btn btn-light btn-sm" href="<?= e($p['website']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-globe"></i> Website</a><?php endif; ?>
  </div>
</section>

<div class="grid-3-1">
  <div class="stack-cards">
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-handshake"></i> Contribution</h3></div>
      <div class="tracks-cell vol-tags"><?php foreach (array_filter(explode(',', (string) $p['ways'])) as $t): ?><span class="tag"><i class="fa-solid <?= e($wayInfo[$t][0] ?? 'fa-handshake') ?>"></i> <?= e($t) ?></span><?php endforeach; ?></div>
      <p class="vol-bio"><?= nl2br(e($p['contribution'])) ?></p>
      <dl class="details">
        <div><dt>Estimated value</dt><dd><?= e($p['budget'] ?: '—') ?></dd></div>
        <div><dt>Best way to reach them</dt><dd><?= e($p['contact_pref'] ?: 'Any') ?></dd></div>
        <div><dt>Heard about us</dt><dd><?= e($p['referred_by'] ?: '—') ?></dd></div>
      </dl>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-regular fa-building"></i> Organisation &amp; contact</h3></div>
      <dl class="details">
        <div><dt>Organisation</dt><dd><?= e($p['organization']) ?></dd></div>
        <div><dt>Type</dt><dd><?= e($p['org_type']) ?></dd></div>
        <div><dt>Contact person</dt><dd><?= e($p['contact_name']) ?><?= $p['job_title'] ? ' <span class="muted">· ' . e($p['job_title']) . '</span>' : '' ?></dd></div>
        <div><dt>Email</dt><dd><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></dd></div>
        <div><dt>Phone / WhatsApp</dt><dd><a href="<?= e(tel_link($p['phone'])) ?>"><?= e($p['phone']) ?></a></dd></div>
        <div><dt>Based in</dt><dd><?= e($p['location']) ?></dd></div>
        <div><dt>Website</dt><dd><?= $p['website'] ? '<a href="' . e($p['website']) . '" target="_blank" rel="noopener">' . e(mb_strimwidth(preg_replace('~^https?://(www\.)?~', '', $p['website']), 0, 40, '…')) . '</a>' : '—' ?></dd></div>
      </dl>
    </section>
  </div>

  <div class="stack-cards">
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-list-check"></i> Status</h3></div>
      <form method="post" class="vol-status"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <?php foreach ($statuses as $k => $label): ?>
          <button class="btn btn-sm <?= $p['status'] === $k ? 'btn-navy' : 'btn-light' ?>" type="submit" name="status" value="<?= e($k) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </form>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-regular fa-note-sticky"></i> Team notes</h3></div>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="notes"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <textarea name="admin_notes" rows="6" placeholder="Calls, meetings, what was agreed, who follows up…"><?= e((string) $p['admin_notes']) ?></textarea>
        <div><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save notes</button></div>
      </form>
    </section>
    <form method="post" data-confirm="Delete this partner interest for good?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-danger btn-sm" type="submit"><i class="fa-regular fa-trash-can"></i> Delete</button></form>
  </div>
</div>
<?php admin_footer();
