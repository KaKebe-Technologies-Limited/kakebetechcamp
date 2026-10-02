<?php
/**
 * Open tracking for campaign emails: a 1×1 transparent image. Loading it marks the email as opened (the blue tick).
 * Some email apps load images automatically or block them, so opens are a good guide rather than exact.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$token = (string) ($_GET['t'] ?? '');
if (preg_match('/^[a-f0-9]{24}$/', $token)) {
    db()->prepare('UPDATE mk_sends SET opened_at = COALESCE(opened_at, ?), open_count = open_count + 1 WHERE token = ? AND status = \'sent\'')->execute([now(), $token]);
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
