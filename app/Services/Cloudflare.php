<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Http;

/**
 * Minimal Cloudflare DNS helper — used to auto-create the TXT records that
 * Google/Bing use for domain verification, for zones in the operator's
 * Cloudflare account. Dormant until CLOUDFLARE_API_TOKEN is set.
 */
final class Cloudflare
{
    private const API = 'https://api.cloudflare.com/client/v4';

    public static function configured(): bool
    {
        return (string) config('search.cloudflare.api_token', '') !== '';
    }

    /** @return array<string,string> */
    private static function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . (string) config('search.cloudflare.api_token'),
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Find the zone id whose name is a suffix of $domain (e.g. zone
     * "santurz.com" for "store.santurz.com").
     *
     * @return array{ok:bool,zone_id:string,zone_name:string,error:string}
     */
    public static function findZone(string $domain): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'zone_id' => '', 'zone_name' => '', 'error' => 'Cloudflare not configured'];
        }
        $domain = strtolower(trim($domain));
        // Try the domain and progressively broader parents.
        $parts = explode('.', $domain);
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $candidate = implode('.', array_slice($parts, $i));
            $res = Http::get(self::API . '/zones?name=' . rawurlencode($candidate), self::headers());
            if ($res['ok'] && is_array($res['json']) && !empty($res['json']['result'][0]['id'])) {
                return [
                    'ok'        => true,
                    'zone_id'   => (string) $res['json']['result'][0]['id'],
                    'zone_name' => (string) $res['json']['result'][0]['name'],
                    'error'     => '',
                ];
            }
        }
        return ['ok' => false, 'zone_id' => '', 'zone_name' => '', 'error' => 'no matching Cloudflare zone'];
    }

    /**
     * Create (or update) a TXT record. Idempotent by (name, content).
     *
     * @return array{ok:bool,error:string}
     */
    public static function upsertTxt(string $zoneId, string $name, string $content): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'error' => 'Cloudflare not configured'];
        }
        // Already present?
        $list = Http::get(
            self::API . '/zones/' . $zoneId . '/dns_records?type=TXT&name=' . rawurlencode($name),
            self::headers()
        );
        if ($list['ok'] && is_array($list['json'])) {
            foreach ($list['json']['result'] ?? [] as $rec) {
                if (trim((string) ($rec['content'] ?? ''), '"') === $content) {
                    return ['ok' => true, 'error' => ''];
                }
            }
        }
        $res = Http::postJson(self::API . '/zones/' . $zoneId . '/dns_records', [
            'type'    => 'TXT',
            'name'    => $name,
            'content' => $content,
            'ttl'     => 300,
        ], self::headers());
        return ['ok' => $res['ok'], 'error' => $res['ok'] ? '' : ($res['error'] ?: 'TXT create failed')];
    }
}
