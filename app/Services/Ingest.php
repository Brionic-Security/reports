<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Support\Database;

/**
 * Ingests a single tracked event (pageview or custom event).
 *
 * Privacy-first: no raw IP is ever stored on an event. The IP is used only to
 * (a) derive a daily-rotating visitor hash for unique counts, and (b) resolve a
 * coarse country/city via the cached Geo service. The daily salt means visitor
 * hashes cannot be linked across days.
 */
final class Ingest
{
    public static function record(
        string $siteKey,
        string $path,
        string $referrer,
        string $userAgent,
        string $ip,
        string $type = 'pageview',
        ?string $name = null,
        ?string $screen = null,
        ?string $via = null
    ): bool {
        $site = Site::findByPublicId($siteKey);
        if ($site === null) {
            return false;
        }

        [$isBot, $botName] = UserAgent::classify($userAgent);
        $ua = UserAgent::parse($userAgent);
        $geo = Geo::lookup($ip);

        Database::insert(
            'INSERT INTO events
                (site_id, type, name, path, referer_host, is_bot, bot_name,
                 browser, os, device, country, city, country_code, lat, lon, visitor_hash, via, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $site['id'],
                $type === 'event' ? 'event' : 'pageview',
                $name !== null ? substr($name, 0, 80) : null,
                substr(self::cleanPath($path), 0, 255),
                self::refererHost($referrer, (string) $site['domain']),
                $isBot ? 1 : 0,
                substr($botName, 0, 60),
                $ua['browser'],
                $ua['os'],
                self::device($ua['device'], $screen),
                $geo['country'] ?? '',
                $geo['city'] ?? '',
                $geo['country_code'] ?? '',
                $geo['lat'] ?? null,
                $geo['lon'] ?? null,
                self::visitorHash((int) $site['id'], $ip, $userAgent),
                self::cleanVia($via),
                now(),
            ]
        );
        return true;
    }

    /** Normalise the install-method marker to a short known token. */
    private static function cleanVia(?string $via): string
    {
        $via = strtolower(trim((string) $via));
        $allowed = ['wordpress', 'html', 'gtm', 'shopify', 'wix', 'squarespace'];
        if ($via === '' || !in_array($via, $allowed, true)) {
            return 'html';
        }
        return $via;
    }

    private static function visitorHash(int $siteId, string $ip, string $ua): string
    {
        $salt = hash('sha256', (string) config('app.key', 'brionic-reports') . gmdate('Y-m-d'));
        return hash('sha256', $salt . '|' . $siteId . '|' . $ip . '|' . $ua);
    }

    private static function cleanPath(string $path): string
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
        if ($path === '') {
            $path = '/';
        }
        return '/' . ltrim($path, '/');
    }

    private static function refererHost(string $referrer, string $selfDomain): string
    {
        if ($referrer === '') {
            return '';
        }
        $host = strtolower((string) (parse_url($referrer, PHP_URL_HOST) ?: ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $self = preg_replace('/^www\./', '', strtolower($selfDomain)) ?? $selfDomain;
        if ($host === '' || $host === $self) {
            return '';
        }
        return substr($host, 0, 190);
    }

    /** Prefer UA-derived device; fall back to screen width when ambiguous. */
    private static function device(string $uaDevice, ?string $screen): string
    {
        if ($uaDevice !== 'Desktop') {
            return $uaDevice;
        }
        $w = (int) $screen;
        if ($w > 0 && $w < 768) {
            return 'Mobile';
        }
        if ($w >= 768 && $w < 1024) {
            return 'Tablet';
        }
        return 'Desktop';
    }
}
