<?php
/** Show or download a finance attachment (receipt, invoice, proof of payment) — only to people allowed to see it. */
require __DIR__ . '/_init.php';
$admin = require_admin();

$f = q('SELECT * FROM fin_files WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
$allowed = false;
if ($f) {
    if (role_can($admin['role'], 'finance')) {
        $allowed = true;
    } elseif (str_starts_with($f['owner_type'], 'req_')) {
        // Requisition documents: the requester, operations, finance and the team lead
        $owner = (int) q('SELECT requested_by FROM fin_requisitions WHERE id = ?', [$f['owner_id']])->fetchColumn();
        $allowed = role_can($admin['role'], 'all_reqs') || $owner === (int) $admin['id'];
    }
}
$path = $f ? fin_upload_dir() . '/' . $f['file'] : '';
if (!$allowed || !preg_match('~^\d{6}/[a-f0-9]{32}\.[a-z]{3,4}$~', (string) ($f['file'] ?? '')) || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
$ext = strtolower(pathinfo($f['file'], PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && !isset($_GET['download']);
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $f['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
