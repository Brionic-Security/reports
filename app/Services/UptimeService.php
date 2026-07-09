<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\UptimeCheck;

/**
 * Pings each monitored site over HTTP, records the result, and emails the
 * operator whenever a site changes state (goes down or recovers).
 */
final class UptimeService
{
    /** @return array<int,array<string,mixed>> per-site check summary */
    public static function run(): array
    {
        if (!config('uptime.enabled', true)) {
            return [];
        }
        $to = (string) (config('uptime.to') ?: config('auth.admin.email'));
        $out = [];

        foreach (Site::all() as $site) {
            if ((int) ($site['monitor_enabled'] ?? 1) !== 1) {
                continue;
            }
            $sid = (int) $site['id'];
            $url = self::monitorUrl($site);

            $prev = UptimeCheck::latest($sid);
            $prevUp = $prev !== null ? ((int) $prev['up'] === 1) : null;

            [$up, $status, $ms, $error] = self::ping($url);
            UptimeCheck::record($sid, $up, $status, $ms, $error);

            // Alert only on a state change (or the very first down result).
            $changed = ($prevUp === null && !$up) || ($prevUp !== null && $prevUp !== $up);
            $sent = false;
            if ($changed && $to !== '') {
                $msg = Email::uptimeAlert($site, $up ? 'up' : 'down', $status, $error, $ms);
                $sent = Mailer::send($to, $msg['subject'], $msg['html'], $msg['text']);
            }

            $out[] = [
                'site'    => $site['name'],
                'url'     => $url,
                'up'      => $up,
                'status'  => $status,
                'ms'      => $ms,
                'changed' => $changed,
                'alerted' => $sent,
            ];
        }

        return $out;
    }

    public static function monitorUrl(array $site): string
    {
        $custom = trim((string) ($site['monitor_url'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        return 'https://' . Site::normalizeDomain((string) $site['domain']);
    }

    /**
     * @return array{0:bool,1:int,2:int,3:string} [up, statusCode, responseMs, error]
     */
    private static function ping(string $url): array
    {
        $timeout = max(3, (int) config('uptime.timeout', 12));
        $ua = (string) config('uptime.user_agent', 'BrionicReportsUptime/1.0');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,          // HEAD first (cheap)
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        $err = curl_error($ch);

        // Some servers reject HEAD (405) — retry once with a GET before failing.
        if ($status === 405 || ($status === 0 && $err === '')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ms = (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $err = curl_error($ch);
        }

        $up = $status >= 200 && $status < 400;
        if (!$up && $err === '' && $status > 0) {
            $err = 'HTTP ' . $status;
        }
        return [$up, $status, $ms, $err];
    }
}
