<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/app/Config/Config.php';
require_once APP_ROOT . '/app/Database/Database.php';
require_once APP_ROOT . '/app/helpers.php';

\FindSync\Config\Config::load(APP_ROOT);

spl_autoload_register(static function (string $class): void {
    $prefix = 'FindSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

\FindSync\Middleware\SessionMiddleware::start();
