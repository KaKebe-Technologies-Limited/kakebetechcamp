<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'connect') {
        $mid = strtoupper(trim((string) ($_POST['ga_measurement_id'] ?? '')));
        $pid = preg_replace('/\D/', '', (string) ($_POST['ga_property_id'] ?? ''));
        $json = trim((string) ($_POST['ga_service_account'] ?? ''));
        $key = $json !== '' ? json_decode($json, true) : null;
        if ($mid !== '' && !preg_match('/^G-[A-Z0-9]{4,20}$/', $mid)) {
            flash('The Measurement ID should look like G-XXXXXXXXXX.', 'error');
        } elseif ($json !== '' && (!is_array($key) || ($key['type'] ?? '') !== 'service_account' || empty($key['client_email']) || !openssl_pkey_get_private((string) ($key['private_key'] ?? '')))) {
            flash('That is not a valid service account key. Paste the whole JSON file you downloaded from Google Cloud.', 'error');
        } else {
            setting_set('ga_measurement_id', $mid);
            setting_set('ga_property_id', $pid);
            if ($json !== '') {
                setting_set('ga_service_account', json_encode([
                    'type' => 'service_account', 'client_email' => $key['client_email'], 'private_key' => $key['private_key'], 'project_id' => $key['project_id'] ?? '',
                ]));
            }
            ga_clear_cache();
            $err = null;
            if (ga_connected()) {
                $ok = ga_summary(7, $err) !== null;
                flash($ok ? 'Google Analytics is connected.' : 'Saved, but Google Analytics could not be reached: ' . $err, $ok ? 'success' : 'error');
            } else {
                flash('Settings saved.');
            }
        }
    } elseif ($action === 'disconnect') {
        setting_set('ga_service_account', '');
        ga_clear_cache();
        flash('The service account key was removed. The Google tag on the website keeps working.');
    } elseif ($action === 'refresh') {
        ga_clear_cache();
        flash('Analytics refreshed.');
    }
    redirect('analytics.php' . (isset($_POST['days']) ? '?days=' . (int) $_POST['days'] : ''));
}

$ranges = [7 => 'Last 7 days', 28 => 'Last 28 days', 90 => 'Last 90 days'];
$days = (int) ($_GET['days'] ?? 28);
$days = isset($ranges[$days]) ? $days : 28;
$connected = ga_connected();
$key = ga_credentials();
$mid = ga_measurement_id();

