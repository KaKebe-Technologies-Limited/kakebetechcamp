<?php
require __DIR__ . '/_init.php';
$me = require_admin();
$manage = role_can($me['role'], 'users');   // only the team lead adds people and sets roles and access
$roles = admin_roles();
$features = admin_features();

/** Email someone that their account / access is ready (with the password only when the team lead chose to send it). */
$sendAccess = function (string $name, string $email, string $role, array $feat, ?string $password) {
    $labels = $role === 'admin' ? ['Everything'] : array_map(fn($k) => admin_features()[$k][1], $feat);
    $inner = '<p><strong>Hi ' . e(explode(' ', $name)[0]) . ',</strong></p><p>You have access to the Kakebe control panel as <b>' . e(admin_role_label($role)) . '</b>.</p>'
        . kt_detail(array_filter(['🔐 Log in at' => base_url('admin/login.php'), '📧 Email' => $email, '🔑 Password' => (string) $password, '🧭 You can use' => implode(', ', $labels)]))
        . ($password ? '<p>Please change the password after you log in (My account → Change my password).</p>' : '')
        . '<p>Add your signature under <b>My account</b> — it goes on the requisitions and accountability documents you sign.</p>'
        . kt_btn('Log in', base_url('admin/login.php'), 'navy');
    return send_mail($email, 'Your Kakebe control panel access', kt_email('Team & access', $inner));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if (!$manage && !in_array($action, ['password', 'signature'], true)) {
        flash('Only the team lead can manage accounts, roles and access.', 'error');
        redirect('users.php');
    }
    switch ($action) {
        case 'save':
            // Add a person, or — if the email is already registered — update their role, access and details
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? ''))), 0, 120);
            $phone = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40);
            $title = mb_substr(trim((string) ($_POST['job_title'] ?? '')), 0, 120);
            $pass = (string) ($_POST['password'] ?? '');
            $role = isset($roles[$_POST['role'] ?? '']) ? $_POST['role'] : 'team';
            $chosen = (array) ($_POST['features'] ?? []);
            $store = $role === 'admin' ? null : features_to_store($role, $chosen);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Enter a valid email address.', 'error');
                break;
            }
            if ($pass !== '' && strlen($pass) < 8) {
                flash('The password must be at least 8 characters.', 'error');
                break;
            }
            $existing = q('SELECT * FROM admins WHERE email = ?', [$email])->fetch();
            if ($existing) {
                if ((int) $existing['id'] === (int) $me['id'] && $role !== 'admin' && (int) q("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn() <= 1) {
                    flash('You are the only team lead — make someone else team lead first.', 'error');
                    break;
                }
                q('UPDATE admins SET name = ?, phone = ?, job_title = ?, role = ?, features = ? WHERE id = ?', [
                    mb_strlen($name) >= 2 ? $name : $existing['name'], $phone !== '' ? $phone : $existing['phone'], $title !== '' ? $title : $existing['job_title'], $role, $store, $existing['id'],
                ]);
                if ($pass !== '') {
                    q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $existing['id']]);
                }
                $who = mb_strlen($name) >= 2 ? $name : $existing['name'];
                $notify = !empty($_POST['send_login']) || $existing['role'] === 'pending';
                if ($notify) {
                    $sendAccess($who, $email, $role, admin_feature_list(['role' => $role, 'features' => $store]), !empty($_POST['send_login']) && $pass !== '' ? $pass : null);
                }
                flash($who . ' (already registered) now has ' . admin_role_label($role) . ' access' . ($pass !== '' ? ' and a new password' : '') . '.' . ($notify ? ' They have been emailed.' . mail_note() : ''));
            } else {
                if (mb_strlen($name) < 2 || strlen($pass) < 8) {
                    flash('For a new person, enter their full name and a password of at least 8 characters.', 'error');
                    break;
                }
                q('INSERT INTO admins (name, email, password_hash, role, phone, job_title, features, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $phone ?: null, $title ?: null, $store, now()]);
                $sent = !empty($_POST['send_login']) && $sendAccess($name, $email, $role, admin_feature_list(['role' => $role, 'features' => $store]), $pass);
                flash('Account created for ' . $name . ' as ' . admin_role_label($role) . '. ' . ($sent ? 'Their login details have been emailed.' . mail_note() : 'Share the login page (' . base_url('admin/') . ') and the password with them.'));
            }
            break;

        case 'role':   // quick buttons for people who signed up themselves
            $id = (int) ($_POST['id'] ?? 0);
            $role = (string) ($_POST['role'] ?? '');
            $before = q('SELECT name, email, role FROM admins WHERE id = ?', [$id])->fetch();
            if (!$before || !isset($roles[$role])) {
                break;
            }
            q('UPDATE admins SET role = ?, features = NULL WHERE id = ?', [$role, $id]);
            if ($before['role'] === 'pending') {
                $sendAccess($before['name'], $before['email'], $role, role_default_features($role), null);
            }
            flash($before['name'] . ' now has access as ' . admin_role_label($role) . ($before['role'] === 'pending' ? ' — they have been emailed.' . mail_note() : '.'));
            break;

        case 'approval_mode':
            setting_set('req_approval_mode', ($_POST['mode'] ?? '') === 'two_step' ? 'two_step' : 'single');
            flash(req_approval_mode() === 'two_step' ? 'Requisitions now need two steps: operations reviews, then the team lead approves.' : 'Requisitions are now approved in one step, by operations or the team lead.');
            redirect('users.php#approvals');

        case 'signature':
            $err = save_signature((int) $me['id'], (string) ($_POST['signature_data'] ?? ''), $_FILES['signature_file'] ?? null);
            flash($err ?: 'Your signature is saved. It will be added to the documents you sign.', $err ? 'error' : 'success');
            redirect('users.php#signature');

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

$people = $manage ? q("SELECT * FROM admins WHERE role <> 'pending' ORDER BY FIELD(role, 'admin', 'operations', 'finance', 'team', 'field'), name")->fetchAll() : [];
$pendingAccounts = $manage ? q("SELECT * FROM admins WHERE role = 'pending' ORDER BY id DESC")->fetchAll() : [];
$mySig = admin_signature_path((string) q('SELECT signature_file FROM admins WHERE id = ?', [$me['id']])->fetchColumn());
$known = $manage ? array_column(q('SELECT email, name FROM admins')->fetchAll(), 'name', 'email') : [];
$assignable = array_diff_key($roles, ['pending' => 1]);

/** Role, access checkboxes and password for one person (or a new one). */
$accessFields = function (?array $a) use ($assignable, $features): string {
    $role = $a['role'] ?? 'team';
    $role = $role === 'pending' ? 'team' : $role;
    $has = admin_feature_list(['role' => $role, 'features' => $a['features'] ?? null]);
    ob_start(); ?>
    <div class="row-2">
      <label>Role <small class="muted">— what they can approve or pay</small>
        <select name="role">
          <?php foreach ($assignable as $k => [$label, $desc]): ?><option value="<?= e($k) ?>" data-features="<?= e(implode(',', role_default_features($k))) ?>" data-desc="<?= e($desc) ?>"<?= $role === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <small class="muted js-role-note"></small>
      </label>
      <label><?= $a ? 'New password <small class="muted">(leave empty to keep theirs)</small>' : 'Password <small class="muted">(min. 8 characters — you share it with them)</small>' ?>
        <input type="text" name="password" minlength="8" autocomplete="new-password" <?= $a ? '' : 'required' ?> placeholder="<?= $a ? '••••••••' : 'e.g. Kakebe#2026' ?>">
      </label>
    </div>
    <div>
      <p class="small"><b>What they can see in the system</b> <span class="muted">— ticked boxes follow the role; change any of them</span></p>
      <div class="access-features">
        <?php foreach ($features as $k => [$label, $short, $icon]): ?>
        <label><input type="checkbox" name="features[]" value="<?= e($k) ?>"<?= in_array($k, $has, true) ? ' checked' : '' ?>><i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <label class="check-inline"><input type="checkbox" name="send_login" value="1"> Email them the login link<?= $a ? ' (and the new password, if you set one)' : ' and this password' ?></label>
    <?php return (string) ob_get_clean();
};

admin_header($manage ? 'Team & access' : 'My account', 'users', $manage ? 'Add people, set their role and choose what they can see' : admin_role_label($me['role']));
?>
<?php if ($manage): ?>
<?php if ($pendingAccounts): ?>
<section class="card pending-accounts">
  <div class="card-head"><h3><i class="fa-solid fa-user-clock"></i> Signed up — waiting for access</h3><span class="badge st-pending"><?= count($pendingAccounts) ?></span></div>
  <p class="small muted">These people created their own staff account. Give them a role in one click (you can fine-tune what they see below), or remove the account.</p>
  <ul class="user-list">
    <?php foreach ($pendingAccounts as $a): ?>
    <li>
      <span class="tb-avatar"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span>
      <div><b><?= e($a['name']) ?></b><small class="block muted"><?= e($a['email']) ?> · <?= e((string) $a['phone']) ?><?= $a['job_title'] ? ' · ' . e($a['job_title']) : '' ?> · signed up <?= e(time_ago($a['created_at'])) ?></small></div>
      <div class="btn-row">
        <?php foreach (['team' => 'Team member', 'field' => 'Field staff', 'operations' => 'Operations', 'finance' => 'Finance'] as $k => $label): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="role" value="<?= $k ?>"><button class="btn btn-sm <?= $k === 'team' ? 'btn-primary' : 'btn-light' ?>" type="submit"><?= e($label) ?></button></form>
        <?php endforeach; ?>
        <form method="post" class="inline" data-confirm="Remove the account for <?= e($a['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="icon-btn danger" type="submit" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card" id="add">
  <div class="card-head"><h3><i class="fa-solid fa-user-plus"></i> Add a person or give access</h3></div>
  <p class="small muted">Type their email. If they are already registered, saving just updates their role and what they can see.</p>
  <form method="post" class="stack js-access-form"><?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="row-2">
      <label>Email<input type="email" name="email" required class="js-email-check" data-known="<?= e(json_encode((object) $known)) ?>" autocomplete="off"><small class="js-email-msg"></small></label>
      <label>Full name<input type="text" name="name" maxlength="120" placeholder="e.g. Moses Komakech"></label>
    </div>
    <div class="row-2">
      <label>Phone <small class="muted">(Mobile Money — for paying them)</small><input type="tel" name="phone" maxlength="40"></label>
      <label>Job title <small class="muted">(optional)</small><input type="text" name="job_title" maxlength="120" placeholder="e.g. Operations Manager"></label>
    </div>
    <?= $accessFields(null) ?>
    <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save access</button></div>
  </form>
</section>

<section class="card">
  <div class="card-head"><h3><i class="fa-solid fa-users-gear"></i> Team</h3><span class="muted"><?= count($people) ?> people</span></div>
  <div class="team-list">
    <?php foreach ($people as $a): $feat = admin_feature_list($a); ?>
    <div class="team-person">
      <div class="team-row">
        <span class="tb-avatar"><?= e(mb_strtoupper(mb_substr($a['name'], 0, 1))) ?></span>
        <div class="team-who">
          <b><?= e($a['name']) ?><?= (int) $a['id'] === (int) $me['id'] ? ' <small class="badge st-approved">You</small>' : '' ?></b>
          <small class="block muted"><?= e($a['email']) ?><?= $a['phone'] ? ' · ' . e($a['phone']) : '' ?><?= $a['job_title'] ? ' · ' . e($a['job_title']) : '' ?> · last login <?= $a['last_login_at'] ? e(time_ago($a['last_login_at'])) : 'never' ?><?= admin_signature_path($a['signature_file']) ? ' · <i class="fa-solid fa-signature" title="Signature saved"></i>' : '' ?></small>
          <div class="access-chips"><?php if ($a['role'] === 'admin'): ?><span>Everything</span><?php else: foreach ($feat as $k): ?><span><?= e($features[$k][1]) ?></span><?php endforeach; if (!$feat): ?><span>Nothing yet</span><?php endif; endif; ?></div>
        </div>
        <span class="role-badge <?= e($a['role']) ?>"><?= e(admin_role_label($a['role'])) ?></span>
        <?php if ((int) $a['id'] !== (int) $me['id']): ?>
        <form method="post" class="inline" data-confirm="Remove access for <?= e($a['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="icon-btn danger" type="submit" title="Remove"><i class="fa-solid fa-trash"></i></button></form>
        <?php endif; ?>
      </div>
      <details class="team-edit-box">
        <summary><i class="fa-solid fa-pen"></i> Edit role &amp; access</summary>
        <form method="post" class="stack js-access-form"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="email" value="<?= e($a['email']) ?>">
          <div class="row-2">
            <label>Full name<input type="text" name="name" value="<?= e($a['name']) ?>" maxlength="120"></label>
            <label>Phone<input type="tel" name="phone" value="<?= e((string) $a['phone']) ?>" maxlength="40"></label>
          </div>
          <?= $accessFields($a) ?>
          <div><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
        </form>
      </details>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="grid-2 align-start">
  <section class="card" id="approvals">
    <div class="card-head"><h3><i class="fa-solid fa-stamp"></i> Who approves money</h3></div>
    <ul class="mini-stats role-help">
      <li><span><b>Team lead</b> — sees everything, approves requisitions and refunds (or adjusts and approves), manages the team. Gets an email for every new request.</span></li>
      <li><span><b>Operations</b> — sees everything, approves requisitions and refunds. Gets an email for every new request and every payment.</span></li>
      <li><span><b>Finance</b> — sends the money with proof of payment, pays people directly in Spending &amp; payments, verifies accountability.</span></li>
      <li><span><b>Team member / Field staff</b> — make requisitions and refunds, account with receipts and their signature.</span></li>
    </ul>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="approval_mode">
      <label class="check-inline"><input type="radio" name="mode" value="single"<?= req_approval_mode() === 'single' ? ' checked' : '' ?>> <span><b>One step</b> — operations <i>or</i> the team lead approves</span></label>
      <label class="check-inline"><input type="radio" name="mode" value="two_step"<?= req_approval_mode() === 'two_step' ? ' checked' : '' ?>> <span><b>Two steps</b> — operations reviews, then the team lead approves</span></label>
      <div><button class="btn btn-navy btn-sm" type="submit">Save</button></div>
    </form>
  </section>
  <div class="stack-cards">
<?php else: ?>
<div class="grid-2 align-start">
  <div class="stack-cards">
<?php endif; ?>
    <div class="card" id="signature">
      <div class="card-head"><h3><i class="fa-solid fa-signature"></i> My signature</h3><?php if ($mySig): ?><span class="badge st-confirmed">Saved</span><?php endif; ?></div>
      <p class="small muted">Your signature is added to requisition and accountability documents you sign (approving, paying or accounting for money).</p>
      <?php if ($mySig): ?><div class="sig-current"><img src="signature.php?v=<?= filemtime($mySig) ?>" alt="Your saved signature"></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="stack js-sig-form"><?= csrf_field() ?><input type="hidden" name="action" value="signature">
        <div class="sig-pad"><canvas width="600" height="200" class="js-sig-canvas" aria-label="Draw your signature"></canvas><span class="sig-hint">Sign here with your finger or mouse</span></div>
        <input type="hidden" name="signature_data" class="js-sig-data">
        <div class="btn-row"><button type="button" class="btn btn-light btn-sm js-sig-clear"><i class="fa-solid fa-eraser"></i> Clear</button><span class="small muted">or upload a photo of your signature:</span><input type="file" name="signature_file" accept=".jpg,.jpeg,.png,.webp"></div>
        <div><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $mySig ? 'Replace my signature' : 'Save my signature' ?></button></div>
      </form>
    </div>
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
<?php admin_footer(['finance.js']);
