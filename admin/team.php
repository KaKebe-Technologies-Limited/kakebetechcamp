<?php
require __DIR__ . '/_init.php';
$admin = require_admin();
$dir = ROOT . '/uploads/team';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $role = mb_substr(trim((string) ($_POST['role'] ?? '')), 0, 120);
    $bio = mb_substr(trim((string) ($_POST['bio'] ?? '')), 0, 300);
    $order = (int) ($_POST['sort_order'] ?? 0);
    [$ext, $err] = check_image_upload('photo', 4);

    if ($err) {
        flash($err, 'error');
    } elseif ($action === 'add') {
        if ($name === '' || $role === '') {
            flash('Name and role are required.', 'error');
        } else {
            $photo = $ext ? store_upload('photo', $ext, $dir) : null;
            q('INSERT INTO team_members (name, role, bio, photo, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', [$name, $role, $bio ?: null, $photo, $order ?: 100, now()]);
            flash("$name added to the core team.");
        }
    } elseif ($action === 'update' && ($m = q('SELECT * FROM team_members WHERE id = ?', [$id])->fetch())) {
        $photo = $m['photo'];
        if ($ext && ($new = store_upload('photo', $ext, $dir))) {
            if ($photo && is_file("$dir/$photo")) {
                @unlink("$dir/$photo");
            }
            $photo = $new;
        }
        if (!empty($_POST['remove_photo']) && $photo) {
            @unlink("$dir/$photo");
            $photo = null;
        }
        q('UPDATE team_members SET name = ?, role = ?, bio = ?, photo = ?, sort_order = ?, is_active = ? WHERE id = ?', [$name ?: $m['name'], $role ?: $m['role'], $bio ?: null, $photo, $order, (int) !empty($_POST['is_active']), $id]);
        flash(($name ?: $m['name']) . ' updated.');
    } elseif ($action === 'delete' && ($m = q('SELECT * FROM team_members WHERE id = ?', [$id])->fetch())) {
        if ($m['photo'] && is_file("$dir/{$m['photo']}")) {
            @unlink("$dir/{$m['photo']}");
        }
        q('DELETE FROM team_members WHERE id = ?', [$id]);
        flash($m['name'] . ' removed.');
    }
    redirect('team.php');
}

$members = team_members(false);
admin_header('Core team', 'team', 'Shown in the "Meet the core team" section of the website');
?>
<div class="team-admin">
  <?php foreach ($members as $m): $ph = team_photo_url($m, '../'); ?>
  <form method="post" enctype="multipart/form-data" class="card member-card <?= $m['is_active'] ? '' : 'inactive' ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
    <label class="mc-photo" title="Click to change photo">
      <?php if ($ph): ?><img src="<?= e($ph) ?>" alt=""><?php else: ?><span><?= e(initials($m['name'])) ?></span><?php endif; ?>
      <em><i class="fa-solid fa-camera"></i> Change photo</em>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="js-autopreview">
    </label>
    <div class="stack">
      <label>Name<input type="text" name="name" value="<?= e($m['name']) ?>" required></label>
      <label>Role<input type="text" name="role" value="<?= e($m['role']) ?>" required></label>
      <div class="row-2">
        <label>Order<input type="number" name="sort_order" value="<?= (int) $m['sort_order'] ?>"></label>
        <label class="check-inline" style="align-self:end;"><input type="checkbox" name="is_active" value="1" <?= $m['is_active'] ? 'checked' : '' ?>> Show on site</label>
      </div>
      <?php if ($ph): ?><label class="check-inline"><input type="checkbox" name="remove_photo" value="1"> Remove photo</label><?php endif; ?>
      <div class="mc-actions">
        <button class="btn btn-primary btn-sm" name="action" value="update" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        <button class="btn btn-danger btn-sm" name="action" value="delete" type="submit" data-confirm-click="Remove <?= e($m['name']) ?> from the team?"><i class="fa-solid fa-trash"></i></button>
      </div>
    </div>
  </form>
  <?php endforeach; ?>

  <form method="post" enctype="multipart/form-data" class="card member-card add">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="mc-photo"><span><i class="fa-solid fa-user-plus"></i></span><em><i class="fa-solid fa-camera"></i> Add photo</em><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="js-autopreview"></label>
    <div class="stack">
      <label>Name<input type="text" name="name" required placeholder="Full name"></label>
      <label>Role<input type="text" name="role" required placeholder="e.g. Mentor"></label>
      <label>Order<input type="number" name="sort_order" value="<?= (count($members) + 1) * 10 ?>"></label>
      <button class="btn btn-navy btn-sm" type="submit"><i class="fa-solid fa-plus"></i> Add member</button>
    </div>
  </form>
</div>
<p class="muted small">Tip: use square photos (at least 600×600 px, JPG/PNG/WEBP, max 4 MB). Lower "order" numbers appear first.</p>
<?php admin_footer();
