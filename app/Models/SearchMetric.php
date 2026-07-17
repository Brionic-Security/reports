<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * Daily search-performance metrics pulled from Google Search Console / Bing.
 * `dimension` is 'total' (one row/day), 'query' or 'page' (top-N rows/day).
 */
final class SearchMetric
{
    /**
     * Idempotent upsert keyed by (site, provider, day, dimension, label).
     */
    public static function record(int $siteId, string $provider, string $day, string $dimension, string $label, array $m): void
    {
        Database::run(
            'DELETE FROM search_metrics WHERE site_id = ? AND provider = ? AND day = ? AND dimension = ? AND label = ?',
            [$siteId, $provider, $day, $dimension, $label]
        );
        Database::insert(
            'INSERT INTO search_metrics (site_id, provider, day, dimension, label, clicks, impressions, ctr, position, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $siteId, $provider, $day, $dimension, mb_substr($label, 0, 255),
                (int) ($m['clicks'] ?? 0),
                (int) ($m['impressions'] ?? 0),
                (float) ($m['ctr'] ?? 0),
                (float) ($m['position'] ?? 0),
                now(),
            ]
        );
    }

    /**
     * Totals over a window for a site, optionally a single provider.
     *
     * @return array{clicks:int,impressions:int,ctr:float,position:float}
     */
    public static function totals(int $siteId, string $from, string $to, ?string $provider = null): array
    {
        $sql = "SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(impressions),0) impressions,
                       COALESCE(AVG(NULLIF(position,0)),0) position
                FROM search_metrics
                WHERE site_id = ? AND dimension = 'total' AND day >= ? AND day <= ?";
        $params = [$siteId, $from, $to];
        if ($provider !== null) {
            $sql .= ' AND provider = ?';
            $params[] = $provider;
        }
        $row = Database::selectOne($sql, $params) ?? [];
        $clicks = (int) ($row['clicks'] ?? 0);
        $impr = (int) ($row['impressions'] ?? 0);
        return [
            'clicks'      => $clicks,
            'impressions' => $impr,
            'ctr'         => $impr > 0 ? $clicks / $impr : 0.0,
            'position'    => (float) ($row['position'] ?? 0),
        ];
    }

    /** Per-day totals for a chart. @return array<int,array<string,mixed>> */
    public static function daily(int $siteId, string $from, string $to, ?string $provider = null): array
    {
        $sql = "SELECT day, SUM(clicks) clicks, SUM(impressions) impressions
                FROM search_metrics
                WHERE site_id = ? AND dimension = 'total' AND day >= ? AND day <= ?";
        $params = [$siteId, $from, $to];
        if ($provider !== null) {
            $sql .= ' AND provider = ?';
            $params[] = $provider;
        }
        $sql .= ' GROUP BY day ORDER BY day';
        return Database::select($sql, $params);
    }

    /**
     * Top queries or pages over a window.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function top(int $siteId, string $dimension, string $from, string $to, ?string $provider = null, int $limit = 15): array
    {
        $sql = "SELECT label, SUM(clicks) clicks, SUM(impressions) impressions,
                       CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) * 1.0 / SUM(impressions) ELSE 0 END ctr,
                       AVG(NULLIF(position,0)) position
                FROM search_metrics
                WHERE site_id = ? AND dimension = ? AND day >= ? AND day <= ?";
        $params = [$siteId, $dimension, $from, $to];
        if ($provider !== null) {
            $sql .= ' AND provider = ?';
            $params[] = $provider;
        }
        $sql .= ' GROUP BY label ORDER BY clicks DESC, impressions DESC LIMIT ' . (int) $limit;
        return Database::select($sql, $params);
    }

    public static function hasAny(int $siteId): bool
    {
        $row = Database::selectOne('SELECT 1 one FROM search_metrics WHERE site_id = ? LIMIT 1', [$siteId]);
        return $row !== null;
    }
}
