<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Database;

/**
 * Cached IP → geolocation lookups (the only place an IP transits, briefly).
 */
final class IpGeo
{
    /** @return array{country:string,country_code:string,region:string,city:string,lat:?float,lon:?float}|null */
    public static function findByIp(string $ip): ?array
    {
        $row = Database::selectOne('SELECT * FROM ip_geo WHERE ip = ?', [$ip]);
        if ($row === null) {
            return null;
        }
        return [
            'country'      => (string) $row['country'],
            'country_code' => (string) $row['country_code'],
            'region'       => (string) $row['region'],
            'city'         => (string) $row['city'],
            'lat'          => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lon'          => $row['lon'] !== null ? (float) $row['lon'] : null,
        ];
    }

    /** @param array{country:string,country_code:string,region:string,city:string,lat:?float,lon:?float} $r */
    public static function store(string $ip, array $r): void
    {
        // Only called after a cache miss, so a plain insert is expected. A rare
        // race (two lookups for the same new IP) is harmless — ignore duplicates.
        try {
            Database::run(
                'INSERT INTO ip_geo (ip, country, country_code, region, city, lat, lon, looked_up_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$ip, $r['country'], $r['country_code'], $r['region'], $r['city'], $r['lat'], $r['lon'], now()]
            );
        } catch (\Throwable $e) {
            // duplicate key / concurrent insert — safe to ignore
        }
    }
}
