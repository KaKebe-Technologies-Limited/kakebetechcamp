<?php
require __DIR__ . '/_init.php';
require_admin();

$type = in_array($_GET['type'] ?? '', ['participants', 'payments', 'sponsors'], true) ? $_GET['type'] : 'participants';
$filename = 'kakebe-' . $type . '-' . date('Y-m-d-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly

// Neutralise spreadsheet formulas (CSV injection), but keep phone numbers like "+256 …" intact.
$safe = static fn($v) => is_string($v) && (preg_match('/^[=@\t\r]/', $v) || (preg_match('/^[+\-]/', $v) && !preg_match('/^[+\-][\d\s()\-]+$/', $v))) ? "'" . $v : $v;
$write = static function (array $row) use ($out, $safe) {
    fputcsv($out, array_map($safe, $row));
};

if ($type === 'participants') {
    [$where, $params] = registration_filters($_GET);
    $write(['Reference', 'Full name', 'Age', 'Gender', 'Email', 'Phone', 'District', 'Country', 'Learning tracks', 'Jersey size', fees()['park_name'] . ' visit', 'Mentorship', 'Funding', 'Sponsor', 'Signed up with', 'Package (UGX)', 'Paid (UGX)', 'Balance (UGX)', 'Payment status', 'Status', 'Heard via', 'Referred by', 'Motivation', 'Admin notes', 'Registered at']);
    foreach (q("SELECT * FROM registrations $where ORDER BY id", $params) as $r) {
        $write([$r['reference'], $r['full_name'], $r['age'], $r['gender'], $r['email'], $r['phone'], $r['district'], $r['country'], $r['interests'], $r['jersey_size'],
            $r['park_visit'] ? 'Yes' : 'No', $r['mentorship'] ? 'Yes' : 'No', is_sponsored($r) ? 'Sponsored' : 'Self', $r['sponsor_name'], $r['auth_provider'] === 'google' ? 'Google' : 'Email link', $r['total_amount'], $r['amount_paid'], balance($r),
            payment_statuses()[$r['payment_status']] ?? $r['payment_status'], statuses()[$r['status']] ?? $r['status'],
            $r['source'] . ($r['source_other'] ? ' — ' . $r['source_other'] : ''), $r['referred_by'], $r['motivation'], $r['admin_notes'], $r['created_at']]);
    }
} elseif ($type === 'payments') {
    $write(['Receipt', 'Date', 'Purpose', 'Participant / sponsor ref', 'Payer', 'Phone', 'Email', 'Amount', 'Currency', 'Method', 'Provider', 'Status', 'ioTec transaction', 'External ID', 'Notes']);
    foreach (q('SELECT p.*, r.reference rref, d.reference dref FROM payments p LEFT JOIN registrations r ON r.id = p.registration_id LEFT JOIN donations d ON d.id = p.donation_id ORDER BY p.id') as $p) {
        $write([receipt_no($p), $p['completed_at'] ?: $p['created_at'], $p['purpose'], $p['rref'] ?? $p['dref'], $p['payer_name'], $p['payer_phone'], $p['payer_email'], $p['amount'], $p['currency'],
            payment_methods()[$p['method']] ?? $p['method'], $p['provider'], $p['status'], $p['provider_txn_id'], $p['external_id'], $p['notes'] ?: $p['message']]);
    }
} else {
    $write(['Reference', 'Sponsor', 'Organisation', 'Email', 'Phone', 'Innovators sponsored', 'Paying for', 'Pledged (UGX)', 'Received (UGX)', 'Status', 'Anonymous', 'Message', 'Date']);
    foreach (q('SELECT * FROM donations ORDER BY id') as $d) {
        $write([$d['reference'], $d['donor_name'], $d['organization'], $d['email'], $d['phone'], $d['children'], $d['children'] ? donation_people_label($d) : '', $d['amount'], $d['amount_paid'], $d['status'], $d['is_anonymous'] ? 'Yes' : 'No', $d['message'], $d['created_at']]);
    }
}
fclose($out);
