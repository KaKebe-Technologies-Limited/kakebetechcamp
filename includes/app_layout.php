<?php
/**
 * Shared shell for the checkout, payment-return and participant-portal pages.
 * $base is the relative path back to the site root ('' for root pages, '../' for /portal).
 */

/** $seo: ['description' => …, 'canonical' => …] makes the page indexable by search engines. */
function app_header(string $title, string $base = '', string $active = '', array $seo = []): void
{
    $p = current_participant();
    $css = $base . 'assets/css/app.css?v=' . filemtime(ROOT . '/assets/css/app.css');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($seo): ?>
  <meta name="description" content="<?= e($seo['description'] ?? '') ?>">
  <link rel="canonical" href="<?= e($seo['canonical'] ?? '') ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e($title) ?> · Kakebe Tech Camp 2026">
  <meta property="og:description" content="<?= e($seo['description'] ?? '') ?>">
  <meta property="og:url" content="<?= e($seo['canonical'] ?? '') ?>">
  <meta property="og:image" content="<?= e(base_url('assets/img/techcamp-flyer.webp')) ?>">
<?php else: ?>
  <meta name="robots" content="noindex">
<?php endif; ?>
<?= ga_tag() ?>
  <title><?= e($title) ?> · Kakebe Tech Camp 2026</title>
  <link rel="icon" href="<?= $base ?>favicon.ico" sizes="any">
  <link rel="icon" type="image/png" href="<?= $base ?>assets/img/favicon.png">
  <link rel="apple-touch-icon" href="<?= $base ?>apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="<?= e($css) ?>">
</head>
<body class="app">
<header class="app-top">
  <div class="app-top-inner">
    <nav class="app-nav left">
      <a href="<?= $base ?>./"><i class="fa-solid fa-house"></i><span>Website</span></a>
      <a href="<?= $base ?>./#faq"><i class="fa-regular fa-circle-question"></i><span>FAQ</span></a>
    </nav>
    <a href="<?= $base ?>./" class="app-logo"><img src="<?= $base ?>assets/img/techcamp-logo-560.webp" alt="Kakebe Tech Camp 2026" width="560" height="280"></a>
    <nav class="app-nav right">
      <?php if ($p): ?>
        <a href="<?= $base ?>portal/" class="<?= $active === 'portal' ? 'active' : '' ?>"><i class="fa-solid fa-user"></i><span>My portal</span></a>
        <a href="<?= $base ?>portal/logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Log out</span></a>
      <?php else: ?>
        <a href="<?= $base ?>portal/login.php" class="<?= $active === 'login' ? 'active' : '' ?>"><i class="fa-solid fa-user"></i><span>My portal</span></a>
        <a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i><span>Help</span></a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="app-main">
    <?php
}

