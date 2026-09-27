<?php
/**
 * PDF receipt download. Allowed with a signed link, for admins, or for the participant who made the payment.
 */
require __DIR__ . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$p = $id ? payment_find($id) : null;
$allowed = $p && (
    sign_valid('receipt', (string) $id, $_GET['t'] ?? null)
    || !empty($_SESSION['admin_id'])
    || (($me = current_participant()) && (int) $p['registration_id'] === (int) $me['id'])
);
if (!$allowed || $p['status'] !== 'success') {
    http_response_code(404);
    exit('Receipt not available.');
}

$pdf = receipt_pdf($p);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="Kakebe-Receipt-' . receipt_no($p) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
