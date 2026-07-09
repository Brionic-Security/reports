<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/**
 * Read-side aggregation for the dashboard and reports. Human traffic only
 * (bots excluded) unless noted.
 */
final class Stats
{
    /**
     * Per-site headline numbers for the overview grid.
     *
     * @return array<int,array<string,mixed>> keyed rows: site_id, pageviews, visitors
     */
    public static function overview(string $range, ?string $from, ?string $to): array
    {
        [$df, $dt] = date_range_bounds($range, $from, $to);
        [$where, $params] = self::window('created_at', $df, $dt);

        $rows = Database::select(
            "SELECT site_id,
                    SUM(CASE WHEN type = 'pageview' THEN 1 ELSE 0 END) pageviews,
                    COUNT(DISTINCT visitor_hash) visitors
             FROM events
             WHERE {$where} AND is_bot = 0
             GROUP BY site_id",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['site_id']] = [
                'pageviews' => (int) $r['pageviews'],
                'visitors'  => (int) $r['visitors'],
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function forSite(int $siteId, string $range, ?string $from, ?string $to): array
    {
        [$df, $dt] = date_range_bounds($range, $from, $to);
        [$win, $p] = self::window('created_at', $df, $dt);
        $base = "site_id = ? AND {$win}";
        $bp = array_merge([$siteId], $p);
        $human = "{$base} AND is_bot = 0";

        $totals = Database::selectOne(
            "SELECT
                SUM(CASE WHEN type = 'pageview' THEN 1 ELSE 0 END) pageviews,
                COUNT(DISTINCT visitor_hash) visitors,
                SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) bots,
                COUNT(*) total
             FROM events WHERE {$base}",
            $bp
        ) ?? [];

        $humanPv = (int) (Database::selectOne(
            "SELECT COUNT(*) c FROM events WHERE {$human} AND type = 'pageview'",
            $bp
        )['c'] ?? 0);

        // Previous equal-length window (for the % change), when a start exists.
        $prev = null;
        if ($df !== null) {
            $endTs = $dt !== null ? strtotime($dt) : time();
            $len = $endTs - strtotime($df);
            if ($len > 0) {
                $prevFrom = gmdate('Y-m-d H:i:s', strtotime($df) - $len);
                $prev = self::periodCounts($siteId, $prevFrom, $df);
            }
        }

        return [
            'pageviews'   => $humanPv,
            'visitors'    => (int) ($totals['visitors'] ?? 0),
            'bots'        => (int) ($totals['bots'] ?? 0),
            'total'       => (int) ($totals['total'] ?? 0),
            'prev'        => $prev,
            'by_day'      => self::byDay($base, $bp),
            'map'         => Database::select(
                "SELECT ROUND(lat, 1) rlat, ROUND(lon, 1) rlon,
                        MAX(country) country, MAX(city) city,
                        COUNT(DISTINCT visitor_hash) n
                 FROM events WHERE {$human} AND lat IS NOT NULL
                 GROUP BY ROUND(lat, 1), ROUND(lon, 1) ORDER BY n DESC LIMIT 250",
                $bp
            ),
            'cities'      => Database::select(
                "SELECT city, MAX(country) country, COUNT(DISTINCT visitor_hash) n
                 FROM events WHERE {$human} AND city <> ''
                 GROUP BY city ORDER BY n DESC LIMIT 10",
                $bp
            ),
            'top_pages'   => Database::select(
                "SELECT path, COUNT(*) n FROM events WHERE {$human} AND type = 'pageview'
                 GROUP BY path ORDER BY n DESC LIMIT 12",
                $bp
            ),
            'referrers'   => Database::select(
                "SELECT referer_host, COUNT(*) n FROM events WHERE {$human} AND referer_host <> ''
                 GROUP BY referer_host ORDER BY n DESC LIMIT 12",
                $bp
            ),
            'countries'   => Database::select(
                "SELECT COALESCE(NULLIF(country, ''), 'Unknown') country, COUNT(DISTINCT visitor_hash) n
                 FROM events WHERE {$human}
                 GROUP BY country ORDER BY n DESC LIMIT 12",
                $bp
            ),
            'devices'     => self::group('device', $human, $bp),
            'browsers'    => self::group('browser', $human, $bp),
            'os'          => self::group('os', $human, $bp),
            'events'      => Database::select(
                "SELECT name, COUNT(*) n FROM events WHERE {$base} AND type = 'event' AND name IS NOT NULL
                 GROUP BY name ORDER BY n DESC LIMIT 12",
                $bp
            ),
            'bot_names'   => Database::select(
                "SELECT bot_name, COUNT(*) n FROM events WHERE {$base} AND is_bot = 1
                 GROUP BY bot_name ORDER BY n DESC LIMIT 8",
                $bp
            ),
            'recent'      => Database::select(
                "SELECT type, name, path, referer_host, is_bot, bot_name, browser, os, device, country, created_at
                 FROM events WHERE {$base} ORDER BY id DESC LIMIT 20",
                $bp
            ),
        ];
    }

    /** @return array<int,array{label:string,n:int}> */
    private static function group(string $col, string $where, array $params): array
    {
        $rows = Database::select(
            "SELECT COALESCE(NULLIF({$col}, ''), 'Unknown') label, COUNT(*) n
             FROM events WHERE {$where} AND type = 'pageview'
             GROUP BY label ORDER BY n DESC LIMIT 10",
            $params
        );
        return array_map(static fn ($r) => ['label' => (string) $r['label'], 'n' => (int) $r['n']], $rows);
    }

    /** @return array<int,array{date:string,humans:int,bots:int}> */
    private static function byDay(string $where, array $params): array
    {
        $rows = Database::select(
            "SELECT substr(created_at, 1, 10) d,
                    SUM(CASE WHEN is_bot = 0 AND type = 'pageview' THEN 1 ELSE 0 END) humans,
                    SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) bots
             FROM events WHERE {$where}
             GROUP BY substr(created_at, 1, 10) ORDER BY d ASC LIMIT 90",
            $params
        );
        return array_map(static fn ($r) => [
            'date'   => (string) $r['d'],
            'humans' => (int) $r['humans'],
            'bots'   => (int) $r['bots'],
        ], $rows);
    }

    /** @return array{0:string,1:array<int,string>} */
    private static function window(string $col, ?string $df, ?string $dt): array
    {
        $conds = [];
        $params = [];
        if ($df !== null) {
            $conds[] = "{$col} >= ?";
            $params[] = $df;
        }
        if ($dt !== null) {
            $conds[] = "{$col} < ?";
            $params[] = $dt;
        }
        return [$conds === [] ? '1 = 1' : implode(' AND ', $conds), $params];
    }

    /** Human visitors + page views over an explicit [from, to) window. */
    private static function periodCounts(int $siteId, string $from, string $to): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(DISTINCT visitor_hash) visitors,
                    SUM(CASE WHEN type = 'pageview' THEN 1 ELSE 0 END) pageviews
             FROM events
             WHERE site_id = ? AND is_bot = 0 AND created_at >= ? AND created_at < ?",
            [$siteId, $from, $to]
        ) ?? [];
        return [
            'visitors'  => (int) ($row['visitors'] ?? 0),
            'pageviews' => (int) ($row['pageviews'] ?? 0),
        ];
    }
}