function app_footer(string $base = '', array $scripts = []): void
{
    ?>
</main>
<footer class="app-foot">
  <p><i class="fa-solid fa-headset"></i> Support line: <a href="<?= e(tel_link(setting('contact_phone'))) ?>"><?= e(setting('contact_phone')) ?></a> · <a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener">WhatsApp</a></p>
  <p class="muted">© <?= date('Y') ?> Kakebe Technologies Limited</p>
</footer>
<script src="<?= $base ?>assets/js/payment.js?v=<?= filemtime(ROOT . '/assets/js/payment.js') ?>"></script>
<?php foreach ($scripts as $src): ?><script src="<?= $base . e($src) ?>?v=<?= filemtime(ROOT . '/' . $src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}

/** Package summary card for a registration. */
function package_card(array $r): string
{
    $bal = balance($r);
    $pct = paid_percent($r);
    ob_start(); ?>
    <div class="pkg">
      <div class="pkg-head">
        <div><small>Reference</small><b><?= e($r['reference']) ?></b></div>
        <span class="pill st-<?= e($r['status']) ?>"><?= e(statuses()[$r['status']] ?? $r['status']) ?></span>
      </div>
      <table class="pkg-items">
        <?php foreach (order_items($r) as [$label, $amt, $tag]): ?>
        <tr><td><?= e($label) ?> <em class="<?= $amt ? '' : 'free' ?>"><?= e($tag) ?></em></td><td><?= $amt ? e(format_ugx($amt)) : 'FREE' ?></td></tr>
        <?php endforeach; ?>
        <tr class="total"><td>Total package</td><td><?= e(format_ugx($r['total_amount'])) ?></td></tr>
      </table>
      <div class="pkg-progress">
        <div class="pp-row"><span>Paid <b><?= e(format_ugx($r['amount_paid'])) ?></b></span><span>Balance <b class="<?= $bal ? 'due' : 'ok' ?>"><?= e(format_ugx($bal)) ?></b></span></div>
        <div class="bar"><i style="width: <?= $pct ?>%"></i></div>
        <small><?= $bal ? 'Pay the full amount to get your camp ticket' : 'Fully paid — your ticket is ready' ?></small>
      </div>
    </div>
    <?php return (string) ob_get_clean();
}

/** Payment form (Mobile Money / card). Works for signed links ($token) or portal sessions. */
function pay_form(array $r, string $base = '', bool $viaToken = true): string
{
    $bal = balance($r);
    ob_start(); ?>
    <form class="pay-form js-pay-form" action="<?= $base ?>api/pay.php" data-status="<?= $base ?>api/payment-status.php" novalidate>
      <?= csrf_field() ?>
      <?php if ($viaToken): ?>
        <input type="hidden" name="ref" value="<?= e($r['reference']) ?>">
        <input type="hidden" name="t" value="<?= e(sign('pay', $r['reference'])) ?>">
      <?php endif; ?>
      <div class="pay-body">
        <div class="pay-due">
          <span>Amount to pay</span>
          <b><?= e(format_ugx($bal)) ?></b>
          <small><?= (int) $r['amount_paid'] > 0 ? 'Remaining balance' : 'Full camp package' ?> · your ticket is emailed once paid</small>
        </div>
        <input type="hidden" name="amount" value="<?= $bal ?>">

        <label class="lbl">Payment method</label>
        <div class="methods">
          <label class="method"><input type="radio" name="method" value="mobile_money" checked><span><i class="fa-solid fa-mobile-screen-button"></i><b>Mobile Money</b><small>MTN · Airtel</small></span></label>
          <label class="method"><input type="radio" name="method" value="card"><span><i class="fa-regular fa-credit-card"></i><b>Card</b><small>Visa · Mastercard</small></span></label>
        </div>
        <div class="field js-mm">
          <label for="pay_phone">Mobile Money number</label>
          <input id="pay_phone" name="pay_phone" type="tel" value="<?= e($r['phone']) ?>" placeholder="e.g. 0772 123 456">
          <span class="hint">You'll get a prompt on this phone — enter your PIN to approve.</span>
          <span class="err" data-err="pay_phone"></span>
        </div>
        <p class="hint js-card" hidden><i class="fa-solid fa-lock"></i> <?= e(card_page_hint()) ?></p>
        <div class="form-alert" hidden></div>
        <button class="btn btn-primary btn-block js-pay-btn" type="submit"><span class="btn-label"><i class="fa-solid fa-lock"></i> Pay <span class="js-amt"><?= e(format_ugx($bal)) ?></span></span><span class="btn-loading"><span class="spinner"></span> Starting payment…</span></button>
      </div>
      <div class="pay-state" hidden></div>
    </form>
    <?php return (string) ob_get_clean();
}

function payments_list(array $r, string $base = ''): string
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE registration_id = ? ORDER BY id DESC');
    $stmt->execute([$r['id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return '<p class="muted">No payments yet.</p>';
    }
    ob_start(); ?>
    <ul class="pay-list">
      <?php foreach ($rows as $p): ?>
      <li>
        <span class="pl-icon st-<?= e($p['status']) ?>"><i class="fa-solid <?= $p['status'] === 'success' ? 'fa-check' : ($p['status'] === 'failed' ? 'fa-xmark' : 'fa-clock') ?>"></i></span>
        <div><b><?= e(format_ugx($p['amount'], $p['currency'])) ?></b><small><?= e(payment_methods()[$p['method']] ?? $p['method']) ?> · <?= e(date('j M Y, g:i a', strtotime($p['created_at']))) ?></small></div>
        <?php if ($p['status'] === 'success'): ?>
          <a href="<?= e(receipt_url($p)) ?>" target="_blank" class="pl-link"><i class="fa-solid fa-file-pdf"></i> <?= e(receipt_no($p)) ?></a>
        <?php else: ?>
          <span class="pl-status"><?= e(ucfirst($p['status'])) ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php return (string) ob_get_clean();
}
