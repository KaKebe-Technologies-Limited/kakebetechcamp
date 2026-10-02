<?php
/**
 * ioTec Pay integration + payment engine.
 * Docs: https://iotec.io/api-docs/pay — same flow as ObiFunds (OAuth client credentials → collect → status).
 *
 * Payment confirmation does not depend on ioTec callbacks reaching this site: the browser polls
 * api/payment-status.php, admins can re-check, and cron/sync-payments.php reconciles anything pending.
 */

function iotec(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    // Sandbox only in the LOCAL environment and only when IOTEC_SANDBOX=true; production always uses the live wallet.
    $sandboxFlag = filter_var(env('IOTEC_SANDBOX', 'false'), FILTER_VALIDATE_BOOLEAN);
    $sandbox = app_env() === 'local' ? $sandboxFlag : false;
    return $c = [
        'client_id'     => env('IOTEC_CLIENT_ID'),
        'client_secret' => env('IOTEC_CLIENT_SECRET'),
        'ipn_secret'    => env('IOTEC_IPN_SECRET'),
        'wallet'        => $sandbox ? env('IOTEC_TEST_WALLET_ID') : env('IOTEC_LIVE_WALLET_ID'),
        'sandbox'       => $sandbox,
        'currency'      => $sandbox ? 'ITX' : 'UGX',
        'auth_url'      => 'https://id.iotec.io/connect/token',
        'base'          => 'https://pay.iotec.io',
    ];
}

function iotec_configured(): bool
{
    $c = iotec();
    return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['wallet'] !== '';
}

/** @return array{0:int,1:array,2:?string} [http code, decoded body, curl error] */
function iotec_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 12,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (env('CURL_CA_BUNDLE') !== '' && is_file(env('CURL_CA_BUNDLE'))) {
        curl_setopt($ch, CURLOPT_CAINFO, env('CURL_CA_BUNDLE')); // only needed where PHP's own certificate list is outdated (e.g. a local XAMPP)
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch) ?: null;
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    return [$code, is_array($data) ? $data : ['raw' => (string) $raw], $err];
}

function iotec_token(): ?string
{
    static $token = null, $expires = 0;
    if ($token && time() < $expires) {
        return $token;
    }
    $c = iotec();
    [$code, $data, $err] = iotec_http('POST', $c['auth_url'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'grant_type'    => 'client_credentials',
    ]), 15);
    if ($err || $code !== 200 || empty($data['access_token'])) {
        error_log('ioTec token error: ' . ($err ?: "HTTP $code " . json_encode($data)));
        return null;
    }
    $token = $data['access_token'];
    $expires = time() + max(60, (int) ($data['expires_in'] ?? 300) - 30);
    return $token;
}

/** Normalise a Ugandan number to 2567XXXXXXXX. */
function msisdn(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with($d, '0')) {
        $d = '256' . substr($d, 1);
    } elseif (strlen($d) === 9) {
        $d = '256' . $d;
    }
    return $d;
}

function valid_msisdn(string $phone): bool
{
    return (bool) preg_match('/^256\d{9}$/', msisdn($phone));
}

function iotec_error_text(array $data, int $code): string
{
    $msg = $data['message'] ?? $data['title'] ?? $data['detail'] ?? "Payment could not be started (HTTP $code).";
    if (iotec()['sandbox'] && stripos((string) $msg, 'test wallet') !== false) {
        $msg .= ' — sandbox mode only accepts ioTec test numbers such as 0111777771.';
    }
    return (string) $msg;
}

/* ------------------------------------------------------------------
 * Payment records
 * ------------------------------------------------------------------ */

/** "Visa •••• 1111 · Pesapal", "ioTec", "Recorded by admin" — the detail line under the payment method. */
function payment_channel_detail(array $p): string
{
    $parts = [];
    if (preg_match('/·\s*(.+)$/u', (string) ($p['provider_status'] ?? ''), $m)) {
        $parts[] = trim($m[1]);                         // card type / wallet, e.g. Visa
    }
    $account = preg_replace('/\s+/', '', (string) ($p['payer_account'] ?? ''));
    if ($account !== '') {
        $parts[] = preg_match('/(\d{4})$/', $account, $m) ? '•••• ' . $m[1] : $account;
    }
    $parts[] = ['pesapal' => 'Pesapal', 'iotec' => 'ioTec', 'manual' => 'Recorded by admin'][$p['provider'] ?? ''] ?? (string) ($p['provider'] ?? '');
    return implode(' · ', array_filter($parts));
}

