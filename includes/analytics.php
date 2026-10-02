<?php
/**
 * Google Analytics 4 — the site tag (gtag.js) and the admin reports (GA4 Data API via a service account).
 */

function ga_measurement_id(): string
{
    $id = strtoupper(trim((string) setting('ga_measurement_id', 'G-H4TEE2S6RG')));
    return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : '';
}

/**
 * The Google tag for public pages and the participant portal. Only on the live site — local testing and
 * admins viewing a participant's dashboard are not counted. Private links (payment / ticket tokens, email
 * links) and personal details are stripped from every URL sent to Google.
 * Also defines window.ktTrack(name, params) for events (a no-op when the tag is off).
 */
function ga_tag(bool $force = false): string
{
    $id = ga_measurement_id();
    // Our own visit counter (Dashboard → Website traffic) runs everywhere except when an admin views a participant's portal
    $own = empty($_SESSION['impersonated_by']) ? "
" . traffic_tag() : '';
    if ($id === '' || (!$force && (!is_production() || !empty($_SESSION['impersonated_by'])))) {
        return '<script>window.ktTrack = function () {};</script>' . $own;
    }
    $idJs = json_encode($id);
    return $own . <<<HTML
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id={$id}"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    (function () {
      var privateParams = ['t', 'ref', 'verify', 'token', 'code', 'id', 'email', 'phone'];
      var clean = function (url) {
        try { var u = new URL(url, location.href); privateParams.forEach(function (k) { u.searchParams.delete(k); }); return u.href; }
        catch (e) { return location.origin + location.pathname; }
      };
      var config = { page_location: clean(location.href) };
      if (document.referrer) { config.page_referrer = clean(document.referrer); }
      gtag('config', {$idJs}, config);
    })();
    window.ktTrack = function (name, params) { gtag('event', name, params || {}); };
  </script>
HTML;
}

/* ------------------------------------------------------------------
 * Reports in the control panel (GA4 Data API)
 * ------------------------------------------------------------------ */

function ga_property_id(): string
{
    $id = trim((string) setting('ga_property_id'));
    return ctype_digit($id) ? $id : '';
}

/** The service account key (JSON) pasted in Admin → Settings. */
function ga_credentials(): ?array
{
    $key = json_decode((string) setting('ga_service_account'), true);
    return is_array($key) && !empty($key['client_email']) && !empty($key['private_key']) ? $key : null;
}

function ga_connected(): bool
{
    return ga_property_id() !== '' && ga_credentials() !== null;
}

function ga_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** OAuth access token for the service account (cached until shortly before it expires). */
function ga_access_token(?string &$error = null): ?string
{
    $key = ga_credentials();
    if (!$key) {
        $error = 'Google Analytics is not connected yet.';
        return null;
    }
    $cacheFile = STORAGE . '/cache/ga_token_' . md5($key['client_email'] . $key['private_key']) . '.json';
    $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60) {
        return $cached['token'];
    }

    $now = time();
    $unsigned = ga_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . ga_b64url(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $pkey = openssl_pkey_get_private((string) $key['private_key']);
    if (!$pkey || !openssl_sign($unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
        $error = 'The service account key could not be read. Paste the JSON key again in Settings.';
        return null;
    }
    [$code, $data, $err] = iotec_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $unsigned . '.' . ga_b64url($signature),
    ]), 15);
    if ($code !== 200 || empty($data['access_token'])) {
        $error = 'Google did not accept the service account key' . (!empty($data['error_description']) ? ': ' . $data['error_description'] : ($err ? ': ' . $err : '.'));
        return null;
    }
    @file_put_contents($cacheFile, json_encode(['token' => $data['access_token'], 'expires' => $now + (int) ($data['expires_in'] ?? 3600)]), LOCK_EX);
    return $data['access_token'];
}

/**
 * Run a GA4 report and return its rows as [['dimension' => 'value', 'metric' => 12.0], ...].
 * $kind is 'runReport' or 'runRealtimeReport'. Results are cached for $ttl seconds.
 */
