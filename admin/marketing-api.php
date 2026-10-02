<?php
/**
 * Background actions for Email campaigns / contacts (admins only, CSRF-protected):
 *   send          — send the next few emails of one group
 *   check_domains — check a few email domains can receive mail
 *   upload        — upload an image for the email body
 *   test          — send a test of a campaign to one address
 */
require __DIR__ . '/_init.php';
require_admin();
require_csrf();
@set_time_limit(120);

$action = (string) ($_POST['action'] ?? '');

if ($action === 'send') {
    $c = find_campaign((int) ($_POST['campaign_id'] ?? 0));
    if (!$c) {
        json_response(['ok' => false, 'message' => 'Campaign not found.'], 404);
    }
    $res = mk_send_chunk($c, max(1, (int) ($_POST['batch'] ?? 1)), 10);
    json_response(['ok' => $res['error'] === null] + $res);
}

if ($action === 'check_domains') {
    json_response(['ok' => true] + mk_check_domains(20));
}

if ($action === 'upload') {
    $f = $_FILES['image'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        json_response(['ok' => false, 'message' => 'No image was received.'], 422);
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        json_response(['ok' => false, 'message' => 'Images must be under 3 MB — make it smaller and try again.'], 422);
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        json_response(['ok' => false, 'message' => 'Please choose a JPG, PNG, GIF or WEBP image.'], 422);
    }
    $dir = ROOT . '/uploads/mail/' . date('Ym');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        json_response(['ok' => false, 'message' => 'The image folder could not be created on the server.'], 500);
    }
    $name = bin2hex(random_bytes(10)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        json_response(['ok' => false, 'message' => 'The image could not be saved.'], 500);
    }
    json_response(['ok' => true, 'url' => base_url('uploads/mail/' . date('Ym') . '/' . $name), 'width' => $info[0], 'height' => $info[1]]);
}

if ($action === 'test') {
    $c = find_campaign((int) ($_POST['campaign_id'] ?? 0));
    $to = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!$c || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'message' => 'Enter a valid email address for the test.'], 422);
    }
    if (trim((string) $c['subject']) === '' || trim((string) $c['body']) === '') {
        json_response(['ok' => false, 'message' => 'Add a subject and some content (and save) before sending a test.'], 422);
    }
    [$subject, $html] = mk_render($c, ['name' => current_admin()['name'] ?? '']);
    $ok = send_mail($to, '[TEST] ' . $subject, $html, mk_settings()['reply_to'] ?: null, $err);
    json_response(['ok' => $ok, 'message' => $ok ? 'Test email sent to ' . $to . '.' . mail_note() : 'The test could not be sent: ' . $err]);
}

json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
