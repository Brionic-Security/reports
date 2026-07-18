<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * A single site's connection to one search-engine property (google | bing).
 */
final class SearchConnection
{
    public static function find(int $siteId, string $provider): ?array
    {
        return Database::selectOne(
            'SELECT * FROM site_search_connections WHERE site_id = ? AND provider = ?',
            [$siteId, $provider]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forSite(int $siteId): array
    {
        return Database::select(
            'SELECT * FROM site_search_connections WHERE site_id = ? ORDER BY provider',
            [$siteId]
        );
    }

    /** All verified connections for a provider (used by the sync cron). */
    public static function verified(string $provider): array
    {
        return Database::select(
            "SELECT c.*, s.domain, s.name FROM site_search_connections c
             JOIN sites s ON s.id = c.site_id
             WHERE c.provider = ? AND c.status = 'verified'",
            [$provider]
        );
    }

    /** All not-yet-verified connections for a provider (used by auto-verify). */
    public static function pending(string $provider): array
    {
        return Database::select(
            "SELECT c.*, s.domain, s.name FROM site_search_connections c
             JOIN sites s ON s.id = c.site_id
             WHERE c.provider = ? AND c.status <> 'verified'",
            [$provider]
        );
    }

    /**
     * Create or replace a connection row for a (site, provider).
     */
    public static function upsert(int $siteId, string $provider, array $fields): array
    {
        $existing = self::find($siteId, $provider);
        if ($existing === null) {
            Database::insert(
                'INSERT INTO site_search_connections
                    (site_id, provider, property, property_type, verification, verify_token, status, detail, verified_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $siteId,
                    $provider,
                    (string) ($fields['property'] ?? ''),
                    (string) ($fields['property_type'] ?? 'url'),
                    (string) ($fields['verification'] ?? ''),
                    (string) ($fields['verify_token'] ?? ''),
                    (string) ($fields['status'] ?? 'pending'),
                    (string) ($fields['detail'] ?? ''),
                    (string) ($fields['verified_at'] ?? ''),
                    now(),
                    now(),
                ]
            );
        } else {
            Database::run(
                'UPDATE site_search_connections
                    SET property = ?, property_type = ?, verification = ?, verify_token = ?, status = ?, detail = ?, verified_at = ?, updated_at = ?
                  WHERE site_id = ? AND provider = ?',
                [
                    (string) ($fields['property'] ?? $existing['property']),
                    (string) ($fields['property_type'] ?? $existing['property_type']),
                    (string) ($fields['verification'] ?? $existing['verification']),
                    (string) ($fields['verify_token'] ?? $existing['verify_token']),
                    (string) ($fields['status'] ?? $existing['status']),
                    (string) ($fields['detail'] ?? $existing['detail']),
                    (string) ($fields['verified_at'] ?? $existing['verified_at']),
                    now(),
                    $siteId,
                    $provider,
                ]
            );
        }
        return self::find($siteId, $provider) ?? [];
    }

    public static function setStatus(int $siteId, string $provider, string $status, string $detail = ''): void
    {
        $verifiedAt = $status === 'verified' ? now() : '';
        Database::run(
            'UPDATE site_search_connections SET status = ?, detail = ?, verified_at = CASE WHEN ? <> \'\' THEN ? ELSE verified_at END, updated_at = ? WHERE site_id = ? AND provider = ?',
            [$status, $detail, $verifiedAt, $verifiedAt, now(), $siteId, $provider]
        );
    }

    public static function markSynced(int $id): void
    {
        Database::run('UPDATE site_search_connections SET synced_at = ? WHERE id = ?', [now(), $id]);
    }

    public static function delete(int $siteId, string $provider): void
    {
        Database::run('DELETE FROM site_search_connections WHERE site_id = ? AND provider = ?', [$siteId, $provider]);
    }
}
