<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

$c = find_campaign((int) ($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$c) {
    flash('That campaign was not found.', 'error');
    redirect('marketing.php');
}
$id = (int) $c['id'];
$self = 'marketing-campaign.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $cta = trim((string) ($_POST['cta_url'] ?? ''));
        if ($cta !== '' && !preg_match('~^https?://~i', $cta)) {
            $cta = 'https://' . ltrim($cta, '/');
        }
        $listId = (int) ($_POST['list_id'] ?? 0);
        $listId = $listId && q('SELECT id FROM mk_lists WHERE id = ?', [$listId])->fetchColumn() ? $listId : null;
        q('UPDATE mk_campaigns SET name = ?, subject = ?, preheader = ?, body = ?, cta_label = ?, cta_url = ?, list_id = ?, updated_at = ? WHERE id = ?', [
            mb_substr(trim((string) ($_POST['name'] ?? '')) ?: $c['name'], 0, 150),
            mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 200),
            mb_substr(trim((string) ($_POST['preheader'] ?? '')), 0, 200) ?: null,
            mk_clean_body((string) ($_POST['body'] ?? '')),
            mb_substr(trim((string) ($_POST['cta_label'] ?? '')), 0, 80) ?: null,
            $cta !== '' && filter_var($cta, FILTER_VALIDATE_URL) ? mb_substr($cta, 0, 500) : null,
            $listId, now(), $id,
        ]);
        flash('Campaign saved.');
        redirect($self);
    }

    if ($action === 'queue') {
        if (trim((string) $c['subject']) === '' || trim((string) $c['body']) === '') {
            flash('Add a subject and the email content, and save, before preparing the send list.', 'error');
            redirect($self);
        }
        $n = mk_queue($c);
        $size = mk_settings()['batch_size'];
        $groups = (int) q('SELECT COALESCE(MAX(batch), 0) FROM mk_sends WHERE campaign_id = ?', [$id])->fetchColumn();
        flash($n ? number_format($n) . ' people added to the send list — ' . $groups . ' group' . ($groups === 1 ? '' : 's') . ' of up to ' . $size . '. Send them one group at a time below.' : 'Nobody new to add — everyone in the audience is already on the send list.', $n ? 'success' : 'info');
        redirect($self . '#sending');
    }

    if ($action === 'retry') {
        $n = q("UPDATE mk_sends s JOIN mk_contacts ct ON ct.id = s.contact_id SET s.status = 'queued', s.error = NULL WHERE s.campaign_id = ? AND s.status = 'failed' AND ct.status = 'valid'", [$id])->rowCount();
        q("UPDATE mk_campaigns SET status = 'ready', finished_at = NULL WHERE id = ? AND status = 'sent'", [$id]);
        flash($n . ' failed email' . ($n === 1 ? '' : 's') . ' put back in the queue.');
        redirect($self . '#sending');
    }

    if ($action === 'cancel') {
        $n = q("DELETE FROM mk_sends WHERE campaign_id = ? AND status = 'queued'", [$id])->rowCount();
        $any = (int) q('SELECT COUNT(*) FROM mk_sends WHERE campaign_id = ?', [$id])->fetchColumn();
        q('UPDATE mk_campaigns SET status = ?, queued_at = IF(? = 0, NULL, queued_at), finished_at = IF(? > 0, COALESCE(finished_at, ?), NULL) WHERE id = ?', [$any ? 'sent' : 'draft', $any, $any, now(), $id]);
        flash(number_format($n) . ' unsent email' . ($n === 1 ? '' : 's') . ' removed from the queue.');
        redirect($self . '#sending');
    }
    redirect($self);
}

$cfg = mk_settings();
$lists = mk_lists();
$allValid = mk_contact_stats()['valid'];
$stats = mk_campaign_stats($id);
$audience = mk_audience_count($c);
$notQueued = (int) q('SELECT COUNT(*) FROM mk_contacts ct' . ($c['list_id'] ? ' JOIN mk_list_contacts lc ON lc.contact_id = ct.id AND lc.list_id = ' . (int) $c['list_id'] : '')
    . " WHERE ct.status = 'valid' AND NOT EXISTS (SELECT 1 FROM mk_sends s WHERE s.campaign_id = ? AND s.contact_id = ct.id)", [$id])->fetchColumn();
