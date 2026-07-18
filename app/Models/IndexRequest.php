<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * Audit log of indexing / sitemap submissions to search engines.
 */
final class IndexRequest
{
    public static function log(int $siteId, string $provider, string $kind, string $target, string $status, string $detail = ''): void
    {
        Database::insert(
            'INSERT INTO index_requests (site_id, provider, kind, target, status, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$siteId, $provider, $kind, mb_substr($target, 0, 500), $status, mb_substr($detail, 0, 500), now()]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentForSite(int $siteId, int $limit = 15): array
    {
        return Database::select(
            'SELECT * FROM index_requests WHERE site_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
            [$siteId]
        );
    }

    /**
     * Most recent request timestamp per provider (google|bing|indexnow).
     *
     * @return array<string,string>  provider => created_at
     */
    public static function latestByProvider(int $siteId): array
    {
        $rows = Database::select(
            'SELECT provider, MAX(created_at) AS last_at FROM index_requests WHERE site_id = ? GROUP BY provider',
            [$siteId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['provider']] = (string) $r['last_at'];
        }
        return $out;
    }

    /** Latest request row for a provider (optionally a specific kind). */
    public static function latest(int $siteId, string $provider, ?string $kind = null): ?array
    {
        $sql = 'SELECT * FROM index_requests WHERE site_id = ? AND provider = ?';
        $params = [$siteId, $provider];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        return Database::selectOne($sql, $params);
    }
}
