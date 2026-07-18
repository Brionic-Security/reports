<?php

declare(strict_types=1);

/**
 * Hands-free search setup: auto-connect any tracked site to Google/Bing and
 * auto-verify pending ownership as soon as the site's verification tag is live.
 * Runs frequently (every ~15 min) so nothing needs a dashboard click.
 *
 *   php scripts/search_autoverify.php
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\SearchService;

$stamp = gmdate('c');

try {
    $lines = SearchService::autoManage();
    if ($lines === []) {
        echo "{$stamp} — search auto-manage: nothing to do.\n";
    } else {
        foreach ($lines as $line) {
            echo "{$stamp} — {$line}\n";
        }
    }
} catch (\Throwable $e) {
    echo "{$stamp} — search auto-manage ERROR: " . $e->getMessage() . "\n";
}
