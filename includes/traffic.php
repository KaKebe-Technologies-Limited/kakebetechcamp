<?php
/**
 * First-party website statistics: page views, visits, visitors and button clicks, stored in our own database.
 * No cookies and no personal data — a random browser id (localStorage) and a per-tab visit id.
 * Works whether or not Google Analytics reporting is connected.
 */

/** The small script on public pages that reports page views and clicks to api/track.php. */
function traffic_tag(): string
{
    $api = json_encode(base_url('api/track.php'), JSON_UNESCAPED_SLASHES);
    return <<<HTML
  <script>
    (function () {
      try {
        var api = {$api};
        var rid = function () { var a = new Uint8Array(8); crypto.getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); };
        var keep = function (store, key) { try { var v = store.getItem(key); if (!v) { v = rid(); store.setItem(key, v); } return v; } catch (e) { return rid(); } };
        var vid = keep(window.localStorage, 'kt_vid'), sid = keep(window.sessionStorage, 'kt_sid');
        var send = function (data) {
          data.v = vid; data.s = sid; data.p = location.pathname; data.w = screen.width || window.innerWidth;
          var body = JSON.stringify(data);
          if (navigator.sendBeacon) { navigator.sendBeacon(api, new Blob([body], { type: 'text/plain' })); }
          else { fetch(api, { method: 'POST', body: body, keepalive: true }); }
        };
        send({ t: 'view', r: document.referrer, u: new URLSearchParams(location.search).get('utm_source') || '' });
        document.addEventListener('click', function (e) {
          var el = e.target.closest && e.target.closest('a, button');
          if (!el) return;
          var label = el.getAttribute('data-track') || (el.innerText || el.getAttribute('aria-label') || el.title || '').replace(/\s+/g, ' ').trim().slice(0, 70);
          var href = el.getAttribute('href') || '';
          if (/wa\.me|whatsapp\.com/i.test(href)) label = 'WhatsApp · ' + label;
          else if (/^tel:/i.test(href)) label = 'Phone call · ' + label;
          else if (/^mailto:/i.test(href)) label = 'Email · ' + label;
          if (label) send({ t: 'click', l: label });
        }, true);
      } catch (e) {}
    })();
  </script>
HTML;
}

