<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');

if (!is_file(ROOT . '/includes/config.php')) {
    http_response_code(500);
    exit('Missing includes/config.php — copy includes/config.sample.php to includes/config.php and edit it.');
}

require ROOT . '/includes/env.php';
load_env(ROOT . '/.env');

$GLOBALS['config'] = require ROOT . '/includes/config.php';

date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Africa/Kampala');

if (!empty($GLOBALS['config']['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

foreach ([STORAGE . '/uploads/photos', STORAGE . '/logs', ROOT . '/uploads/team'] as $dir) {
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
require ROOT . '/includes/emails.php';
require ROOT . '/includes/payments.php';