$groups = q("SELECT batch, COUNT(*) total, SUM(status = 'sent') sent, SUM(status = 'queued') queued, SUM(status = 'failed') failed, SUM(opened_at IS NOT NULL) opened, MAX(sent_at) last_sent
    FROM mk_sends WHERE campaign_id = ? GROUP BY batch ORDER BY batch", [$id])->fetchAll();
$dailyLeft = max(0, $cfg['daily_limit'] - mk_sent_last_24h());
$ready = trim((string) $c['subject']) !== '' && trim((string) $c['body']) !== '';
[, $previewHtml] = mk_render($c, ['name' => $admin['name']]);

// Recipients
$views = ['' => 'All', 'opened' => 'Opened', 'unopened' => 'Not opened', 'queued' => 'Waiting', 'failed' => 'Failed', 'skipped' => 'Skipped'];
$view = isset($views[$_GET['view'] ?? '']) ? (string) $_GET['view'] : '';
$search = trim((string) ($_GET['q'] ?? ''));
$batchFilter = max(0, (int) ($_GET['batch'] ?? 0));
$where = ['s.campaign_id = ?'];
$params = [$id];
$viewSql = ['opened' => 's.opened_at IS NOT NULL', 'unopened' => "s.status = 'sent' AND s.opened_at IS NULL", 'queued' => "s.status = 'queued'", 'failed' => "s.status = 'failed'", 'skipped' => "s.status = 'skipped'"];
if ($view !== '') {
    $where[] = $viewSql[$view];
}
if ($search !== '') {
    $where[] = '(s.email LIKE ? OR ct.name LIKE ?)';
    array_push($params, '%' . $search . '%', '%' . $search . '%');
}
if ($batchFilter) {
    $where[] = 's.batch = ?';
    $params[] = $batchFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$perPage = 50;
$total = (int) q("SELECT COUNT(*) FROM mk_sends s LEFT JOIN mk_contacts ct ON ct.id = s.contact_id $whereSql", $params)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$recipients = q("SELECT s.*, ct.name, ct.status contact_status FROM mk_sends s LEFT JOIN mk_contacts ct ON ct.id = s.contact_id $whereSql
    ORDER BY (s.opened_at IS NULL), s.opened_at DESC, s.sent_at DESC, s.id LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$viewCounts = [
    '' => $stats['total'], 'opened' => $stats['opened'], 'unopened' => max(0, $stats['sent'] - $stats['opened']),
    'queued' => $stats['queued'], 'failed' => $stats['failed'], 'skipped' => $stats['skipped'],
];
$qs = array_filter(['id' => $id, 'view' => $view, 'q' => $search, 'batch' => $batchFilter ?: ''], fn($v) => $v !== '');
$url = fn(array $extra) => 'marketing-campaign.php?' . http_build_query(array_filter($extra + $qs, fn($v) => $v !== '' && $v !== null)) . '#recipients';

$tick = function (array $s): string {
    if ($s['opened_at']) {
        return '<span class="mk-tick opened" title="Opened ' . e(date('j M, g:i a', strtotime($s['opened_at']))) . ($s['open_count'] > 1 ? ' · ' . (int) $s['open_count'] . ' times' : '') . '"><i class="fa-solid fa-check-double"></i> Opened</span>';
    }
    return match ($s['status']) {
        'sent'    => '<span class="mk-tick sent" title="Sent ' . e(date('j M, g:i a', strtotime((string) $s['sent_at']))) . ' · not opened yet"><i class="fa-solid fa-check"></i> Sent</span>',
        'queued'  => '<span class="mk-tick wait"><i class="fa-regular fa-clock"></i> Waiting</span>',
        'failed'  => '<span class="mk-tick failed" title="' . e((string) $s['error']) . '"><i class="fa-solid fa-xmark"></i> Failed</span>',
        default   => '<span class="mk-tick wait" title="' . e((string) $s['error']) . '"><i class="fa-solid fa-ban"></i> Skipped</span>',
    };
};

admin_header($c['name'], 'campaigns', 'Email campaign · ' . ($stats['total'] ? number_format($stats['sent']) . ' of ' . number_format($stats['total']) . ' sent' : 'not sent yet'),
    ['https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css']);
?>
<a href="marketing.php" class="back"><i class="fa-solid fa-arrow-left"></i> All campaigns</a>

<?php if ($stats['total']): ?>
<div class="mk-stats card">
  <div><small>Recipients</small><b><?= number_format($stats['total']) ?></b></div>
  <div><small>Sent</small><b><?= number_format($stats['sent']) ?></b></div>
  <div><small>Opened</small><b class="mk-blue"><i class="fa-solid fa-check-double"></i> <?= number_format($stats['opened']) ?></b></div>
  <div><small>Open rate</small><b><?= $stats['open_rate'] ?>%</b></div>
  <div><small>Waiting</small><b><?= number_format($stats['queued']) ?></b></div>
  <div><small>Failed</small><b class="<?= $stats['failed'] ? 'due' : '' ?>"><?= number_format($stats['failed']) ?></b></div>
  <div><small>Unsubscribed</small><b><?= number_format($stats['unsubscribed']) ?></b></div>
</div>
<?php endif; ?>

<div class="mk-layout">
  <form method="post" class="card stack" id="mkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="body" id="mkBody" value="<?= e((string) $c['body']) ?>">
    <div class="card-head"><h3><i class="fa-regular fa-pen-to-square"></i> Compose</h3>
      <div class="mk-templates">
        <span class="small muted">Start from:</span>
        <button type="button" class="chip-btn" data-template="program"><i class="fa-solid fa-graduation-cap"></i> Program</button>
        <button type="button" class="chip-btn" data-template="event"><i class="fa-regular fa-calendar"></i> Event</button>
        <button type="button" class="chip-btn" data-template="news"><i class="fa-regular fa-newspaper"></i> Update</button>
      </div>
    </div>
    <?php if ($stats['sent']): ?><p class="alert alert-info small"><i class="fa-solid fa-circle-info"></i><span><?= number_format($stats['sent']) ?> people already received this email. Changes you save now only apply to emails that have not been sent yet.</span></p><?php endif; ?>
    <div class="row-2">
      <label>Campaign name <small class="muted">only you see this</small><input type="text" name="name" value="<?= e($c['name']) ?>" maxlength="150" required></label>
      <label>Send to
        <select name="list_id">
          <option value="0">All contacts (<?= number_format($allValid) ?> ready)</option>
          <?php foreach ($lists as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $c['list_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?> (<?= number_format((int) $l['valid']) ?> ready)</option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Subject line <small class="muted">what people see in their inbox · {first_name} adds their first name</small><input type="text" name="subject" value="<?= e($c['subject']) ?>" maxlength="200" placeholder="e.g. {first_name}, the Digital Bridge internship is open — apply by 20 October"></label>
    <label>Preview text <small class="muted">the grey line shown after the subject in Gmail (optional)</small><input type="text" name="preheader" value="<?= e((string) $c['preheader']) ?>" maxlength="200" placeholder="Free mentorship in Lira, Gulu, Kitgum and online — 2 minutes to apply"></label>
    <div class="mk-editor-wrap">
      <div class="mk-editor-bar small muted">
        <span>Email content — headings, <b>bold</b>, bullets, links and pictures</span>
        <span class="mk-merge">Insert: <button type="button" class="link" data-merge="{first_name}">{first_name}</button> <button type="button" class="link" data-merge="{name}">{name}</button></span>
      </div>
      <div id="mkEditor"></div>
      <p class="small muted mk-upload-note" id="mkUploadNote" hidden><span class="spinner dark"></span> Uploading picture…</p>
    </div>
    <div class="row-2">
      <label>Button text <small class="muted">optional — a big red button under the content</small><input type="text" name="cta_label" value="<?= e((string) $c['cta_label']) ?>" maxlength="80" placeholder="Apply now"></label>
      <label>Button link<input type="text" name="cta_url" value="<?= e((string) $c['cta_url']) ?>" maxlength="500" placeholder="https://kakebetechcamp.com/#programs"></label>
    </div>
    <div class="mk-form-actions">
      <button class="btn btn-primary" type="submit" id="mkSave"><i class="fa-solid fa-floppy-disk"></i> Save</button>
      <span class="small muted">Save to update the preview. Every email gets the Kakebe header, your content, the contact details and an unsubscribe link.</span>
    </div>
  </form>

  <div class="mk-side">
    <section class="card">
      <div class="card-head"><h3><i class="fa-regular fa-eye"></i> Preview</h3>
        <div class="tabs mk-device"><a href="#" class="active" data-device="desktop"><i class="fa-solid fa-desktop"></i></a><a href="#" data-device="mobile"><i class="fa-solid fa-mobile-screen"></i></a></div>
      </div>
      <?php if ($ready): ?>
        <div class="mk-inbox"><b><?= e($cfg['from_name']) ?></b><span><?= e(mk_personalise((string) $c['subject'], ['name' => $admin['name']], false)) ?></span><?php if ($c['preheader']): ?><small><?= e(mk_personalise((string) $c['preheader'], ['name' => $admin['name']], false)) ?></small><?php endif; ?></div>
        <div class="mk-frame" id="mkFrame"><iframe title="Email preview" srcdoc="<?= e($previewHtml) ?>" sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin"></iframe></div>
        <div class="mk-test">
          <input type="email" id="mkTestTo" value="<?= e($admin['email']) ?>" aria-label="Send a test to">
          <button type="button" class="btn btn-navy btn-sm" id="mkTest" data-id="<?= $id ?>"><i class="fa-solid fa-paper-plane"></i> Send me a test</button>
        </div>
        <p class="small" id="mkTestMsg" hidden></p>
      <?php else: ?>
        <div class="empty-state"><i class="fa-regular fa-envelope"></i><p>Write a subject and some content, then save to see the email here.</p></div>
      <?php endif; ?>
    </section>

    <section class="card" id="sending">
      <div class="card-head"><h3><i class="fa-solid fa-paper-plane"></i> Send</h3><span class="badge <?= $dailyLeft ? 'st-booked' : 'st-cancelled' ?>" title="Daily limit <?= number_format($cfg['daily_limit']) ?>"><?= number_format($dailyLeft) ?> left today</span></div>
      <?php if (setting('mail_transport', 'log') === 'log'): ?><p class="alert alert-warning small"><i class="fa-solid fa-triangle-exclamation"></i><span>Email sending is off (log only): sends are recorded but not delivered.</span></p><?php endif; ?>

      <?php if (!$ready): ?>
        <p class="muted small">Write and save the email first.</p>
      <?php elseif (!$stats['total']): ?>
        <p class="small">This email will go to <b><?= number_format($audience) ?></b> <?= $c['list_id'] ? 'people in the chosen list' : 'contacts' ?> — each person gets their own copy.
          <?php if ($audience): ?>That's <b><?= (int) ceil($audience / $cfg['batch_size']) ?></b> group<?= ceil($audience / $cfg['batch_size']) == 1 ? '' : 's' ?> of up to <?= $cfg['batch_size'] ?>.<?php endif; ?></p>
        <?php if ($audience): ?>
          <form method="post" data-confirm="Prepare the send list for <?= number_format($audience) ?> people? Nothing is sent until you press Send on a group."><?= csrf_field() ?><input type="hidden" name="action" value="queue"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-list-check"></i> Prepare send list</button></form>
        <?php else: ?>
          <p class="muted small">No contacts are ready in this audience. <a href="marketing-contacts.php">Import contacts</a></p>
        <?php endif; ?>
      <?php else: ?>
        <div class="mk-groups">
          <?php foreach ($groups as $g): $done = (int) $g['total'] - (int) $g['queued']; $pct = $g['total'] ? round($done / $g['total'] * 100) : 0; ?>
          <div class="mk-group" data-batch="<?= (int) $g['batch'] ?>">
            <div class="mk-group-top">
              <b>Group <?= (int) $g['batch'] ?></b>
              <span class="small muted"><span class="js-done"><?= number_format($done) ?></span> / <?= number_format((int) $g['total']) ?> sent<?= $g['failed'] ? ' · <span class="due">' . (int) $g['failed'] . ' failed</span>' : '' ?><?= $g['opened'] ? ' · <span class="mk-blue"><i class="fa-solid fa-check-double"></i> ' . (int) $g['opened'] . '</span>' : '' ?></span>
            </div>
            <div class="mini-progress <?= $pct >= 100 ? 'full' : 'booked' ?>"><i style="width:<?= $pct ?>%"></i></div>
            <?php if ($g['queued']): ?>
              <button type="button" class="btn btn-primary btn-sm js-send" data-id="<?= $id ?>" data-batch="<?= (int) $g['batch'] ?>" data-total="<?= (int) $g['total'] ?>" <?= $dailyLeft ? '' : 'disabled' ?>><i class="fa-solid fa-paper-plane"></i> Send group <?= (int) $g['batch'] ?> (<?= number_format((int) $g['queued']) ?>)</button>
            <?php else: ?>
              <span class="small ok-text"><i class="fa-solid fa-circle-check"></i> Done<?= $g['last_sent'] ? ' · ' . e(date('j M, g:i a', strtotime($g['last_sent']))) : '' ?></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mk-live" id="mkLive" hidden>
          <p class="small"><b id="mkLiveTitle">Sending…</b> <span id="mkLiveText" class="muted"></span></p>
          <div class="big-progress"><i id="mkLiveBar" style="width:0%"></i></div>
          <button type="button" class="btn btn-light btn-sm" id="mkStop"><i class="fa-solid fa-pause"></i> Pause</button>
          <p class="small muted">Keep this page open while a group is sending.</p>
        </div>
        <div class="mk-send-actions">
          <?php if ($notQueued): ?>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="queue"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-user-plus"></i> Add <?= number_format($notQueued) ?> new contact<?= $notQueued === 1 ? '' : 's' ?></button></form>
          <?php endif; ?>
          <?php if ($stats['failed']): ?>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-rotate-right"></i> Retry <?= number_format($stats['failed']) ?> failed</button></form>
          <?php endif; ?>
          <?php if ($stats['queued']): ?>
          <form method="post" class="inline-form" data-confirm="Remove the <?= number_format($stats['queued']) ?> emails that have not been sent yet from the queue?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-ban"></i> Cancel unsent</button></form>
          <?php endif; ?>
        </div>
        <p class="small muted">Groups send about 10 emails at a time. The daily limit (<?= number_format($cfg['daily_limit']) ?>) pauses sending automatically — continue the next day.</p>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php if ($stats['total']): ?>
<section class="card" id="recipients">
  <div class="card-head list-head">
    <h3><i class="fa-solid fa-users"></i> Recipients</h3>
    <form method="get" class="mk-recip-filter" action="marketing-campaign.php#recipients">
      <input type="hidden" name="id" value="<?= $id ?>"><?php if ($view): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
      <?php if (count($groups) > 1): ?><select name="batch" onchange="this.form.submit()"><option value="">All groups</option><?php foreach ($groups as $g): ?><option value="<?= (int) $g['batch'] ?>" <?= $batchFilter === (int) $g['batch'] ? 'selected' : '' ?>>Group <?= (int) $g['batch'] ?></option><?php endforeach; ?></select><?php endif; ?>
      <div class="filter-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= e($search) ?>" placeholder="Email or name"></div>
    </form>
  </div>
  <div class="tabs mk-views">
    <?php foreach ($views as $k => $label): ?><a href="<?= e($url(['view' => $k, 'page' => null])) ?>" class="<?= $view === $k ? 'active' : '' ?>"><?= e($label) ?> <em><?= number_format((int) $viewCounts[$k]) ?></em></a><?php endforeach; ?>
  </div>
  <?php if ($recipients): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Email</th><th>Name</th><th>Group</th><th>Status</th><th>Sent</th><th>Opened</th></tr></thead>
      <tbody>
      <?php foreach ($recipients as $s): ?>
        <tr>
          <td><?= e($s['email']) ?><?php if ($s['contact_status'] === 'unsubscribed'): ?> <span class="badge st-waitlisted">Unsubscribed</span><?php endif; ?><?php if ($s['status'] === 'failed' && $s['error']): ?><small class="block err-text"><?= e($s['error']) ?></small><?php endif; ?></td>
          <td><?= e((string) $s['name']) ?: '<span class="muted">—</span>' ?></td>
          <td><?= (int) $s['batch'] ?></td>
          <td><?= $tick($s) ?></td>
          <td class="nowrap muted"><?= $s['sent_at'] ? e(date('j M, g:i a', strtotime($s['sent_at']))) : '—' ?></td>
          <td class="nowrap"><?= $s['opened_at'] ? e(date('j M, g:i a', strtotime($s['opened_at']))) . ($s['open_count'] > 1 ? ' <span class="muted">×' . (int) $s['open_count'] . '</span>' : '') : '<span class="muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pagination">
    <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> people</span>
    <div>
      <?php if ($page > 1): ?><a href="<?= e($url(['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?><a href="<?= e($url(['page' => $p])) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </nav>
  <?php endif; ?>
  <?php else: ?>
    <div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>No one here yet.</p></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<script>window.MK = { csrf: <?= json_encode(csrf_token()) ?>, api: 'marketing-api.php' };</script>
<?php admin_footer(['https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js', 'marketing.js']);
