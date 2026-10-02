<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$id = (int) ($_GET['id'] ?? 0);
$r = find_registration($id);
if (!$r) {
    flash('Participant not found.', 'error');
    redirect('registrations.php');
}
$self = 'view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $notify = !empty($_POST['notify']);
    switch ($_POST['action'] ?? '') {
        case 'manual_payment':
            $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
            $method = array_key_exists($_POST['method'] ?? '', payment_methods()) ? $_POST['method'] : 'cash';
            if ($amount < 500) {
                flash('Enter a valid amount (at least UGX 500).', 'error');
                break;
            }
            if ($amount > balance($r) && !isset($_POST['allow_over'])) {
                flash('That is more than the balance (' . format_ugx(balance($r)) . '). Tick "allow overpayment" to record it anyway.', 'error');
                break;
            }
            $p = record_manual_payment($r, $amount, $method, mb_substr(trim((string) ($_POST['ref'] ?? '')), 0, 64), mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 200), (int) $admin['id'], $notify);
            flash('Payment of ' . format_ugx($amount) . ' recorded (' . receipt_no($p) . ').' . ($notify ? ' Receipt emailed to ' . $r['email'] . '.' . mail_note() : ''));
            break;

        case 'void':
            $p = payment_find((int) ($_POST['payment_id'] ?? 0));
            if ($p && (int) $p['registration_id'] === $id && $p['provider'] === 'manual' && $p['status'] === 'success') {
                q("UPDATE payments SET status = 'failed', message = ? WHERE id = ?", ['Voided by ' . $admin['name'] . ' on ' . date('j M Y'), $p['id']]);
                recompute_registration($id);
                flash('Manual payment ' . receipt_no($p) . ' voided.');
            } else {
                flash('Only successful manual payments can be voided.', 'error');
            }
            break;

        case 'recheck':
            $p = sync_payment((int) ($_POST['payment_id'] ?? 0), true);
            flash($p ? 'Payment ' . receipt_no($p) . ' is ' . $p['status'] . ($p['provider_status'] ? ' (' . ($p['provider'] === 'pesapal' ? 'Pesapal' : 'ioTec') . ': ' . $p['provider_status'] . ')' : '') . '.' : 'Payment not found.', $p ? 'success' : 'error');
            break;

        case 'resend_receipt':
            $p = payment_find((int) ($_POST['payment_id'] ?? 0));
            if ($p && $p['status'] === 'success') {
                $attach = [['name' => 'Kakebe-Receipt-' . receipt_no($p) . '.pdf', 'type' => 'application/pdf', 'data' => receipt_pdf($p)]];
                [$s, $h] = tpl_payment_receipt($r, $p);
                $ok = send_mail($r['email'], $s, $h, setting('contact_email') ?: null, $err, $attach);
                flash($ok ? 'Receipt ' . receipt_no($p) . ' emailed to ' . $r['email'] . '.' . mail_note() : 'Email failed: ' . $err, $ok ? 'success' : 'error');
            }
            break;

        case 'waive':
            q("UPDATE registrations SET payment_status = 'waived', updated_at = ? WHERE id = ?", [now(), $id]);
            recompute_registration($id);
            if ($notify) {
                [$s, $h] = tpl_ticket(find_registration($id));
                send_mail($r['email'], $s, $h, setting('contact_email') ?: null);
            }
            flash('Balance waived — participant confirmed.' . ($notify ? ' Ticket emailed.' . mail_note() : ''));
            break;

        case 'unwaive':
            q("UPDATE registrations SET payment_status = 'unpaid', updated_at = ? WHERE id = ?", [now(), $id]);
            recompute_registration($id);
            flash('Waiver removed — status recalculated from payments.');
            break;

        case 'status':
            set_registration_status($r, (string) ($_POST['status'] ?? 'auto'));
            flash('Status updated.');
            break;

        case 'edit':
            $interests = array_values(array_intersect((array) ($_POST['interests'] ?? []), interests()));
            $park = !empty($_POST['park_visit']);
            $parkAmount = $park ? ((int) $r['park_visit'] ? (int) $r['park_amount'] : fees()['park']) : 0;
            $total = (int) $r['camp_amount'] + (int) $r['jersey_amount'] + $parkAmount;
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(trim((string) $_POST['full_name'])) < 3) {
                flash('Name and a valid email are required.', 'error');
                break;
            }
            q('UPDATE registrations SET full_name = ?, email = ?, phone = ?, age = ?, gender = ?, district = ?, country = ?, jersey_size = ?, interests = ?, park_visit = ?, park_amount = ?, total_amount = ?, mentorship = ?, referred_by = ?, updated_at = ? WHERE id = ?', [
                mb_substr(trim((string) $_POST['full_name']), 0, 150), $email, mb_substr(trim((string) $_POST['phone']), 0, 40), max(10, min(60, (int) $_POST['age'])),
                in_array($_POST['gender'] ?? '', genders(), true) ? $_POST['gender'] : null, mb_substr(trim((string) $_POST['district']), 0, 100), mb_substr(trim((string) $_POST['country']), 0, 100),
                in_array($_POST['jersey_size'] ?? '', jersey_sizes(), true) ? $_POST['jersey_size'] : null, implode(', ', array_slice($interests, 0, 2)),
                (int) $park, $parkAmount, $total, (int) !empty($_POST['mentorship']), mb_substr(trim((string) ($_POST['referred_by'] ?? '')), 0, 150) ?: null, now(), $id,
            ]);
            recompute_registration($id);
            flash('Participant details saved.');
            break;

        case 'notes':
            q('UPDATE registrations SET admin_notes = ?, updated_at = ? WHERE id = ?', [mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 5000) ?: null, now(), $id]);
            flash('Notes saved.');
            break;

        case 'email':
            $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 200);
            $message = trim((string) ($_POST['message'] ?? ''));
            if ($subject === '' || $message === '') {
                flash('Please enter a subject and message.', 'error');
                break;
            }
            [$s, $h] = tpl_custom($r, $subject, $message);
            $ok = send_mail($r['email'], $s, $h, setting('contact_email') ?: null, $err);
            flash($ok ? 'Email sent to ' . $r['email'] . '.' . mail_note() : 'Email failed: ' . $err, $ok ? 'success' : 'error');
            break;

        case 'quick_email':
            [$s, $h] = match ($_POST['template'] ?? '') {
                'reminder' => tpl_balance_reminder($r),
                'ticket'   => tpl_ticket($r),
                default    => tpl_applicant_received($r),
            };
            $ok = send_mail($r['email'], $s, $h, setting('contact_email') ?: null, $err);
            flash($ok ? 'Email sent to ' . $r['email'] . '.' . mail_note() : 'Email failed: ' . $err, $ok ? 'success' : 'error');
            break;

        case 'approve_sponsorship':
            if ($r['status'] !== 'review') {
                flash('This registration is not awaiting sponsorship approval.', 'error');
                break;
            }
            $sent = approve_sponsorship($r, $notify);
            flash('Sponsorship approved — ' . $r['full_name'] . ' is confirmed.' . ($sent ? ' Ticket emailed to ' . $r['email'] . '.' . mail_note() : ''));
            break;

        case 'decline_sponsorship':
            if ($r['status'] !== 'review') {
                flash('This registration is not awaiting sponsorship approval.', 'error');
                break;
            }
            $sent = decline_sponsorship($r, trim((string) ($_POST['note'] ?? '')), $notify);
            flash('Sponsorship declined — the registration is now self-funded.' . ($sent ? ' The participant has been emailed.' . mail_note() : ''));
            break;

        case 'impersonate':
            session_regenerate_id(true);
            $_SESSION['participant_id'] = $id;
            $_SESSION['impersonated_by'] = (int) $admin['id'];
            redirect('../portal/');

        case 'send_reset':
            [$s2, $h2] = tpl_password_reset($r, password_reset_link($r, 24 * 60));
            $ok = send_mail($r['email'], $s2, $h2, setting('contact_email') ?: null, $err);
            flash($ok ? 'Password link emailed to ' . $r['email'] . ' (valid 24 hours).' . mail_note() : 'Email failed: ' . $err, $ok ? 'success' : 'error');
            break;

        case 'delete':
            delete_registration($r);
            flash('Participant ' . $r['reference'] . ' deleted.');
            redirect('registrations.php');
    }
    redirect($self);
}

