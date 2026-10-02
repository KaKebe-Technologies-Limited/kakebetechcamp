<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$one = fn(string $sql, array $p = []) => q($sql, $p)->fetchColumn();
$all = fn(string $sql, array $p = []) => q($sql, $p)->fetchAll();
$f = fees();

$active     = (int) $one("SELECT COUNT(*) FROM registrations WHERE status <> 'cancelled'");
$today      = (int) $one('SELECT COUNT(*) FROM registrations WHERE created_at >= ?', [date('Y-m-d 00:00:00')]);
$week       = (int) $one('SELECT COUNT(*) FROM registrations WHERE created_at >= ?', [date('Y-m-d 00:00:00', strtotime('-6 days'))]);
$booked     = (int) $one("SELECT COUNT(*) FROM registrations WHERE status = 'confirmed'");
$fullyPaid  = (int) $one("SELECT COUNT(*) FROM registrations WHERE payment_status IN ('paid','waived') AND status <> 'cancelled'");
$unpaid     = (int) $one("SELECT COUNT(*) FROM registrations WHERE payment_status = 'unpaid' AND status <> 'cancelled'");
$collected  = (int) $one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND purpose = 'camp'");
$sponsorAmt = (int) $one("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND purpose = 'sponsorship'");
$expected   = (int) $one("SELECT COALESCE(SUM(total_amount),0) FROM registrations WHERE status <> 'cancelled' AND payment_status <> 'waived'");
$outstanding = max(0, $expected - (int) $one("SELECT COALESCE(SUM(amount_paid),0) FROM registrations WHERE status <> 'cancelled' AND payment_status <> 'waived'"));
$parkCount  = (int) $one("SELECT COUNT(*) FROM registrations WHERE park_visit = 1 AND status <> 'cancelled'");
$pendingPay = (int) $one("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
$unread     = (int) $one('SELECT COUNT(*) FROM messages WHERE is_read = 0');
$testMoney  = (int) $one("SELECT COUNT(*) FROM payments WHERE status = 'success' AND currency <> 'UGX'");
$capacity   = max(1, (int) setting('camp_capacity', 300));
$seatsTaken = seats_taken();
$capPct     = min(100, round($seatsTaken / $capacity * 100));

$regSeries = days_series('SELECT DATE(created_at) d, COUNT(*) v FROM registrations WHERE created_at >= ? GROUP BY DATE(created_at)');
$paySeries = days_series("SELECT DATE(completed_at) d, SUM(amount) v FROM payments WHERE status = 'success' AND completed_at >= ? GROUP BY DATE(completed_at)");

$sizes = array_fill_keys(jersey_sizes(), 0);
foreach ($all("SELECT jersey_size s, COUNT(*) c FROM registrations WHERE status <> 'cancelled' AND jersey_size IS NOT NULL GROUP BY jersey_size") as $row) {
    $sizes[$row['s']] = (int) $row['c'];
}
$trackCounts = array_fill_keys(interests(), 0);
foreach ($all("SELECT interests FROM registrations WHERE status <> 'cancelled' AND interests IS NOT NULL") as $row) {
    foreach (array_map('trim', explode(',', $row['interests'])) as $t) {
        if (isset($trackCounts[$t])) $trackCounts[$t]++;
    }
}
arsort($trackCounts);
$trackItems = array_map(fn($k, $v) => ['label' => $k, 'c' => $v], array_keys($trackCounts), $trackCounts);

$sources   = $all("SELECT source label, COUNT(*) c FROM registrations WHERE status <> 'cancelled' GROUP BY source ORDER BY c DESC");
$genders   = $all("SELECT COALESCE(NULLIF(gender, ''), 'Not stated') label, COUNT(*) c FROM registrations WHERE status <> 'cancelled' GROUP BY label ORDER BY c DESC");
$districts = $all("SELECT MIN(district) label, COUNT(*) c FROM registrations WHERE status <> 'cancelled' GROUP BY LOWER(TRIM(district)) ORDER BY c DESC LIMIT 8");
$referrers = $all("SELECT MIN(referred_by) label, COUNT(*) c FROM registrations WHERE referred_by IS NOT NULL AND TRIM(referred_by) <> '' GROUP BY LOWER(TRIM(referred_by)) ORDER BY c DESC LIMIT 10");
$recent    = $all('SELECT * FROM registrations ORDER BY id DESC LIMIT 8');
$recentPay = $all("SELECT p.*, r.full_name, r.reference, d.donor_name FROM payments p LEFT JOIN registrations r ON r.id = p.registration_id LEFT JOIN donations d ON d.id = p.donation_id WHERE p.status = 'success' ORDER BY p.completed_at DESC LIMIT 8");
$emailReady = setting('mail_transport', 'log') !== 'log' && trim((string) setting('notify_emails')) !== '';

// Outreach: email contacts, campaign emails, mentorship
$mk = mk_contact_stats();
$mkSends = q("SELECT COUNT(*) sent, COALESCE(SUM(opened_at IS NOT NULL), 0) opened FROM mk_sends WHERE status = 'sent'")->fetch();
$mkRate = $mkSends['sent'] ? round($mkSends['opened'] / $mkSends['sent'] * 100, 1) : 0;
$notifSent = (int) $one("SELECT COUNT(*) FROM email_log WHERE status = 'sent'");
$mentees = array_column($all('SELECT status, COUNT(*) c FROM mentorship_registrations GROUP BY status'), 'c', 'status');
$flyers = $all('SELECT id, name, reference, updated_at FROM flyers ORDER BY updated_at DESC LIMIT 6');
$flyerCount = (int) $one('SELECT COUNT(*) FROM flyers');

admin_header('Dashboard', 'dashboard', 'Kakebe Tech Camp 2026 · ' . camp()['dates']);
?>
<?php if (!$emailReady): ?>
<div class="alert alert-warning"><i class="fa-solid fa-envelope-circle-check"></i> <span>Email sending is switched off — emails are only logged. <a href="settings.php#email">Turn on Gmail notifications →</a></span></div>
<?php endif; ?>
<?php $awaitingApproval = (int) $one("SELECT COUNT(*) FROM registrations WHERE status = 'review'"); if ($awaitingApproval): ?>
<div class="alert alert-warning"><i class="fa-solid fa-user-check"></i> <span><b><?= $awaitingApproval ?></b> sponsored registration<?= $awaitingApproval === 1 ? ' is' : 's are' ?> waiting for your approval. <a href="sponsors.php">Review sponsorships →</a></span></div>
<?php endif; ?>
<?php if ($testMoney): ?>
<div class="alert alert-info"><i class="fa-solid fa-flask"></i> <span>Some payments below were made in <b>ioTec sandbox (test) mode</b> — no real money. They are marked "ITX".</span></div>
<?php endif; ?>

<section class="welcome">
  <div>
    <h2>Hello, <?= e(explode(' ', $admin['name'])[0]) ?> 👋</h2>
    <p><?= $today ?> new registration<?= $today === 1 ? '' : 's' ?> today · <?= $week ?> this week · <?= $pendingPay ?> payment<?= $pendingPay === 1 ? '' : 's' ?> awaiting confirmation</p>
  </div>
  <div class="welcome-actions">
    <a href="registrations.php?payment=balance" class="btn btn-primary"><i class="fa-solid fa-bell"></i> Follow up balances</a>
    <a href="finance.php" class="btn btn-light"><i class="fa-solid fa-chart-pie"></i> Finance</a>
    <a href="export.php" class="btn btn-light"><i class="fa-solid fa-file-csv"></i> Export</a>
  </div>
</section>

<div class="kpis six">
  <a class="kpi" href="registrations.php?status=active"><span class="kpi-icon red"><i class="fa-solid fa-users"></i></span><div><small>Registered</small><b><?= number_format($active) ?></b><em>+<?= $week ?> in 7 days</em></div></a>
  <a class="kpi" href="registrations.php?status=confirmed"><span class="kpi-icon blue"><i class="fa-solid fa-ticket"></i></span><div><small>Seats confirmed</small><b><?= number_format($booked) ?></b><em><?= $capPct ?>% of <?= $capacity ?> seats</em></div></a>
  <a class="kpi" href="registrations.php?payment=paid"><span class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></span><div><small>Fully paid</small><b><?= number_format($fullyPaid) ?></b><em><?= $unpaid ?> not paid yet</em></div></a>
  <a class="kpi" href="payments.php?status=success"><span class="kpi-icon green"><i class="fa-solid fa-sack-dollar"></i></span><div><small>Camp fees collected</small><b><?= e(format_ugx($collected)) ?></b><em>of <?= e(format_ugx($expected)) ?> expected</em></div></a>
  <a class="kpi" href="registrations.php?payment=balance"><span class="kpi-icon amber"><i class="fa-solid fa-scale-unbalanced"></i></span><div><small>Outstanding balances</small><b><?= e(format_ugx($outstanding)) ?></b><em>to be collected</em></div></a>
  <a class="kpi" href="sponsors.php"><span class="kpi-icon purple"><i class="fa-solid fa-hand-holding-heart"></i></span><div><small>Sponsorships</small><b><?= e(format_ugx($sponsorAmt)) ?></b><em>donations received</em></div></a>
</div>

<div class="kpis">
  <a class="kpi" href="marketing-contacts.php"><span class="kpi-icon blue"><i class="fa-solid fa-address-book"></i></span><div><small>Email contacts</small><b><?= number_format($mk['total']) ?></b><em><?= number_format($mk['valid']) ?> ready to email</em></div></a>
  <a class="kpi" href="marketing.php"><span class="kpi-icon green"><i class="fa-solid fa-paper-plane"></i></span><div><small>Emails sent</small><b><?= number_format((int) $mkSends['sent']) ?></b><em><?= number_format(mk_sent_last_24h()) ?> today · <?= number_format($notifSent) ?> notifications</em></div></a>
  <a class="kpi" href="marketing.php"><span class="kpi-icon navy"><i class="fa-solid fa-check-double mk-blue"></i></span><div><small>Emails opened</small><b><?= number_format((int) $mkSends['opened']) ?></b><em><?= $mkRate ?>% open rate</em></div></a>
  <a class="kpi" href="mentorship.php"><span class="kpi-icon purple"><i class="fa-solid fa-handshake-angle"></i></span><div><small>Mentorship &amp; DBIP</small><b><?= number_format((int) ($mentees['confirmed'] ?? 0)) ?></b><em><?= number_format((int) ($mentees['pending'] ?? 0)) ?> awaiting email confirmation</em></div></a>
</div>

<?= traffic_section(7) ?>

<?php if (ga_connected() && ($ga = ga_summary(7))): $gaNow = ga_realtime_users(); ?>
<a class="card ga-strip" href="analytics.php?days=7">
  <span class="ga-strip-title"><i class="fa-brands fa-google"></i> Google Analytics — last 7 days</span>
  <span><small>Visitors</small><b><?= number_format($ga['current']['activeUsers']) ?></b></span>
  <span><small>Page views</small><b><?= number_format($ga['current']['screenPageViews']) ?></b></span>
  <span><small>New visitors</small><b><?= number_format($ga['current']['newUsers']) ?></b></span>
  <span><small>On the site now</small><b><?= $gaNow === null ? '—' : number_format($gaNow) ?></b></span>
  <span class="link">Website analytics →</span>
</a>
<?php endif; ?>

<div class="grid-3-1">
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-chart-column"></i> Registrations — last 30 days</h3><span class="muted"><?= array_sum($regSeries) ?> total</span></div>
    <?= bar_chart($regSeries) ?>
  </div>
  <div class="card capacity-card">
    <div class="card-head"><h3><i class="fa-solid fa-campground"></i> Camp capacity</h3></div>
    <div class="ring" style="--p: <?= $capPct ?>"><div><b><?= $capPct ?>%</b><small><?= $seatsTaken ?> / <?= $capacity ?> seats taken</small></div></div>
    <ul class="mini-stats">
      <li><span><i class="fa-solid fa-chair"></i> Seats left</span><b><?= max(0, $capacity - $seatsTaken) ?></b></li>
      <li><span><i class="fa-solid fa-ticket"></i> Confirmed (fully paid)</span><b><?= $booked ?></b></li>
      <li><span><i class="fa-solid fa-water"></i> <?= e($f['park_name']) ?> sign-ups</span><b><?= $parkCount ?></b></li>
      <li><span><i class="fa-solid fa-envelope"></i> Unread messages</span><b><?= $unread ?></b></li>
      <li><span><i class="fa-solid fa-hourglass-half"></i> Pending payments</span><b><?= $pendingPay ?></b></li>
    </ul>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-money-bill-trend-up"></i> Money collected — last 30 days</h3><span class="muted"><?= e(format_ugx(array_sum($paySeries))) ?></span></div>
    <?= bar_chart($paySeries, ' UGX', 'green') ?>
  </div>
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-receipt"></i> Latest payments</h3><a href="payments.php" class="link">All payments →</a></div>
    <?php if ($recentPay): ?>
    <ul class="feed">
      <?php foreach ($recentPay as $p): ?>
      <li>
        <span class="feed-icon <?= $p['purpose'] === 'camp' ? 'red' : 'purple' ?>"><i class="fa-solid <?= $p['purpose'] === 'camp' ? 'fa-ticket' : 'fa-heart' ?>"></i></span>
        <div><b><?= e($p['full_name'] ?? $p['donor_name'] ?? $p['payer_name']) ?></b><small><?= e(payment_methods()[$p['method']] ?? $p['method']) ?> · <?= e(payment_channel_detail($p)) ?> · <?= e(time_ago($p['completed_at'] ?? $p['created_at'])) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></small></div>
        <strong><?= e(format_ugx($p['amount'], $p['currency'])) ?></strong>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="empty-note">No payments yet.</p><?php endif; ?>
  </div>
</div>

<div class="grid-3">
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-lightbulb"></i> Learning tracks</h3></div>
    <?= bar_list($trackItems, max(1, $active), 'red') ?>
  </div>
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-trophy"></i> Top referrers</h3><span class="muted">target 10 each</span></div>
    <?php if ($referrers): ?>
    <ol class="leaderboard">
      <?php foreach ($referrers as $i => $r): ?>
      <li><span class="lb-rank r<?= $i + 1 ?>"><?= $i + 1 ?></span><span class="lb-name"><?= e($r['label']) ?></span><span class="lb-count"><?= (int) $r['c'] ?> <small>/ 10</small></span><span class="lb-bar"><i style="width: <?= min(100, $r['c'] * 10) ?>%"></i></span></li>
      <?php endforeach; ?>
    </ol>
    <?php else: ?><p class="empty-note">No referrals yet — they appear when applicants fill in "Who referred you?".</p><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-shirt"></i> Jersey sizes</h3><span class="muted">for ordering</span></div>
    <div class="size-grid"><?php foreach ($sizes as $s => $n): ?><div><b><?= $n ?></b><small><?= e($s) ?></small></div><?php endforeach; ?></div>
    <p class="muted small" style="margin-top:14px;">Camp shirts (free): <b><?= $active ?></b> · Jerseys: <b><?= array_sum($sizes) ?></b></p>
  </div>
</div>

<div class="grid-3">
  <div class="card"><div class="card-head"><h3><i class="fa-solid fa-bullhorn"></i> How people heard</h3></div><?= bar_list($sources, max(1, $active), 'navy') ?></div>
  <div class="card"><div class="card-head"><h3><i class="fa-solid fa-map-location-dot"></i> Top districts</h3></div><?= bar_list($districts, max(1, $active), 'blue') ?></div>
  <div class="card"><div class="card-head"><h3><i class="fa-solid fa-venus-mars"></i> Gender</h3></div><?= bar_list($genders, max(1, $active), 'red') ?></div>
</div>

<?php if ($flyers): ?>
<div class="card">
  <div class="card-head"><h3><i class="fa-solid fa-image-portrait"></i> Latest “I will be there” flyers</h3><a href="flyers.php" class="link">All <?= number_format($flyerCount) ?> flyers →</a></div>
  <div class="flyer-strip">
    <?php foreach ($flyers as $fl): ?><a href="flyers.php?img=<?= (int) $fl['id'] ?>" target="_blank" title="<?= e($fl['name'] . ($fl['reference'] ? ' · ' . $fl['reference'] : '')) ?>"><img src="flyers.php?img=<?= (int) $fl['id'] ?>&amp;thumb=1&amp;v=<?= e(strtotime($fl['updated_at'])) ?>" alt="" loading="lazy"><span><?= e($fl['name'] ?: $fl['reference']) ?></span></a><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Latest registrations</h3><a href="registrations.php" class="link">All participants →</a></div>
  <?php if ($recent): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Participant</th><th>Reference</th><th>District</th><th>Package</th><th>Paid</th><th>Status</th><th>Registered</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr class="row-link" data-href="view.php?id=<?= (int) $r['id'] ?>">
          <td><div class="person"><?= avatar_html($r) ?><div><b><?= e($r['full_name']) ?></b><small><?= e($r['email']) ?></small></div></div></td>
          <td><a class="ref" href="view.php?id=<?= (int) $r['id'] ?>"><?= e($r['reference']) ?></a></td>
          <td><?= e($r['district']) ?></td>
          <td><?= money_cell((int) $r['total_amount']) ?></td>
          <td style="min-width:130px;"><?= money_cell((int) $r['amount_paid']) ?><?= progress_bar($r) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="muted nowrap"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>No registrations yet. Share the website to start receiving applications!</p><a href="../#register" target="_blank" class="btn btn-primary">Open registration form</a></div>
  <?php endif; ?>
</div>
<?php admin_footer();
