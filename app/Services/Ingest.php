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
    /**
     * Identical pageviews from the same visitor+path arriving within this many
     * seconds are treated as a single view. Client scripts can fire more than
     * once per navigation (speed-optimiser duplication, combined/inlined
     * bundles, browser prefetch, SPA quirks); a human cannot meaningfully
     * reload and re-read a page this fast, so a tight window only collapses
     * machine double-fires without dropping genuine re-visits.
     */
    private const DEDUPE_SECONDS = 2;

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

        $siteId      = (int) $site['id'];
        $eventType   = $type === 'event' ? 'event' : 'pageview';
        $path        = substr(self::cleanPath($path), 0, 255);
        $visitorHash = self::visitorHash($siteId, $ip, $userAgent);

        // Drop machine double-fires: an identical pageview from the same
        // visitor+path within a couple of seconds is a duplicate, not a real
        // second view. Custom events are exempt (they can legitimately repeat).
        if ($eventType === 'pageview'
            && self::isDuplicatePageview($siteId, $visitorHash, $path)) {
            return true;
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
                $siteId,
                $eventType,
                $name !== null ? substr($name, 0, 80) : null,
                $path,
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
                $visitorHash,
                self::cleanVia($via),
                now(),
            ]
        );
        return true;
    }

    /** True if an identical pageview was recorded within the dedupe window. */
    private static function isDuplicatePageview(int $siteId, string $visitorHash, string $path): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::DEDUPE_SECONDS);
        $row = Database::selectOne(
            "SELECT 1 FROM events
             WHERE site_id = ? AND type = 'pageview' AND visitor_hash = ?
               AND path = ? AND created_at >= ?
             LIMIT 1",
            [$siteId, $visitorHash, $path, $since]
        );
        return $row !== null;
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