$r = find_registration($id);
$payments = q('SELECT p.*, a.name AS admin_name FROM payments p LEFT JOIN admins a ON a.id = p.recorded_by WHERE p.registration_id = ? ORDER BY p.id DESC', [$id])->fetchAll();
$bal = balance($r);
$pct = paid_percent($r);
$chosen = array_map('trim', explode(',', (string) $r['interests']));
$waNum = intl_digits((string) $r['phone']);

admin_header($r['full_name'], 'registrations', $r['reference'] . ' · registered ' . date('j M Y, g:i a', strtotime($r['created_at'])));
?>
<a href="registrations.php" class="back"><i class="fa-solid fa-arrow-left"></i> All participants</a>

<section class="profile-head">
  <div class="ph-person">
    <?= avatar_html($r, 'ph-photo') ?>
    <div>
      <h2><?= e($r['full_name']) ?></h2>
      <p><span class="ref"><?= e($r['reference']) ?></span> <?= status_badge($r['status']) ?> <?= payment_badge($r['payment_status']) ?><?= $r['park_visit'] ? ' <span class="badge st-booked"><i class="fa-solid fa-water"></i> ' . e(fees()['park_name']) . '</span>' : '' ?><?= $r['mentorship'] ? ' <span class="badge pay-waived">Mentorship</span>' : '' ?></p>
      <p class="muted small"><i class="fa-regular fa-envelope"></i> <?= e($r['email']) ?> · <i class="fa-solid fa-phone"></i> <?= e($r['phone']) ?> · <i class="fa-solid fa-location-dot"></i> <?= e($r['district']) ?>, <?= e($r['country']) ?><?= $r['last_login_at'] ? ' · portal login ' . e(time_ago($r['last_login_at'])) : '' ?></p>
    </div>
  </div>
  <div class="ph-actions">
    <a class="btn btn-light btn-sm" href="<?= e(tel_link($r['phone'])) ?>"><i class="fa-solid fa-phone"></i> Call</a>
    <a class="btn btn-light btn-sm" href="https://wa.me/<?= e($waNum) ?>?text=<?= rawurlencode('Hello ' . explode(' ', $r['full_name'])[0] . ', this is the Kakebe Tech Camp team regarding your registration ' . $r['reference'] . '.') ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
    <?php if (can_remind($r)): ?><button type="button" class="btn btn-sm btn-remind js-remind" data-id="<?= $id ?>" data-wa="<?= e(participant_whatsapp_reminder_link($r)) ?>" title="Emails a payment reminder and opens WhatsApp with the message ready to send"><i class="fa-solid fa-bell"></i> Remind to pay</button><?php endif; ?>
    <a class="btn btn-primary btn-sm" href="take-payment.php?id=<?= $id ?>"><i class="fa-solid fa-hand-holding-dollar"></i> Take a payment</a>
    <a class="btn btn-light btn-sm" href="<?= e(pay_url($r)) ?>" target="_blank"><i class="fa-solid fa-link"></i> Pay page</a>
    <a class="btn btn-light btn-sm" href="../ticket.php?id=<?= $id ?>" target="_blank"><i class="fa-solid fa-ticket"></i> Ticket</a>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="impersonate"><button class="btn btn-navy btn-sm" type="submit" title="Open this participant's dashboard exactly as they see it"><i class="fa-regular fa-eye"></i> View their dashboard</button></form>
  </div>
