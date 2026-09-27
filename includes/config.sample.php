<?php
/**
 * Copy this file to config.php and fill in your values.
 * Generate a key with:  php -r "echo bin2hex(random_bytes(32));"
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'kakebe_techcamp',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'     => 'Kakebe Tech Camp 2026',
        'url'      => '',
        'timezone' => 'Africa/Kampala',
        'key'      => 'CHANGE-ME-TO-A-LONG-RANDOM-STRING',
        'debug'    => false,
    ],
];
