<?php
/** Show the logged-in person's own saved signature (for the preview on My account). */
require __DIR__ . '/_init.php';
$admin = require_admin();
$path = admin_signature_path((string) q('SELECT signature_file FROM admins WHERE id = ?', [$admin['id']])->fetchColumn());
if (!$path) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
