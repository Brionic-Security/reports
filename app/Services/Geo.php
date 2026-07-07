<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IpGeo;

/**
 * IP geolocation via ipwho.is (free, no key), cached in the ip_geo table.
 * Private/invalid IPs are skipped (and never sent to the API — no SSRF).
 */
final class Geo
{
    /**
     * Resolve an IP to a cached geo record, looking it up once if needed.
     *
     * @return array{country:string,country_code:string,region:string,city:string,lat:?float,lon:?float}|null
     */
    public static function lookup(string $ip): ?array
    {
        if (!config('auth.geo.enabled', true) || self::isPrivate($ip)) {
            return null;
        }

        $cached = IpGeo::findByIp($ip);
        if ($cached !== null) {
            return $cached;
        }

        $endpoint = rtrim((string) config('auth.geo.endpoint', 'https://ipwho.is/'), '/') . '/' . rawurlencode($ip);
        $json = @file_get_contents($endpoint, false, stream_context_create([
            'http' => ['timeout' => 4, 'ignore_errors' => true],
        ]));
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            return null;
        }

        $record = [
            'country'      => (string) ($data['country'] ?? ''),
            'country_code' => (string) ($data['country_code'] ?? ''),
            'region'       => (string) ($data['region'] ?? ''),
            'city'         => (string) ($data['city'] ?? ''),
            'lat'          => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'lon'          => isset($data['longitude']) ? (float) $data['longitude'] : null,
        ];
        IpGeo::store($ip, $record);
        return $record;
    }

    public static function isPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
