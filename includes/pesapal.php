<?php
/**
 * Pesapal (API 3.0) — card payments for registrations and sponsorships. Mobile Money stays on ioTec.
 *
 * Live: PESAPAL_CONSUMER_KEY / PESAPAL_CONSUMER_SECRET. Local testing only ever uses the sandbox
 * (PESAPAL_SANDBOX_CONSUMER_KEY / _SECRET), so a development machine never creates live orders.
 * Kakebe registers its own notification URL (api/pesapal-ipn.php), so sharing a Pesapal account
 * with another site does not affect that site's notifications.
 */

function pesapal(): array
{
    $sandbox = app_env() === 'local';
    return [
        'sandbox' => $sandbox,
        'key'     => $sandbox ? env('PESAPAL_SANDBOX_CONSUMER_KEY') : env('PESAPAL_CONSUMER_KEY'),
        'secret'  => $sandbox ? env('PESAPAL_SANDBOX_CONSUMER_SECRET') : env('PESAPAL_CONSUMER_SECRET'),
        'base'    => $sandbox ? 'https://cybqa.pesapal.com/pesapalv3/api' : 'https://pay.pesapal.com/v3/api',
    ];
}

/** Card payments go to Pesapal when its keys are set; otherwise the old ioTec card page is used. */
function pesapal_configured(): bool
{
    $c = pesapal();
    return $c['key'] !== '' && $c['secret'] !== '';
}

function card_page_hint(): string
{
    return pesapal_configured()
        ? "You'll go to Pesapal's secure page — tap “Card payments” (Visa · Mastercard) there, then you'll be brought back here."
        : "You'll be taken to a secure card page, then brought back here.";
}

/** Access token (valid for up to 5 minutes, cached). */
function pesapal_token(?string &$error = null): ?string
{
    $c = pesapal();
    $file = STORAGE . '/cache/pesapal_token_' . md5($c['base'] . '|' . $c['key']) . '.json';
    $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 30) {
        return $cached['token'];
    }
    [$code, $data, $err] = iotec_http('POST', $c['base'] . '/Auth/RequestToken', ['Content-Type: application/json', 'Accept: application/json'],
        json_encode(['consumer_key' => $c['key'], 'consumer_secret' => $c['secret']]), 20);
    if (empty($data['token'])) {
        $error = (string) ($data['error']['message'] ?? $data['message'] ?? $err ?? ('HTTP ' . $code));
        error_log('Pesapal auth failed: ' . $error);
        return null;
    }
    $expires = strtotime((string) ($data['expiryDate'] ?? '')) ?: time() + 240;
    @file_put_contents($file, json_encode(['token' => $data['token'], 'expires' => min($expires, time() + 290)]), LOCK_EX);
    return $data['token'];
}

function pesapal_api(string $method, string $path, ?array $body = null): array
{
    $token = pesapal_token($error);
    if (!$token) {
        return [0, ['error' => ['message' => $error]], $error];
    }
    return iotec_http($method, pesapal()['base'] . $path, ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        $body === null ? null : json_encode($body), 25);
}

/** Settings key where Kakebe's notification id for the current Pesapal account and URL is remembered. */
function pesapal_ipn_key(): string
{
    $c = pesapal();
    return 'pesapal_ipn_' . substr(md5($c['base'] . '|' . $c['key'] . '|' . base_url('api/pesapal-ipn.php')), 0, 12);
}

/** Kakebe's own notification URL, registered once per Pesapal account and remembered in settings. */
function pesapal_ipn_id(): ?string
{
    $url = base_url('api/pesapal-ipn.php');
    $key = pesapal_ipn_key();
    $saved = (string) setting($key);
    if ($saved !== '') {
        return $saved;
    }
    [$code, $data] = pesapal_api('POST', '/URLSetup/RegisterIPN', ['url' => $url, 'ipn_notification_type' => 'GET']);
    if (empty($data['ipn_id'])) {
        error_log('Pesapal IPN registration failed: HTTP ' . $code . ' ' . json_encode($data));
        return null;
    }
    setting_set($key, (string) $data['ipn_id']);
    return (string) $data['ipn_id'];
}

