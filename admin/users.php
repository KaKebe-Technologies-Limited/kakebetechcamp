<?php
require __DIR__ . '/_init.php';
$me = require_admin();
$manage = role_can($me['role'], 'users');   // only the team lead adds people and sets roles
$roles = admin_roles();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (!$manage && $action !== 'password') {
        flash('Only the team lead can manage accounts and roles.', 'error');
        redirect('users.php');
    }
    switch ($action) {
        case 'add':
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $pass = (string) ($_POST['password'] ?? '');
            $role = isset($roles[$_POST['role'] ?? '']) ? $_POST['role'] : 'team';
            if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                flash('Enter a name, a valid email and a password of at least 8 characters.', 'error');
                break;
            }
            if (q('SELECT id FROM admins WHERE email = ?', [$email])->fetchColumn()) {
                flash('An account with that email already exists.', 'error');
                break;
            }
            q('INSERT INTO admins (name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, now()]);
            flash("Account created for $name as " . admin_role_label($role) . '. Share the login page and temporary password with them.');
            break;

        case 'role':
            $id = (int) ($_POST['id'] ?? 0);
            $role = (string) ($_POST['role'] ?? '');
            if (!isset($roles[$role])) {
                break;
            }
            if ($id === (int) $me['id'] && $role !== 'admin' && (int) q("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn() <= 1) {
                flash('You are the only team lead — make someone else team lead first.', 'error');
                break;
            }
            q('UPDATE admins SET role = ? WHERE id = ?', [$role, $id]);
            flash('Role updated to ' . admin_role_label($role) . '.');
            break;

        case 'password':
            $current = (string) ($_POST['current'] ?? '');
            $new = (string) ($_POST['new'] ?? '');
            $hash = q('SELECT password_hash FROM admins WHERE id = ?', [$me['id']])->fetchColumn();
            if (!password_verify($current, $hash)) {
                flash('Your current password is incorrect.', 'error');
            } elseif (strlen($new) < 8 || $new !== ($_POST['new2'] ?? '')) {
                flash('New passwords must match and be at least 8 characters.', 'error');
            } else {
                q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                session_regenerate_id(true);
                flash('Your password has been changed.');
            }
            break;

        case 'delete':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $me['id']) {
                flash('You cannot delete your own account.', 'error');
            } elseif ((int) q('SELECT COUNT(*) FROM admins')->fetchColumn() <= 1) {
                flash('At least one account must remain.', 'error');
            } else {
                q('DELETE FROM admins WHERE id = ?', [$id]);
                flash('Account removed.');
            }
            break;
    }
    redirect('users.php');
}

$admins = $manage ? q("SELECT id, name, email, role, last_login_at, created_at FROM admins ORDER BY FIELD(role, 'admin', 'finance', 'operations', 'team', 'field'), name")->fetchAll() : [];
admin_header($manage ? 'Admin users & roles' : 'My account', 'users', $manage ? 'Who can use the control panel, project finance and requisitions' : admin_role_label($me['role']));
?>
<div class="grid-2 align-start">
  <?php if ($manage): ?>
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-user-shield"></i> Accounts</h3><span class="muted"><?= count($admins) ?></span></div>
    <ul class="user-list">
      <?php foreach ($admins as $a): ?>
      <li>
        <span class="tb-avatar"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span>
        <div><b><?= e($a['name']) ?><?= (int) $a['id'] === (int) $me['id'] ? ' <small class="badge st-approved">You</small>' : '' ?></b><small class="block muted"><?= e($a['email']) ?> · last login: <?= $a['last_login_at'] ? e(time_ago($a['last_login_at'])) : 'never' ?></small></div>
        <form method="post" class="role-form"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <select name="role" onchange="this.form.submit()" aria-label="Role for <?= e($a['name']) ?>"><?php foreach ($roles as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $a['role'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        </form>
        <?php if ((int) $a['id'] !== (int) $me['id']): ?>
        <form method="post" data-confirm="Remove access for <?= e($a['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="icon-btn danger" type="submit" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <h4 class="sub-title">What each role can do</h4>
    <ul class="mini-stats role-help">
      <?php foreach ($roles as [$label, $desc]): ?><li><span><b><?= e($label) ?></b> — <?= e($desc) ?></span></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="stack-cards">
    <?php if ($manage): ?>
    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-user-plus"></i> Add a person</h3></div>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label>Full name<input type="text" name="name" required></label>
        <label>Email<input type="email" name="email" required></label>
        <label>Role<select name="role"><?php foreach ($roles as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $k === 'field' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Temporary password <small class="muted">(min. 8 characters)</small><input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-user-plus"></i> Create account</button>
        <p class="small muted">Field staff log in at <b><?= e(base_url('admin/')) ?></b> and only see their own requisitions.</p>
      </form>
    </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-key"></i> Change my password</h3></div>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
        <div class="row-2">
          <label>New password<input type="password" name="new" minlength="8" required autocomplete="new-password"></label>
          <label>Confirm new password<input type="password" name="new2" minlength="8" required autocomplete="new-password"></label>
        </div>
        <button class="btn btn-navy" type="submit"><i class="fa-solid fa-key"></i> Update password</button>
      </form>
    </div>
  </div>
</div>
<?php admin_footer();
