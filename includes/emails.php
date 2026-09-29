<?php
/**
 * Email templates — same structure as the ObiFunds notifications (branded header, accent strip,
 * detail boxes, button, footer) in Kakebe red/navy. Each tpl_* function returns [subject, html].
 */

function kt_email(string $subtitle, string $inner, string $preheader = ''): string
{
    $phone = e(setting('contact_phone', '0779 712 990'));
    $wa = e(whatsapp_link());
    $site = e(base_url());
    $siteLabel = e(preg_replace('~^https?://~', '', base_url()));
    $year = date('Y');
    $pre = e($preheader);
    $sub = e($subtitle);

    return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$sub}</title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #1B2340; margin: 0; background: #f1f2f5; }
  .container { max-width: 600px; margin: 0 auto; padding: 20px; }
  .header { background: #E11D2A; background: linear-gradient(135deg, #E11D2A, #A60F1A); padding: 26px 24px; border-radius: 14px 14px 0 0; }
  .header-row { display: table; width: 100%; }
  .logo-cell { display: table-cell; vertical-align: middle; width: 48px; }
  .logo-box { width: 42px; height: 42px; background: #ffffff; border-radius: 10px; color: #E11D2A; font-weight: 800; font-size: 16px; text-align: center; line-height: 42px; letter-spacing: -.5px; }
  .brand-cell { display: table-cell; vertical-align: middle; padding-left: 12px; }
  .brand-cell h1 { margin: 0; color: #ffffff; font-size: 18px; }
  .brand-cell p { margin: 3px 0 0; color: #FFD9D6; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; }
  .accent { height: 5px; background: #0F2557; }
  .content { background: #ffffff; padding: 30px 28px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 14px 14px; font-size: 15px; line-height: 1.6; }
  .content p { margin: 0 0 14px; }
  .detail { margin: 18px 0; padding: 16px 18px; background: #F8FAFC; border-radius: 10px; border-left: 4px solid #E11D2A; }
  .detail p { margin: 0 0 8px; }
  .detail p:last-child { margin: 0; }
  .detail.navy { border-left-color: #0F2557; }
  .detail.green { border-left-color: #14804A; background: #F2FBF6; }
  .label { font-weight: bold; color: #0F2557; }
  .btn { display: inline-block; padding: 13px 28px; background: #E11D2A; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: 700; }
  .btn.navy { background: #0F2557; }
  .btn.whatsapp { background: #128C7E; }
  .ref { background: #FFF2F2; border: 2px dashed #F4A6A0; border-radius: 12px; padding: 14px; text-align: center; margin: 18px 0; }
  .ref small { display: block; font-size: 11px; letter-spacing: .14em; color: #B5121B; font-weight: 800; }
  .ref b { display: block; font-size: 26px; color: #0F2557; letter-spacing: 1px; margin-top: 4px; }
  .items { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 14px; }
  .items td { padding: 10px 12px; border-bottom: 1px solid #EEF0F5; }
  .items tr.head td { background: #0F2557; color: #ffffff; font-weight: bold; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
  .items td.amt { text-align: right; font-weight: bold; white-space: nowrap; }
  .items tr.total td { background: #FFF2F2; font-weight: bold; color: #0F2557; font-size: 15px; }
  .tag { display: inline-block; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 99px; background: #EEF1F8; color: #475067; text-transform: uppercase; margin-left: 6px; }
  .tag.free { background: #E6F7EE; color: #14804A; }
  .bar { height: 10px; background: #EEF1F8; border-radius: 99px; overflow: hidden; margin: 8px 0 4px; }
  .bar i { display: block; height: 10px; background: #14804A; border-radius: 99px; }
  .badge { display: inline-block; padding: 4px 12px; border-radius: 99px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
  .support { margin-top: 22px; padding: 14px 16px; background: #0F2557; border-radius: 10px; color: #ffffff; font-size: 14px; }
  .support a { color: #ffffff; font-weight: bold; }
  .footer { text-align: center; padding: 20px; color: #9aa0ab; font-size: 12px; }
  .footer a { color: #9aa0ab; }
</style></head>
<body>
<span style="display:none!important;opacity:0;color:transparent;max-height:0;overflow:hidden;">{$pre}</span>
<div class="container">
  <div class="header">
    <div class="header-row">
      <div class="logo-cell"><div class="logo-box">KT</div></div>
      <div class="brand-cell"><h1>Kakebe Tech Camp 2026</h1><p>{$sub}</p></div>
    </div>
  </div>
  <div class="accent"></div>
  <div class="content">
    {$inner}
    <div class="support">📞 <b>Support line:</b> <a href="tel:{$phone}">{$phone}</a> &nbsp;·&nbsp; 💬 <a href="{$wa}">WhatsApp us</a></div>
  </div>
  <div class="footer"><p>&copy; {$year} Kakebe Technologies Limited · Learn. Build. Innovate.<br><a href="{$site}">{$siteLabel}</a></p></div>
</div>
</body></html>
HTML;
}

function kt_btn(string $label, string $url, string $class = ''): string
{
    return "<p style='text-align:center;margin:24px 0;'><a href='" . e($url) . "' class='btn $class'>" . e($label) . '</a></p>';
}

/** Label/value rows. Values are escaped, except the labels listed in $htmlKeys (already-safe HTML). */
function kt_detail(array $rows, string $class = '', array $htmlKeys = []): string
{
    $html = "<div class='detail $class'>";
    foreach ($rows as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $html .= "<p><span class='label'>" . e($label) . ':</span> ' . (in_array($label, $htmlKeys, true) ? (string) $value : nl2br(e((string) $value))) . '</p>';
    }
    return $html . '</div>';
}

function kt_items(array $r): string
{
    $html = "<table class='items' role='presentation'><tr class='head'><td>Item</td><td class='amt'>Amount</td></tr>";
    foreach (order_items($r) as [$label, $amt, $tag]) {
        $html .= '<tr><td>' . e($label) . " <span class='tag " . ($amt ? '' : 'free') . "'>" . e($tag) . "</span></td><td class='amt'>" . ($amt ? e(format_ugx($amt)) : 'FREE') . '</td></tr>';
    }
    return $html . "<tr class='total'><td>Total</td><td class='amt'>" . e(format_ugx($r['total_amount'])) . '</td></tr></table>';
}

function first_name(string $name): string
{
    return e(explode(' ', trim($name))[0]);
}

function tpl_applicant_received(array $r): array
{
    $setup = password_reset_link($r, 7 * 24 * 60);
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p>'
        . '<p>Thank you for registering for <strong>Kakebe Tech Camp 2026</strong>! 🎉 Your application has been received.</p>'
        . "<div class='ref'><small>YOUR REFERENCE NUMBER</small><b>" . e($r['reference']) . '</b></div>'
        . kt_detail([
            '📅 Camp dates' => camp()['dates'] . ' (10 days, residential)',
            '📍 Venue' => camp()['venue'],
            '🎯 Learning tracks' => $r['interests'] ?: '—',
            '👕 Jersey size' => $r['jersey_size'] ?: '—',
        ], 'navy');
    if ($group = whatsapp_group_link()) {
        $inner .= '<p style="margin-top:20px;"><strong>💬 Join the Tech Camp WhatsApp group</strong><br>Get camp updates and announcements, and meet your fellow innovators. Tap the button to join.</p>'
            . kt_btn('Join the WhatsApp group', $group, 'whatsapp');
    }

    if (is_sponsored($r)) {
        $inner .= kt_detail([
            '🤝 Sponsorship' => 'You indicated that your camp fees are covered by ' . ($r['sponsor_name'] ?: 'a sponsor') . '.',
            '⏳ Next step' => 'Our team is confirming your sponsorship. We will email you as soon as it is approved — no payment is needed from you in the meantime.',
        ]);
    } else {
        $inner .= '<p style="margin-top:20px;"><strong>Your camp package</strong></p>' . kt_items($r)
            . kt_detail([
                '🗓️ When to pay' => 'Any time before camp. The package (' . format_ugx($r['total_amount']) . ') is paid in full in one payment — your camp ticket is emailed as soon as it is paid.',
                '📱 How to pay' => 'Click the button below and pay with Mobile Money (MTN/Airtel) or Visa/Mastercard — or log in to your participant dashboard (with Google or your email) and pay from there.',
                '🧾 Receipt' => 'A PDF receipt is emailed to you after your payment.',
            ])
            . kt_btn('Pay now or view my registration', pay_url($r));
    }

    $inner .= ($r['mentorship'] ? kt_detail(['🎁 Bonus' => 'You are automatically enrolled — free — in the Kakebe Mentorship Program and Digital Bridge Internship (' . camp()['mentorship'] . ') with experienced industry professionals. Online sessions run every Monday, 8:00 – 9:30 PM.'], 'green') : '')
        . '<p><strong>Your participant dashboard</strong><br>Create a password to log in any time, update your profile and photo, see your tracks and download your ticket.</p>'
        . kt_btn('Create my password', $setup, 'navy')
        . '<p style="font-size:13px;color:#6B7390;">This link is valid for 7 days. You can always request a new one from the login page.</p>'
        . '<p><strong>Tell your friends you will be there 📸</strong><br>Upload your best photo and get a personalised “I will be there” flyer to share on WhatsApp and social media.</p>'
        . kt_btn('Make my “I will be there” flyer', base_url('flyer.php'));
    return ['🎉 Registration received — ' . $r['reference'], kt_email('Registration Received', $inner, 'Your reference number is ' . $r['reference'])];
}

function tpl_sponsorship_approved(array $r): array
{
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p>'
        . '<p>Great news — your sponsorship has been <strong style="color:#14804A;">approved</strong>. Your Kakebe Tech Camp 2026 package is fully covered' . ($r['sponsor_name'] ? ' by <strong>' . e($r['sponsor_name']) . '</strong>' : '') . ', and your place at camp is confirmed. 🎉</p>'
        . kt_detail(['🆔 Reference' => $r['reference'], '📅 Dates' => camp()['dates'], '📍 Venue' => camp()['venue'] . ' (residential)'], 'green')
        . kt_btn('View & download my ticket', ticket_url($r))
        . '<p>Log in to your participant dashboard to update your profile and photo and download your ticket as a PDF.</p>'
        . kt_btn('Go to my dashboard', base_url('portal/'), 'navy');
    return ['✅ Sponsorship approved — your place at Kakebe Tech Camp is confirmed', kt_email('Sponsorship Approved', $inner)];
}

function tpl_sponsorship_declined(array $r, string $note = ''): array
{
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p>'
        . '<p>Thank you for registering for Kakebe Tech Camp 2026. Unfortunately we were not able to confirm the sponsorship you selected' . ($r['sponsor_name'] ? ' (' . e($r['sponsor_name']) . ')' : '') . '.</p>'
        . ($note !== '' ? kt_detail(['💬 Note from our team' => $note]) : '')
        . '<p>Your registration is still saved. You can still attend by paying the camp package yourself (' . e(format_ugx($r['total_amount'])) . '), or contact us if you believe this is a mistake.</p>'
        . kt_btn('View my registration', pay_url($r));
    return ['Update on your Kakebe Tech Camp sponsorship — ' . $r['reference'], kt_email('Sponsorship Update', $inner)];
}

function tpl_password_reset(array $r, string $link): array
{
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p>'
        . '<p>We received a request to set or reset the password for your Kakebe Tech Camp participant dashboard.</p>'
        . kt_btn('Set a new password', $link)
        . '<p style="font-size:13px;color:#6B7390;">This link expires in 60 minutes and can only be used once. If you did not request it, you can ignore this email — your account is safe.</p>';
    return ['🔐 Reset your Kakebe Tech Camp password', kt_email('Password Reset', $inner)];
}

function tpl_admin_registration(array $r): array
{
    $inner = '<p><strong>Hi Team,</strong></p><p>A new participant just registered for Kakebe Tech Camp 2026.</p>'
        . kt_detail([
            '🆔 Reference' => $r['reference'],
            '👤 Name' => $r['full_name'] . ' (' . $r['age'] . ($r['gender'] ? ', ' . $r['gender'] : '') . ')',
            '📧 Email' => $r['email'],
            '📞 Phone' => "<a href='" . e(participant_whatsapp_link($r)) . "' style='color:#128C7E;font-weight:700;'>" . e($r['phone']) . "</a> <span style='color:#6B7390;'>(WhatsApp)</span> · <a href='" . e(tel_link($r['phone'])) . "' style='color:#0F2557;'>Call</a>",
            '📍 District' => $r['district'] . ', ' . $r['country'],
            '🤝 Funding' => is_sponsored($r) ? 'Sponsored by ' . ($r['sponsor_name'] ?: '—') . ' — NEEDS APPROVAL' : 'Self-funded',
            '🎯 Tracks' => $r['interests'],
            '👕 Jersey' => $r['jersey_size'],
            '🌊 ' . fees()['park_name'] => $r['park_visit'] ? 'Yes' : 'No',
            '📣 Heard via' => $r['source'] . ($r['source_other'] ? ' — ' . $r['source_other'] : ''),
            '🤝 Referred by' => $r['referred_by'],
            '💬 Motivation' => $r['motivation'],
        ], '', ['📞 Phone'])
        . kt_btn('💬 Welcome ' . explode(' ', trim($r['full_name']))[0] . ' on WhatsApp', participant_whatsapp_link($r), 'whatsapp')
        . "<p style='text-align:center;font-size:13px;color:#6B7390;margin-top:-12px;'>Opens WhatsApp with a welcome message from Moses already typed — just press send. If the number isn't on WhatsApp, use Call instead.</p>"
        . kt_detail(['💰 Package total' => format_ugx($r['total_amount']), '🕒 Submitted' => date('D, j M Y · g:i A', strtotime($r['created_at']))], 'navy')
        . kt_btn('Open in control panel', base_url('admin/view.php?id=' . $r['id']), 'navy');
    return ['🆕 New registration — ' . $r['full_name'] . ' (' . $r['reference'] . ')', kt_email('New Registration', $inner)];
}

function tpl_payment_receipt(array $r, array $p): array
{
    $bal = balance($r);
    $pct = paid_percent($r);
    $cur = $p['currency'];
    $full = $bal === 0;
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p>'
        . '<p>We have received your payment of <strong>' . e(format_ugx($p['amount'], $cur)) . '</strong> — thank you! Your receipt is attached as a PDF.</p>'
        . kt_detail([
            '🧾 Receipt No' => receipt_no($p),
            '🆔 Reference' => $r['reference'],
            '💳 Method' => payment_methods()[$p['method']] ?? $p['method'],
            '🕒 Date' => date('D, j M Y · g:i A', strtotime($p['completed_at'] ?: $p['created_at'])),
        ], 'navy')
        . "<div class='detail " . ($full ? 'green' : '') . "'>"
        . "<p><span class='label'>💰 Package total:</span> " . e(format_ugx($r['total_amount'], $cur)) . '</p>'
        . "<p><span class='label'>✅ Paid so far:</span> " . e(format_ugx($r['amount_paid'], $cur)) . '</p>'
        . "<p><span class='label'>" . ($full ? '🎉 Balance:' : '⏳ Balance to clear:') . '</span> <strong>' . e(format_ugx($bal, $cur)) . '</strong></p>'
        . "<div class='bar'><i style='width:{$pct}%'></i></div><p style='font-size:12px;color:#6B7390;'>{$pct}% paid</p></div>"
        . ($full
            ? '<p>🎟️ Your place at camp is <strong>confirmed</strong>. Your camp ticket is ready — bring it (printed or on your phone) to check-in.</p>' . kt_btn('View my camp ticket', ticket_url($r))
            : '<p>Please pay the remaining balance to confirm your place and receive your camp ticket.</p>' . kt_btn('Pay balance — ' . format_ugx($bal, $cur), pay_url($r)));
    $subject = $full ? '✅ Paid in full — your Kakebe Tech Camp place is confirmed' : '✅ Payment received — ' . format_ugx($p['amount'], $cur) . ' · balance ' . format_ugx($bal, $cur);
    return [$subject, kt_email('Payment Receipt', $inner, 'Receipt ' . receipt_no($p))];
}

function tpl_admin_payment(array $p, ?array $r, ?array $d): array
{
    $who = $r ? $r['full_name'] . ' (' . $r['reference'] . ')' : ($d ? $d['donor_name'] . ' (' . $d['reference'] . ')' : $p['payer_name']);
    $rows = [
        '👤 From' => $who,
        '💰 Amount' => format_ugx($p['amount'], $p['currency']),
        '🎯 For' => $p['purpose'] === 'camp' ? 'Camp fees' : 'Sponsorship / donation',
        '💳 Method' => (payment_methods()[$p['method']] ?? $p['method']) . ($p['provider'] === 'manual' ? ' (recorded manually)' : ' via ioTec'),
        '🧾 Receipt' => receipt_no($p),
        '🔗 Transaction' => $p['provider_txn_id'],
    ];
    if ($r) {
        $rows['⏳ Balance'] = format_ugx(balance($r), $p['currency']) . ' · ' . (statuses()[$r['status']] ?? $r['status']);
    }
    $link = $r ? base_url('admin/view.php?id=' . $r['id']) : base_url('admin/sponsors.php');
    $inner = '<p><strong>Hi Team,</strong></p><p>A payment has just been completed. 💸</p>' . kt_detail($rows) . kt_btn('Open in control panel', $link, 'navy');
    return ['💸 Payment received — ' . format_ugx($p['amount'], $p['currency']) . ' from ' . ($r['full_name'] ?? $d['donor_name'] ?? 'payer'), kt_email('Payment Notification', $inner)];
}

function tpl_donation_thanks(array $d, array $p): array
{
    $inner = '<p><strong>Dear ' . first_name($d['donor_name']) . ',</strong></p>'
        . '<p>Thank you for sponsoring Kakebe Tech Camp 2026! ❤️ We have received your contribution of <strong>' . e(format_ugx($p['amount'], $p['currency'])) . '</strong>. Your receipt is attached.</p>'
        . kt_detail([
            '🧾 Receipt No' => receipt_no($p),
            '🆔 Reference' => $d['reference'],
            '🌟 Innovators sponsored' => $d['children'] ? (string) $d['children'] : 'General support',
            '🏢 Organisation' => $d['organization'],
        ], 'green')
        . '<p>Your support gives young people from Northern Uganda 10 days of hands-on learning in AI, software, content creation, entrepreneurship, gaming and robotics. We will share updates and photos from camp with you.</p>';
    return ['❤️ Thank you for sponsoring Kakebe Tech Camp 2026', kt_email('Sponsorship Receipt', $inner)];
}

function tpl_admin_donation_pledge(array $d): array
{
    $inner = '<p><strong>Hi Team,</strong></p><p>A new sponsor has started a pledge on the website.</p>'
        . kt_detail(['👤 Sponsor' => $d['donor_name'], '🏢 Organisation' => $d['organization'], '📧 Email' => $d['email'], '📞 Phone' => $d['phone'], '🌟 Innovators' => $d['children'] ?: 'General', '💰 Amount' => format_ugx($d['amount']), '💬 Message' => $d['message']])
        . kt_btn('Open sponsorships', base_url('admin/sponsors.php'), 'navy');
    return ['🤝 New sponsorship pledge — ' . $d['donor_name'], kt_email('Sponsorship Pledge', $inner)];
}

function tpl_registration_link(string $link): array
{
    $inner = '<p><strong>Hello,</strong></p>'
        . '<p>Thank you for your interest in <strong>Kakebe Tech Camp 2026</strong> (' . e(camp()['dates']) . ', Kitgum). Click the button below to confirm this email address and continue your registration.</p>'
        . kt_btn('Continue my registration', $link)
        . '<p style="font-size:13px;color:#6B7390;">This link is valid for 24 hours. If the button does not work, copy this address into your browser:<br><a href="' . e($link) . '">' . e($link) . '</a></p>'
        . '<p style="font-size:13px;color:#6B7390;">If you did not start a registration, you can safely ignore this email.</p>';
    return ['Continue your Kakebe Tech Camp 2026 registration', kt_email('Registration Link', $inner, 'Confirm your email to continue registering')];
}

function tpl_login_code(array $r, string $code): array
{
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p><p>Use this code to log in to your Kakebe Tech Camp participant portal:</p>'
        . "<div class='ref'><small>YOUR LOGIN CODE</small><b style='letter-spacing:8px;'>" . e($code) . '</b></div>'
        . '<p>The code expires in 10 minutes. If you did not request it, you can ignore this email.</p>';
    return ['🔐 Your login code: ' . $code, kt_email('Portal Login', $inner, 'Your code is ' . $code)];
}

function tpl_balance_reminder(array $r): array
{
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p><p>This is a friendly reminder about your Kakebe Tech Camp 2026 package (' . e($r['reference']) . ').</p>'
        . kt_detail(['💰 Package total' => format_ugx($r['total_amount']), '✅ Paid so far' => format_ugx($r['amount_paid']), '⏳ Balance' => format_ugx(balance($r)), '📅 Camp' => camp()['dates']])
        . '<p>Pay the full amount to confirm your place and receive your camp ticket — places are limited to ' . seat_capacity() . '.</p>'
        . kt_btn('Pay now', pay_url($r));
    return ['⏰ Reminder: your Kakebe Tech Camp balance is ' . format_ugx(balance($r)), kt_email('Payment Reminder', $inner)];
}

function tpl_ticket(array $r): array
{
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p><p>Your place at <strong>Kakebe Tech Camp 2026</strong> is confirmed! 🎟️</p>'
        . kt_detail(['🆔 Reference' => $r['reference'], '📅 Dates' => camp()['dates'], '📍 Venue' => camp()['venue'] . ' (residential)'], 'green')
        . kt_btn('View & download my ticket', ticket_url($r))
        . '<p style="font-size:13px;color:#6B7390;">Open the ticket and choose Download / Print to save it as a PDF.</p>';
    return ['🎟️ Your Kakebe Tech Camp 2026 ticket — ' . $r['reference'], kt_email('Camp Ticket', $inner)];
}

function tpl_custom(array $r, string $subject, string $message): array
{
    $inner = '<p><strong>Hi ' . first_name($r['full_name']) . ',</strong></p><p>' . nl2br(e($message)) . '</p>'
        . "<p style='font-size:13px;color:#6B7390;'>Reference: <strong>" . e($r['reference']) . '</strong></p>';
    return [$subject, kt_email('Message from the Kakebe team', $inner)];
}

function tpl_admin_message(array $m): array
{
    $inner = '<p><strong>Hi Team,</strong></p><p>Someone sent a message through the website contact form.</p>'
        . kt_detail(['👤 Name' => $m['name'], '📧 Email' => $m['email'], '📞 Phone' => $m['phone'], '💬 Message' => $m['message']])
        . kt_btn('Open messages', base_url('admin/messages.php'), 'navy');
    return ['✉️ New website message from ' . $m['name'], kt_email('Contact Message', $inner)];
}

function tpl_test(): array
{
    $inner = '<p><strong>Hi Team,</strong></p><p>This is a test email from your Kakebe Tech Camp control panel. If you are reading this, email notifications are working. ✅</p>'
        . kt_detail(['📮 Transport' => strtoupper((string) setting('mail_transport')), '🖥️ SMTP host' => setting('smtp_host'), '🕒 Sent at' => date('D, j M Y · g:i A')], 'green');
    return ['✅ Test email — Kakebe Tech Camp', kt_email('Email Test', $inner)];
}
