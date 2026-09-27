<?php
/**
 * Serves applicant photos (stored outside public access).
 * Allowed for admins (?id=), the signed-in participant (?me=1), or via a signed ticket link (?ref=&t=).
 */
require __DIR__ . '/includes/bootstrap.php';

$file = null;
if (!empty($_SESSION['admin_id']) && isset($_GET['id'])) {
    $r = find_registration((int) $_GET['id']);
    $file = $r['photo'] ?? null;
} elseif (isset($_GET['me']) && ($p = current_participant())) {
    $file = $p['photo'];
} elseif (isset($_GET['ref']) && is_string($_GET['ref']) && sign_valid('ticket', $_GET['ref'], $_GET['t'] ?? null)) {
    $r = find_registration_by_ref($_GET['ref']);
    $file = $r['photo'] ?? null;
}

$path = photo_path($file ?: null);
if (!$path) {
    http_response_code(404);
    exit('Not found');
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
