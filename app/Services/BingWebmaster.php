<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Http;

/**
 * Bing Webmaster Tools API (JSON). Authenticated with a single account-wide
 * API key (Bing Webmaster Tools → Settings → API access). Dormant until
 * BING_WEBMASTER_API_KEY is set.
 *
 * Docs: https://learn.microsoft.com/en-us/bingwebmaster/getting-access
 */
final class BingWebmaster
{
    public static function configured(): bool
    {
        return (string) config('search.bing.api_key', '') !== '';
    }

    private static function url(string $action): string
    {
        $base = rtrim((string) config('search.bing.endpoint'), '/');
        return $base . '/' . $action . '?apikey=' . rawurlencode((string) config('search.bing.api_key'));
    }

    /**
     * Add a site to the Bing Webmaster account.
     *
     * @return array{ok:bool,error:string}
     */
    public static function addSite(string $siteUrl): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Bing not configured'];
        }
        $res = Http::postJson(self::url('AddSite'), ['siteUrl' => $siteUrl]);
        return ['ok' => $res['ok'], 'error' => $res['ok'] ? '' : ($res['error'] ?: 'add site failed')];
    }

    /** @return array{ok:bool,sites:array,error:string} */
    public static function getSites(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'sites' => [], 'error' => 'Bing not configured'];
        }
        $res = Http::get(self::url('GetUserSites'));
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'sites' => [], 'error' => $res['error'] ?: 'get sites failed'];
        }
        return ['ok' => true, 'sites' => $res['json']['d'] ?? [], 'error' => ''];
    }

    /**
     * Ask Bing to verify ownership of a site that has been added. Bing reads
     * the msvalidate.01 meta tag (or BingSiteAuth.xml / CNAME) already present
     * on the site. Returns verified=true once ownership is confirmed.
     *
     * @return array{ok:bool,verified:bool,error:string}
     */
    public static function verifySite(string $siteUrl): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'verified' => false, 'error' => 'Bing not configured'];
        }
        $res = Http::postJson(self::url('VerifySite'), ['siteUrl' => $siteUrl]);
        if (!$res['ok']) {
            return ['ok' => false, 'verified' => false, 'error' => $res['error'] ?: 'verify failed'];
        }
        $verified = is_array($res['json']) && !empty($res['json']['d']);
        return ['ok' => true, 'verified' => $verified, 'error' => ''];
    }

    /**
     * Submit one or more URLs for crawling/indexing (Bing supports this
     * natively, unlike Google).
     *
     * @param string[] $urls
     * @return array{ok:bool,error:string}
     */
    public static function submitUrls(string $siteUrl, array $urls): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Bing not configured'];
        }
        $res = Http::postJson(self::url('SubmitUrlBatch'), [
            'siteUrl' => $siteUrl,
            'urlList' => array_values($urls),
        ]);
        return ['ok' => $res['ok'], 'error' => $res['ok'] ? '' : ($res['error'] ?: 'submit failed')];
    }

    /** Remaining daily/monthly URL-submission quota. @return array{ok:bool,daily:int,monthly:int,error:string} */
    public static function submissionQuota(string $siteUrl): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'daily' => 0, 'monthly' => 0, 'error' => 'Bing not configured'];
        }
        $res = Http::get(self::url('GetUrlSubmissionQuota') . '&siteUrl=' . rawurlencode($siteUrl));
        $d = is_array($res['json']) ? ($res['json']['d'] ?? []) : [];
        return [
            'ok'      => $res['ok'],
            'daily'   => (int) ($d['DailyQuota'] ?? 0),
            'monthly' => (int) ($d['MonthlyQuota'] ?? 0),
            'error'   => $res['ok'] ? '' : ($res['error'] ?: 'quota failed'),
        ];
    }

    /**
     * Rank & traffic stats (impressions + clicks per day).
     *
     * @return array{ok:bool,rows:array,error:string}
     */
    public static function trafficStats(string $siteUrl): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'rows' => [], 'error' => 'Bing not configured'];
        }
        $res = Http::get(self::url('GetRankAndTrafficStats') . '&siteUrl=' . rawurlencode($siteUrl));
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'rows' => [], 'error' => $res['error'] ?: 'stats failed'];
        }
        return ['ok' => true, 'rows' => $res['json']['d'] ?? [], 'error' => ''];
    }

    /**
     * Top search queries (impressions, clicks, avg position).
     *
     * @return array{ok:bool,rows:array,error:string}
     */
    public static function queryStats(string $siteUrl): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'rows' => [], 'error' => 'Bing not configured'];
        }
        $res = Http::get(self::url('GetQueryStats') . '&siteUrl=' . rawurlencode($siteUrl));
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'rows' => [], 'error' => $res['error'] ?: 'query stats failed'];
        }
        return ['ok' => true, 'rows' => $res['json']['d'] ?? [], 'error' => ''];
    }

    /**
     * Top pages (impressions, clicks).
     *
     * @return array{ok:bool,rows:array,error:string}
     */
    public static function pageStats(string $siteUrl): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'rows' => [], 'error' => 'Bing not configured'];
        }
        $res = Http::get(self::url('GetPageStats') . '&siteUrl=' . rawurlencode($siteUrl));
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'rows' => [], 'error' => $res['error'] ?: 'page stats failed'];
        }
        return ['ok' => true, 'rows' => $res['json']['d'] ?? [], 'error' => ''];
    }
}