/** Create the Pesapal order for a card payment and return the hosted payment page to redirect to. */
function pesapal_start_payment(array $p): array
{
    $fail = function (string $why, string $userMessage) use ($p): array {
        db()->prepare("UPDATE payments SET status = 'failed', message = ? WHERE id = ?")->execute([mb_substr($why, 0, 255), $p['id']]);
        return ['ok' => false, 'message' => $userMessage];
    };
    db()->prepare("UPDATE payments SET provider = 'pesapal', currency = 'UGX' WHERE id = ?")->execute([$p['id']]);

    $ipn = pesapal_ipn_id();
    if (!$ipn) {
        return $fail('Pesapal: could not register the notification URL.', 'Card payment is not available right now. Please use Mobile Money or try again shortly.');
    }

    $reference = 'KTC-P' . $p['id'] . '-' . time();
    $names = preg_split('/\s+/', trim((string) $p['payer_name'])) ?: [];
    $first = array_shift($names) ?: 'Kakebe';
    $last = implode(' ', $names) ?: 'Supporter';
    $what = $p['purpose'] === 'camp' ? 'Kakebe Tech Camp 2026 fee' : 'Kakebe Tech Camp 2026 sponsorship';
    [$code, $data, $err] = pesapal_api('POST', '/Transactions/SubmitOrderRequest', [
        'id'              => $reference,
        'currency'        => 'UGX',
        'amount'          => (float) $p['amount'],
        'description'     => mb_substr($what . ' — payment #' . $p['id'], 0, 100),
        'callback_url'    => base_url('api/pesapal-return.php'),
        'notification_id' => $ipn,
        'billing_address' => array_filter([
            'email_address' => (string) $p['payer_email'],
            'phone_number'  => preg_replace('/[^\d+]/', '', (string) $p['payer_phone']),
            'country_code'  => 'UG',
            'first_name'    => mb_substr($first, 0, 50),
            'last_name'     => mb_substr($last, 0, 50),
        ]),
    ]);

    $redirect = $data['redirect_url'] ?? null;
    if (!$redirect || !empty($data['error'])) {
        $why = (string) ($data['error']['message'] ?? $data['message'] ?? $err ?? ('HTTP ' . $code));
        error_log("Pesapal order failed (payment {$p['id']}): " . json_encode($data));
        return $fail('Pesapal: ' . $why, 'Card payment could not be started (' . $why . '). Please try again or use Mobile Money.');
    }
    db()->prepare("UPDATE payments SET external_id = ?, provider_txn_id = ?, provider_status = 'Pending', redirect_url = ?, message = NULL, checked_at = ? WHERE id = ?")
        ->execute([$reference, $data['order_tracking_id'] ?? null, $redirect, now(), $p['id']]);
    return ['ok' => true, 'payment' => payment_find((int) $p['id']), 'redirect' => $redirect];
}

/** Ask Pesapal for the latest status of a pending card payment and apply it. */
function pesapal_sync_payment(array $p): void
{
    if (empty($p['provider_txn_id'])) {
        return;
    }
    [$code, $data] = pesapal_api('GET', '/Transactions/GetTransactionStatus?orderTrackingId=' . rawurlencode((string) $p['provider_txn_id']));
    $statusCode = isset($data['status_code']) ? (int) $data['status_code'] : -1;
    $label = trim((string) ($data['payment_status_description'] ?? '')) ?: 'Pending';
    $method = trim((string) ($data['payment_method'] ?? ''));

    if ($statusCode === 1) {            // COMPLETED
        db()->prepare('UPDATE payments SET provider_status = ?, provider_ref = ?, payer_account = ?, message = NULL WHERE id = ?')->execute([
            $label . ($method ? ' · ' . $method : ''),
            mb_substr(trim((string) ($data['confirmation_code'] ?? '')), 0, 80) ?: null,
            mb_substr(trim((string) ($data['payment_account'] ?? '')), 0, 80) ?: null,
            $p['id'],
        ]);
        finalize_payment((int) $p['id']);
    } elseif ($statusCode === 2 || $statusCode === 3) {   // FAILED / REVERSED
        db()->prepare("UPDATE payments SET status = 'failed', provider_status = ?, message = ? WHERE id = ? AND status = 'pending'")
            ->execute([$label, mb_substr((string) ($data['description'] ?? 'The card payment was not successful.'), 0, 255), $p['id']]);
    } elseif (strtotime((string) $p['created_at']) < time() - 2 * 3600) {   // never paid on the Pesapal page
        db()->prepare("UPDATE payments SET status = 'failed', provider_status = ?, message = 'The card payment was not completed.' WHERE id = ? AND status = 'pending'")
            ->execute([$label, $p['id']]);
    } else {
        db()->prepare('UPDATE payments SET provider_status = ? WHERE id = ?')->execute([$label, $p['id']]);
    }
}
