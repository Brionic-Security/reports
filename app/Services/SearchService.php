<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IndexRequest;
use App\Models\SearchConnection;
use App\Models\SearchMetric;
use App\Models\Site;

/**
 * High-level orchestration for connecting a site to Google Search Console and
 * Bing, verifying ownership, requesting indexing (sitemap for Google, URL
 * submission + IndexNow for Bing), and syncing search-performance data.
 */
final class SearchService
{
    /** Bare domain for a site (no scheme/path). */
    public static function domain(array $site): string
    {
        return Site::normalizeDomain((string) $site['domain']);
    }

    public static function homeUrl(array $site): string
    {
        return 'https://' . self::domain($site) . '/';
    }

    public static function sitemapUrl(array $site): string
    {
        return 'https://' . self::domain($site) . '/sitemap.xml';
    }

    /**
     * Default list of URLs to offer for indexing — the site's own pages, pulled
     * from its sitemap (cached ~1h), falling back to pages seen in analytics,
     * then the homepage. Homepage is always first. Capped so Bing's daily
     * URL-submission quota stays comfortable; the operator can add more.
     *
     * @return string[]
     */
    public static function defaultIndexUrls(array $site, int $limit = 12): array
    {
        $home = self::homeUrl($site);
        $urls = self::sitemapUrls($site, $limit);
        if (count($urls) <= 1) {
            foreach (self::analyticsUrls($site, $limit) as $u) {
                $urls[] = $u;
            }
        }
        $urls = array_values(array_unique(array_merge([$home], $urls)));
        return array_slice($urls, 0, $limit);
    }

