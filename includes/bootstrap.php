<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');

require ROOT . '/includes/env.php';
load_env(ROOT . '/.env');

/** Friendly setup page instead of a PHP error when the server is not configured yet. */
function setup_stop(string $title, string $detail): never
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>Kakebe Tech Camp 2026</title></head><body style="margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#F2F4F9;color:#101935">'
        . '<div style="max-width:520px;background:#fff;padding:36px;border-radius:20px;box-shadow:0 20px 50px rgba(15,37,87,.1);text-align:center">'
        . '<h1 style="margin:0 0 10px;font-size:22px;color:#0F2557">' . htmlspecialchars($title) . '</h1>'
        . '<p style="margin:0;color:#6B7390;line-height:1.6">' . $detail . '</p></div></body></html>';
    exit;
}

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== realpath(ROOT . '/index.php')) {
    header('X-Robots-Tag: noindex, nofollow');
}

if (!is_file(ROOT . '/includes/config.php')) {
    setup_stop('Site update in progress', 'The website files are still being uploaded. Please try again in a few minutes.');
}
// On the live server the database login comes from the .env file (never stored in git).
if (is_production() && env('DB_LIVE_USER') === '') {
    setup_stop('Server setup incomplete', 'The <code>.env</code> settings file has not been uploaded to the server yet. Upload it to the website root folder (next to <code>index.php</code>) and refresh this page.');
}

$GLOBALS['config'] = require ROOT . '/includes/config.php';

date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Africa/Kampala');

if (!empty($GLOBALS['config']['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

foreach ([STORAGE . '/uploads/photos', STORAGE . '/logs', STORAGE . '/cache', ROOT . '/uploads/team'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('KTCSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require ROOT . '/includes/db.php';
require ROOT . '/includes/functions.php';
require ROOT . '/includes/mailer.php';
require ROOT . '/includes/pdf.php';
require ROOT . '/includes/qrcode.php';
require ROOT . '/includes/emails.php';
require ROOT . '/includes/payments.php';
require ROOT . '/includes/pesapal.php';
require ROOT . '/includes/spreadsheet.php';
require ROOT . '/includes/marketing.php';
require ROOT . '/includes/mentorship.php';
require ROOT . '/includes/volunteers.php';
require ROOT . '/includes/google.php';
require ROOT . '/includes/analytics.php';
require ROOT . '/includes/traffic.php';

// Fail with a clear message (not a blank error page) if the live database can't be reached.
if (PHP_SAPI !== 'cli') {
    try {
        db();
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        setup_stop('Database connection problem', is_production()
            ? 'The website could not connect to its database. Please check the <code>DB_LIVE_*</code> values in the <code>.env</code> file on the server.'
            : 'Could not connect to the local database. Start MySQL in the XAMPP Control Panel and refresh.');
    }
}
