<?php
/**
 * QR code image for a camp ticket (shown in ticket emails). Scanning it opens the online ticket,
 * so check-in staff can see that the seat is confirmed.
 */
require __DIR__ . '/includes/bootstrap.php';

$ref = (string) ($_GET['ref'] ?? '');
$r = sign_valid('ticket', $ref, $_GET['t'] ?? null) ? find_registration_by_ref($ref) : null;
if (!$r) {
    http_response_code(404);
    exit;
}
$png = qr_png(ticket_url($r), 8, 4);
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: public, max-age=31536000, immutable');
echo $png;
