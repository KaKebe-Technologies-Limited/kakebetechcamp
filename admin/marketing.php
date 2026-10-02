<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'new') {
        $name = trim((string) ($_POST['name'] ?? '')) ?: 'New campaign · ' . date('j M Y');
        q('INSERT INTO mk_campaigns (name, created_by, created_at, updated_at) VALUES (?, ?, ?, ?)', [mb_substr($name, 0, 150), $admin['id'], now(), now()]);
        redirect('marketing-campaign.php?id=' . (int) db()->lastInsertId());
    }

    if ($action === 'duplicate' && ($c = find_campaign((int) ($_POST['id'] ?? 0)))) {
        q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [mb_substr('Copy of ' . $c['name'], 0, 150), $c['subject'], $c['preheader'], $c['body'], $c['cta_label'], $c['cta_url'], $c['list_id'], $admin['id'], now(), now()]);
        flash('Campaign copied — edit it and send it to a new audience.');
        redirect('marketing-campaign.php?id=' . (int) db()->lastInsertId());
    }

    if ($action === 'delete' && ($c = find_campaign((int) ($_POST['id'] ?? 0)))) {
        q('DELETE FROM mk_sends WHERE campaign_id = ?', [$c['id']]);
        q('DELETE FROM mk_campaigns WHERE id = ?', [$c['id']]);
        flash('Campaign "' . $c['name'] . '" deleted.');
        redirect('marketing.php');
    }

    if ($action === 'settings') {
        setting_set('mk_from_name', mb_substr(trim((string) ($_POST['mk_from_name'] ?? '')), 0, 80));
        $reply = strtolower(trim((string) ($_POST['mk_reply_to'] ?? '')));
        setting_set('mk_reply_to', filter_var($reply, FILTER_VALIDATE_EMAIL) ? $reply : '');
        setting_set('mk_daily_limit', (string) max(1, min(100000, (int) ($_POST['mk_daily_limit'] ?? 500))));
        setting_set('mk_batch_size', (string) max(50, min(2000, (int) ($_POST['mk_batch_size'] ?? 500))));
        flash('Sending settings saved.');
        redirect('marketing.php#settings');
    }
    redirect('marketing.php');
}

