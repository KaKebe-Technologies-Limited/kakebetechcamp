<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$v = find_volunteer((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$v) {
    flash('That volunteer was not found.', 'error');
    redirect('volunteers.php');
}
$statuses = volunteer_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'status' && isset($statuses[$_POST['status'] ?? ''])) {
        q('UPDATE volunteers SET status = ?, updated_at = ? WHERE id = ?', [$_POST['status'], now(), $v['id']]);
        flash('Marked as "' . $statuses[$_POST['status']] . '".');
    } elseif ($action === 'notes') {
        q('UPDATE volunteers SET admin_notes = ?, updated_at = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 3000) ?: null, now(), $v['id']]);
        flash('Notes saved.');
    } elseif ($action === 'delete') {
        if ($path = volunteer_cv_path($v)) {
            @unlink($path);
        }
        q('DELETE FROM volunteers WHERE id = ?', [$v['id']]);
        flash('Application ' . $v['reference'] . ' deleted.');
        redirect('volunteers.php');
    }
    redirect('volunteer.php?id=' . (int) $v['id']);
}

$badge = ['new' => 'st-booked', 'shortlisted' => 'st-pending', 'accepted' => 'st-confirmed', 'declined' => 'st-cancelled'];
$cv = volunteer_cv_path($v);
$wa = 'https://wa.me/' . intl_digits($v['phone']) . '?text=' . rawurlencode('Hello ' . explode(' ', $v['full_name'])[0] . ', this is the Kakebe Tech Camp team about your volunteer trainer application (' . $v['reference'] . ').');

admin_header($v['full_name'], 'volunteers', 'Volunteer trainer application ' . $v['reference'] . ' · applied ' . date('j M Y', strtotime($v['created_at'])));
?>
<a href="volunteers.php" class="back"><i class="fa-solid fa-arrow-left"></i> All volunteer trainers</a>

<section class="card vol-head">
  <div class="person"><span class="av ini"><?= e(initials($v['full_name'])) ?></span><div><b><?= e($v['full_name']) ?></b><small><?= e($v['reference']) ?> · <span class="badge <?= $badge[$v['status']] ?? '' ?>"><?= e($statuses[$v['status']] ?? $v['status']) ?></span></small></div></div>
  <div class="actions">
    <?php if ($cv): ?>
      <?php if (str_ends_with($v['cv_file'], '.pdf')): ?><a class="btn btn-light btn-sm" href="volunteers.php?cv=<?= (int) $v['id'] ?>&amp;view=1" target="_blank"><i class="fa-regular fa-eye"></i> View CV</a><?php endif; ?>
      <a class="btn btn-primary btn-sm" href="volunteers.php?cv=<?= (int) $v['id'] ?>"><i class="fa-solid fa-file-arrow-down"></i> Download CV</a>
    <?php endif; ?>
    <a class="btn btn-light btn-sm" href="mailto:<?= e($v['email']) ?>"><i class="fa-regular fa-envelope"></i> Email</a>
    <a class="btn btn-light btn-sm" href="<?= e(tel_link($v['phone'])) ?>"><i class="fa-solid fa-phone"></i> Call</a>
    <a class="btn btn-light btn-sm" href="<?= e($wa) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
  </div>
</section>

