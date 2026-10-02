<?php
/**
 * POST /api/flyer-save.php — keeps a copy of a finished "I will be there" flyer (and a small preview)
 * when someone downloads or shares it, so the team can find and reuse it in Admin → "I will be there" flyers.
 * One flyer per participant code (the latest replaces the earlier one).
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false], 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Session expired.'], 419);
}
$ip = client_ip();
if (too_many('flyers', $ip, 60, 40)) {
    json_response(['ok' => false, 'message' => 'Too many flyers from your network.'], 429);
}

$file = $_FILES['flyer'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > 8 * 1024 * 1024) {
    json_response(['ok' => false, 'message' => 'No flyer received.'], 422);
}
$info = @getimagesize($file['tmp_name']);
if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] !== 1254 || $info[1] !== 1254) {
    json_response(['ok' => false, 'message' => 'Not a flyer image.'], 422);
}
$thumb = $_FILES['thumb'] ?? null;
$thumbOk = $thumb && ($thumb['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($thumb['tmp_name']) && $thumb['size'] <= 400 * 1024
    && ($ti = @getimagesize($thumb['tmp_name'])) && $ti[2] === IMAGETYPE_JPEG && $ti[0] <= 800 && $ti[1] <= 800;

$name = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($_POST['name'] ?? '')))), 0, 80);
$code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($_POST['code'] ?? '')));
$code = mb_substr($code, 0, 20);
$reg = $code !== '' ? find_registration_by_ref($code) : null;

// Same participant (or the same visitor in this session) → replace their earlier flyer
$existing = null;
if ($reg) {
    $st = db()->prepare('SELECT * FROM flyers WHERE registration_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$reg['id']]);
    $existing = $st->fetch() ?: null;
}
if (!$existing && !empty($_SESSION['flyer_id'])) {
    $st = db()->prepare('SELECT * FROM flyers WHERE id = ? AND registration_id IS NULL');
    $st->execute([(int) $_SESSION['flyer_id']]);
    $existing = $st->fetch() ?: null;
}

$dir = STORAGE . '/uploads/flyers/' . date('Ym');
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    json_response(['ok' => false, 'message' => 'Storage unavailable.'], 500);
}
$stem = date('Ym') . '/' . bin2hex(random_bytes(10));
if (!move_uploaded_file($file['tmp_name'], STORAGE . '/uploads/flyers/' . $stem . '.png')) {
    json_response(['ok' => false, 'message' => 'Could not save.'], 500);
}
$thumbFile = $thumbOk && move_uploaded_file($thumb['tmp_name'], STORAGE . '/uploads/flyers/' . $stem . '_t.jpg') ? $stem . '_t.jpg' : null;

$values = [$reg['id'] ?? null, $reg['reference'] ?? $code, $name !== '' ? $name : ($reg['full_name'] ?? ''), $stem . '.png', $thumbFile, (int) $file['size'], $ip, now()];
if ($existing) {
    foreach ([$existing['file'], $existing['thumb']] as $old) {
        if ($old && is_file($path = STORAGE . '/uploads/flyers/' . $old)) {
            @unlink($path);
        }
    }
    db()->prepare('UPDATE flyers SET registration_id = ?, reference = ?, name = ?, file = ?, thumb = ?, bytes = ?, ip = ?, updated_at = ?, saves = saves + 1 WHERE id = ?')
        ->execute(array_merge($values, [$existing['id']]));
    $id = (int) $existing['id'];
} else {
    db()->prepare('INSERT INTO flyers (registration_id, reference, name, file, thumb, bytes, ip, updated_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array_merge($values, [now()]));
    $id = (int) db()->lastInsertId();
}
$_SESSION['flyer_id'] = $id;
json_response(['ok' => true]);
