<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * A record of a client report that was generated (and sent, or failed).
 * Used to avoid sending the same weekly report twice.
 */
final class ReportRun
{
    public static function sentExists(int $siteId, string $periodStart): bool
    {
        $row = Database::selectOne(
            "SELECT id FROM report_runs WHERE site_id = ? AND period_start = ? AND status = 'sent' LIMIT 1",
            [$siteId, $periodStart]
        );
        return $row !== null;
    }

    public static function record(int $siteId, string $periodStart, string $periodEnd, string $sentTo, string $status, string $detail = ''): void
    {
        Database::insert(
            'INSERT INTO report_runs (site_id, period_start, period_end, sent_to, status, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$siteId, $periodStart, $periodEnd, $sentTo, $status, substr($detail, 0, 255), now()]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentForSite(int $siteId, int $limit = 10): array
    {
        return Database::select(
            'SELECT * FROM report_runs WHERE site_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
            [$siteId]
        );
    }
}