    /**
     * Fetch + parse the site's sitemap.xml (handles a sitemap index). Cached to
     * storage/cache for ~1h so settings-page loads stay fast. Best-effort.
     *
     * @return string[]
     */
    public static function sitemapUrls(array $site, int $limit = 50): array
    {
        $domain = self::domain($site);
        $cacheFile = storage_path('cache/sitemap_' . preg_replace('/[^a-z0-9]+/i', '_', $domain) . '.json');
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile) < 3600)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return array_slice($cached, 0, $limit);
            }
        }
        $urls = self::crawlSitemap('https://' . $domain . '/sitemap.xml', $limit, 0);
        @file_put_contents($cacheFile, json_encode($urls));
        return array_slice($urls, 0, $limit);
    }

    /** @return string[] */
    private static function crawlSitemap(string $url, int $limit, int $depth): array
    {
        if ($depth > 2) {
            return [];
        }
        $res = \App\Support\Http::get($url, [], 8, 5);
        if (!$res['ok'] || (string) $res['body'] === '') {
            return [];
        }
        $xml = @simplexml_load_string((string) $res['body']);
        if ($xml === false) {
            return [];
        }
        $out = [];
        if (strtolower($xml->getName()) === 'sitemapindex') {
            $seen = 0;
            foreach ($xml->sitemap as $sm) {
                $loc = trim((string) $sm->loc);
                if ($loc === '') {
                    continue;
                }
                foreach (self::crawlSitemap($loc, $limit, $depth + 1) as $u) {
                    $out[] = $u;
                }
                if (count($out) >= $limit || ++$seen >= 5) {
                    break;
                }
            }
        } else {
            foreach ($xml->url as $u) {
                $loc = trim((string) $u->loc);
                if ($loc !== '') {
                    $out[] = $loc;
                }
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Pages seen in our own analytics (fallback when the sitemap is missing).
     *
     * @return string[]
     */
    private static function analyticsUrls(array $site, int $limit = 12): array
    {
        $rows = \App\Support\Database::select(
            "SELECT path, COUNT(*) n FROM events
             WHERE site_id = ? AND is_bot = 0 AND type = 'pageview' AND path <> ''
             GROUP BY path ORDER BY n DESC LIMIT " . (int) $limit,
            [(int) $site['id']]
        );
        $base = 'https://' . self::domain($site);
        $out = [];
        foreach ($rows as $r) {
            $p = (string) $r['path'];
            $out[] = str_contains($p, '://') ? $p : ($base . '/' . ltrim($p, '/'));
        }
        return $out;
    }

    /**
     * Connect a site to Google Search Console. Prefers DNS-TXT (domain
     * property) via Cloudflare when the zone is in the operator's account;
     * otherwise sets up a URL-prefix property verified by a meta tag (served by
     * the Brionic WordPress plugin, or added manually).
     *
     * @return array{ok:bool,status:string,message:string}
     */
    public static function connectGoogle(array $site): array
    {
        if (!GoogleOAuth::connected()) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Connect a Google account first (Integrations).'];
        }
        $siteId = (int) $site['id'];
        $domain = self::domain($site);

        // Path A: DNS-TXT domain property via Cloudflare.
        if (Cloudflare::configured()) {
            $zone = Cloudflare::findZone($domain);
            if ($zone['ok']) {
                $identifier = $domain;                      // INET_DOMAIN
                $property   = 'sc-domain:' . $domain;
                $tok = GoogleSearchConsole::getVerificationToken($identifier, 'DNS_TXT');
                if ($tok['ok'] && $tok['token'] !== '') {
                    $dns = Cloudflare::upsertTxt($zone['zone_id'], $domain, $tok['token']);
                    if ($dns['ok']) {
                        // DNS can take a moment; attempt verify, but store either way.
                        $ver = GoogleSearchConsole::verify($identifier, 'DNS_TXT');
                        GoogleSearchConsole::addSite($property);
                        SearchConnection::upsert($siteId, 'google', [
                            'property'      => $property,
                            'property_type' => 'domain',
                            'verification'  => 'dns',
                            'verify_token'  => $tok['token'],
                            'status'        => $ver['ok'] ? 'verified' : 'pending',
                            'detail'        => $ver['ok'] ? 'Verified via Cloudflare DNS.' : 'DNS record added — verifying (may take a few minutes).',
                        ]);
                        return $ver['ok']
                            ? ['ok' => true, 'status' => 'verified', 'message' => 'Google verified via Cloudflare DNS.']
                            : ['ok' => true, 'status' => 'pending', 'message' => 'DNS TXT added via Cloudflare — click Verify again in a few minutes.'];
                    }
                }
            }
        }

        // Path B: URL-prefix property, meta-tag verification (WP plugin / manual).
        $identifier = self::homeUrl($site);
        $tok = GoogleSearchConsole::getVerificationToken($identifier, 'META');
        if (!$tok['ok']) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Google: ' . $tok['error']];
        }
        $ver = GoogleSearchConsole::verify($identifier, 'META');
        GoogleSearchConsole::addSite($identifier);
        SearchConnection::upsert($siteId, 'google', [
            'property'      => $identifier,
            'property_type' => 'url',
            'verification'  => 'meta',
            'verify_token'  => $tok['token'],
            'status'        => $ver['ok'] ? 'verified' : 'pending',
            'detail'        => $ver['ok']
                ? 'Verified via meta tag.'
                : 'Add the meta tag (Brionic WP plugin does this automatically), then Verify.',
        ]);
        return $ver['ok']
            ? ['ok' => true, 'status' => 'verified', 'message' => 'Google verified.']
            : ['ok' => true, 'status' => 'pending', 'message' => 'Google property added. Install/refresh the WP plugin so the meta tag is served, then click Verify.'];
    }

    /** Re-attempt verification for an existing pending Google connection. */
    public static function verifyGoogle(array $site): array
    {
        $conn = SearchConnection::find((int) $site['id'], 'google');
        if ($conn === null) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Not connected to Google yet.'];
        }
        $method = $conn['verification'] === 'dns' ? 'DNS_TXT' : 'META';
        $identifier = $conn['property_type'] === 'domain' ? self::domain($site) : self::homeUrl($site);
        $ver = GoogleSearchConsole::verify($identifier, $method);
        SearchConnection::setStatus(
            (int) $site['id'],
            'google',
            $ver['ok'] ? 'verified' : 'pending',
            $ver['ok'] ? 'Verified.' : ('Not verified yet: ' . $ver['error'])
        );
        return $ver['ok']
            ? ['ok' => true, 'status' => 'verified', 'message' => 'Google ownership verified.']
            : ['ok' => false, 'status' => 'pending', 'message' => 'Still not verified: ' . $ver['error']];
    }

    /**
     * Connect a site to Bing. Adds the site via the API; indexing works
     * immediately through IndexNow (key file served by the WP plugin). Stats
     * require the site to be verified in Bing — easiest via one-click "Import
     * from Google Search Console" in the Bing UI.
     *
     * @return array{ok:bool,status:string,message:string}
     */
    public static function connectBing(array $site): array
    {
        if (!BingWebmaster::configured()) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Add a Bing Webmaster API key first (Integrations).'];
        }
        $siteId = (int) $site['id'];
        $siteUrl = self::homeUrl($site);
        $add = BingWebmaster::addSite($siteUrl);

        // Pull the site's Bing ownership (msvalidate.01) code so the plugin/HTML
        // can inject it — Bing then verifies when it reads the tag.
        $authCode = '';
        $list = BingWebmaster::getSites();
        if ($list['ok']) {
            $want = rtrim($siteUrl, '/');
            foreach ($list['sites'] as $s) {
                if (is_array($s) && rtrim((string) ($s['Url'] ?? ''), '/') === $want) {
                    $authCode = (string) ($s['AuthenticationCode'] ?? '');
                    break;
                }
            }
        }

        // Auto-verify ownership now (Bing reads the msvalidate meta served by the
        // WP plugin / site HTML). If the tag isn't live yet this stays pending
        // and the daily cron retries automatically — no manual step needed.
        $verified = false;
        if ($add['ok']) {
            $vr = BingWebmaster::verifySite($siteUrl);
            $verified = $vr['ok'] && $vr['verified'];
        }

        SearchConnection::upsert($siteId, 'bing', [
            'property'      => $siteUrl,
            'property_type' => 'url',
            'verification'  => 'meta',
            'verify_token'  => $authCode,
            'status'        => $verified ? 'verified' : 'pending',
            'verified_at'   => $verified ? now() : '',
            'detail'        => !$add['ok']
                ? ('Bing add-site: ' . $add['error'])
                : ($verified
                    ? 'Verified — direct URL submission + stats enabled.'
                    : 'Added to Bing. Indexing via IndexNow now; ownership auto-verifies once the msvalidate meta is live (WP plugin serves it; re-checked daily).'),
        ]);
        if (!$add['ok']) {
            return ['ok' => false, 'status' => 'pending', 'message' => 'Bing: ' . $add['error']];
        }
        return $verified
            ? ['ok' => true, 'status' => 'verified', 'message' => 'Bing connected and verified — direct submission + stats enabled.']
            : ['ok' => true, 'status' => 'pending', 'message' => 'Bing connected. Indexing works now via IndexNow; ownership will auto-verify once the meta tag is live (re-checked daily).'];
    }

    /** Re-attempt Bing ownership verification for an existing connection. */
    public static function verifyBing(array $site): array
    {
        if (!BingWebmaster::configured()) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Add a Bing Webmaster API key first (Integrations).'];
        }
        $conn = SearchConnection::find((int) $site['id'], 'bing');
        if ($conn === null) {
            return ['ok' => false, 'status' => 'error', 'message' => 'Not connected to Bing yet.'];
        }
        $vr = BingWebmaster::verifySite((string) $conn['property']);
        $verified = $vr['ok'] && $vr['verified'];
        SearchConnection::setStatus(
            (int) $site['id'],
            'bing',
            $verified ? 'verified' : 'pending',
            $verified
                ? 'Verified — direct URL submission + stats enabled.'
                : ('Not verified yet' . ($vr['error'] !== '' ? ': ' . $vr['error'] : ' — make sure the msvalidate meta tag is live on your site, then retry.'))
        );
        return $verified
            ? ['ok' => true, 'status' => 'verified', 'message' => 'Bing ownership verified — direct submission + stats enabled.']
            : ['ok' => false, 'status' => 'pending', 'message' => 'Bing not verified yet — ensure the meta tag is live on your site (the WP plugin serves it), then Verify.'];
    }

    /**
     * Retry ownership verification for every pending connection (both engines).
     * Called by the daily cron so a site verifies automatically once its
     * verification tag is live — no manual step after connecting.
     *
     * @return array<int,string>  lines describing sites that became verified
     */
    public static function autoVerifyPending(): array
    {
        $out = [];
        foreach (['google', 'bing'] as $provider) {
            foreach (SearchConnection::pending($provider) as $conn) {
                $site = Site::find((int) $conn['site_id']);
                if ($site === null) {
                    continue;
                }
                try {
                    $res = $provider === 'google'
                        ? self::verifyGoogle($site)
                        : self::verifyBing($site);
                    if (($res['status'] ?? '') === 'verified') {
                        $out[] = ucfirst($provider) . ': ' . ($site['name'] ?? ('site ' . $conn['site_id'])) . ' verified.';
                    }
                } catch (\Throwable $e) {
                    // best-effort; the next run retries.
                }
            }
        }
        return $out;
    }

    /**
     * Request (re)indexing across connected engines. Google = submit sitemap;
     * Bing = submit the URLs + IndexNow ping.
     *
     * @param string[] $urls  absolute URLs; defaults to the homepage
     * @return array<int,string>  human-readable per-engine results
     */
    public static function requestIndexing(array $site, array $urls = []): array
    {
        $siteId = (int) $site['id'];
        $domain = self::domain($site);
        if ($urls === []) {
            $urls = [self::homeUrl($site)];
        }
        $out = [];

        // Google — sitemap submission (the supported crawl signal).
        $g = SearchConnection::find($siteId, 'google');
        if ($g !== null && $g['status'] === 'verified') {
            $sitemap = self::sitemapUrl($site);
            $res = GoogleSearchConsole::submitSitemap((string) $g['property'], $sitemap);
            $detail = $res['ok'] ? '' : $res['error'];
            $processed = '';
            if ($res['ok']) {
                $st = GoogleSearchConsole::sitemapStatus((string) $g['property'], $sitemap);
                if ($st['ok']) {
                    $detail = (string) json_encode([
                        'downloaded' => $st['last_downloaded'],
                        'submitted'  => $st['last_submitted'],
                        'pending'    => $st['is_pending'],
                        'warnings'   => $st['warnings'],
                        'errors'     => $st['errors'],
                    ]);
                    if ($st['last_downloaded'] !== '') {
                        $processed = ' Last fetched by Google ' . time_ago($st['last_downloaded']) . '.';
                    } elseif ($st['is_pending']) {
                        $processed = ' Google has queued it for crawling.';
                    }
                }
            }
            IndexRequest::log($siteId, 'google', 'sitemap', $sitemap, $res['ok'] ? 'ok' : 'error', $detail);
            $out[] = $res['ok']
                ? 'Google: sitemap submitted (' . $sitemap . ').' . $processed
                : 'Google: sitemap failed — ' . $res['error'];
        }

        // Bing — direct URL submission. This API needs verified ownership in
        // Bing Webmaster; if that isn't in place yet, IndexNow (below) still
        // delivers the URLs to Bing, so treat NotAuthorized as informational.
        $b = SearchConnection::find($siteId, 'bing');
        if ($b !== null) {
            $res = BingWebmaster::submitUrls((string) $b['property'], $urls);
            $notVerified = !$res['ok'] && preg_match('/not\s*authoriz|unauthoriz|forbidden/i', $res['error']) === 1;
            IndexRequest::log($siteId, 'bing', 'url', implode(' ', $urls), $res['ok'] ? 'ok' : ($notVerified ? 'skipped' : 'error'), $res['error']);
            if ($res['ok']) {
                $out[] = 'Bing: submitted ' . count($urls) . ' URL(s).';
            } elseif ($notVerified) {
                $out[] = 'Bing: delivered via IndexNow (verify ownership in Bing to also enable direct submission + stats).';
            } else {
                $out[] = 'Bing: submit failed — ' . $res['error'];
            }
        }

        // IndexNow — instant ping (Bing/Yandex/etc).
        if (IndexNow::configured()) {
            $res = IndexNow::submit($domain, $urls);
            IndexRequest::log($siteId, 'indexnow', 'indexnow', implode(' ', $urls), $res['ok'] ? 'ok' : 'error', $res['error']);
            $out[] = $res['ok']
                ? 'IndexNow: pinged ' . count($urls) . ' URL(s).'
                : 'IndexNow: ' . $res['error'];
        }

        if ($out === []) {
            $out[] = 'No search engines connected yet.';
        }
        return $out;
    }

    /**
     * Pull the last N days of search-performance data into search_metrics for
     * one site (both providers, whichever are verified).
     *
     * @return array<int,string> per-provider summary
     */
    public static function syncMetrics(array $site, int $days = 30): array
    {
        $siteId = (int) $site['id'];
        $out = [];
        // GSC lag: data is final ~3 days back; query a window ending 2 days ago.
        $end   = gmdate('Y-m-d', time() - 2 * 86400);
        $start = gmdate('Y-m-d', time() - ($days + 2) * 86400);

        $g = SearchConnection::find($siteId, 'google');
        if ($g !== null && $g['status'] === 'verified' && GoogleOAuth::connected()) {
            $property = (string) $g['property'];
            $n = 0;
            $byDate = GoogleSearchConsole::searchAnalytics($property, $start, $end, ['date'], 500);
            if ($byDate['ok']) {
                foreach ($byDate['rows'] as $row) {
                    $day = (string) ($row['keys'][0] ?? '');
                    if ($day === '') { continue; }
                    SearchMetric::record($siteId, 'google', $day, 'total', '', [
                        'clicks'      => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr'         => $row['ctr'] ?? 0,
                        'position'    => $row['position'] ?? 0,
                    ]);
                    $n++;
                }
            }
            $today = gmdate('Y-m-d');
            foreach (['query', 'page'] as $dim) {
                $res = GoogleSearchConsole::searchAnalytics($property, $start, $end, [$dim], 25);
                if (!$res['ok']) { continue; }
                foreach ($res['rows'] as $row) {
                    SearchMetric::record($siteId, 'google', $today, $dim, (string) ($row['keys'][0] ?? ''), [
                        'clicks'      => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr'         => $row['ctr'] ?? 0,
                        'position'    => $row['position'] ?? 0,
                    ]);
                }
            }
            SearchConnection::markSynced((int) $g['id']);
            $out[] = 'Google: ' . $n . ' day(s) synced.';
        }

        $b = SearchConnection::find($siteId, 'bing');
        if ($b !== null && BingWebmaster::configured()) {
            $siteUrl = (string) $b['property'];
            $traffic = BingWebmaster::trafficStats($siteUrl);
            $n = 0;
            if ($traffic['ok']) {
                foreach ($traffic['rows'] as $row) {
                    $day = self::bingDate($row['Date'] ?? '');
                    if ($day === '') { continue; }
                    SearchMetric::record($siteId, 'bing', $day, 'total', '', [
                        'clicks'      => $row['Clicks'] ?? 0,
                        'impressions' => $row['Impressions'] ?? 0,
                        'ctr'         => (int) ($row['Impressions'] ?? 0) > 0 ? ($row['Clicks'] ?? 0) / $row['Impressions'] : 0,
                        'position'    => $row['AvgImpressionPosition'] ?? 0,
                    ]);
                    $n++;
                }
            }
            $today = gmdate('Y-m-d');
            $queries = BingWebmaster::queryStats($siteUrl);
            if ($queries['ok']) {
                foreach (array_slice($queries['rows'], 0, 25) as $row) {
                    SearchMetric::record($siteId, 'bing', $today, 'query', (string) ($row['Query'] ?? ''), [
                        'clicks'      => $row['Clicks'] ?? 0,
                        'impressions' => $row['Impressions'] ?? 0,
                        'position'    => $row['AvgImpressionPosition'] ?? 0,
                    ]);
                }
            }
            SearchConnection::markSynced((int) $b['id']);
            $out[] = 'Bing: ' . $n . ' day(s) synced.';
        }

        if ($out === []) {
            $out[] = 'Nothing to sync (no verified connections).';
        }
        return $out;
    }

    /** Bing dates arrive as "/Date(1700000000000)/". Normalize to YYYY-MM-DD. */
    private static function bingDate(mixed $raw): string
    {
        if (is_string($raw) && preg_match('/(\d{10,13})/', $raw, $m)) {
            $ms = (int) $m[1];
            $sec = $ms > 20000000000 ? intdiv($ms, 1000) : $ms;
            return gmdate('Y-m-d', $sec);
        }
        return '';
    }

    /**
     * Verification tokens + IndexNow key for a site, consumed by the Brionic
     * WordPress plugin to inject meta tags and serve the key file.
     *
     * @return array{google_meta:string,indexnow_key:string}
     */
    public static function tagsForSite(array $site): array
    {
        $g = SearchConnection::find((int) $site['id'], 'google');
        $googleMeta = ($g !== null && $g['verification'] === 'meta') ? (string) $g['verify_token'] : '';

        $b = SearchConnection::find((int) $site['id'], 'bing');
        $bingCode = ($b !== null) ? (string) ($b['verify_token'] ?? '') : '';
        $bingMeta = $bingCode !== ''
            ? '<meta name="msvalidate.01" content="' . htmlspecialchars($bingCode, ENT_QUOTES) . '" />'
            : '';

        return [
            'google_meta'  => $googleMeta,
            'bing_meta'    => $bingMeta,
            'indexnow_key' => IndexNow::key(),
        ];
    }
}