/** The provider's reference for a payment: confirmation code, else its transaction id, else the admin's note. */
function payment_reference(array $p): string
{
    return (string) ($p['provider_ref'] ?? '') ?: ((string) $p['provider_txn_id'] ?: ((string) $p['notes'] ?: '—'));
}

function payment_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function create_payment(array $f): array
{
    $stmt = db()->prepare('INSERT INTO payments (registration_id, donation_id, purpose, amount, currency, method, provider, payer_name, payer_phone, payer_email, status, notes, recorded_by, ip, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $f['registration_id'] ?? null,
        $f['donation_id'] ?? null,
        $f['purpose'] ?? 'camp',
        (int) $f['amount'],
        $f['currency'] ?? (($f['provider'] ?? 'iotec') === 'iotec' ? iotec()['currency'] : 'UGX'),
        $f['method'] ?? 'mobile_money',
        $f['provider'] ?? 'iotec',
        $f['payer_name'] ?? null,
        $f['payer_phone'] ?? null,
        $f['payer_email'] ?? null,
        $f['status'] ?? 'pending',
        $f['notes'] ?? null,
        $f['recorded_by'] ?? null,
        client_ip(),
        now(),
    ]);
    return payment_find((int) db()->lastInsertId());
}

/**
 * Send a payment request to ioTec. Mobile money pushes a PIN prompt to the payer's phone;
 * card returns a hosted-page URL to redirect to.
 */
