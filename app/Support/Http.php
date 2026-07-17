<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal curl-based JSON HTTP client for talking to external APIs (Google,
 * Bing, Cloudflare). Returns a normalized result array so callers never touch
 * curl directly.
 */
final class Http
{
    /**
     * @param array<string,mixed>|string|null $body  array => encoded per $type
     * @param array<string,string>            $headers
     * @return array{ok:bool,status:int,body:string,json:mixed,error:string}
     */
    public static function request(
        string $method,
        string $url,
        array|string|null $body = null,
        array $headers = [],
        string $type = 'json',
        int $timeout = 20
    ): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'curl unavailable'];
        }

        $payload = null;
        if ($body !== null) {
            if (is_array($body)) {
                if ($type === 'form') {
                    $payload = http_build_query($body);
                    $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/x-www-form-urlencoded';
                } else {
                    $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
                    $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
                }
            } else {
                $payload = $body;
            }
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_USERAGENT      => 'BrionicReports/1.0 (+https://reports.brionicsecurity.com)',
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $raw === null) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'json' => null, 'error' => $err !== '' ? $err : 'request failed'];
        }

        $bodyStr = (string) $raw;
        $json = null;
        $trim = ltrim($bodyStr);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $json = json_decode($bodyStr, true);
        }

        $ok = $status >= 200 && $status < 300;
        $error = '';
        if (!$ok) {
            $error = is_array($json)
                ? (string) ($json['error']['message'] ?? $json['error_description'] ?? $json['Message'] ?? ('HTTP ' . $status))
                : ('HTTP ' . $status);
        }

        return ['ok' => $ok, 'status' => $status, 'body' => $bodyStr, 'json' => $json, 'error' => $error];
    }

    /** @param array<string,string> $headers */
    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, null, $headers, 'json', $timeout);
    }

    /**
     * @param array<string,mixed>|string|null $body
     * @param array<string,string>            $headers
     */
    public static function postJson(string $url, array|string|null $body, array $headers = [], int $timeout = 20): array
    {
        return self::request('POST', $url, $body, $headers, 'json', $timeout);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public static function postForm(string $url, array $body, array $headers = [], int $timeout = 20): array
    {
        return self::request('POST', $url, $body, $headers, 'form', $timeout);
    }
}
