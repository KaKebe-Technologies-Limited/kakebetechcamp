<?php
/**
 * Kakebe Tech Camp — core configuration.
 *
 * All secrets and server-specific values live in the .env file (never committed).
 * The database connection switches automatically between LOCAL (XAMPP) and LIVE (kakebetechcamp.com) — see app_env().
 * Email, contacts, pricing and registration options are managed in Admin → Settings.
 */
$db = is_production() ? 'DB_LIVE_' : 'DB_LOCAL_';

return [
    'db' => [
        'host'    => env($db . 'HOST', 'localhost'),
        'port'    => (int) env($db . 'PORT', '3306'),
        'name'    => env($db . 'NAME', 'kakebe_techcamp'),
        'user'    => env($db . 'USER', 'root'),
        'pass'    => env($db . 'PASS', ''),
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'     => 'Kakebe Tech Camp 2026',
        'env'      => app_env(),
        // Public URL used in emails, tickets and share links. Live: https://kakebetechcamp.com. Local: auto-detected.
        'url'      => is_production() ? rtrim(env('APP_URL_LIVE', 'https://kakebetechcamp.com'), '/') : '',
        'timezone' => 'Africa/Kampala',
        // Signs ticket/payment links and encrypts the stored SMTP password. Keep it the same on local and live.
        'key'      => env('APP_KEY'),
        'official_email' => env('MAIL_OFFICIAL', 'info@kakebetechcamp.com'),
        'debug'    => false,
    ],
];
