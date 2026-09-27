<?php
require __DIR__ . '/_init.php';
$r = require_participant();
$flash = portal_flash();
$bal = balance($r);
$photo = participant_photo_url($r);
$pct = paid_percent($r);
$dep = fees()['deposit_pct'];
$steps = [
    ['fa-pen-to-square', 'Registered', date('j M', strtotime($r['created_at'])), true],
    ['fa-bookmark', 'Slot booked', $dep . '% paid', $pct >= $dep],
    ['fa-circle-check', 'Fully paid', format_ugx($r['total_amount']), $bal === 0],
    ['fa-campground', 'Camp', '14 Dec 2026', date('Y-m-d') >= camp()['start_date']],
];

app_header('My portal', '../', 'portal');
?>
<?php if ($flash): ?><div class="notice <?= $flash[0] === 'ok' ? 'ok' : 'warn' ?>"><i class="fa-solid fa-circle-info"></i> <?= e($flash[1]) ?></div><?php endif; ?>

<section class="portal-hero">
  <div class="ph-left">
    <?php if ($photo): ?><img class="ph-avatar" src="<?= e($photo) ?>" alt=""><?php else: ?><span class="ph-avatar"><?= e(initials($r['full_name'])) ?></span><?php endif; ?>
    <div>
      <h1>Hi <?= e(explode(' ', $r['full_name'])[0]) ?> 👋</h1>
      <p><?= e($r['reference']) ?> · <?= e(statuses()[$r['status']] ?? $r['status']) ?> · Kakebe Tech Camp 2026</p>
    </div>
  </div>
  <a href="profile.php" class="btn btn-ghost btn-sm"><i class="fa-solid fa-user-pen"></i> Edit profile &amp; photo</a>
</section>

<div class="stats">
  <div class="stat"><small>Camp package</small><b><?= e(format_ugx($r['total_amount'])) ?></b></div>
  <div class="stat"><small>Paid so far</small><b class="ok-text"><?= e(format_ugx($r['amount_paid'])) ?></b></div>
  <div class="stat"><small>Balance</small><b class="<?= $bal ? 'due' : 'ok-text' ?>"><?= e(format_ugx($bal)) ?></b></div>
  <div class="stat"><small>Status</small><b><?= e(statuses()[$r['status']] ?? $r['status']) ?></b></div>
</div>

<section class="app-card">
  <div class="steps-line">
    <?php foreach ($steps as [$icon, $label, $sub, $done]): ?>
    <div class="sl <?= $done ? 'done' : '' ?>"><span><i class="fa-solid <?= $icon ?>"></i></span><b><?= e($label) ?></b><small><?= e($sub) ?></small></div>
    <?php endforeach; ?>
  </div>
</section>

<div class="app-grid">
  <section class="app-card">
    <?php if ($bal > 0 && $r['status'] !== 'waitlisted'): ?>
      <h3><i class="fa-solid fa-wallet"></i> Make a payment</h3>
      <p class="muted"><?= (int) $r['amount_paid'] < deposit_amount($r) ? 'Pay at least ' . e(format_ugx(min_payment($r))) . ' to book your slot, or pay the full balance.' : 'Your slot is booked — clear the balance any time before camp.' ?></p>
      <?= pay_form($r, '../', false) ?>
    <?php elseif ($bal === 0): ?>
      <div class="done-box">
        <span><i class="fa-solid fa-ticket"></i></span>
        <h2>Your place is confirmed! 🎉</h2>
        <p class="muted">Bring your ticket (printed or on your phone) to check-in on 14th December.</p>
        <a class="btn btn-primary" href="<?= e(ticket_url($r)) ?>" target="_blank"><i class="fa-solid fa-ticket"></i> Open my camp ticket</a>
      </div>
    <?php else: ?>
      <div class="notice warn"><i class="fa-solid fa-hourglass-half"></i> You are on the waitlist. We will contact you as soon as a place opens up.</div>
    <?php endif; ?>
  </section>

  <aside class="app-side">
    <div class="app-card">
      <h3><i class="fa-solid fa-receipt"></i> Your package</h3>
      <?= package_card($r) ?>
    </div>
    <div class="app-card">
      <h3><i class="fa-solid fa-file-invoice"></i> Payments &amp; receipts</h3>
      <?= payments_list($r, '../') ?>
    </div>
  </aside>
</div>

<div class="app-grid">
  <section class="app-card">
    <h3><i class="fa-solid fa-campground"></i> Camp details</h3>
    <ul class="info-list">
      <li><i class="fa-regular fa-calendar"></i><div><b><?= e(camp()['dates']) ?></b> · 10 days, residential</div></li>
      <li><i class="fa-solid fa-location-dot"></i><div><b><?= e(camp()['venue']) ?></b></div></li>
      <li><i class="fa-solid fa-lightbulb"></i><div>Your tracks: <b><?= e($r['interests'] ?: '—') ?></b></div></li>
      <li><i class="fa-solid fa-shirt"></i><div>Jersey size <b><?= e($r['jersey_size'] ?: '—') ?></b> · camp shirt included free</div></li>
      <li><i class="fa-solid fa-water"></i><div><?= e(fees()['park_name']) ?> visit: <b><?= $r['park_visit'] ? 'Yes — included' : 'Not added' ?></b> <a href="profile.php">change</a></div></li>
    </ul>
  </section>
  <aside class="app-side">
    <div class="app-card">
      <h3><i class="fa-solid fa-people-arrows"></i> Mentorship &amp; Digital Bridge</h3>
      <?php if ($r['mentorship']): ?>
        <p>You're enrolled — <b>free</b> — in the Kakebe Mentorship Program and Digital Bridge Internship (<?= e(camp()['mentorship']) ?>) with experienced industry professionals.</p>
        <ul class="info-list"><li><i class="fa-solid fa-wifi"></i><div>Online sessions every <b>Monday, 8:00 – 9:30 PM</b></div></li></ul>
      <?php else: ?>
        <p class="muted">You opted out of the free mentorship programme. <a href="profile.php">Join now</a></p>
      <?php endif; ?>
    </div>
    <div class="app-card">
      <h3><i class="fa-solid fa-headset"></i> Need help?</h3>
      <p>Call or WhatsApp our support line <a href="<?= e(tel_link(setting('contact_phone'))) ?>"><?= e(setting('contact_phone')) ?></a>.</p>
    </div>
  </aside>
</div>
<?php app_footer('../');
