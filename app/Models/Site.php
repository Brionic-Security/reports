<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;
use App\Support\Id;

/**
 * A tracked website. `public_id` (site_xxx) is the key embedded in the tracker
 * snippet; `report_email` is where weekly reports are sent (optional).
 */
final class Site
{
    public static function create(string $name, string $domain, ?string $reportEmail = null): array
    {
        $publicId = Id::generate('site', 20);
        Database::insert(
            'INSERT INTO sites (public_id, name, domain, report_email, created_at) VALUES (?, ?, ?, ?, ?)',
            [$publicId, $name, self::normalizeDomain($domain), $reportEmail, now()]
        );
        return self::findByPublicId($publicId);
    }

    public static function all(): array
    {
        return Database::select('SELECT * FROM sites ORDER BY name ASC');
    }

    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM sites WHERE id = ?', [$id]);
    }

    public static function findByPublicId(string $publicId): ?array
    {
        return Database::selectOne('SELECT * FROM sites WHERE public_id = ?', [$publicId]);
    }

    public static function update(int $id, string $name, string $domain, ?string $reportEmail): void
    {
        Database::run(
            'UPDATE sites SET name = ?, domain = ?, report_email = ? WHERE id = ?',
            [$name, self::normalizeDomain($domain), $reportEmail, $id]
        );
    }

    public static function updateMonitor(int $id, ?string $monitorUrl, bool $enabled): void
    {
        Database::run(
            'UPDATE sites SET monitor_url = ?, monitor_enabled = ? WHERE id = ?',
            [$monitorUrl, $enabled ? 1 : 0, $id]
        );
    }

    /** Remembered list of URLs the operator wants (re)indexed (newline-joined; null = use sitemap default). */
    public static function updateIndexUrls(int $id, ?string $urls): void
    {
        Database::run('UPDATE sites SET index_urls = ? WHERE id = ?', [$urls, $id]);
    }

    public static function delete(int $id): void
    {
        Database::transaction(function () use ($id) {
            Database::run('DELETE FROM events WHERE site_id = ?', [$id]);
            Database::run('DELETE FROM sites WHERE id = ?', [$id]);
        });
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        return rtrim(explode('/', $domain)[0], '.');
    }
}