$cfg = mk_settings();
$contacts = mk_contact_stats();
$sent24 = mk_sent_last_24h();
$totals = q("SELECT COUNT(*) sent, COALESCE(SUM(opened_at IS NOT NULL), 0) opened FROM mk_sends WHERE status = 'sent'")->fetch();
$openRate = $totals['sent'] ? round($totals['opened'] / $totals['sent'] * 100, 1) : 0;
$campaigns = q("SELECT c.*, l.name list_name,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id) recipients,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id AND s.status = 'sent') sent,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id AND s.status = 'queued') queued,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id AND s.opened_at IS NOT NULL) opened
    FROM mk_campaigns c LEFT JOIN mk_lists l ON l.id = c.list_id ORDER BY c.id DESC")->fetchAll();
$transport = setting('mail_transport', 'log');
$bulkSmtp = env('MK_SMTP_HOST') !== '';
$smtp = mk_smtp_config();
$sentSeries = days_series("SELECT DATE(sent_at) d, COUNT(*) v FROM mk_sends WHERE status = 'sent' AND sent_at >= ? GROUP BY DATE(sent_at)", 14);
$openSeries = days_series("SELECT DATE(opened_at) d, COUNT(*) v FROM mk_sends WHERE opened_at >= ? GROUP BY DATE(opened_at)", 14);

admin_header('Email campaigns', 'campaigns', 'Compose rich emails and send them to your contacts in groups — everyone gets their own copy');
?>
<?php if ($transport === 'log'): ?>
<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><span>Email sending is switched off (log only) — campaigns will be recorded but nothing is delivered. <a href="settings.php#email">Settings → Email</a></span></div>
<?php endif; ?>

<div class="kpis">
  <a class="kpi" href="marketing-contacts.php?status=valid"><span class="kpi-icon blue"><i class="fa-solid fa-address-book"></i></span><div><small>Ready to receive</small><b><?= number_format($contacts['valid']) ?></b><em><?= number_format($contacts['invalid']) ?> filtered out · <?= number_format($contacts['unsubscribed']) ?> unsubscribed</em></div></a>
  <div class="kpi"><span class="kpi-icon amber"><i class="fa-solid fa-gauge-high"></i></span><div><small>Sent in 24 hours</small><b><?= number_format($sent24) ?></b><em>of <?= number_format($cfg['daily_limit']) ?> allowed · <?= number_format(max(0, $cfg['daily_limit'] - $sent24)) ?> left</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-paper-plane"></i></span><div><small>Emails sent</small><b><?= number_format((int) $totals['sent']) ?></b><em>across <?= count($campaigns) ?> campaign<?= count($campaigns) === 1 ? '' : 's' ?></em></div></div>
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-check-double mk-blue"></i></span><div><small>Opened</small><b><?= number_format((int) $totals['opened']) ?></b><em><?= $openRate ?>% open rate</em></div></div>
</div>

<section class="card">
    <div class="card-head list-head">
      <h3><i class="fa-solid fa-bullhorn"></i> Campaigns</h3>
      <form method="post" class="mk-new"><?= csrf_field() ?><input type="hidden" name="action" value="new">
        <input type="text" name="name" placeholder="Campaign name, e.g. Mentorship intake — October" maxlength="150">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-plus"></i> New campaign</button>
      </form>
    </div>
    <?php if ($campaigns): ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Campaign</th><th>Audience</th><th>Status</th><th>Sent</th><th>Opened</th><th>Created</th><th class="row-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($campaigns as $c):
            $rate = $c['sent'] ? round($c['opened'] / $c['sent'] * 100) : 0;
            if ((int) $c['recipients'] === 0) { [$label, $cls] = ['Draft', 'st-waitlisted']; }
            elseif ((int) $c['queued'] === 0) { [$label, $cls] = ['Sent', 'st-confirmed']; }
            elseif ((int) $c['sent'] === 0) { [$label, $cls] = ['Ready to send', 'st-booked']; }
            else { [$label, $cls] = ['Partly sent', 'st-pending']; }
        ?>
          <tr class="row-link" data-href="marketing-campaign.php?id=<?= (int) $c['id'] ?>">
            <td><b><?= e($c['name']) ?></b><small class="block muted"><?= e($c['subject'] ?: 'No subject yet') ?></small></td>
            <td><?= e($c['list_name'] ?? 'All contacts') ?></td>
            <td><span class="badge <?= $cls ?>"><?= $label ?></span></td>
            <td class="nowrap"><b><?= number_format((int) $c['sent']) ?></b><?php if ($c['recipients']): ?> <span class="muted">/ <?= number_format((int) $c['recipients']) ?></span><?php endif; ?></td>
            <td class="nowrap"><?php if ($c['sent']): ?><i class="fa-solid fa-check-double mk-blue"></i> <b><?= number_format((int) $c['opened']) ?></b> <span class="muted"><?= $rate ?>%</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="nowrap muted"><?= e(date('j M Y', strtotime($c['created_at']))) ?></td>
            <td class="nowrap row-actions">
              <a class="btn btn-light btn-sm" href="marketing-campaign.php?id=<?= (int) $c['id'] ?>"><i class="fa-regular fa-pen-to-square"></i> Open</a>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-light btn-sm" type="submit" title="Make a copy"><i class="fa-regular fa-copy"></i></button></form>
              <form method="post" class="inline-form" data-confirm="Delete this campaign and its statistics? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-danger btn-sm" type="submit" title="Delete"><i class="fa-regular fa-trash-can"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty-state"><i class="fa-regular fa-envelope-open"></i><p>No campaigns yet. <?= $contacts['valid'] ? 'Give your first one a name above.' : 'Start by importing your contacts.' ?></p><?php if (!$contacts['valid']): ?><a class="btn btn-primary" href="marketing-contacts.php"><i class="fa-solid fa-file-import"></i> Import contacts</a><?php endif; ?></div>
    <?php endif; ?>
</section>

<div class="grid-2 align-start">
  <section class="card" id="settings">
    <div class="card-head"><h3><i class="fa-solid fa-sliders"></i> Sending settings</h3></div>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <div class="row-2">
        <label>Sender name <input type="text" name="mk_from_name" value="<?= e(setting('mk_from_name')) ?>" placeholder="Kakebe Technologies" maxlength="80"></label>
        <label>Replies go to <input type="email" name="mk_reply_to" value="<?= e(setting('mk_reply_to')) ?>" placeholder="<?= e(setting('contact_email')) ?>"></label>
      </div>
      <div class="row-2">
        <label>Daily limit <small class="muted">emails per 24 hours</small><input type="number" name="mk_daily_limit" min="1" max="100000" value="<?= (int) $cfg['daily_limit'] ?>"></label>
        <label>Group size <small class="muted">emails per group (50–2000)</small><input type="number" name="mk_batch_size" min="50" max="2000" value="<?= (int) $cfg['batch_size'] ?>"></label>
      </div>
      <dl class="details one">
        <div><dt>Sent through</dt><dd class="small"><?= $bulkSmtp ? '<span class="ok-text"><i class="fa-solid fa-circle-check"></i> Bulk email service</span> · ' . e($smtp['host']) : 'The site email account' . ($smtp['user'] ? ' · ' . e($smtp['user']) : '') . ' <span class="muted">(Settings → Email)</span>' ?></dd></div>
        <div><dt>From address</dt><dd class="small"><?= e($cfg['from_name']) ?> &lt;<?= e($smtp['from_email'] ?: ($smtp['user'] ?: '—')) ?>&gt;</dd></div>
      </dl>
      <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save settings</button></div>
    </form>
  </section>

  <div class="stack-cards">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-chart-column"></i> Last 14 days</h3></div>
    <p class="small muted mk-chart-label">Emails sent</p>
    <?= bar_chart($sentSeries, '', 'mk-sent') ?>
    <p class="small muted mk-chart-label">Opens <i class="fa-solid fa-check-double mk-blue"></i></p>
    <?= bar_chart($openSeries, '', 'mk-opens') ?>
  </section>
  <section class="card muted-card">
    <div class="card-head"><h3><i class="fa-solid fa-circle-info"></i> Good to know</h3></div>
    <ul class="mk-tips">
      <li><b>Gmail allows about 500 emails a day.</b> Keep the daily limit at 500 while sending from Gmail, or connect a bulk email service (Brevo, Mailgun, Amazon SES, Zoho ZeptoMail…) with the <code>MK_SMTP_*</code> lines in the <code>.env</code> file, then raise the limit.</li>
      <li><b>Everyone gets their own copy</b> — no shared To, CC or BCC, so no one sees other addresses.</li>
      <li><b>Every email has an Unsubscribe link</b> (also the one-click button in Gmail). People who unsubscribe are never emailed again.</li>
      <li><b>Blue ticks</b> <i class="fa-solid fa-check-double mk-blue"></i> show when an email is opened with images on. Some apps block images or open them automatically, so treat open numbers as a guide.</li>
      <li>Email people who know Kakebe or asked to hear from you. Cold lists get emails flagged as spam and can block the account.</li>
    </ul>
  </section>
  </div>
</div>
<?php admin_footer();
