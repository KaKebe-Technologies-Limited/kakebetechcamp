<?php
/**
 * Admin bootstrap: authentication, helpers and the shared control-panel layout.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

/** Prepare + execute a query in one call. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function admins_exist(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
}

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    $admin = null;
    if (!empty($_SESSION['admin_id'])) {
        if (time() - (int) ($_SESSION['admin_seen'] ?? 0) > 8 * 3600) {
            unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
            return null;
        }
        $admin = q('SELECT id, name, email FROM admins WHERE id = ?', [(int) $_SESSION['admin_id']])->fetch() ?: null;
        $_SESSION['admin_seen'] = time();
    }
    return $admin;
}

function require_admin(): array
{
    if (!admins_exist()) {
        redirect('setup.php');
    }
    $admin = current_admin();
    if (!$admin) {
        redirect('login.php?next=' . rawurlencode(basename($_SERVER['SCRIPT_NAME']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')));
    }
    return $admin;
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid or expired form. Please go back, refresh the page and try again.');
    }
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = [$type, $message];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function mail_note(): string
{
    return setting('mail_transport', 'log') === 'log' ? ' (Email sending is switched off — it was only logged. See Settings → Email.)' : '';
}

/* ---------- Participants ---------- */

function registration_filters(array $in): array
{
    $f = [];
    foreach (['q', 'status', 'payment', 'park', 'interest', 'source', 'jersey', 'from', 'to', 'funding'] as $k) {
        $f[$k] = trim((string) ($in[$k] ?? ''));
    }
    $where = [];
    $params = [];
    if (in_array($f['funding'], ['self', 'sponsored'], true)) {
        $where[] = 'funding = ?';
        $params[] = $f['funding'];
    } else {
        $f['funding'] = '';
    }
    if ($f['q'] !== '') {
        $where[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR reference LIKE ? OR district LIKE ? OR referred_by LIKE ?)';
        array_push($params, ...array_fill(0, 6, '%' . $f['q'] . '%'));
    }
    if (isset(statuses()[$f['status']])) {
        $where[] = 'status = ?';
        $params[] = $f['status'];
    } elseif ($f['status'] === 'active') {
        $where[] = "status <> 'cancelled'";
    } else {
        $f['status'] = '';
    }
    if (isset(payment_statuses()[$f['payment']])) {
        $where[] = 'payment_status = ?';
        $params[] = $f['payment'];
    } elseif ($f['payment'] === 'balance') {
        $where[] = "payment_status IN ('unpaid','partial') AND status <> 'cancelled'";
    } else {
        $f['payment'] = '';
    }
    if ($f['park'] === 'yes' || $f['park'] === 'no') {
        $where[] = 'park_visit = ?';
        $params[] = $f['park'] === 'yes' ? 1 : 0;
    } else {
        $f['park'] = '';
    }
    if (in_array($f['interest'], interests(), true)) {
        $where[] = 'interests LIKE ?';
        $params[] = '%' . $f['interest'] . '%';
    } else {
        $f['interest'] = '';
    }
    if (in_array($f['source'], sources(), true)) {
        $where[] = 'source = ?';
        $params[] = $f['source'];
    } else {
        $f['source'] = '';
    }
    if (in_array($f['jersey'], jersey_sizes(), true)) {
        $where[] = 'jersey_size = ?';
        $params[] = $f['jersey'];
    } else {
        $f['jersey'] = '';
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
            $where[] = "created_at $op ?";
            $params[] = $f[$k] . ($k === 'from' ? ' 00:00:00' : ' 23:59:59');
        } else {
            $f[$k] = '';
        }
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params, $f];
}

/** Admin status override: 'auto' recalculates from payments; waitlisted/cancelled are kept as set. */
function set_registration_status(array $r, string $status): void
{
    if ($status === 'auto') {
        q("UPDATE registrations SET status = 'pending', updated_at = ? WHERE id = ?", [now(), $r['id']]);
    } elseif (in_array($status, ['waitlisted', 'cancelled'], true)) {
        q('UPDATE registrations SET status = ?, updated_at = ? WHERE id = ?', [$status, now(), $r['id']]);
        return;
    }
    recompute_registration((int) $r['id']);
}

