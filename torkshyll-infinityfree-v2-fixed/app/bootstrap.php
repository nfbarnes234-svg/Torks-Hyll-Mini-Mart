<?php
declare(strict_types=1);

$configFile = __DIR__ . '/../config/env.php';
$config = file_exists($configFile)
    ? require $configFile
    : require __DIR__ . '/../config/env.example.php';

date_default_timezone_set($config['app']['timezone'] ?? 'Africa/Accra');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name'] ?? 'torkshyll_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function app_config(string $key = ''): mixed
{
    global $config;
    if ($key === '') {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function app_settings(): array
{
    static $settings;
    if ($settings !== null) {
        return $settings;
    }
    try {
        $settings = db()->query('SELECT * FROM settings WHERE id = 1')->fetch() ?: [];
    } catch (Throwable) {
        $settings = [];
    }
    return $settings;
}