function ga_report(array $body, string $kind = 'runReport', int $ttl = 900, ?string &$error = null): ?array
{
    if (!ga_connected()) {
        $error = 'Google Analytics is not connected yet.';
        return null;
    }
    $pid = ga_property_id();
    $cacheFile = STORAGE . '/cache/ga_' . md5($pid . $kind . json_encode($body)) . '.json';
    if ($ttl > 0 && is_file($cacheFile) && filemtime($cacheFile) > time() - $ttl) {
        $rows = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($rows)) {
            return $rows;
        }
    }
    $token = ga_access_token($error);
    if (!$token) {
        return null;
    }
    [$code, $data, $err] = iotec_http('POST', "https://analyticsdata.googleapis.com/v1beta/properties/$pid:$kind", [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ], json_encode($body), 20);
    if ($code !== 200) {
        $msg = (string) ($data['error']['message'] ?? $err ?? ('HTTP ' . $code));
        $error = $code === 403
            ? 'Google Analytics refused access. Check that the Google Analytics Data API is enabled and that the service account email is added as a Viewer on the property. (' . $msg . ')'
            : 'Google Analytics error: ' . $msg;
        return null;
    }
    $dims = array_column($data['dimensionHeaders'] ?? [], 'name');
    $mets = array_column($data['metricHeaders'] ?? [], 'name');
    $rows = [];
    foreach ($data['rows'] ?? [] as $row) {
        $r = [];
        foreach ($dims as $i => $d) {
            $r[$d] = (string) ($row['dimensionValues'][$i]['value'] ?? '');
        }
        foreach ($mets as $i => $m) {
            $r[$m] = (float) ($row['metricValues'][$i]['value'] ?? 0);
        }
        $rows[] = $r;
    }
    @file_put_contents($cacheFile, json_encode($rows), LOCK_EX);
    return $rows;
}

/** Forget cached reports (the "Refresh" button). */
function ga_clear_cache(): void
{
    foreach (glob(STORAGE . '/cache/ga_*.json') ?: [] as $f) {
        if (!str_starts_with(basename($f), 'ga_token_')) {
            @unlink($f);
        }
    }
}

/** GA4 date range for the last $days days, optionally shifted back by one period (for comparisons). */
function ga_range(int $days, bool $previous = false, string $name = ''): array
{
    $end = $previous ? $days : 0;
    $r = ['startDate' => ($end + $days - 1) . 'daysAgo', 'endDate' => $end ? $end . 'daysAgo' : 'today'];
    return $name !== '' ? $r + ['name' => $name] : $r;
}

/**
 * Headline numbers for the last $days days, with the period before for comparison.
 * Returns ['current' => [...], 'previous' => [...]] with users, new users, sessions, views, engagement.
 */
function ga_summary(int $days, ?string &$error = null): ?array
{
    $metrics = ['activeUsers', 'newUsers', 'sessions', 'screenPageViews', 'engagedSessions', 'userEngagementDuration'];
    $rows = ga_report([
        'dateRanges' => [ga_range($days, false, 'current'), ga_range($days, true, 'previous')],
        'metrics'    => array_map(fn($m) => ['name' => $m], $metrics),
    ], 'runReport', 900, $error);
    if ($rows === null) {
        return null;
    }
    $out = ['current' => array_fill_keys($metrics, 0.0), 'previous' => array_fill_keys($metrics, 0.0)];
    foreach ($rows as $row) {
        $key = ($row['dateRange'] ?? 'current') === 'previous' ? 'previous' : 'current';
        $out[$key] = array_merge($out[$key], array_intersect_key($row, $out[$key]));
    }
    return $out;
}

/** Visitors in the last 30 minutes. */
function ga_realtime_users(?string &$error = null): ?int
{
    $rows = ga_report(['metrics' => [['name' => 'activeUsers']]], 'runRealtimeReport', 60, $error);
    return $rows === null ? null : (int) ($rows[0]['activeUsers'] ?? 0);
}

/** Signed % change between two numbers, or null when there is nothing to compare with. */
function ga_delta(float $now, float $before): ?int
{
    return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
}

function ga_duration(float $seconds): string
{
    $s = (int) round($seconds);
    return $s >= 60 ? intdiv($s, 60) . 'm ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . 's' : $s . 's';
}

