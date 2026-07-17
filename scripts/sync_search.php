<?php

declare(strict_types=1);

/**
 * Sync search-performance data (Google Search Console + Bing) for every site
 * with a verified connection. Run daily.
 *
 *   php scripts/sync_search.php            # all connected sites, last 90 days
 *   php scripts/sync_search.php --site=3   # one site
 *   php scripts/sync_search.php --days=30
 *
 * Cron (daily 06:00):
 *   0 6 * * *  php /path/to/reports/scripts/sync_search.php >> storage/logs/cron.log 2>&1
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Models\SearchConnection;
use App\Models\Site;
use App\Services\SearchService;

$days = 90;
$onlySite = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) { $days = (int) $m[1]; }
    if (preg_match('/^--site=(\d+)$/', $arg, $m)) { $onlySite = (int) $m[1]; }
}

$stamp = gmdate('c');

// Collect distinct site ids that have any verified connection.
$ids = [];
foreach (['google', 'bing'] as $provider) {
    foreach (SearchConnection::verified($provider) as $c) {
        $ids[(int) $c['site_id']] = true;
    }
}
if ($onlySite > 0) {
    $ids = isset($ids[$onlySite]) ? [$onlySite => true] : [];
}

if ($ids === []) {
    echo "{$stamp} — search sync: no verified connections.\n";
    exit(0);
}

$synced = 0;
foreach (array_keys($ids) as $siteId) {
    $site = Site::find($siteId);
    if ($site === null) { continue; }
    try {
        $res = SearchService::syncMetrics($site, $days);
        echo "{$stamp} — {$site['name']}: " . implode(' ', $res) . "\n";
        $synced++;
    } catch (\Throwable $e) {
        echo "{$stamp} — {$site['name']}: ERROR " . $e->getMessage() . "\n";
    }
}

echo "{$stamp} — search sync done ({$synced} site(s)).\n";