function start_payment(array $p): array
{
    // Card payments go through Pesapal when it is set up; Mobile Money stays on ioTec.
    if ($p['method'] === 'card' && pesapal_configured()) {
        return pesapal_start_payment($p);
    }
    if (!iotec_configured()) {
        db()->prepare("UPDATE payments SET status = 'failed', message = ? WHERE id = ?")->execute(['Online payments are not configured.', $p['id']]);
        return ['ok' => false, 'message' => 'Online payments are not available right now. Please contact us on ' . setting('contact_phone') . '.'];
    }
    $token = iotec_token();
    if (!$token) {
        db()->prepare("UPDATE payments SET status = 'failed', message = ? WHERE id = ?")->execute(['Could not authenticate with ioTec.', $p['id']]);
        return ['ok' => false, 'message' => 'We could not reach the payment service. Please try again in a moment.'];
    }

    $c = iotec();
    $externalId = 'KTC-P' . $p['id'] . '-' . time();
    $what = $p['purpose'] === 'camp' ? 'Kakebe Tech Camp fee' : 'Kakebe Tech Camp sponsorship';
    $payload = [
        'currency'    => $c['currency'],
        'walletId'    => $c['wallet'],
        'externalId'  => $externalId,
        'payerName'   => mb_substr($p['payer_name'] ?: 'Kakebe participant', 0, 60),
        'payerNote'   => $what,
        'payeeNote'   => $what . ' — payment #' . $p['id'],
        'amount'      => (float) $p['amount'],
    ];
    if ($p['method'] === 'card') {
        $payload += ['category' => 'Card', 'payer' => (string) $p['payer_email'], 'redirectUrl' => base_url('payment-return.php?id=' . $p['id'] . '&t=' . sign('payment', (string) $p['id']))];
        $endpoint = '/api/collections/collect/card';
    } else {
        $payload += ['category' => 'MobileMoney', 'payer' => msisdn((string) $p['payer_phone']), 'transactionChargesCategory' => 'ChargeWallet'];
        $endpoint = '/api/collections/collect';
    }

    [$code, $data, $err] = iotec_http('POST', $c['base'] . $endpoint, ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'], json_encode($payload));

    if ($err || !in_array($code, [200, 201], true)) {
        $msg = $err ? 'Network error contacting the payment service.' : iotec_error_text($data, $code);
        error_log("ioTec collect failed (payment {$p['id']}): " . ($err ?: "HTTP $code " . json_encode($data)));
        db()->prepare("UPDATE payments SET status = 'failed', external_id = ?, message = ? WHERE id = ?")->execute([$externalId, mb_substr($msg, 0, 255), $p['id']]);
        return ['ok' => false, 'message' => $msg];
    }

    $redirect = $data['cardRedirectUrl'] ?? null;
    db()->prepare('UPDATE payments SET external_id = ?, provider_txn_id = ?, provider_status = ?, redirect_url = ?, message = ?, checked_at = ? WHERE id = ?')
        ->execute([$externalId, $data['id'] ?? null, $data['status'] ?? 'Pending', $redirect, $data['statusMessage'] ?? null, now(), $p['id']]);

    if ($p['method'] === 'card' && !$redirect) {
        db()->prepare("UPDATE payments SET status = 'failed', message = 'No card payment link returned.' WHERE id = ?")->execute([$p['id']]);
        return ['ok' => false, 'message' => 'Card payment is not available right now. Please use Mobile Money.'];
    }

    $row = payment_find((int) $p['id']);
    // ioTec can settle instantly (e.g. sandbox success numbers).
    if (strtolower((string) ($data['status'] ?? '')) === 'success') {
        db()->prepare('UPDATE payments SET message = NULL WHERE id = ?')->execute([$p['id']]);
        finalize_payment((int) $p['id']);
        $row = payment_find((int) $p['id']);
    } elseif (strtolower((string) ($data['status'] ?? '')) === 'failed') {
        db()->prepare("UPDATE payments SET status = 'failed' WHERE id = ?")->execute([$p['id']]);
        $row = payment_find((int) $p['id']);
        return ['ok' => false, 'message' => $data['statusMessage'] ?? 'The payment was declined.', 'payment' => $row];
    }
    return ['ok' => true, 'payment' => $row, 'redirect' => $redirect];
}

/** Ask the payment provider (ioTec or Pesapal) for the latest status of a pending payment and apply it. Returns the updated row. */
function sync_payment(int $id, bool $force = false): ?array
{
    $p = payment_find($id);
    if (!$p || $p['status'] !== 'pending' || !in_array($p['provider'], ['iotec', 'pesapal'], true)) {
        return $p;
    }
    if (!$force && $p['checked_at'] && time() - strtotime($p['checked_at']) < 4) {
        return $p;
    }
    db()->prepare('UPDATE payments SET checked_at = ? WHERE id = ?')->execute([now(), $id]);
    if ($p['provider'] === 'pesapal') {
        pesapal_sync_payment($p);
        return payment_find($id);
    }

    $token = iotec_token();
    if (!$token) {
        return payment_find($id);
    }
    $c = iotec();
    if ($p['provider_txn_id']) {
        [$code, $data] = iotec_http('GET', $c['base'] . '/api/collections/status/' . rawurlencode($p['provider_txn_id']), ['Authorization: Bearer ' . $token, 'Accept: application/json'], null, 15);
    } elseif ($p['external_id']) {
        [$code, $data] = iotec_http('GET', $c['base'] . '/api/collections/external-id/' . rawurlencode($p['external_id']), ['Authorization: Bearer ' . $token, 'Accept: application/json'], null, 15);
        if (isset($data[0])) {
            $data = $data[0];
        }
        if (!empty($data['id'])) {
            db()->prepare('UPDATE payments SET provider_txn_id = ? WHERE id = ?')->execute([$data['id'], $id]);
        }
    } else {
        return $p;
    }

    $status = strtolower((string) ($data['status'] ?? ''));
    $notFound = $code === 400 && stripos((string) ($data['message'] ?? ''), 'not found') !== false;
    if ($status === 'success') {
        db()->prepare('UPDATE payments SET provider_status = ?, message = NULL WHERE id = ?')->execute([$data['status'], $id]);
        finalize_payment($id);
    } elseif (in_array($status, ['failed', 'cancelled', 'rejected'], true) || ($notFound && strtotime($p['created_at']) < time() - 180)) {
        db()->prepare("UPDATE payments SET status = 'failed', provider_status = ?, message = ? WHERE id = ? AND status = 'pending'")
            ->execute([$data['status'] ?? 'NotFound', mb_substr((string) ($data['statusMessage'] ?? $data['message'] ?? 'Payment failed or was cancelled.'), 0, 255), $id]);
    } elseif ($status !== '') {
        db()->prepare('UPDATE payments SET provider_status = ?, message = ? WHERE id = ?')->execute([$data['status'], $data['statusMessage'] ?? null, $id]);
    }
    return payment_find($id);
}

/**
 * Mark a payment successful (idempotent), update the participant/sponsor totals and send receipts.
 * Returns true only the first time a payment is finalised.
 */
function finalize_payment(int $id, bool $emailPayer = true): bool
{
    $stmt = db()->prepare("UPDATE payments SET status = 'success', completed_at = COALESCE(completed_at, ?) WHERE id = ? AND status <> 'success'");
    $stmt->execute([now(), $id]);
    if ($stmt->rowCount() === 0) {
        return false;
    }
    $p = payment_find($id);
    if ($p['registration_id']) {
        recompute_registration((int) $p['registration_id']);
    }
    if ($p['donation_id']) {
        recompute_donation((int) $p['donation_id']);
    }
    send_payment_emails($p, $emailPayer);
    return true;
}

/** Recalculate amount paid, payment status and booking status from successful payments. */
function recompute_registration(int $id): ?array
{
    $r = find_registration($id);
    if (!$r) {
        return null;
    }
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE registration_id = ? AND status = 'success'");
    $stmt->execute([$id]);
    $paid = (int) $stmt->fetchColumn();
    $total = (int) $r['total_amount'];

    $pay = in_array($r['payment_status'], ['waived', 'sponsored'], true) ? $r['payment_status'] : ($paid >= $total && $total > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'));
    $status = $r['status'];
    // Sponsored applications stay "awaiting approval" until an admin decides.
    if (!in_array($status, ['waitlisted', 'cancelled', 'review'], true)) {
        if (in_array($pay, ['paid', 'waived', 'sponsored'], true)) {
            $status = 'confirmed';
        } else {
            $status = 'pending';
        }
    }
    $paidAt = ($pay === 'paid' && !$r['paid_at']) ? now() : $r['paid_at'];
    db()->prepare('UPDATE registrations SET amount_paid = ?, payment_status = ?, status = ?, paid_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$paid, $pay, $status, $pay === 'paid' ? $paidAt : null, now(), $id]);
    return find_registration($id);
}

function recompute_donation(int $id): void
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE donation_id = ? AND status = 'success'");
    $stmt->execute([$id]);
    $paid = (int) $stmt->fetchColumn();
    db()->prepare("UPDATE donations SET amount_paid = ?, status = IF(? > 0, 'paid', status), paid_at = IF(? > 0, COALESCE(paid_at, ?), paid_at) WHERE id = ?")
        ->execute([$paid, $paid, $paid, now(), $id]);

    // Website sponsors who paid (and are not anonymous) become selectable when participants register as sponsored.
    if ($paid > 0) {
        $d = db()->query('SELECT * FROM donations WHERE id = ' . (int) $id)->fetch();
        $exists = db()->prepare('SELECT COUNT(*) FROM sponsors WHERE donation_id = ?');
        $exists->execute([$id]);
        if ($d && !$d['is_anonymous'] && !(int) $exists->fetchColumn()) {
            db()->prepare("INSERT INTO sponsors (name, organization, email, phone, seats, source, donation_id, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'website', ?, 1, ?)")
                ->execute([$d['donor_name'], $d['organization'], $d['email'], $d['phone'], (int) $d['children'], $id, now()]);
        }
    }
}

/** Notify the team (always, with the payment copy list) and email the payer their PDF receipt (when $emailPayer). */
function send_payment_emails(array $p, bool $emailPayer = true): void
{
    try {
        $pdf = receipt_pdf($p);
        $attach = [['name' => 'Kakebe-Receipt-' . receipt_no($p) . '.pdf', 'type' => 'application/pdf', 'data' => $pdf]];
        if ($p['registration_id']) {
            $r = find_registration((int) $p['registration_id']);
            if ($emailPayer) {
                [$s, $h] = tpl_payment_receipt($r, $p);
                $ok = send_mail($r['email'], $s, $h, setting('contact_email') ?: null, $err, $attach);
            }
            [$s2, $h2] = tpl_admin_payment($p, $r, null);
            notify_team('payment', $s2, $h2, $r['email'], $attach);
        } elseif ($p['donation_id']) {
            $d = db()->query('SELECT * FROM donations WHERE id = ' . (int) $p['donation_id'])->fetch();
            if ($emailPayer) {
                [$s, $h] = tpl_donation_thanks($d, $p);
                $ok = send_mail($d['email'], $s, $h, setting('contact_email') ?: null, $err, $attach);
            }
            [$s2, $h2] = tpl_admin_payment($p, null, $d);
            notify_team('payment', $s2, $h2, $d['email'], $attach);
        }
        if (!empty($ok)) {
            db()->prepare('UPDATE payments SET receipt_sent = 1 WHERE id = ?')->execute([$p['id']]);
        }
    } catch (Throwable $e) {
        error_log('Payment email failed for payment ' . $p['id'] . ': ' . $e->getMessage());
    }
}

/** Safe summary of a payment for the browser. */
function payment_public(array $p): array
{
    $out = [
        'id'       => (int) $p['id'],
        'status'   => $p['status'],
        'amount'   => (int) $p['amount'],
        'currency' => $p['currency'],
        'method'   => $p['method'],
        'message'  => $p['message'],
        'receipt'  => $p['status'] === 'success' ? receipt_url($p) : null,
        'receipt_no' => receipt_no($p),
    ];
    if ($p['registration_id'] && ($r = find_registration((int) $p['registration_id']))) {
        $out += ['total' => (int) $r['total_amount'], 'paid' => (int) $r['amount_paid'], 'balance' => balance($r), 'booking' => statuses()[$r['status']] ?? $r['status'], 'reference' => $r['reference']];
    }
    return $out;
}

/** Validate and start a participant payment. Returns API-style array. */
function begin_registration_payment(array $r, int $amount, string $method, string $phone): array
{
    $bal = balance($r);
    if ($bal <= 0) {
        return ['ok' => false, 'message' => 'Your camp package is already fully paid. 🎉'];
    }
    if (in_array($r['status'], ['cancelled'], true)) {
        return ['ok' => false, 'message' => 'This registration was cancelled. Please contact us.'];
    }
    if ($r['status'] === 'review') {
        return ['ok' => false, 'message' => 'Your sponsorship is being reviewed — no payment is needed right now. We will email you once it is confirmed.'];
    }
    if ($amount !== $bal) { // full payment only
        return ['ok' => false, 'message' => 'The amount due is ' . format_ugx($bal) . '. Please refresh the page and try again.'];
    }
    if (!in_array($method, ['mobile_money', 'card'], true)) {
        $method = 'mobile_money';
    }
    if ($method === 'mobile_money' && !valid_msisdn($phone)) {
        return ['ok' => false, 'errors' => ['pay_phone' => 'Enter a valid MTN or Airtel number, e.g. 0772 123 456.'], 'message' => 'Please check the phone number.'];
    }
    if (too_many('payments', client_ip(), 10, 12)) {
        return ['ok' => false, 'message' => 'Too many payment attempts. Please wait a few minutes and try again.'];
    }
    $p = create_payment([
        'registration_id' => $r['id'], 'purpose' => 'camp', 'amount' => $amount, 'method' => $method,
        'payer_name' => $r['full_name'], 'payer_phone' => $method === 'mobile_money' ? $phone : $r['phone'], 'payer_email' => $r['email'],
    ]);
    $res = start_payment($p);
    $res['payment'] = isset($res['payment']) ? payment_public($res['payment']) : payment_public(payment_find((int) $p['id']));
    $res['token'] = sign('payment', (string) $p['id']);
    return $res;
}
