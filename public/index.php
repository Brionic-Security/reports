<?php

declare(strict_types=1);

/**
 * Front controller. Point the web server document root here.
 *
 * Dev:  php -S 127.0.0.1:8790 -t public public/index.php
 */

use App\Support\Kernel;
use App\Support\Request;

require dirname(__DIR__) . '/bootstrap/app.php';

// Serve existing static files directly when using the PHP built-in server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$kernel = new Kernel();
$response = $kernel->handle(new Request());
$response->send();
