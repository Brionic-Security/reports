<?php

declare(strict_types=1);

/**
 * Application bootstrap: autoloader, environment, configuration, runtime setup.
 * Shared by the HTTP front controller and all CLI scripts.
 */

use App\Support\Config;
use App\Support\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');
Config::load(dirname(__DIR__) . '/config');

date_default_timezone_set((string) config('app.timezone', 'UTC'));

if (config('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');
}

foreach (['logs', 'cache'] as $dir) {
    $path = dirname(__DIR__) . '/storage/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}
