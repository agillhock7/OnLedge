<?php

declare(strict_types=1);

if (!function_exists('onledge_load_config')) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    function onledge_load_config(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $configPath = __DIR__ . '/../config/config.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('Missing /api/config/config.php. Copy config.example.php and fill values.');
        }

        $loaded = require $configPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('Config file must return an array.');
        }

        $config = $loaded;

        $session = $config['session_cookie'] ?? [];
        $name = (string) ($session['name'] ?? 'onledge_session');

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => (bool) ($session['secure'] ?? true),
            'httponly' => (bool) ($session['httponly'] ?? true),
            'samesite' => (string) ($session['samesite'] ?? 'Lax'),
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        date_default_timezone_set('UTC');

        return $config;
    }
}