/** Friendly names for the events the website sends. */
function ga_event_labels(): array
{
    return [
        'sign_up'        => 'Registrations completed',
        'begin_checkout' => 'Payments started',
        'purchase'       => 'Payments completed',
        'generate_lead'  => 'Contact messages',
    ];
}

/**
 * Single-series line chart (inline SVG) with a crosshair + tooltip hover layer (see admin/assets/admin.js).
 * $series: ['Y-m-d' => value]. The same numbers are offered as a table under the chart.
 */
function ga_line_chart(array $series, string $unit): string
{
    $W = 760; $H = 240; $L = 44; $R = 22; $T = 16; $B = 30;
    $vals = array_values($series);
    $dates = array_keys($series);
    $n = count($vals);
    if ($n === 0) {
        return '<p class="empty-note">No data yet.</p>';
    }
    // Clean y-axis: 4 intervals of a 1 / 2 / 2.5 / 5 × 10ⁿ step
    $raw = max(1, max($vals)) / 4;
    $mag = 10 ** floor(log10($raw));
    $step = $mag;
    foreach ([1, 2, 2.5, 5, 10] as $f) {
        if ($f * $mag >= $raw) { $step = $f * $mag; break; }
    }
    $ymax = $step * 4;
    $pw = $W - $L - $R;
    $ph = $H - $T - $B;
    $x = fn(int $i) => $L + ($n > 1 ? $i / ($n - 1) * $pw : $pw / 2);
    $y = fn(float $v) => $T + $ph - $v / $ymax * $ph;

    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e(ucfirst($unit)) . ' per day" tabindex="0">';
    for ($k = 0; $k <= 4; $k++) {
        $gy = round($y($step * $k)) + 0.5;
        $svg .= '<line class="ga-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '"/>'
            . '<text class="ga-tick" x="' . ($L - 8) . '" y="' . ($gy + 4) . '" text-anchor="end">' . number_format($step * $k) . '</text>';
    }
    $labelEvery = max(1, (int) ceil($n / 6));
    foreach ($dates as $i => $d) {
        if ($i % $labelEvery === 0 || $i === $n - 1) {
            if ($i !== $n - 1 && $n - 1 - $i < $labelEvery / 2) {
                continue; // keep the last label clear of its neighbour
            }
            $svg .= '<text class="ga-tick" x="' . round($x($i), 1) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e(date('j M', strtotime($d))) . '</text>';
        }
    }
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($x($i), 1) . ',' . round($y($v), 1);
    }
    $baseline = round($y(0), 1);
    $svg .= '<path class="ga-area" d="M' . round($x(0), 1) . ',' . $baseline . ' L' . implode(' L', $pts) . ' L' . round($x($n - 1), 1) . ',' . $baseline . ' Z"/>'
        . '<polyline class="ga-line" points="' . implode(' ', $pts) . '"/>';
    $lx = round($x($n - 1), 1);
    $ly = round($y(end($vals)), 1);
    $svg .= '<circle class="ga-dot" cx="' . $lx . '" cy="' . $ly . '" r="4"/>'
        . '<text class="ga-end" x="' . min($W - 4, $lx + 2) . '" y="' . max(12, $ly - 12) . '" text-anchor="end">' . number_format(end($vals)) . '</text>'
        . '<line class="ga-cross" x1="0" x2="0" y1="' . $T . '" y2="' . ($T + $ph) . '" visibility="hidden"/>'
        . '<circle class="ga-hover" r="5" cx="0" cy="0" visibility="hidden"/>'
        . '<rect class="ga-hit" x="' . $L . '" y="0" width="' . $pw . '" height="' . $H . '"/></svg>';

    $points = [];
    foreach ($series as $d => $v) {
        $points[] = ['d' => date('D j M', strtotime($d)), 'v' => (int) $v];
    }
    $geo = ['W' => $W, 'L' => $L, 'pw' => $pw, 'T' => $T, 'ph' => $ph, 'ymax' => $ymax];
    return '<div class="ga-chart" data-unit="' . e($unit) . '" data-points="' . e(json_encode($points)) . '" data-geo="' . e(json_encode($geo)) . '">'
        . $svg . '<div class="ga-tip" hidden></div></div>';
}
