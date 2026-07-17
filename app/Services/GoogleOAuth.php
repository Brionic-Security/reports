<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SearchCredential;
use App\Support\Http;

/**
 * Google OAuth 2.0 (authorization-code flow) for the operator's single
 * connected Google account. Stores a refresh token and mints access tokens on
 * demand. Dormant until GOOGLE_OAUTH_CLIENT_ID/SECRET are set in .env.
 */
final class GoogleOAuth
{
    public static function configured(): bool
    {
        $c = config('search.google');
        return (string) ($c['client_id'] ?? '') !== '' && (string) ($c['client_secret'] ?? '') !== '';
    }

    public static function connected(): bool
    {
        return self::configured() && SearchCredential::connected('google');
    }

    /** Build the consent URL. $state is an opaque CSRF token. */
    public static function authorizeUrl(string $state): string
    {
        $c = config('search.google');
        $params = http_build_query([
            'client_id'     => $c['client_id'],
            'redirect_uri'  => $c['redirect_uri'],
            'response_type' => 'code',
            'scope'         => implode(' ', $c['scopes']),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
            'state'         => $state,
        ]);
        return $c['auth_url'] . '?' . $params;
    }

    /**
     * Exchange an authorization code for tokens and persist them.
     *
     * @return array{ok:bool,error:string}
     */
    public static function exchangeCode(string $code): array
    {
        $c = config('search.google');
        $res = Http::postForm($c['token_url'], [
            'code'          => $code,
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'redirect_uri'  => $c['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ]);
        if (!$res['ok'] || !is_array($res['json'])) {
            return ['ok' => false, 'error' => $res['error'] ?: 'token exchange failed'];
        }
        $tok = $res['json'];

        $account = '';
        if (!empty($tok['access_token'])) {
            $info = Http::get($c['userinfo_url'], ['Authorization' => 'Bearer ' . $tok['access_token']]);
            if ($info['ok'] && is_array($info['json'])) {
                $account = (string) ($info['json']['email'] ?? '');
            }
        }

        SearchCredential::store('google', [
            'account'       => $account,
            'access_token'  => (string) ($tok['access_token'] ?? ''),
            'refresh_token' => (string) ($tok['refresh_token'] ?? ''),
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + (int) ($tok['expires_in'] ?? 3600) - 60),
            'scope'         => (string) ($tok['scope'] ?? ''),
        ]);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Return a valid access token, refreshing if expired. Empty string on
     * failure.
     */
    public static function accessToken(): string
    {
        $cred = SearchCredential::forProvider('google');
        if ($cred === null) {
            return '';
        }
        $expires = (string) ($cred['expires_at'] ?? '');
        if ((string) ($cred['access_token'] ?? '') !== '' && $expires !== '' && $expires > now()) {
            return (string) $cred['access_token'];
        }

        $refresh = (string) ($cred['refresh_token'] ?? '');
        if ($refresh === '') {
            return '';
        }
        $c = config('search.google');
        $res = Http::postForm($c['token_url'], [
            'refresh_token' => $refresh,
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'refresh_token',
        ]);
        if (!$res['ok'] || !is_array($res['json']) || empty($res['json']['access_token'])) {
            return '';
        }
        $access = (string) $res['json']['access_token'];
        SearchCredential::updateAccessToken(
            'google',
            $access,
            gmdate('Y-m-d H:i:s', time() + (int) ($res['json']['expires_in'] ?? 3600) - 60)
        );
        return $access;
    }
}