/** Save one page view or click sent by traffic_tag(). */
function traffic_record(array $d): bool
{
    $type = in_array($d['t'] ?? '', ['view', 'click'], true) ? $d['t'] : null;
    if (!$type) {
        return false;
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|facebookexternalhit|whatsapp\/|python|curl|wget/i', $ua)) {
        return false;
    }
    $fallback = substr(hash('sha256', client_ip() . '|' . $ua . '|' . date('Y-m-d')), 0, 16);
    $visitor = preg_match('/^[a-f0-9]{16}$/', (string) ($d['v'] ?? '')) ? $d['v'] : $fallback;
    $session = preg_match('/^[a-f0-9]{16}$/', (string) ($d['s'] ?? '')) ? $d['s'] : $fallback;

    // Path relative to the site root, without query strings
    $path = '/' . ltrim((string) parse_url((string) ($d['p'] ?? '/'), PHP_URL_PATH), '/');
    $basePath = rtrim((string) parse_url(base_url(), PHP_URL_PATH), '/');
    if ($basePath !== '' && str_starts_with($path, $basePath)) {
        $path = '/' . ltrim(substr($path, strlen($basePath)), '/');
    }
    $path = mb_substr(preg_replace('~/index\.php$~', '/', $path), 0, 190);

    $w = (int) ($d['w'] ?? 0);
    $device = $w <= 0 ? '' : ($w < 768 ? 'Mobile' : ($w < 1100 ? 'Tablet' : 'Desktop'));
    $source = '';
    $label = null;
    if ($type === 'view') {
        $source = traffic_source((string) ($d['r'] ?? ''), (string) ($d['u'] ?? ''));
    } else {
        $label = mb_substr(trim(strip_tags((string) ($d['l'] ?? ''))), 0, 120);
        if ($label === '') {
            return false;
        }
    }
    db()->prepare('INSERT INTO site_events (type, visitor, session, path, label, source, device, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$type, $visitor, $session, $path, $label, $source, $device, now()]);
    if (random_int(1, 500) === 1) {   // keep about 13 months
        db()->prepare('DELETE FROM site_events WHERE created_at < ?')->execute([date('Y-m-d H:i:s', strtotime('-400 days'))]);
    }
    return true;
}

/** Where a visit came from: campaign tag, a known site, another website, or Direct. */
function traffic_source(string $referrer, string $utm): string
{
    $utm = trim(preg_replace('/[^\w .-]/u', '', $utm));
    if ($utm !== '') {
        $names = ['whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'fb' => 'Facebook', 'instagram' => 'Instagram', 'ig' => 'Instagram', 'tiktok' => 'TikTok',
            'linkedin' => 'LinkedIn', 'x' => 'X (Twitter)', 'twitter' => 'X (Twitter)', 'youtube' => 'YouTube', 'google' => 'Google', 'email' => 'Email', 'sms' => 'SMS'];
        return $names[mb_strtolower($utm)] ?? mb_substr(ucfirst(mb_strtolower($utm)), 0, 60);
    }
    $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
    if ($host === '') {
        return 'Direct';
    }
    $own = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
    if ($host === $own || $host === 'www.' . $own || 'www.' . $host === $own) {
        return 'Internal';
    }
    $known = [
        'google.' => 'Google', 'bing.' => 'Bing', 'yahoo.' => 'Yahoo', 'duckduckgo.' => 'DuckDuckGo',
        'facebook.' => 'Facebook', 'fb.' => 'Facebook', 'instagram.' => 'Instagram', 't.co' => 'X (Twitter)', 'twitter.' => 'X (Twitter)', 'x.com' => 'X (Twitter)',
        'linkedin.' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'tiktok.' => 'TikTok', 'youtube.' => 'YouTube', 'whatsapp.' => 'WhatsApp', 'wa.me' => 'WhatsApp',
        'mail.google.' => 'Gmail', 'outlook.' => 'Outlook', 'chatgpt.' => 'ChatGPT', 'telegram.' => 'Telegram',
    ];
    foreach ($known as $needle => $name) {
        if (str_contains($host, $needle)) {
            return $name;
        }
    }
    return mb_substr(preg_replace('/^www\./', '', $host), 0, 60);
}

/* ------------------------------------------------------------------
 * Reports
 * ------------------------------------------------------------------ */

function traffic_totals(string $from, string $to): array
{
    $st = db()->prepare("SELECT COUNT(DISTINCT CASE WHEN type = 'view' THEN visitor END) visitors, COUNT(DISTINCT CASE WHEN type = 'view' THEN session END) visits,
        SUM(type = 'view') views, SUM(type = 'click') clicks FROM site_events WHERE created_at >= ? AND created_at < ?");
    $st->execute([$from, $to]);
    $r = $st->fetch() ?: [];
    return ['visitors' => (int) ($r['visitors'] ?? 0), 'visits' => (int) ($r['visits'] ?? 0), 'views' => (int) ($r['views'] ?? 0), 'clicks' => (int) ($r['clicks'] ?? 0)];
}

/** This period and the one before, for the "+12%" arrows. */
function traffic_summary(int $days): array
{
    $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
    $prev = date('Y-m-d 00:00:00', strtotime('-' . (2 * $days - 1) . ' days'));
    return ['current' => traffic_totals($start, $end), 'previous' => traffic_totals($prev, $start), 'today' => traffic_totals(date('Y-m-d 00:00:00'), $end),
        'now' => (int) db()->query("SELECT COUNT(DISTINCT visitor) FROM site_events WHERE created_at >= '" . date('Y-m-d H:i:s', time() - 300) . "'")->fetchColumn()];
}

/** Per day (last $days) or per week (last $weeks, Monday to Sunday): visitors, visits and page views. */
function traffic_series(string $by, int $n): array
{
    $rows = [];
    if ($by === 'week') {
        $monday = strtotime('monday this week');
        for ($i = $n - 1; $i >= 0; $i--) {
            $k = date('Y-m-d', strtotime("-$i weeks", $monday));
            $rows[$k] = ['label' => date('j M', strtotime($k)), 'title' => 'Week of ' . date('j M', strtotime($k)), 'visitors' => 0, 'visits' => 0, 'views' => 0];
        }
        $group = "DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))";
        $from = date('Y-m-d 00:00:00', strtotime('-' . ($n - 1) . ' weeks', $monday));
    } else {
        for ($i = $n - 1; $i >= 0; $i--) {
            $k = date('Y-m-d', strtotime("-$i days"));
            $rows[$k] = ['label' => date('j', strtotime($k)), 'title' => date('D j M', strtotime($k)), 'visitors' => 0, 'visits' => 0, 'views' => 0];
        }
        $group = 'DATE(created_at)';
        $from = date('Y-m-d 00:00:00', strtotime('-' . ($n - 1) . ' days'));
    }
    $st = db()->prepare("SELECT $group k, COUNT(DISTINCT visitor) visitors, COUNT(DISTINCT session) visits, COUNT(*) views FROM site_events WHERE type = 'view' AND created_at >= ? GROUP BY k");
    $st->execute([$from]);
    foreach ($st as $r) {
        if (isset($rows[$r['k']])) {
            $rows[$r['k']] = ['visitors' => (int) $r['visitors'], 'visits' => (int) $r['visits'], 'views' => (int) $r['views']] + $rows[$r['k']];
        }
    }
    return $rows;
}

/** Top pages / sources / clicked buttons / devices for the last $days. Rows: label, c. */
function traffic_top(string $what, int $days, int $limit = 8): array
{
    $from = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
    $sql = match ($what) {
        'pages'   => "SELECT path label, COUNT(*) c FROM site_events WHERE type = 'view' AND created_at >= ? GROUP BY path ORDER BY c DESC",
        'sources' => "SELECT source label, COUNT(DISTINCT session) c FROM site_events WHERE type = 'view' AND source NOT IN ('', 'Internal') AND created_at >= ? GROUP BY source ORDER BY c DESC",
        'clicks'  => "SELECT label, COUNT(*) c FROM site_events WHERE type = 'click' AND created_at >= ? GROUP BY label ORDER BY c DESC",
        'devices' => "SELECT device label, COUNT(DISTINCT visitor) c FROM site_events WHERE type = 'view' AND device <> '' AND created_at >= ? GROUP BY device ORDER BY c DESC",
    };
    $st = db()->prepare($sql . ' LIMIT ' . (int) $limit);
    $st->execute([$from]);
    $rows = $st->fetchAll();
    if ($what === 'pages') {
        foreach ($rows as &$r) {
            $r['label'] = traffic_page_name($r['label']);
        }
    }
    return $rows;
}

function traffic_page_name(string $path): string
{
    $names = [
        '/' => 'Home page', '/mentorship.php' => 'Mentorship & DBIP registration', '/pay.php' => 'My registration / pay', '/flyer.php' => 'Flyer maker',
        '/ticket.php' => 'Camp ticket', '/receipt.php' => 'Receipt', '/payment-return.php' => 'Payment result', '/portal/' => 'Participant portal: home',
        '/portal/login.php' => 'Participant portal: log in', '/portal/profile.php' => 'Participant portal: profile', '/api/mk-unsubscribe.php' => 'Email unsubscribe',
    ];
    return $names[$path] ?? $path;
}

function traffic_delta(int $now, int $before): string
{
    if ($before <= 0) {
        return $now > 0 ? '<span class="delta up">new</span>' : '';
    }
    $pct = (int) round(($now - $before) / $before * 100);
    return '<span class="delta ' . ($pct >= 0 ? 'up' : 'down') . '"><i class="fa-solid fa-arrow-' . ($pct >= 0 ? 'up' : 'down') . '"></i> ' . abs($pct) . '%</span>';
}

/** Visitors (solid) over page views (light), one column per day or week. */
function traffic_chart(array $rows): string
{
    $max = max(1, max(array_map(fn($r) => max($r['views'], $r['visitors']), $rows ?: [['views' => 0, 'visitors' => 0]])));
    $html = '<div class="tchart">';
    foreach ($rows as $r) {
        $html .= '<div class="tcol" title="' . e($r['title'] . ': ' . number_format($r['visitors']) . ' visitors · ' . number_format($r['visits']) . ' visits · ' . number_format($r['views']) . ' page views') . '">'
            . '<span class="tval">' . ($r['visitors'] ? number_format($r['visitors']) : '') . '</span>'
            . '<div class="tbars"><i class="tv-views" style="height:' . round($r['views'] / $max * 100) . '%"></i><i class="tv-visitors" style="height:' . max($r['visitors'] ? 2 : 0, round($r['visitors'] / $max * 100)) . '%"></i></div>'
            . '<small>' . e($r['label']) . '</small></div>';
    }
    return $html . '</div>';
}

/** The whole "Website traffic" block for the dashboard and the analytics page. */
function traffic_section(int $days = 7, bool $full = false): string
{
    $s = traffic_summary($days);
    $c = $s['current'];
    $p = $s['previous'];
    $pages = traffic_top('pages', $days, $full ? 12 : 6);
    $sources = traffic_top('sources', $days, $full ? 12 : 6);
    $clicks = traffic_top('clicks', $days, $full ? 15 : 6);
    $devices = traffic_top('devices', $days, 3);
    $period = $days === 1 ? 'today' : "last $days days";
    $any = (int) db()->query('SELECT COUNT(*) FROM site_events')->fetchColumn() > 0;
    ob_start(); ?>
    <section class="card traffic">
      <div class="card-head">
        <h3><i class="fa-solid fa-chart-line"></i> Website traffic <small class="muted">· <?= e($period) ?></small></h3>
        <span class="small muted"><span class="live-dot"></span> <b><?= number_format($s['now']) ?></b> on the site now</span>
      </div>
      <?php if (!$any): ?>
        <p class="small muted">Counting has started. Visits appear here as soon as people open the website (visits by logged-in admins are not counted).</p>
      <?php endif; ?>
      <div class="traffic-kpis">
        <div><small>Visitors</small><b><?= number_format($c['visitors']) ?></b><?= traffic_delta($c['visitors'], $p['visitors']) ?><em>different people</em></div>
        <div><small>Visits</small><b><?= number_format($c['visits']) ?></b><?= traffic_delta($c['visits'], $p['visits']) ?><em>times the site was opened</em></div>
        <div><small>Page views</small><b><?= number_format($c['views']) ?></b><?= traffic_delta($c['views'], $p['views']) ?><em>pages opened</em></div>
        <div><small>Clicks</small><b><?= number_format($c['clicks']) ?></b><?= traffic_delta($c['clicks'], $p['clicks']) ?><em>on buttons &amp; links</em></div>
        <div><small>Today</small><b><?= number_format($s['today']['visitors']) ?></b><em><?= number_format($s['today']['views']) ?> page views</em></div>
      </div>
      <div class="traffic-chart" data-tabs>
        <div class="tabs traffic-tabs"><a href="#" class="active" data-tab="tDay">Per day</a><a href="#" data-tab="tWeek">Per week</a></div>
        <span class="tlegend"><i class="tv-visitors"></i> Visitors <i class="tv-views"></i> Page views</span>
        <div data-panel="tDay"><?= traffic_chart(traffic_series('day', 30)) ?></div>
        <div data-panel="tWeek" hidden><?= traffic_chart(traffic_series('week', 12)) ?></div>
      </div>
      <div class="traffic-lists">
        <div><h4><i class="fa-regular fa-file-lines"></i> Top pages</h4><?= bar_list($pages, max(1, $c['views']), 'navy') ?></div>
        <div><h4><i class="fa-solid fa-signs-post"></i> Where visitors come from</h4><?= bar_list($sources, max(1, array_sum(array_column($sources, 'c'))), 'blue') ?></div>
        <div><h4><i class="fa-solid fa-arrow-pointer"></i> Most clicked</h4><?= bar_list($clicks, max(1, $c['clicks']), 'red') ?>
          <?php if ($devices): ?><p class="small muted traffic-devices"><?php foreach ($devices as $d): ?><span><i class="fa-solid <?= $d['label'] === 'Mobile' ? 'fa-mobile-screen' : ($d['label'] === 'Tablet' ? 'fa-tablet-screen-button' : 'fa-desktop') ?>"></i> <?= e($d['label']) ?> <b><?= $c['visitors'] ? round($d['c'] / $c['visitors'] * 100) : 0 ?>%</b></span><?php endforeach; ?></p><?php endif; ?>
        </div>
      </div>
    </section>
    <?php return (string) ob_get_clean();
}
