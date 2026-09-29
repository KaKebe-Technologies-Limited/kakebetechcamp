<?php
/**
 * Checkout page — pay now or come back later.
 * Opened from the signed link in emails/after registration, or by looking up email + phone.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/app_layout.php';

$r = null;
$ref = (string) ($_GET['ref'] ?? '');
if ($ref !== '' && sign_valid('pay', $ref, $_GET['t'] ?? null)) {
    $r = find_registration_by_ref($ref);
}
$justRegistered = isset($_GET['new']);

app_header($r ? 'My registration' : 'Find my registration');
?>
<?php if (!$r): ?>
  <section class="app-card narrow">
    <div class="card-icon"><i class="fa-solid fa-id-badge"></i></div>
    <h1>My registration</h1>
    <p class="muted">Enter the email and phone number you registered with to view your registration, pay and download your ticket.</p>
    <form class="stack js-lookup" action="api/lookup.php" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="l_email">Email address</label><input id="l_email" name="email" type="email" required autocomplete="email" placeholder="you@example.com"></div>
      <div class="field"><label for="l_phone">Phone number</label><input id="l_phone" name="phone" type="tel" required autocomplete="tel" placeholder="e.g. 0772 123 456"></div>
      <div class="form-alert" hidden></div>
      <button class="btn btn-primary btn-block" type="submit"><span class="btn-label">Find my registration <i class="fa-solid fa-arrow-right"></i></span><span class="btn-loading"><span class="spinner"></span> Searching…</span></button>
    </form>
    <p class="center muted small">Not registered yet? <a href="./#register">Register for the camp</a></p>
  </section>
<?php else: ?>
  <div class="app-grid">
    <section class="app-card">
      <?php if ($justRegistered): ?><div class="notice ok"><i class="fa-solid fa-circle-check"></i> Registration received — a confirmation has been sent to <b><?= e($r['email']) ?></b>.</div><?php endif; ?>
      <h1>Hi <?= e(explode(' ', $r['full_name'])[0]) ?> 👋</h1>
      <?php if ($r['status'] === 'review'): ?>
        <div class="notice"><i class="fa-solid fa-hourglass-half"></i> Your sponsorship by <b><?= e($r['sponsor_name'] ?: 'your sponsor') ?></b> is being reviewed. We'll email you as soon as it is approved — there is nothing to pay.</div>
        <p class="muted">Meanwhile you can <a href="portal/">open your participant dashboard</a> to check your details and learning tracks.</p>
      <?php elseif (balance($r) > 0): ?>
        <p class="muted">Pay your camp package in full to confirm your place — your camp ticket is emailed straight away.</p>
        <?= pay_form($r) ?>
        <p class="later"><i class="fa-regular fa-clock"></i> <b>Prefer to pay later?</b> That's fine — your registration is saved. Log in to <a href="portal/">your dashboard</a> any time (with Google or your email) or use the payment link we emailed you.</p>
      <?php else: ?>
        <div class="done-box">
          <span><i class="fa-solid fa-champagne-glasses"></i></span>
          <h2><?= is_covered($r) ? 'Your place is confirmed' : 'You are fully paid' ?></h2>
          <p>Your place at Kakebe Tech Camp 2026 is confirmed.</p>
          <a class="btn btn-primary" href="<?= e(ticket_url($r)) ?>" target="_blank"><i class="fa-solid fa-ticket"></i> View my camp ticket</a>
        </div>
      <?php endif; ?>
    </section>
    <aside class="app-side">
      <?php if ($waGroup = whatsapp_group_link()): ?>
      <a class="app-card wa-group-card" href="<?= e($waGroup) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i><span><b>Join the Tech Camp WhatsApp group</b><small>Updates and announcements — tap to join</small></span></a>
      <?php endif; ?>
      <div class="app-card flyer-card">
        <h3>📸 “I will be there” flyer</h3>
        <p class="small muted">Upload your best photo and get a flyer with your name to share with friends.</p>
        <a class="btn btn-ghost btn-block" href="flyer.php"><i class="fa-solid fa-image"></i> Make my flyer</a>
      </div>
      <div class="app-card">
        <h3><i class="fa-solid fa-receipt"></i> Your camp package</h3>
        <?= package_card($r) ?>
      </div>
      <div class="app-card">
        <h3><i class="fa-solid fa-clock-rotate-left"></i> Payments</h3>
        <?= payments_list($r) ?>
      </div>
    </aside>
  </div>
<?php endif; ?>
<?php app_footer();