$error = null;
if ($connected) {
    $range = [ga_range($days)];
    $sum = ga_summary($days, $error);
    $now = $error ? null : ga_realtime_users($error);
    $report = function (array $body) use (&$error, $range) {
        return $error ? [] : (ga_report($body + ['dateRanges' => $range], 'runReport', 900, $error) ?? []);
    };
    $daily = $report(['dimensions' => [['name' => 'date']], 'metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']], 'orderBys' => [['dimension' => ['dimensionName' => 'date']]]]);
    $channels = $report(['dimensions' => [['name' => 'sessionDefaultChannelGroup']], 'metrics' => [['name' => 'sessions']], 'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 7]);
    $sources = $report(['dimensions' => [['name' => 'sessionSource']], 'metrics' => [['name' => 'sessions']], 'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 7]);
    $devices = $report(['dimensions' => [['name' => 'deviceCategory']], 'metrics' => [['name' => 'activeUsers']], 'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]]]);
    $pages = $report(['dimensions' => [['name' => 'pagePath']], 'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']], 'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]], 'limit' => 10]);
    $cities = $report(['dimensions' => [['name' => 'city'], ['name' => 'country']], 'metrics' => [['name' => 'activeUsers']], 'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]], 'limit' => 8]);
    $events = $report(['dimensions' => [['name' => 'eventName']], 'metrics' => [['name' => 'eventCount']], 'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => array_keys(ga_event_labels())]]]]);

    // Daily visitors, with zero for days without visits
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $series[date('Y-m-d', strtotime("-$i days"))] = ['users' => 0, 'views' => 0];
    }
    foreach ($daily as $row) {
        $d = substr($row['date'], 0, 4) . '-' . substr($row['date'], 4, 2) . '-' . substr($row['date'], 6, 2);
        if (isset($series[$d])) {
            $series[$d] = ['users' => (int) $row['activeUsers'], 'views' => (int) $row['screenPageViews']];
        }
    }
    $items = fn(array $rows, string $dim, string $metric, ?callable $label = null) => array_map(fn($r) => ['label' => $label ? $label($r) : ($r[$dim] ?: 'Unknown'), 'c' => (int) $r[$metric]], $rows);
    $eventCounts = array_fill_keys(array_keys(ga_event_labels()), 0);
    foreach ($events as $row) {
        $eventCounts[$row['eventName']] = (int) $row['eventCount'];
    }
    $registered = (int) q("SELECT COUNT(*) FROM registrations WHERE status <> 'cancelled' AND created_at >= ?", [date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'))])->fetchColumn();
}

$pageNames = ['/' => 'Home page', '/pay.php' => 'My registration & payment', '/portal/' => 'Participant dashboard', '/portal/index.php' => 'Participant dashboard',
    '/portal/login.php' => 'Portal login', '/portal/profile.php' => 'Participant profile', '/portal/forgot.php' => 'Forgot password', '/portal/reset.php' => 'Reset password', '/payment-return.php' => 'Card payment return'];
$deltaHtml = function (float $cur, float $prev) use ($days): string {
    $d = ga_delta($cur, $prev);
    if ($d === null) {
        return 'no earlier data';
    }
    $cls = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat');
    $icon = $d > 0 ? 'fa-arrow-up' : ($d < 0 ? 'fa-arrow-down' : 'fa-minus');
    return '<span class="delta ' . $cls . '"><i class="fa-solid ' . $icon . '"></i> ' . abs($d) . '%</span> vs previous ' . $days . ' days';
};

admin_header('Website analytics', 'analytics', 'Visitors to kakebetechcamp.com — from Google Analytics');
?>

<?php if (!$connected): ?>
<div class="grid-2 align-start">
  <section class="card">
    <div class="card-head"><h3><i class="fa-brands fa-google"></i> Google tag on the website</h3><?= $mid ? '<span class="badge st-confirmed"><i class="fa-solid fa-circle-check"></i> Installed</span>' : '<span class="badge st-cancelled">Off</span>' ?></div>
    <p>Measurement ID <b><?= e($mid ?: '—') ?></b>. The tag runs on every public page and the participant dashboard of the live site. Visits from localhost, the control panel and admins viewing a participant's dashboard are not counted, and private links (payment and ticket links, email links) are never sent to Google.</p>
    <p class="muted small">Registrations, payments started, payments completed and contact messages are sent as events, so you can see them in Google Analytics.</p>
    <a class="btn btn-light btn-sm" href="https://analytics.google.com/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open Google Analytics</a>
  </section>

  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-chart-line"></i> Show your analytics here</h3></div>
    <ol class="setup-steps">
      <li>In <a href="https://analytics.google.com/" target="_blank" rel="noopener">Google Analytics</a> open <b>Admin → Property details</b> and copy the <b>Property ID</b> (a number such as 456789123 — not the G- code).</li>
      <li>In <a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener">Google Cloud</a>, enable the <b>Google Analytics Data API</b>.</li>
      <li>Go to <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">IAM → Service accounts</a>, create a service account, then <b>Keys → Add key → JSON</b>. A .json file downloads.</li>
      <li>Back in Google Analytics: <b>Admin → Property access management → +</b>, add the service account's email (ends in <i>iam.gserviceaccount.com</i>) as a <b>Viewer</b>.</li>
      <li>Paste the Property ID and the whole JSON file below.</li>
    </ol>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="connect">
      <div class="row-2">
        <label>Measurement ID<input type="text" name="ga_measurement_id" value="<?= e($mid) ?>" placeholder="G-XXXXXXXXXX"></label>
        <label>Property ID<input type="text" inputmode="numeric" name="ga_property_id" value="<?= e(ga_property_id()) ?>" placeholder="e.g. 456789123"></label>
      </div>
      <label>Service account key (JSON)
        <textarea name="ga_service_account" rows="5" placeholder='<?= $key ? 'Saved for ' . e($key['client_email']) . ' — paste a new key to replace it' : '{ "type": "service_account", "project_id": "…", "private_key": "…", "client_email": "…" }' ?>' spellcheck="false" autocomplete="off"></textarea>
      </label>
      <p class="muted small">The key is stored on your server only and is never shown again. It gives read-only access to your analytics.</p>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-plug"></i> Connect Google Analytics</button>
    </form>
  </section>
</div>

<?php else: ?>
<div class="filters ga-filters">
  <div class="tabs">
    <?php foreach ($ranges as $n => $label): ?><a href="?days=<?= $n ?>" class="<?= $n === $days ? 'active' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
  </div>
  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="days" value="<?= $days ?>"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-rotate"></i> Refresh</button></form>
  <a class="btn btn-light btn-sm" href="https://analytics.google.com/analytics/web/#/p<?= e(ga_property_id()) ?>/reports/intelligenthome" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open Google Analytics</a>
  <span class="muted small">Numbers update every 15 minutes · live visitors every minute</span>
</div>

<?php if ($error): ?>
  <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <span><?= e($error) ?></span></div>
<?php endif; ?>

<?php if ($sum): $c = $sum['current']; $p = $sum['previous']; ?>
<div class="kpis six">
  <div class="kpi"><span class="kpi-icon red"><i class="fa-solid fa-users"></i></span><div><small>Visitors</small><b><?= number_format($c['activeUsers']) ?></b><em><?= $deltaHtml($c['activeUsers'], $p['activeUsers']) ?></em></div></div>
  <div class="kpi"><span class="kpi-icon blue"><i class="fa-solid fa-eye"></i></span><div><small>Page views</small><b><?= number_format($c['screenPageViews']) ?></b><em><?= $deltaHtml($c['screenPageViews'], $p['screenPageViews']) ?></em></div></div>
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-user-plus"></i></span><div><small>New visitors</small><b><?= number_format($c['newUsers']) ?></b><em><?= number_format($c['sessions']) ?> visits · <?= $c['sessions'] ? round($c['engagedSessions'] / $c['sessions'] * 100) : 0 ?>% engaged</em></div></div>
  <div class="kpi"><span class="kpi-icon amber"><i class="fa-regular fa-clock"></i></span><div><small>Time on site</small><b><?= e(ga_duration($c['activeUsers'] ? $c['userEngagementDuration'] / $c['activeUsers'] : 0)) ?></b><em>average per visitor</em></div></div>
  <a class="kpi" href="registrations.php"><span class="kpi-icon green"><i class="fa-solid fa-id-card"></i></span><div><small>Registrations</small><b><?= number_format($registered) ?></b><em><?= $c['activeUsers'] ? round($registered / $c['activeUsers'] * 100, 1) : 0 ?>% of visitors registered</em></div></a>
  <div class="kpi"><span class="kpi-icon purple"><i class="fa-solid fa-signal"></i></span><div><small>On the site now</small><b><?= $now === null ? '—' : number_format($now) ?></b><em>visitors in the last 30 minutes</em></div></div>
</div>
<?php endif; ?>

<?php if (!$error): ?>
<div class="card">
  <div class="card-head"><h3><i class="fa-solid fa-chart-line"></i> Visitors per day</h3><span class="muted"><?= e($ranges[$days]) ?></span></div>
  <?= ga_line_chart(array_map(fn($s) => $s['users'], $series), 'visitors') ?>
  <details class="ga-table">
    <summary>Show as a table</summary>
    <div class="table-wrap"><table class="table"><thead><tr><th>Day</th><th class="r">Visitors</th><th class="r">Page views</th></tr></thead><tbody>
      <?php foreach (array_reverse($series, true) as $d => $s): ?><tr><td><?= e(date('D j M Y', strtotime($d))) ?></td><td class="r"><?= number_format($s['users']) ?></td><td class="r"><?= number_format($s['views']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </details>
</div>

<div class="grid-3">
  <div class="card"><div class="card-head"><h3><i class="fa-solid fa-compass"></i> How people find the site</h3><span class="muted">visits</span></div><?= bar_list($items($channels, 'sessionDefaultChannelGroup', 'sessions'), max(1, (int) array_sum(array_column($channels, 'sessions'))), 'blue') ?></div>
  <div class="card"><div class="card-head"><h3><i class="fa-solid fa-link"></i> Top sources</h3><span class="muted">visits</span></div><?= bar_list($items($sources, 'sessionSource', 'sessions', fn($r) => $r['sessionSource'] === '(direct)' ? 'Direct (typed or shared link)' : $r['sessionSource']), max(1, (int) array_sum(array_column($sources, 'sessions'))), 'blue') ?></div>
  <div class="card"><div class="card-head"><h3><i class="fa-solid fa-mobile-screen-button"></i> Devices</h3><span class="muted">visitors</span></div><?= bar_list($items($devices, 'deviceCategory', 'activeUsers', fn($r) => ucfirst($r['deviceCategory'])), max(1, (int) array_sum(array_column($devices, 'activeUsers'))), 'blue') ?></div>
</div>

<div class="grid-2 align-start">
  <div class="card">
    <div class="card-head"><h3><i class="fa-regular fa-file-lines"></i> Most viewed pages</h3></div>
    <?php if ($pages): ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Page</th><th class="r">Views</th><th class="r">Visitors</th></tr></thead><tbody>
      <?php foreach ($pages as $row): $path = $row['pagePath']; ?>
      <tr><td><b><?= e($pageNames[$path] ?? $path) ?></b><?php if (isset($pageNames[$path])): ?><br><small class="muted"><?= e($path) ?></small><?php endif; ?></td><td class="r"><?= number_format($row['screenPageViews']) ?></td><td class="r"><?= number_format($row['activeUsers']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><p class="empty-note">No page views yet.</p><?php endif; ?>
  </div>
  <div class="stack-cards">
    <div class="card"><div class="card-head"><h3><i class="fa-solid fa-location-dot"></i> Where visitors are</h3><span class="muted">visitors</span></div><?= bar_list($items($cities, 'city', 'activeUsers', fn($r) => ($r['city'] && $r['city'] !== '(not set)' ? $r['city'] . ', ' : '') . ($r['country'] && $r['country'] !== '(not set)' ? $r['country'] : 'Unknown')), max(1, (int) ($c['activeUsers'] ?? 0)), 'blue') ?></div>
    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-bullseye"></i> Website actions</h3><span class="muted">tracked by Google</span></div>
      <ul class="mini-stats">
        <?php foreach (ga_event_labels() as $ev => $label): ?><li><span><?= e($label) ?></span><b><?= number_format($eventCounts[$ev]) ?></b></li><?php endforeach; ?>
      </ul>
      <p class="muted small" style="margin-top:12px;">Google counts can be a little lower than the Participants and Payments pages — some browsers block analytics.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<details class="card ga-settings">
  <summary><i class="fa-solid fa-gear"></i> Connection settings</summary>
  <form method="post" class="stack" style="margin-top:16px;">
    <?= csrf_field() ?><input type="hidden" name="action" value="connect">
    <div class="row-2">
      <label>Measurement ID<input type="text" name="ga_measurement_id" value="<?= e($mid) ?>"></label>
      <label>Property ID<input type="text" inputmode="numeric" name="ga_property_id" value="<?= e(ga_property_id()) ?>"></label>
    </div>
    <label>Replace service account key (JSON) <small class="muted">connected as <?= e($key['client_email'] ?? '') ?></small><textarea name="ga_service_account" rows="3" spellcheck="false" autocomplete="off" placeholder="Leave empty to keep the current key"></textarea></label>
    <div class="actions"><button class="btn btn-primary btn-sm" type="submit">Save</button></div>
  </form>
  <form method="post" data-confirm="Remove the service account key? The analytics here will stop updating (the tag on the website keeps working).">
    <?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><button class="btn btn-light btn-sm" type="submit"><i class="fa-solid fa-plug-circle-xmark"></i> Disconnect</button>
  </form>
</details>
<?php endif; ?>

<?php admin_footer();
