<?php
/**
 * Participant camp ticket.
 * Opened from the signed link emailed to the participant (?ref=&t=), by the participant in the portal, or by an admin (?id=).
 * Valid once the camp package is fully paid (or waived by an admin).
 */
require __DIR__ . '/includes/bootstrap.php';

$r = null;
$isAdmin = !empty($_SESSION['admin_id']);
if ($isAdmin && isset($_GET['id'])) {
    $r = find_registration((int) $_GET['id']);
} elseif (isset($_GET['ref']) && is_string($_GET['ref']) && sign_valid('ticket', $_GET['ref'], $_GET['t'] ?? null)) {
    $r = find_registration_by_ref($_GET['ref']);
} elseif (isset($_GET['me']) && ($p = current_participant())) {
    $r = $p;
}

$valid = $r && ticket_valid($r);
$showTicket = $r && ($valid || $isAdmin);

if ($showTicket && ($_GET['format'] ?? '') === 'pdf') {
    $pdf = ticket_pdf($r);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="Kakebe-Tech-Camp-Ticket-' . $r['reference'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}
$pdfUrl = $r ? (strtok($_SERVER['REQUEST_URI'], '#') . (str_contains($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'format=pdf&download=1') : '';

if ($r) {
    $photoUrl = photo_path($r['photo']) ? 'photo.php?ref=' . rawurlencode($r['reference']) . '&t=' . ticket_token($r['reference']) : null;
    $details = [['Dates', camp()['dates']], ['Duration', '10 days · Residential'], ['Venue', camp()['venue']]];
    [$payLabel, $payText] = ticket_payment($r);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $r ? e($r['reference'] . ' · ' . $r['full_name']) . ' — ' : '' ?>Kakebe Tech Camp Ticket</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <style>
    :root { --red: #E11D2A; --navy: #0F2557; --ink: #101935; --muted: #6B7390; --line: #E7EAF3; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; font-family: 'Plus Jakarta Sans', system-ui, sans-serif; color: var(--ink); background: radial-gradient(900px 500px at 90% 0%, rgba(225,29,42,.1), transparent 60%), #F2F4F9; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 30px 16px; }
    a { color: var(--red); font-weight: 700; }
    .toolbar { width: 100%; max-width: 900px; display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
    .toolbar a.home { display: inline-flex; align-items: center; gap: 8px; color: var(--navy); text-decoration: none; }
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; border-radius: 999px; border: 0; background: var(--red); color: #fff; font: inherit; font-weight: 700; cursor: pointer; box-shadow: 0 10px 24px rgba(225,29,42,.28); text-decoration: none; }
    .ticket { width: 100%; max-width: 900px; display: grid; grid-template-columns: 1fr 250px; background: #fff; border-radius: 26px; overflow: hidden; box-shadow: 0 30px 70px rgba(15,37,87,.16); }
    .main { padding: 30px 34px; position: relative; }
    .main::after { content: ''; position: absolute; right: 0; top: 16px; bottom: 16px; border-right: 2px dashed var(--line); }
    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
    .head img { height: 88px; width: auto; }
    .pass { text-align: right; }
    .pass small { display: block; font-size: 11px; font-weight: 800; letter-spacing: .16em; color: var(--muted); }
    .pass b { display: inline-block; margin-top: 6px; padding: 6px 14px; border-radius: 999px; background: #FFF1F1; color: var(--red); font-size: 12px; letter-spacing: .1em; }
    .who { display: flex; gap: 20px; align-items: center; margin: 24px 0 22px; }
    .photo { width: 112px; height: 132px; border-radius: 16px; overflow: hidden; flex-shrink: 0; background: linear-gradient(135deg, var(--red), #FF6A2F); color: #fff; display: grid; place-items: center; font-size: 38px; font-weight: 800; border: 4px solid #fff; box-shadow: 0 8px 20px rgba(15,37,87,.15); }
    .photo img { width: 100%; height: 100%; object-fit: cover; }
    .who small { font-size: 11px; font-weight: 800; letter-spacing: .14em; color: var(--muted); }
    .who h1 { margin: 2px 0 6px; font-size: 28px; line-height: 1.15; color: var(--navy); }
    .who p { margin: 0; color: var(--muted); font-weight: 600; font-size: 14px; }
    .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .cell { background: #F6F8FC; border-radius: 14px; padding: 12px 14px; }
    .cell small { display: block; font-size: 10.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
    .cell b { font-size: 14px; }
    .extras { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
    .extras span { padding: 6px 12px; border-radius: 999px; background: #EEF3FF; color: var(--navy); font-size: 12px; font-weight: 700; }
    .paid { margin-top: 14px; padding: 12px 16px; border-radius: 14px; background: #E9F8F0; border-left: 5px solid #14804A; }
    .paid.no { background: #FFF4E5; border-left-color: #B54708; }
    .paid small { display: block; font-size: 11px; font-weight: 800; letter-spacing: .1em; color: #14804A; }
    .paid.no small { color: #B54708; }
    .paid b { font-size: 14px; }
    .foot { margin-top: 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 12px; color: var(--muted); }
    .stub { background: linear-gradient(170deg, var(--navy), #1B3A8C); color: #fff; padding: 28px 22px; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center; gap: 16px; }
    .stub small { font-size: 10.5px; letter-spacing: .16em; font-weight: 800; opacity: .75; }
    .stub .ref { font-size: 24px; font-weight: 800; letter-spacing: .05em; }
    #qr { background: #fff; padding: 10px; border-radius: 14px; }
    #qr img, #qr canvas { display: block; width: 140px !important; height: 140px !important; }
    .stamp { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 999px; font-weight: 800; font-size: 13px; letter-spacing: .08em; }
    .stamp.ok { background: rgba(34,197,94,.18); color: #7CF0A6; border: 1px solid rgba(124,240,166,.4); }
    .stamp.no { background: rgba(245,165,36,.18); color: #FFD37A; border: 1px solid rgba(255,211,122,.4); }
    .notice { max-width: 900px; width: 100%; margin-bottom: 16px; padding: 12px 18px; border-radius: 14px; background: #FFF8E6; border: 1px solid #F8D98B; color: #7A5200; font-weight: 600; font-size: 14px; }
    .empty { max-width: 520px; text-align: center; background: #fff; padding: 40px 30px; border-radius: 24px; box-shadow: 0 20px 50px rgba(15,37,87,.1); }
    .empty i { font-size: 40px; color: var(--red); }
    .empty h1 { font-size: 24px; margin: 14px 0 8px; color: var(--navy); }
    .empty p { color: var(--muted); }
    @media (max-width: 760px) {
      .ticket { grid-template-columns: 1fr; }
      .main::after { display: none; }
      .grid { grid-template-columns: 1fr 1fr; }
      .head img { height: 64px; }
      .who h1 { font-size: 22px; }
    }
    @media print {
      body { background: #fff; padding: 0; display: block; }
      .toolbar, .notice { display: none !important; }
      .ticket { box-shadow: none; border: 1px solid #ccc; margin: 0 auto; grid-template-columns: 1fr 230px; }
      * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      @page { size: A4 landscape; margin: 14mm; }
    }
  </style>
</head>
<body>
<?php if (!$r): ?>
  <div class="empty">
    <i class="fa-solid fa-ticket"></i>
    <h1>Ticket not found</h1>
    <p>This ticket link is invalid. Please use the link in your email, or contact us on <?= e(setting('contact_phone')) ?>.</p>
    <a class="btn" href="./"><i class="fa-solid fa-house"></i> Go to website</a>
  </div>
<?php elseif (!$showTicket): ?>
  <div class="empty">
    <i class="fa-solid fa-hourglass-half"></i>
    <h1>Your ticket isn't ready yet</h1>
    <p>Hi <?= e(explode(' ', $r['full_name'])[0]) ?>, your registration <b><?= e($r['reference']) ?></b> is saved. Your ticket becomes available once your camp package is fully paid (balance: <b><?= e(format_ugx(balance($r))) ?></b>).</p>
    <a class="btn" href="<?= e(pay_url($r)) ?>"><i class="fa-solid fa-wallet"></i> Pay now</a>
  </div>
<?php else: ?>
  <?php if (!$valid): ?><div class="notice"><i class="fa-solid fa-eye"></i> Admin preview — this ticket is not yet valid for the participant (status: <?= e(statuses()[$r['status']] ?? $r['status']) ?>, balance <?= e(format_ugx(balance($r))) ?>).</div><?php endif; ?>
  <div class="toolbar">
    <a class="home" href="./"><i class="fa-solid fa-arrow-left"></i> Kakebe Tech Camp</a>
    <span style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="<?= e($pdfUrl) ?>"><i class="fa-solid fa-file-pdf"></i> Download PDF ticket</a>
      <button class="btn" style="background:#0F2557;box-shadow:none;" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </span>
  </div>
  <article class="ticket">
    <div class="main">
      <div class="head">
        <img src="assets/img/techcamp-logo.webp" alt="Kakebe Tech Camp 2026">
        <div class="pass"><small>PARTICIPANT TICKET</small><b>TECH CAMP</b></div>
      </div>
      <div class="who">
        <div class="photo"><?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt="Participant photo"><?php else: ?><?= e(initials($r['full_name'])) ?><?php endif; ?></div>
        <div>
          <small>PARTICIPANT</small>
          <h1><?= e($r['full_name']) ?></h1>
          <p><?= e($r['interests'] ?: 'Kakebe Tech Camp 2026') ?></p>
          <p><?= e($r['district']) ?>, <?= e($r['country']) ?> · Age <?= (int) $r['age'] ?></p>
        </div>
      </div>
      <div class="grid">
        <?php foreach ($details as [$label, $value]): ?><div class="cell"><small><?= e($label) ?></small><b><?= e($value) ?></b></div><?php endforeach; ?>
      </div>
      <div class="extras">
        <span><i class="fa-solid fa-shirt"></i> Jersey <?= e($r['jersey_size'] ?: '—') ?> + camp shirt</span>
        <?php if ($r['park_visit']): ?><span><i class="fa-solid fa-water"></i> <?= e(fees()['park_name']) ?> visit</span><?php endif; ?>
        <?php if ($r['mentorship']): ?><span><i class="fa-solid fa-people-arrows"></i> Mentorship &amp; Digital Bridge</span><?php endif; ?>
      </div>
      <div class="paid<?= $valid ? '' : ' no' ?>"><small><?= e($payLabel) ?></small><b><?= e($payText) ?></b></div>
      <div class="foot">
        <span><i class="fa-solid fa-phone"></i> Support: <?= e(setting('contact_phone')) ?></span>
        <span>Present this ticket (printed or on your phone) at check-in.</span>
      </div>
    </div>
    <aside class="stub">
      <div><small>CODE NUMBER</small><div class="ref"><?= e($r['reference']) ?></div></div>
      <div id="qr" aria-label="Ticket QR code"></div>
      <span class="stamp <?= $valid ? 'ok' : 'no' ?>"><i class="fa-solid <?= $valid ? 'fa-circle-check' : 'fa-clock' ?>"></i> <?= $valid ? 'SEAT CONFIRMED' : 'NOT YET VALID' ?></span>
    </aside>
  </article>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
    new QRCode(document.getElementById('qr'), { text: <?= json_encode(ticket_url($r)) ?>, width: 280, height: 280, colorDark: '#0F2557', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
  </script>
<?php endif; ?>
</body>
</html>
