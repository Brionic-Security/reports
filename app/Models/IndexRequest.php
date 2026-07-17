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
}