</section>

<div class="fin-strip">
  <div><small>Package</small><b><?= e(format_ugx($r['total_amount'])) ?></b></div>
  <div><small>Paid</small><b class="ok-text"><?= e(format_ugx($r['amount_paid'])) ?></b></div>
  <div><small>Balance</small><b class="<?= $bal ? 'due' : 'ok-text' ?>"><?= e(format_ugx($bal)) ?></b></div>
  <div class="grow"><small><?= $pct ?>% paid · full payment issues the ticket</small><div class="big-progress"><i style="width: <?= $pct ?>%"></i></div></div>
</div>

<div class="view-grid">
  <div class="stack-cards">
    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-file-invoice-dollar"></i> Payments ledger</h3><span class="muted"><?= count($payments) ?> transaction<?= count($payments) === 1 ? '' : 's' ?></span></div>
      <?php if ($payments): ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Receipt</th><th>Date</th><th>Amount</th><th>Method</th><th>Status</th><th>Reference / txn</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><b><?= e(receipt_no($p)) ?></b></td>
              <td class="nowrap muted"><?= e(date('j M Y, g:i a', strtotime($p['completed_at'] ?: $p['created_at']))) ?></td>
              <td><?= money_cell((int) $p['amount'], $p['currency']) ?></td>
              <td><?= e(payment_methods()[$p['method']] ?? $p['method']) ?><small class="block muted"><?= $p['provider'] === 'manual' ? 'Recorded by ' . e($p['admin_name'] ?? 'admin') : e(payment_channel_detail($p)) ?></small></td>
              <td><?= txn_badge($p['status']) ?><?php if ($p['message'] && $p['status'] !== 'success'): ?><small class="block muted"><?= e(mb_strimwidth($p['message'], 0, 60, '…')) ?></small><?php endif; ?></td>
              <td class="small muted" title="<?= e(payment_reference($p)) ?>"><?= e(mb_strimwidth(payment_reference($p), 0, 30, '…')) ?></td>
              <td class="nowrap actions">
                <?php if ($p['status'] === 'success'): ?>
                  <a class="icon-btn" href="../receipt.php?id=<?= (int) $p['id'] ?>" target="_blank" title="Open PDF receipt"><i class="fa-solid fa-file-pdf"></i></a>
                  <form method="post" class="inline" data-confirm="Email receipt <?= e(receipt_no($p)) ?> to <?= e($r['email']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="resend_receipt"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button class="icon-btn" title="Email receipt"><i class="fa-solid fa-paper-plane"></i></button></form>
                  <?php if ($p['provider'] === 'manual'): ?><form method="post" class="inline" data-confirm="Void this manual payment? The balance will increase again."><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button class="icon-btn danger" title="Void"><i class="fa-solid fa-ban"></i></button></form><?php endif; ?>
                <?php elseif ($p['status'] === 'pending' && $p['provider'] === 'iotec'): ?>
                  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-light btn-sm"><i class="fa-solid fa-rotate"></i> Re-check</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><p class="empty-note">No payments yet.</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fa-regular fa-id-card"></i> Participant details</h3><button type="button" class="btn btn-light btn-sm" data-toggle="#editForm"><i class="fa-solid fa-pen"></i> Edit</button></div>
      <dl class="details">
        <div><dt>Full name</dt><dd><?= e($r['full_name']) ?></dd></div>
        <div><dt>Age / gender</dt><dd><?= (int) $r['age'] ?><?= $r['gender'] ? ' · ' . e($r['gender']) : '' ?></dd></div>
        <div><dt>Email</dt><dd><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a> <?= !empty($r['email_verified']) ? '<span class="badge st-confirmed"><i class="fa-solid fa-circle-check"></i> Verified</span>' : '<span class="badge st-pending">Not verified</span>' ?></dd></div>
        <div><dt>Phone</dt><dd><a href="<?= e(tel_link($r['phone'])) ?>"><?= e($r['phone']) ?></a></dd></div>
        <div><dt>District / country</dt><dd><?= e($r['district']) ?>, <?= e($r['country']) ?></dd></div>
        <div><dt>Learning tracks</dt><dd><?= e($r['interests'] ?: '—') ?></dd></div>
        <div><dt>Jersey size</dt><dd><?= e($r['jersey_size'] ?: '—') ?></dd></div>
        <div><dt><?= e(fees()['park_name']) ?> visit</dt><dd><?= $r['park_visit'] ? 'Yes (' . e(format_ugx($r['park_amount'])) . ')' : 'No' ?></dd></div>
        <div><dt>Funding</dt><dd><?= is_sponsored($r) ? 'Sponsored by ' . e($r['sponsor_name'] ?: '—') . ($r['payment_status'] === 'sponsored' ? ' (approved)' : ' (awaiting approval)') : 'Self-funded' ?><?= $r['sponsor_note'] ? '<small class="block muted">' . e($r['sponsor_note']) . '</small>' : '' ?></dd></div>
        <div><dt>Signed up with</dt><dd><?= $r['auth_provider'] === 'google' ? '<span class="badge pay-waived"><i class="fa-brands fa-google"></i> Google</span>' : 'Email link' ?><?= $r['google_sub'] && $r['auth_provider'] !== 'google' ? ' <small class="muted">(Google linked)</small>' : '' ?></dd></div>
        <div><dt>Dashboard login</dt><dd><?= implode(' · ', array_filter([$r['google_sub'] ? 'Google' : null, $r['password_hash'] ? 'Password' : null])) ?: 'Not set up yet' ?><?= $r['last_login_at'] ? ' <small class="muted">· last login ' . e(time_ago($r['last_login_at'])) . '</small>' : '' ?></dd></div>
        <div><dt>Mentorship &amp; DBIP</dt><dd><?= $r['mentorship'] ? 'Enrolled (free)' : 'Opted out' ?></dd></div>
        <div><dt>Heard via</dt><dd><?= e($r['source']) ?><?= $r['source_other'] ? ' — ' . e($r['source_other']) : '' ?></dd></div>
        <div><dt>Referred by</dt><dd><?= e($r['referred_by'] ?: '—') ?></dd></div>
        <div><dt>Fully paid on</dt><dd><?= $r['paid_at'] ? e(date('j M Y, g:i a', strtotime($r['paid_at']))) : '—' ?></dd></div>
      </dl>
      <?php if ($r['motivation']): ?><div class="motivation"><h4>Why they want to join</h4><p><?= nl2br(e($r['motivation'])) ?></p></div><?php endif; ?>

      <form method="post" id="editForm" class="stack edit-form" hidden>
        <?= csrf_field() ?><input type="hidden" name="action" value="edit">
        <div class="row-3">
          <label>Full name<input type="text" name="full_name" value="<?= e($r['full_name']) ?>" required></label>
          <label>Email<input type="email" name="email" value="<?= e($r['email']) ?>" required></label>
          <label>Phone<input type="text" name="phone" value="<?= e($r['phone']) ?>"></label>
          <label>Age<input type="number" name="age" value="<?= (int) $r['age'] ?>"></label>
          <label>Gender<select name="gender"><option value="">—</option><?php foreach (genders() as $g): ?><option <?= $r['gender'] === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></label>
          <label>Jersey size<select name="jersey_size"><option value="">—</option><?php foreach (jersey_sizes() as $s): ?><option <?= $r['jersey_size'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
          <label>District<input type="text" name="district" value="<?= e($r['district']) ?>"></label>
          <label>Country<input type="text" name="country" value="<?= e($r['country']) ?>"></label>
          <label>Referred by<input type="text" name="referred_by" value="<?= e($r['referred_by']) ?>"></label>
        </div>
        <div class="check-grid js-max2"><?php foreach (interests() as $t): ?><label class="check-inline"><input type="checkbox" name="interests[]" value="<?= e($t) ?>" <?= in_array($t, $chosen, true) ? 'checked' : '' ?>> <?= e($t) ?></label><?php endforeach; ?></div>
        <div class="check-grid">
          <label class="check-inline"><input type="checkbox" name="park_visit" value="1" <?= $r['park_visit'] ? 'checked' : '' ?>> <?= e(fees()['park_name']) ?> park visit (+<?= e(format_ugx(fees()['park'])) ?>)</label>
          <label class="check-inline"><input type="checkbox" name="mentorship" value="1" <?= $r['mentorship'] ? 'checked' : '' ?>> Mentorship &amp; Digital Bridge</label>
        </div>
        <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save details</button></div>
      </form>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fa-regular fa-paper-plane"></i> Email the participant</h3></div>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="email">
        <label>Subject<input type="text" name="subject" maxlength="200" required placeholder="e.g. Your Kakebe Tech Camp registration"></label>
        <label>Message<textarea name="message" rows="5" required placeholder="Sent in the branded Kakebe email template with the support line."></textarea></label>
        <div><button class="btn btn-navy" type="submit"><i class="fa-solid fa-paper-plane"></i> Send email</button></div>
      </form>
      <div class="resend">
        <span class="muted small">Quick send:</span>
        <?php foreach (['received' => 'Registration confirmation', 'reminder' => 'Balance reminder', 'ticket' => 'Ticket'] as $tpl => $label):
            if ($tpl === 'ticket' && $r['status'] !== 'confirmed') continue;
            if ($tpl === 'reminder' && $bal === 0) continue; ?>
        <form method="post" class="inline" data-confirm="Send the <?= e(strtolower($label)) ?> email to <?= e($r['email']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="quick_email"><input type="hidden" name="template" value="<?= $tpl ?>"><button class="chip-btn" type="submit"><i class="fa-solid fa-paper-plane"></i> <?= $label ?></button></form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="stack-cards">
    <?php if ($r['status'] === 'review'): ?>
    <div class="card approval-card">
      <div class="card-head"><h3><i class="fa-solid fa-user-check"></i> Sponsorship approval</h3><span class="badge st-pending">Awaiting approval</span></div>
      <p>This participant says their camp fees are covered by <b><?= e($r['sponsor_name'] ?: '—') ?></b><?= $r['sponsor_id'] ? '' : ' <span class="muted">(not on the sponsor list)</span>' ?>. Confirm with the sponsor, then approve or decline.</p>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve_sponsorship">
        <label class="check-inline"><input type="checkbox" name="notify" value="1" checked> Email the participant their confirmation &amp; ticket</label>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Approve sponsorship</button>
      </form>
      <form method="post" class="stack decline" data-confirm="Decline this sponsorship? The participant will be asked to pay the camp package themselves.">
        <?= csrf_field() ?><input type="hidden" name="action" value="decline_sponsorship">
        <label>Reason (included in the email)<input type="text" name="note" maxlength="250" placeholder="e.g. The sponsor could not confirm your sponsorship"></label>
        <label class="check-inline"><input type="checkbox" name="notify" value="1" checked> Email the participant</label>
        <button class="btn btn-light" type="submit"><i class="fa-solid fa-xmark"></i> Decline</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-key"></i> Dashboard access</h3></div>
      <p class="small muted"><?= $r['password_hash'] ? 'The participant has created a password.' : 'The participant has not created a password yet.' ?> Send them a secure link to set or reset it.</p>
      <form method="post" data-confirm="Email a password link to <?= e($r['email']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="send_reset"><button class="btn btn-light" type="submit"><i class="fa-solid fa-paper-plane"></i> Email password link</button></form>
    </div>

    <div class="card accent-card">
      <div class="card-head"><h3><i class="fa-solid fa-cash-register"></i> Record a payment</h3><span class="muted small">cash, bank or direct MoMo</span></div>
      <?php if ($bal > 0): ?>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="manual_payment">
        <div class="row-2">
          <label>Amount (UGX)<input type="text" inputmode="numeric" name="amount" value="<?= number_format($bal) ?>" required></label>
          <label>Method<select name="method"><?php foreach (['cash', 'mobile_money', 'bank', 'other'] as $m): ?><option value="<?= $m ?>"><?= e(payment_methods()[$m]) ?></option><?php endforeach; ?></select></label>
        </div>
        <label>Transaction / receipt reference<input type="text" name="ref" maxlength="64" placeholder="e.g. MoMo ID or bank slip no."></label>
        <label>Note<input type="text" name="notes" maxlength="200" placeholder="Optional"></label>
        <label class="check-inline"><input type="checkbox" name="notify" value="1" checked> Email the PDF receipt to the participant</label>
        <label class="check-inline"><input type="checkbox" name="allow_over" value="1"> Allow overpayment</label>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus"></i> Record payment</button>
      </form>
      <?php else: ?>
        <p class="ok-text"><i class="fa-solid fa-circle-check"></i> Fully paid<?= $r['payment_status'] === 'waived' ? ' (waived)' : '' ?>.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-list-check"></i> Status</h3></div>
      <p class="small muted">Status updates automatically from payments (Registered → Confirmed once fully paid). Override it here if needed.</p>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="status">
        <select name="status">
          <option value="auto">Automatic (from payments)</option>
          <option value="waitlisted" <?= $r['status'] === 'waitlisted' ? 'selected' : '' ?>>Waitlisted</option>
          <option value="cancelled" <?= $r['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <button class="btn btn-light" type="submit"><i class="fa-solid fa-floppy-disk"></i> Update status</button>
      </form>
      <?php if ($r['payment_status'] !== 'waived' && $bal > 0): ?>
      <form method="post" class="stack waive" data-confirm="Waive the remaining <?= e(format_ugx($bal)) ?> and confirm this participant (e.g. sponsored)?">
        <?= csrf_field() ?><input type="hidden" name="action" value="waive">
        <label class="check-inline"><input type="checkbox" name="notify" value="1" checked> Email their ticket</label>
        <button class="btn btn-light" type="submit"><i class="fa-solid fa-hand-holding-heart"></i> Waive balance (sponsored)</button>
      </form>
      <?php elseif ($r['payment_status'] === 'waived'): ?>
      <form method="post" class="stack waive"><?= csrf_field() ?><input type="hidden" name="action" value="unwaive"><button class="btn btn-light" type="submit"><i class="fa-solid fa-rotate-left"></i> Remove waiver</button></form>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-box"></i> Package</h3></div>
      <ul class="pkg-lines">
        <?php foreach (order_items($r) as [$label, $amt, $tag]): ?><li><span><?= e($label) ?> <em><?= e($tag) ?></em></span><b><?= $amt ? e(format_ugx($amt)) : 'FREE' ?></b></li><?php endforeach; ?>
        <li class="total"><span>Total</span><b><?= e(format_ugx($r['total_amount'])) ?></b></li>
      </ul>
      <div class="copy-field"><input type="text" readonly value="<?= e(pay_url($r)) ?>" id="payLink"><button type="button" class="btn btn-light btn-sm" data-copy="#payLink" title="Copy payment link"><i class="fa-regular fa-copy"></i></button></div>
      <p class="small muted">Send this link to the participant to pay online.</p>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fa-regular fa-note-sticky"></i> Internal notes</h3></div>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="notes">
        <textarea name="admin_notes" rows="4" placeholder="Visible to admins only"><?= e($r['admin_notes']) ?></textarea>
        <button class="btn btn-light" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save notes</button>
      </form>
    </div>

    <div class="card muted-card">
      <div class="card-head"><h3><i class="fa-solid fa-circle-info"></i> Technical</h3></div>
      <dl class="details one"><div><dt>IP address</dt><dd><?= e($r['ip'] ?: '—') ?></dd></div><div><dt>Browser</dt><dd class="small"><?= e($r['user_agent'] ?: '—') ?></dd></div></dl>
      <form method="post" data-confirm="Permanently delete this participant? Successful payments are kept in the finance records."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-danger btn-sm" type="submit"><i class="fa-solid fa-trash"></i> Delete participant</button></form>
    </div>
  </div>
</div>
<?php admin_footer();
