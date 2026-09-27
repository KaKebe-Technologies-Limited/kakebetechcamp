<?php
require __DIR__ . '/_init.php';
$r = require_participant();
$flash = portal_flash();
$bal = balance($r);
$photo = participant_photo_url($r);
$pct = paid_percent($r);
$dep = fees()['deposit_pct'];
$confirmed = $r['status'] === 'confirmed';
$review = $r['status'] === 'review';
$tracks = array_values(array_filter(array_map('trim', explode(',', (string) $r['interests']))));
$receipts = db()->prepare("SELECT * FROM payments WHERE registration_id = ? AND status = 'success' ORDER BY id DESC");
$receipts->execute([$r['id']]);
$receipts = $receipts->fetchAll();

$steps = is_sponsored($r)
    ? [['Registered', date('j M', strtotime($r['created_at'])), true], ['Sponsor confirmed', $r['sponsor_name'] ?: 'Sponsor', $r['payment_status'] === 'sponsored'], ['Ticket ready', 'PDF ticket', $confirmed], ['Camp', '14 Dec 2026', date('Y-m-d') >= camp()['start_date']]]
    : [['Registered', date('j M', strtotime($r['created_at'])), true], ['Place secured', $dep . '% paid', $pct >= $dep], ['Ticket ready', 'Fully paid', $confirmed], ['Camp', '14 Dec 2026', date('Y-m-d') >= camp()['start_date']]];

app_header('My dashboard', '../', 'portal');
?>
<?php if (!empty($_SESSION['impersonated_by'])): ?>
  <div class="notice warn"><i class="fa-solid fa-user-secret"></i> You are viewing this dashboard as an administrator. <a href="../admin/view.php?id=<?= (int) $r['id'] ?>">Back to the control panel</a></div>
<?php endif; ?>
<?php if ($flash): ?><div class="notice <?= $flash[0] === 'ok' ? 'ok' : 'warn' ?>"><i class="fa-solid fa-circle-info"></i> <?= e($flash[1]) ?></div><?php endif; ?>
<?php if (!$r['password_hash'] && !$r['google_sub'] && empty($_SESSION['impersonated_by'])): ?>
  <div class="notice"><i class="fa-solid fa-key"></i> Create a password so you can log in from any device. <a href="profile.php#password">Create password</a></div>
<?php endif; ?>

<section class="portal-hero">
  <div class="ph-left">
    <?php if ($photo): ?><img class="ph-avatar" src="<?= e($photo) ?>" alt=""><?php else: ?><span class="ph-avatar"><?= e(initials($r['full_name'])) ?></span><?php endif; ?>
    <div>
      <h1>Hi <?= e(explode(' ', $r['full_name'])[0]) ?></h1>
      <p><?= e($r['reference']) ?> · <?= e(statuses()[$r['status']] ?? $r['status']) ?> · Kakebe Tech Camp 2026</p>
    </div>
  </div>
  <div class="ph-actions">
    <?php if ($confirmed): ?><a href="../ticket.php?me=1&amp;format=pdf&amp;download=1" class="btn btn-primary btn-sm"><i class="fa-solid fa-file-pdf"></i> Download ticket</a><?php endif; ?>
    <a href="profile.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-user-pen"></i> Edit profile</a>
  </div>
</section>

<section class="app-card">
  <div class="steps-line">
    <?php foreach ($steps as [$label, $sub, $done]): ?>
    <div class="sl <?= $done ? 'done' : '' ?>"><span><?= $done ? '<i class="fa-solid fa-check"></i>' : '' ?></span><b><?= e($label) ?></b><small><?= e($sub) ?></small></div>
    <?php endforeach; ?>
  </div>
</section>

