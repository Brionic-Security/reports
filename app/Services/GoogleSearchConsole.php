<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Http;

/**
 * Thin wrapper over the Google Search Console API + Site Verification API.
 * All calls use the operator's OAuth access token (see GoogleOAuth).
 *
 * Docs:
 *   Search Console  https://developers.google.com/webmaster-tools/v1/api_reference_index
 *   Verification    https://developers.google.com/site-verification/v1
 */
final class GoogleSearchConsole
{
    private const SC   = 'https://searchconsole.googleapis.com/webmasters/v3';
    private const SV   = 'https://www.googleapis.com/siteVerification/v1';

    /** @return array<string,string> */
    private static function authHeader(): array
    {
        $token = GoogleOAuth::accessToken();
        return $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Request a verification token (meta tag or DNS TXT) for a URL-prefix or
     * domain identifier.
     *
     * @param string $method 'META' | 'DNS_TXT'
     * @return array{ok:bool,token:string,error:string}
     */
    public static function getVerificationToken(string $identifier, string $method): array
    {
        $headers = self::authHeader();
        if ($headers === []) {
            return ['ok' => false, 'token' => '', 'error' => 'Google not connected'];
        }
        $isDomain = $method === 'DNS_TXT';
        $body = [
            'verificationMethod' => $method,
            'site' => [
                'type'       => $isDomain ? 'INET_DOMAIN' : 'SITE',
                'identifier' => $identifier,
            ],
        ];
        $res = Http::postJson(self::SV . '/token', $body, $headers);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'token' => '', 'error' => $res['error'] ?: 'token request failed'];
        }
        return ['ok' => true, 'token' => (string) ($res['json']['token'] ?? ''), 'error' => ''];
    }

    /**
     * Ask Google to verify ownership (after the token is placed).
     *
     * @return array{ok:bool,error:string}
     */
    public static function verify(string $identifier, string $method): array
    {
        $headers = self::authHeader();
        if ($headers === []) {
            return ['ok' => false, 'error' => 'Google not connected'];
        }
        $isDomain = $method === 'DNS_TXT';
        $body = [
            'site' => [
                'type'       => $isDomain ? 'INET_DOMAIN' : 'SITE',
                'identifier' => $identifier,
            ],
        ];
        $url = self::SV . '/webResource?verificationMethod=' . rawurlencode($method);
        $res = Http::postJson($url, $body, $headers);
        return ['ok' => $res['ok'], 'error' => $res['ok'] ? '' : ($res['error'] ?: 'verification failed')];
    }

    /**
     * Add a property to Search Console (idempotent — PUT).
     *
     * @return array{ok:bool,error:string}
     */
    public static function addSite(string $property): array
    {
        $headers = self::authHeader();
        if ($headers === []) {
            return ['ok' => false, 'error' => 'Google not connected'];
        }
        $res = Http::request('PUT', self::SC . '/sites/' . rawurlencode($property), null, $headers);
        return ['ok' => $res['ok'], 'error' => $res['ok'] ? '' : ($res['error'] ?: 'add site failed')];
    }

    /**
     * Submit a sitemap for a property (the supported "please crawl" signal).
     *
     * @return array{ok:bool,error:string}
     */
    public static function submitSitemap(string $property, string $sitemapUrl): array
    {
        $headers = self::authHeader();
        if ($headers === []) {
            return ['ok' => false, 'error' => 'Google not connected'];
        }
        $url = self::SC . '/sites/' . rawurlencode($property) . '/sitemaps/' . rawurlencode($sitemapUrl);
        $res = Http::request('PUT', $url, null, $headers);
        return ['ok' => $res['ok'], 'error' => $res['ok'] ? '' : ($res['error'] ?: 'sitemap submit failed')];
    }

    /**
     * Query Search Analytics.
     *
     * @param string[] $dimensions e.g. ['date'] or ['query']
     * @return array{ok:bool,rows:array,error:string}
     */
    public static function searchAnalytics(string $property, string $start, string $end, array $dimensions, int $rowLimit = 25): array
    {
        $headers = self::authHeader();
        if ($headers === []) {
            return ['ok' => false, 'rows' => [], 'error' => 'Google not connected'];
        }
        $body = [
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => $dimensions,
            'rowLimit'   => $rowLimit,
            'dataState'  => 'all',
        ];
        $url = self::SC . '/sites/' . rawurlencode($property) . '/searchAnalytics/query';
        $res = Http::postJson($url, $body, $headers);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'rows' => [], 'error' => $res['error'] ?: 'query failed'];
        }
        return ['ok' => true, 'rows' => $res['json']['rows'] ?? [], 'error' => ''];
    }
}
