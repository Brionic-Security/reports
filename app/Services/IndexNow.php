<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Http;

/**
 * IndexNow — an open protocol (Bing, Yandex, Seznam, Naver) for instantly
 * notifying search engines that URLs were added/updated. No per-site account
 * or OAuth: you host a <key>.txt file at the site root (or a shared
 * keyLocation) and POST the URLs.
 *
 * For third-party sites, the key file must be reachable on that host — the
 * Brionic WordPress plugin serves it, or a keyLocation can be supplied.
 */
final class IndexNow
{
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public static function configured(): bool
    {
        return (string) config('search.indexnow.key', '') !== '';
    }

    public static function key(): string
    {
        return (string) config('search.indexnow.key', '');
    }

    /**
     * Submit URLs for a host.
     *
     * @param string[]     $urls        absolute URLs on $host
     * @param string|null  $keyLocation absolute URL of the key file (defaults to host root /<key>.txt)
     * @return array{ok:bool,error:string}
     */
    public static function submit(string $host, array $urls, ?string $keyLocation = null): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'IndexNow key not set'];
        }
        $urls = array_values(array_filter(array_map('trim', $urls)));
        if ($urls === []) {
            return ['ok' => false, 'error' => 'no URLs'];
        }
        $body = [
            'host'        => $host,
            'key'         => self::key(),
            'keyLocation' => $keyLocation ?? ('https://' . $host . '/' . self::key() . '.txt'),
            'urlList'     => $urls,
        ];
        $res = Http::postJson(self::ENDPOINT, $body);
        // IndexNow returns 200/202 on success; 422/403 when the key file can't
        // be validated on the host.
        $ok = $res['ok'] || $res['status'] === 202;
        return ['ok' => $ok, 'error' => $ok ? '' : ($res['error'] ?: ('HTTP ' . $res['status']))];
    }
}
