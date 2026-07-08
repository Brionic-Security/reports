<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/** Log of sent traffic alerts (used to avoid duplicates per site/day/kind). */
final class Alert
{
    public static function sentToday(int $siteId, string $day, string $kind): bool
    {
        return Database::selectOne(
            'SELECT id FROM alerts WHERE site_id = ? AND day = ? AND kind = ? LIMIT 1',
            [$siteId, $day, $kind]
        ) !== null;
    }

    public static function record(int $siteId, string $day, string $kind, string $detail = ''): void
    {
        Database::insert(
            'INSERT INTO alerts (site_id, kind, day, detail, created_at) VALUES (?, ?, ?, ?, ?)',
            [$siteId, $kind, $day, substr($detail, 0, 255), now()]
        );
    }
}
