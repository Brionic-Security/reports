<?php

declare(strict_types=1);

/**
 * Dev-only: seed a realistic 30-day dataset for one site so every dashboard
 * metric has data to render. NOT for production. Run: php scripts/seed_demo.php
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Database;

if (config('app.env') === 'production') {
    fwrite(STDERR, "refusing to seed in production\n");
    exit(1);
}

$site = Database::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1');
if (!$site) {
    fwrite(STDERR, "no site found\n");
    exit(1);
}
$siteId = (int) $site['id'];

$pages = ['/', '/', '/', '/pricing', '/features', '/blog/hello-world', '/contact', '/about', '/docs'];
$refs  = ['', '', 'google.com', 'google.com', 'bing.com', 'x.com', 'reddit.com', 'github.com', 'duckduckgo.com'];
$browsers = ['Chrome', 'Chrome', 'Chrome', 'Safari', 'Safari', 'Firefox', 'Edge'];
$osList   = ['Windows', 'macOS', 'macOS', 'iOS', 'Android', 'Linux'];
$devices  = ['Desktop', 'Desktop', 'Desktop', 'Mobile', 'Mobile', 'Tablet'];
$geos = [
    ['United States', 'US', 'Los Angeles', 34.05, -118.24],
    ['United States', 'US', 'New York', 40.71, -74.0],
    ['United Kingdom', 'GB', 'London', 51.5, -0.12],
    ['Germany', 'DE', 'Berlin', 52.52, 13.4],
    ['Canada', 'CA', 'Toronto', 43.65, -79.38],
    ['Australia', 'AU', 'Sydney', -33.86, 151.2],
    ['India', 'IN', 'Mumbai', 19.07, 72.87],
    ['Brazil', 'BR', 'São Paulo', -23.55, -46.63],
];
$bots = ['Googlebot', 'Bingbot', 'GPTBot', 'ClaudeBot', 'AhrefsBot'];
$events = ['signup_click', 'checkout_started', 'download', 'video_play'];

$salt = static fn (string $day) => hash('sha256', (string) config('app.key', 'k') . $day);

$rows = 0;
for ($d = 29; $d >= 0; $d--) {
    $dayTs = strtotime("-{$d} day");
    $day   = gmdate('Y-m-d', $dayTs);
    // Weekend dip + gentle upward trend.
    $dow = (int) gmdate('w', $dayTs);
    $base = 18 + (29 - $d);                      // trend up
    $base = ($dow === 0 || $dow === 6) ? (int) ($base * 0.6) : $base;
    $humanHits = max(3, $base + random_int(-6, 8));
    $visitors  = max(2, (int) ($humanHits * 0.7));

    for ($i = 0; $i < $humanHits; $i++) {
        $geo = $geos[array_rand($geos)];
        $vh  = substr($salt($day) . '|' . random_int(1, $visitors), 0, 64);
        $ts  = gmdate('Y-m-d H:i:s', $dayTs + random_int(0, 86399));
        $isEvent = random_int(1, 10) === 1;
        Database::insert(
            'INSERT INTO events (site_id,type,name,path,referer_host,is_bot,bot_name,browser,os,device,country,city,country_code,lat,lon,visitor_hash,via,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $siteId,
                $isEvent ? 'event' : 'pageview',
                $isEvent ? $events[array_rand($events)] : null,
                $pages[array_rand($pages)],
                $refs[array_rand($refs)],
                0, '',
                $browsers[array_rand($browsers)],
                $osList[array_rand($osList)],
                $devices[array_rand($devices)],
                $geo[0], $geo[2], $geo[1], $geo[3], $geo[4],
                $vh, 'html', $ts,
            ]
        );
        $rows++;
    }
    // A handful of JS-executing bots per day.
    $botHits = random_int(0, 5);
    for ($i = 0; $i < $botHits; $i++) {
        $geo = $geos[array_rand($geos)];
        $ts  = gmdate('Y-m-d H:i:s', $dayTs + random_int(0, 86399));
        Database::insert(
            'INSERT INTO events (site_id,type,name,path,referer_host,is_bot,bot_name,browser,os,device,country,city,country_code,lat,lon,visitor_hash,via,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $siteId, 'pageview', null,
                $pages[array_rand($pages)], '',
                1, $bots[array_rand($bots)],
                'Chrome', 'Unknown', 'Desktop',
                $geo[0], $geo[2], $geo[1], $geo[3], $geo[4],
                'bot' . random_int(1, 9999), 'html', $ts,
            ]
        );
        $rows++;
    }
}

echo "seeded {$rows} events for site {$siteId}\n";