<div class="app-grid">
  <section class="stack-col">
    <div class="app-card">
      <h3>My profile</h3>
      <div class="profile-grid">
        <div><small>Full name</small><b><?= e($r['full_name']) ?></b></div>
        <div><small>Email</small><b><?= e($r['email']) ?></b></div>
        <div><small>Phone</small><b><?= e($r['phone']) ?></b></div>
        <div><small>Age / gender</small><b><?= (int) $r['age'] ?><?= $r['gender'] ? ' · ' . e($r['gender']) : '' ?></b></div>
        <div><small>District</small><b><?= e($r['district']) ?>, <?= e($r['country']) ?></b></div>
        <div><small>Jersey size</small><b><?= e($r['jersey_size'] ?: '—') ?></b></div>
      </div>
      <h4 class="sub-h">My learning tracks</h4>
      <div class="track-tags"><?php foreach ($tracks as $t): ?><span><?= e($t) ?></span><?php endforeach; ?><?php if (!$tracks): ?><span class="muted">None chosen yet</span><?php endif; ?></div>
      <p class="small muted" style="margin-top:14px;"><?= e(fees()['park_name']) ?> excursion: <b><?= $r['park_visit'] ? 'Yes' : 'No' ?></b> · Mentorship &amp; Digital Bridge: <b><?= $r['mentorship'] ? 'Enrolled (free)' : 'Not enrolled' ?></b></p>
      <a href="profile.php" class="btn btn-ghost btn-sm">Update profile, photo or tracks</a>
    </div>

    <div class="app-card">
      <?php if ($review): ?>
        <h3>Sponsorship</h3>
        <div class="notice"><i class="fa-solid fa-hourglass-half"></i> Your sponsorship by <b><?= e($r['sponsor_name'] ?: 'your sponsor') ?></b> is being reviewed. We'll email you once it is approved — there is nothing to pay.</div>
      <?php elseif ($confirmed): ?>
        <div class="done-box">
          <h2>Your place is confirmed</h2>
          <p class="muted"><?= $r['payment_status'] === 'sponsored' ? 'Sponsored by ' . e($r['sponsor_name'] ?: 'your sponsor') . '. ' : '' ?>Bring your ticket (printed or on your phone) to check-in on 14th December.</p>
          <div class="btn-row">
            <a class="btn btn-primary" href="../ticket.php?me=1&amp;format=pdf&amp;download=1"><i class="fa-solid fa-file-pdf"></i> Download PDF ticket</a>
            <a class="btn btn-ghost" href="<?= e(ticket_url($r)) ?>" target="_blank">View online</a>
          </div>
        </div>
      <?php elseif ($bal > 0 && $r['status'] !== 'waitlisted'): ?>
        <h3>Secure your place</h3>
        <p class="muted"><?= (int) $r['amount_paid'] < deposit_amount($r) ? 'Complete at least ' . e(format_ugx(min_payment($r))) . ' to secure your place, or the full balance.' : 'Your place is secured — clear the balance any time before camp.' ?></p>
        <?= pay_form($r, '../', false) ?>
      <?php else: ?>
        <div class="notice warn"><i class="fa-solid fa-hourglass-half"></i> You are on the waitlist. We will contact you as soon as a place opens up.</div>
      <?php endif; ?>
    </div>
  </section>

  <aside class="app-side">
    <div class="app-card">
      <h3>My ticket</h3>
      <?php if ($confirmed): ?>
        <p class="small">Your camp ticket is ready.</p>
        <a class="btn btn-primary btn-block" href="../ticket.php?me=1&amp;format=pdf&amp;download=1"><i class="fa-solid fa-file-pdf"></i> Download PDF ticket</a>
      <?php else: ?>
        <p class="small muted"><?= $review ? 'Available once your sponsorship is approved.' : 'Available once your camp package is complete.' ?></p>
      <?php endif; ?>
    </div>
    <?php if (!is_covered($r) && !$review): ?>
    <div class="app-card">
      <h3>Camp package</h3>
      <?= package_card($r) ?>
    </div>
    <?php endif; ?>
    <div class="app-card">
      <h3>Receipts</h3>
      <?php if ($receipts): ?>
        <ul class="pay-list">
          <?php foreach ($receipts as $p): ?>
          <li><div><b><?= e(format_ugx($p['amount'], $p['currency'])) ?></b><small><?= e(date('j M Y', strtotime($p['completed_at'] ?: $p['created_at']))) ?></small></div><a class="pl-link" href="<?= e(receipt_url($p)) ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> <?= e(receipt_no($p)) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><p class="small muted"><?= is_covered($r) ? 'No payments needed — your fees are covered.' : 'No payments yet.' ?></p><?php endif; ?>
    </div>
    <div class="app-card">
      <h3>Camp details</h3>
      <ul class="info-list plain">
        <li><b><?= e(camp()['dates']) ?></b> · 10 days, residential</li>
        <li><b><?= e(camp()['venue']) ?></b></li>
        <li>Mentorship online every <b>Monday, 8:00 – 9:30 PM</b> (Oct – Nov)</li>
        <li>Help: <a href="<?= e(tel_link(setting('contact_phone'))) ?>"><?= e(setting('contact_phone')) ?></a> · <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></li>
      </ul>
    </div>
  </aside>
</div>
<?php app_footer('../');
