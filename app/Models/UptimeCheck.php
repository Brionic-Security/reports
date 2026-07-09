<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/** A single HTTP health check for a site. */
final class UptimeCheck
{
    public static function record(int $siteId, bool $up, int $status, int $ms, string $error = ''): void
    {
        Database::insert(
            'INSERT INTO uptime_checks (site_id, up, status_code, response_ms, error, checked_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$siteId, $up ? 1 : 0, $status, $ms, substr($error, 0, 255), now()]
        );
    }

    /** Most recent check for a site (or null). */
    public static function latest(int $siteId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM uptime_checks WHERE site_id = ? ORDER BY id DESC LIMIT 1',
            [$siteId]
        );
    }

    /** @return array<int,array<string,mixed>> recent checks, newest first */
    public static function recent(int $siteId, int $limit = 20): array
    {
        return Database::select(
            'SELECT * FROM uptime_checks WHERE site_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$siteId]
        );
    }

    /** Uptime percentage over a window (default 30 days). Null if no checks. */
    public static function uptimePercent(int $siteId, string $sinceSql): ?float
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) c, SUM(up) u FROM uptime_checks WHERE site_id = ? AND checked_at >= ?',
            [$siteId, $sinceSql]
        );
        $c = (int) ($row['c'] ?? 0);
        if ($c === 0) {
            return null;
        }
        return round((int) $row['u'] / $c * 100, 2);
    }

    /** Average response time (ms) over a window, for successful checks only. */
    public static function avgResponseMs(int $siteId, string $sinceSql): ?int
    {
        $row = Database::selectOne(
            'SELECT AVG(response_ms) a FROM uptime_checks WHERE site_id = ? AND up = 1 AND checked_at >= ?',
            [$siteId, $sinceSql]
        );
        return isset($row['a']) && $row['a'] !== null ? (int) round((float) $row['a']) : null;
    }

    /**
     * Latest check per site (for the overview grid).
     *
     * @return array<int,array<string,mixed>> keyed by site_id
     */
    public static function latestForAll(): array
    {
        $rows = Database::select(
            'SELECT u.* FROM uptime_checks u
             JOIN (SELECT site_id, MAX(id) mid FROM uptime_checks GROUP BY site_id) m
               ON m.mid = u.id'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['site_id']] = $r;
        }
        return $out;
    }
}