/** Record a cash/bank/offline payment and (optionally) email the receipt. */
function record_manual_payment(array $r, int $amount, string $method, string $ref, string $notes, int $adminId, bool $notify): array
{
    $p = create_payment([
        'registration_id' => $r['id'], 'purpose' => 'camp', 'amount' => $amount, 'currency' => 'UGX',
        'method' => $method, 'provider' => 'manual', 'payer_name' => $r['full_name'], 'payer_phone' => $r['phone'],
        'payer_email' => $r['email'], 'notes' => trim($ref . ($notes !== '' ? ' — ' . $notes : '')), 'recorded_by' => $adminId,
    ]);
    if ($ref !== '') {
        q('UPDATE payments SET provider_txn_id = ? WHERE id = ?', [mb_substr($ref, 0, 64), $p['id']]);
    }
    finalize_payment((int) $p['id'], $notify);
    return payment_find((int) $p['id']);
}

/** Approve a sponsored application: fees covered, place confirmed, participant emailed. */
function approve_sponsorship(array $r, bool $notify): bool
{
    q("UPDATE registrations SET payment_status = 'sponsored', status = 'confirmed', sponsor_decided_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $r['id']]);
    $r = recompute_registration((int) $r['id']);
    if ($notify) {
        [$s, $h] = tpl_sponsorship_approved($r);
        return send_mail($r['email'], $s, $h, setting('contact_email') ?: null);
    }
    return false;
}

/** Decline a sponsorship: the registration stays, but becomes self-funded so they can pay. */
function decline_sponsorship(array $r, string $note, bool $notify): bool
{
    q("UPDATE registrations SET funding = 'self', payment_status = 'unpaid', status = 'pending', sponsor_decided_at = ?, sponsor_note = ?, updated_at = ? WHERE id = ?",
        [now(), $note !== '' ? mb_substr($note, 0, 255) : 'Sponsorship not confirmed', now(), $r['id']]);
    $r = recompute_registration((int) $r['id']);
    if ($notify) {
        [$s, $h] = tpl_sponsorship_declined($r, $note);
        return send_mail($r['email'], $s, $h, setting('contact_email') ?: null);
    }
    return false;
}

function funding_badge(array $r): string
{
    if (is_sponsored($r)) {
        $cls = $r['payment_status'] === 'sponsored' ? 'st-confirmed' : 'st-pending';
        return '<span class="badge ' . $cls . '" title="' . e($r['sponsor_name']) . '">Sponsored</span>';
    }
    return '<span class="badge st-waitlisted">Self</span>';
}

function delete_registration(array $r): void
{
    if ($path = photo_path($r['photo'])) {
        @unlink($path);
    }
    q('DELETE FROM payments WHERE registration_id = ? AND status <> ?', [$r['id'], 'success']);
    q('UPDATE payments SET registration_id = NULL, notes = CONCAT(COALESCE(notes, ?), ?) WHERE registration_id = ?', ['', ' [participant ' . $r['reference'] . ' deleted]', $r['id']]);
    q('DELETE FROM registrations WHERE id = ?', [$r['id']]);
}

/* ---------- Badges & formatting ---------- */

function status_badge(string $status): string
{
    return '<span class="badge st-' . e($status) . '">' . e(statuses()[$status] ?? ucfirst($status)) . '</span>';
}

function payment_badge(string $status): string
{
    return '<span class="badge pay-' . e($status) . '">' . e(payment_statuses()[$status] ?? ucfirst($status)) . '</span>';
}

function txn_badge(string $status): string
{
    $map = ['success' => 'st-confirmed', 'pending' => 'st-pending', 'failed' => 'st-cancelled'];
    return '<span class="badge ' . ($map[$status] ?? '') . '">' . e(ucfirst($status)) . '</span>';
}

function money_cell(int $amount, string $cur = 'UGX'): string
{
    return '<span class="money">' . e($cur) . ' <b>' . number_format($amount) . '</b></span>';
}

function progress_bar(array $r): string
{
    $pct = paid_percent($r);
    $cls = $pct >= 100 ? 'full' : ($pct > 0 ? 'booked' : 'low');
    return '<div class="mini-progress ' . $cls . '" title="' . $pct . '% paid"><i style="width:' . $pct . '%"></i></div>';
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    return date('j M Y', strtotime($datetime));
}

function avatar_html(array $r, string $cls = 'av'): string
{
    return photo_path($r['photo'] ?? null)
        ? '<img class="' . $cls . '" src="../photo.php?id=' . (int) $r['id'] . '" alt="" loading="lazy">'
        : '<span class="' . $cls . ' ini">' . e(initials($r['full_name'])) . '</span>';
}

/** Tiny bar chart from [label => value] (last N days). */
function bar_chart(array $series, string $suffix = '', string $class = ''): string
{
    $max = max(1, max($series ?: [0]));
    $html = '<div class="chart ' . $class . '">';
    foreach ($series as $d => $v) {
        $html .= '<div class="chart-col" title="' . e(date('D j M', strtotime($d))) . ': ' . e(number_format($v)) . $suffix . '">'
            . '<span class="chart-val">' . ($v ? e($v >= 1000 ? round($v / 1000) . 'k' : (string) $v) : '') . '</span>'
            . '<i style="height:' . max(2, round($v / $max * 100)) . '%" class="' . ($v ? '' : 'zero') . '"></i>'
            . '<small>' . date('j', strtotime($d)) . '</small></div>';
    }
    return $html . '</div>';
}

function days_series(string $sql, int $days = 30): array
{
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $series[date('Y-m-d', strtotime("-$i days"))] = 0;
    }
    foreach (q($sql, [date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'))])->fetchAll() as $row) {
        if (isset($series[$row['d']])) {
            $series[$row['d']] = (int) $row['v'];
        }
    }
    return $series;
}

function bar_list(array $items, int $total, string $color = 'red'): string
{
    if (!$items) {
        return '<p class="empty-note">No data yet.</p>';
    }
    $html = '<ul class="bar-list">';
    foreach ($items as $it) {
        $pct = $total ? round($it['c'] / $total * 100) : 0;
        $html .= '<li><div class="bl-top"><span>' . e($it['label']) . '</span><b>' . number_format((int) $it['c']) . ' <small>' . $pct . '%</small></b></div>'
            . '<div class="bl-track"><i class="bl-' . $color . '" style="width:' . max(2, $pct) . '%"></i></div></li>';
    }
    return $html . '</ul>';
}

/* ---------- Layout ---------- */

function admin_header(string $title, string $active = '', string $subtitle = '', array $css = []): void
{
    $admin = current_admin();
    $pending = (int) db()->query("SELECT COUNT(*) FROM registrations WHERE status = 'review'")->fetchColumn();
    $unread = (int) db()->query('SELECT COUNT(*) FROM messages WHERE is_read = 0')->fetchColumn();
    $pendingPay = (int) db()->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
    $groups = [
        'Overview' => [
            'dashboard' => ['index.php', 'fa-gauge-high', 'Dashboard', 0],
            'analytics' => ['analytics.php', 'fa-chart-line', 'Website analytics', 0],
        ],
        'Participants' => [
            'registrations' => ['registrations.php', 'fa-users', 'Participants', $pending],
            'mentorship'    => ['mentorship.php', 'fa-handshake-angle', 'Mentorship & DBIP', 0],
            'flyers'        => ['flyers.php', 'fa-image-portrait', '“I will be there” flyers', 0],
            'messages'      => ['messages.php', 'fa-envelope', 'Messages', $unread],
        ],
        'Finance' => [
            'finance'  => ['finance.php', 'fa-chart-pie', 'Finance overview', 0],
            'take'     => ['take-payment.php', 'fa-hand-holding-dollar', 'Take a payment', 0],
            'payments' => ['payments.php', 'fa-money-bill-transfer', 'Payments', $pendingPay],
            'sponsors' => ['sponsors.php', 'fa-hand-holding-heart', 'Sponsorships', 0],
        ],
        'Marketing' => [
            'campaigns' => ['marketing.php', 'fa-bullhorn', 'Email campaigns', 0],
            'contacts'  => ['marketing-contacts.php', 'fa-address-book', 'Email contacts', 0],
        ],
        'Website' => [
            'team' => ['team.php', 'fa-people-group', 'Core team', 0],
        ],
        'System' => [
            'emails'   => ['emails.php', 'fa-paper-plane', 'Email log', 0],
            'settings' => ['settings.php', 'fa-gear', 'Settings', 0],
            'users'    => ['users.php', 'fa-user-shield', 'Admin users', 0],
        ],
    ];
    $flash = flash();
    $sandbox = iotec()['sandbox'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> · Kakebe Tech Camp Admin</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
  <?php foreach ($css as $href): ?><link rel="stylesheet" href="<?= e($href) ?>" referrerpolicy="no-referrer"><?php endforeach; ?>
</head>
<body class="admin">
<script>try { if (localStorage.getItem('kt_sb_mini') === '1') document.body.classList.add('sb-mini'); } catch (e) {}</script>
<aside class="sidebar" id="sidebar">
  <a href="index.php" class="sb-brand" title="Dashboard">
    <img class="sb-full-logo" src="../assets/img/techcamp-logo-email.png" alt="Kakebe Tech Camp 2026">
    <img class="sb-mini-logo" src="../assets/img/favicon.png" alt="Kakebe Tech Camp 2026">
    <span>Control Panel</span>
  </a>
  <nav class="sb-nav">
    <?php foreach ($groups as $group => $items): ?>
      <p class="sb-group"><?= e($group) ?></p>
      <?php foreach ($items as $key => [$href, $icon, $label, $count]): ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>" title="<?= e($label) ?>"><i class="fa-solid <?= $icon ?>"></i><span><?= $label ?></span><?php if ($count): ?><em><?= $count ?></em><?php endif; ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sb-foot">
    <button type="button" class="sb-collapse" id="sbCollapse" title="Collapse or expand the menu"><i class="fa-solid fa-angles-left"></i><span>Collapse menu</span></button>
    <a href="../" target="_blank" title="View website"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>View website</span></a>
    <a href="logout.php" title="Log out"><i class="fa-solid fa-right-from-bracket"></i><span>Log out</span></a>
  </div>
</aside>
<div class="sb-overlay" id="sbOverlay"></div>
<div class="main">
  <header class="topbar">
    <button class="sb-toggle" id="sbToggle" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button>
    <div class="tb-title"><h1><?= e($title) ?></h1><?php if ($subtitle): ?><p><?= e($subtitle) ?></p><?php endif; ?></div>
    <div class="tb-right">
      <?php if ($sandbox): ?><span class="mode-pill test" title="ioTec sandbox — no real money"><i class="fa-solid fa-flask"></i> Test payments</span><?php else: ?><span class="mode-pill live"><i class="fa-solid fa-bolt"></i> Live payments</span><?php endif; ?>
      <form action="registrations.php" method="get" class="tb-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" placeholder="Search participants…"></form>
      <div class="tb-user"><span class="tb-avatar"><?= e(mb_strtoupper(mb_substr($admin['name'] ?? 'A', 0, 1))) ?></span><span class="tb-name"><?= e($admin['name'] ?? '') ?><small><?= e($admin['email'] ?? '') ?></small></span></div>
    </div>
  </header>
  <main class="content">
    <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" data-autohide><i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i> <span><?= e($flash[1]) ?></span></div><?php endif; ?>
    <?php
}

/** $js: extra scripts — full URLs, or files in admin/assets (versioned by file time). */
function admin_footer(array $js = []): void
{
    ?>
  </main>
</div>
<script src="assets/admin.js?v=<?= filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
<?php foreach ($js as $src): ?><script src="<?= e(str_starts_with($src, 'https://') ? $src : 'assets/' . $src . '?v=' . filemtime(__DIR__ . '/assets/' . $src)) ?>"<?= str_starts_with($src, 'https://') ? ' referrerpolicy="no-referrer"' : '' ?>></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}

/** Simple page shell for login/setup screens. */
function auth_header(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> · Kakebe Tech Camp Admin</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="assets/admin.css?v=<?= filemtime(__DIR__ . '/assets/admin.css') ?>">
</head>
<body class="auth">
  <div class="auth-wrap">
    <div class="auth-side">
      <img src="../assets/img/techcamp-logo.webp" alt="Kakebe Tech Camp 2026" class="auth-logo">
      <p>Manage participants, payments, sponsorships, receipts and the camp team for Kakebe Tech Camp 2026.</p>
    </div>
    <div class="auth-card">
    <?php
}

function auth_footer(): void
{
    echo '</div></div></body></html>';
}