<div class="grid-3-1">
  <div class="stack-cards">
    <section class="card">
      <div class="card-head"><h3><i class="fa-regular fa-user"></i> Personal details</h3></div>
      <dl class="details">
        <div><dt>Full name</dt><dd><?= e($v['full_name']) ?></dd></div>
        <div><dt>Age</dt><dd><?= volunteer_age($v['dob']) ?> <span class="muted">(born <?= e(date('j M Y', strtotime($v['dob']))) ?>)</span></dd></div>
        <div><dt>Gender</dt><dd><?= e($v['gender'] ?: '—') ?></dd></div>
        <div><dt>Nationality</dt><dd><?= e($v['nationality'] ?: '—') ?></dd></div>
        <div><dt>Email</dt><dd><a href="mailto:<?= e($v['email']) ?>"><?= e($v['email']) ?></a></dd></div>
        <div><dt>Phone / WhatsApp</dt><dd><a href="<?= e(tel_link($v['phone'])) ?>"><?= e($v['phone']) ?></a></dd></div>
        <div><dt>Based in</dt><dd><?= e($v['location']) ?></dd></div>
        <div><dt>Recommended by</dt><dd><?= e($v['referred_by'] ?: '—') ?></dd></div>
      </dl>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-graduation-cap"></i> Academic background</h3></div>
      <dl class="details">
        <div><dt>Highest qualification</dt><dd><?= e($v['qualification']) ?></dd></div>
        <div><dt>Course / field</dt><dd><?= e($v['course']) ?></dd></div>
        <div><dt>Institution</dt><dd><?= e($v['institution']) ?></dd></div>
        <div><dt>Graduated</dt><dd><?= (int) $v['grad_year'] ?></dd></div>
      </dl>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-person-chalkboard"></i> Expertise &amp; availability</h3></div>
      <div class="tracks-cell vol-tags"><?php foreach (array_filter(explode(',', (string) $v['fields'])) as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></div>
      <dl class="details">
        <div><dt>Experience</dt><dd><?= e($v['experience']) ?></dd></div>
        <div><dt>Current role</dt><dd><?= e($v['job_role'] ?: '—') ?></dd></div>
        <div><dt>Availability</dt><dd><?= e(volunteer_availability_options()[$v['availability']] ?? $v['availability']) ?></dd></div>
        <div><dt>LinkedIn / portfolio</dt><dd><?= $v['portfolio_url'] ? '<a href="' . e($v['portfolio_url']) . '" target="_blank" rel="noopener">' . e(mb_strimwidth(preg_replace('~^https?://(www\.)?~', '', $v['portfolio_url']), 0, 40, '…')) . '</a>' : '—' ?></dd></div>
      </dl>
      <h4 class="sub-title">About them &amp; what they would teach</h4>
      <p class="vol-bio"><?= nl2br(e($v['bio'])) ?></p>
    </section>
  </div>

  <div class="stack-cards">
    <section class="card">
      <div class="card-head"><h3><i class="fa-solid fa-list-check"></i> Status</h3></div>
      <form method="post" class="vol-status"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
        <?php foreach ($statuses as $k => $label): ?>
          <button class="btn btn-sm <?= $v['status'] === $k ? 'btn-navy' : 'btn-light' ?>" type="submit" name="status" value="<?= e($k) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </form>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-regular fa-note-sticky"></i> Team notes</h3></div>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="notes"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
        <textarea name="admin_notes" rows="6" placeholder="Interview notes, sessions assigned, travel details…"><?= e((string) $v['admin_notes']) ?></textarea>
        <div><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save notes</button></div>
      </form>
    </section>
    <section class="card">
      <div class="card-head"><h3><i class="fa-regular fa-file-lines"></i> CV</h3></div>
      <?php if ($cv): ?>
        <p class="small"><b><?= e(volunteer_cv_name($v)) ?></b><br><span class="muted"><?= number_format(max(1, (int) $v['cv_size']) / 1024, 0) ?> KB · uploaded <?= e(date('j M Y', strtotime($v['created_at']))) ?></span></p>
        <a class="btn btn-light btn-sm" href="volunteers.php?cv=<?= (int) $v['id'] ?>"><i class="fa-solid fa-file-arrow-down"></i> Download</a>
      <?php else: ?><p class="empty-note">The CV file is missing.</p><?php endif; ?>
    </section>
    <form method="post" data-confirm="Delete this application and the CV for good?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="btn btn-danger btn-sm" type="submit"><i class="fa-regular fa-trash-can"></i> Delete application</button></form>
  </div>
</div>
<?php admin_footer();
