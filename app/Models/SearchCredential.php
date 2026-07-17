<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * Operator-level OAuth / API credentials for a search provider. Normally a
 * single row per provider (the admin's connected Google account).
 */
final class SearchCredential
{
    public static function forProvider(string $provider): ?array
    {
        return Database::selectOne('SELECT * FROM search_credentials WHERE provider = ?', [$provider]);
    }

    public static function connected(string $provider): bool
    {
        $row = self::forProvider($provider);
        return $row !== null && (string) ($row['refresh_token'] ?? '') !== '';
    }

    /**
     * Insert or update the single row for a provider.
     */
    public static function store(string $provider, array $fields): void
    {
        $existing = self::forProvider($provider);
        if ($existing === null) {
            Database::insert(
                'INSERT INTO search_credentials (provider, account, access_token, refresh_token, expires_at, scope, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $provider,
                    (string) ($fields['account'] ?? ''),
                    $fields['access_token'] ?? null,
                    $fields['refresh_token'] ?? null,
                    (string) ($fields['expires_at'] ?? ''),
                    $fields['scope'] ?? null,
                    now(),
                    now(),
                ]
            );
            return;
        }

        // Never wipe an existing refresh token if the new grant omits one.
        $refresh = $fields['refresh_token'] ?? null;
        if ($refresh === null || $refresh === '') {
            $refresh = $existing['refresh_token'];
        }
        Database::run(
            'UPDATE search_credentials SET account = ?, access_token = ?, refresh_token = ?, expires_at = ?, scope = ?, updated_at = ? WHERE provider = ?',
            [
                (string) ($fields['account'] ?? $existing['account']),
                $fields['access_token'] ?? $existing['access_token'],
                $refresh,
                (string) ($fields['expires_at'] ?? $existing['expires_at']),
                $fields['scope'] ?? $existing['scope'],
                now(),
                $provider,
            ]
        );
    }

    public static function updateAccessToken(string $provider, string $accessToken, string $expiresAt): void
    {
        Database::run(
            'UPDATE search_credentials SET access_token = ?, expires_at = ?, updated_at = ? WHERE provider = ?',
            [$accessToken, $expiresAt, now(), $provider]
        );
    }

    public static function disconnect(string $provider): void
    {
        Database::run('DELETE FROM search_credentials WHERE provider = ?', [$provider]);
    }
}
