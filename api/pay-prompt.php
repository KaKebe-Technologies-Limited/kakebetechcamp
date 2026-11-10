<?php
/**
 * GET /api/pay-prompt.php — what the payment reminder pop-up on the website says to this visitor.
 * A participant who is logged in, or whose device we remember (they registered, logged in or opened their payment
 * link here), gets their own balance and payment link; everyone else is asked to log in (or find their
 * registration) and pay. POST: "Not me?" on a shared device — forget this device.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    forget_registration();
    unset($_SESSION['participant_id']);   // "Not me": this device stops being signed in as them too
    json_response(['ok' => true]);
}

$daysLeft = (int) ceil((strtotime(camp()['start_date']) - strtotime('today')) / 86400);
if (setting('pay_popup', '1') !== '1' || $daysLeft < 0) {
    json_response(['show' => false]);
}

// Someone in the middle of registering (verified their email, form not sent yet) — leave them to it
$regEmail = (string) ($_SESSION['reg_email'] ?? '');
if ($regEmail !== '' && email_verified_in_session($regEmail)) {
    json_response(['show' => false]);
}

$logged = current_participant();
$r = $logged ?: remembered_registration();
session_write_close();
$base = ['show' => true, 'days_left' => $daysLeft, 'dates' => camp()['dates_short']];

if ($r) {
    // Nothing to ask of people who have paid, are covered, or whose place isn't open for payment
    if (in_array($r['status'], ['cancelled', 'waitlisted', 'review'], true) || balance($r) <= 0) {
        json_response(['show' => false]);
    }
    $half = half_payment($r);
    $deadline = $r['pay_deadline'] && (int) $r['amount_paid'] === 0 ? $r['pay_deadline'] : null;
    json_response($base + [
        'mode'        => 'pay',
        'name'        => mk_first_name($r['full_name']),
        'balance'     => format_ugx(balance($r)),
        'part_paid'   => (int) $r['amount_paid'] > 0,
        'half'        => $half ? format_ugx($half) : null,
        'pay_url'     => pay_url($r),
        'half_url'    => $half ? pay_url($r) . '&plan=half' : null,
        'deadline'    => $deadline ? date('l, j F', strtotime($deadline)) : null,
        'overdue'     => $deadline !== null && $deadline < date('Y-m-d'),
        'remembered'  => true,   // always offer "Not <name>?" — shared phones and computers are common
    ]);
}

json_response($base + [
    'mode'         => 'guest',
    'login_url'    => base_url('portal/login.php'),
    'find_url'     => base_url('pay.php'),
    'register_url' => base_url('#register'),
]);
