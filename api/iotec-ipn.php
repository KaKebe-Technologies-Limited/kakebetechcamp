<?php
/**
 * ioTec IPN (payment notification) endpoint.
 * Register https://YOUR-DOMAIN/api/iotec-ipn.php in the ioTec portal if Kakebe gets its own wallet/callback.
 * The payload is never trusted directly: the payment is re-checked with the ioTec status API.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true) ?: [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(200);
    exit('OK');
}

$secret = iotec()['ipn_secret'];
if ($secret !== '') {
    $headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER);
    $presented = $headers['x-ipn-secret'] ?? $headers['x-iotec-signature'] ?? $headers['x-iotec-secret'] ?? $_GET['secret'] ?? ($data['secret'] ?? $data['ipnSecret'] ?? '');
    if (!is_string($presented) || !hash_equals($secret, $presented)) {
        http_response_code(401);
        exit('Unauthorized');
    }
}

$externalId = (string) ($data['externalId'] ?? $data['reference'] ?? '');
if (preg_match('/^KTC-P(\d+)-/', $externalId, $m)) {
    $p = payment_find((int) $m[1]);
    if ($p && !empty($data['id']) && empty($p['provider_txn_id'])) {
        db()->prepare('UPDATE payments SET provider_txn_id = ? WHERE id = ?')->execute([$data['id'], $p['id']]);
    }
    sync_payment((int) $m[1], true);
}

http_response_code(200);
echo 'OK';
