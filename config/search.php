<?php

declare(strict_types=1);

/**
 * Search-engine integration (Google Search Console + Bing Webmaster Tools).
 *
 * All credentials are optional — the feature stays dormant (UI shows "not
 * configured") until the relevant keys are present in .env, mirroring the way
 * social login ships dormant. See docs / setup guide for how to create these.
 *
 *   Google : one-time OAuth (a Google Cloud project with the Search Console API
 *            + Site Verification API enabled). The operator connects their
 *            Google account once; a refresh token is stored server-side.
 *   Bing   : a single Webmaster API key (Bing Webmaster Tools → Settings → API
 *            access).
 *   IndexNow: a self-chosen 32-hex key used to instantly notify Bing/Yandex of
 *            new/updated URLs. The key file is served at /{key}.txt.
 *   Cloudflare: an API token (Zone:DNS:Edit) used to auto-add DNS TXT
 *            verification records for domains hosted in the operator's account.
 */
return [
    'google' => [
        'client_id'     => (string) env('GOOGLE_OAUTH_CLIENT_ID', ''),
        'client_secret' => (string) env('GOOGLE_OAUTH_CLIENT_SECRET', ''),
        // Where Google redirects back after consent. Must match a "Authorized
        // redirect URI" registered on the OAuth client in Google Cloud.
        'redirect_uri'  => rtrim((string) config('app.url', ''), '/') . '/integrations/google/callback',
        // Read Search Console data + manage sitemaps + verify site ownership.
        'scopes' => [
            'https://www.googleapis.com/auth/webmasters',
            'https://www.googleapis.com/auth/siteverification',
        ],
        'auth_url'     => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url'    => 'https://oauth2.googleapis.com/token',
        'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
    ],

    'bing' => [
        'api_key'  => (string) env('BING_WEBMASTER_API_KEY', ''),
        'endpoint' => 'https://ssl.bing.com/webmaster/api.svc/json',
    ],

    'indexnow' => [
        // 32+ hex chars. Generate: php -r "echo bin2hex(random_bytes(16));"
        'key' => (string) env('INDEXNOW_KEY', ''),
    ],

    'cloudflare' => [
        // Token needs Zone:Read + DNS:Edit on the zones you want auto-verified.
        'api_token' => (string) env('CLOUDFLARE_API_TOKEN', ''),
    ],
];
