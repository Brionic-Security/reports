<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\IndexRequest;
use App\Models\SearchConnection;
use App\Models\SearchCredential;
use App\Models\Site;
use App\Services\BingWebmaster;
use App\Services\Cloudflare;
use App\Services\GoogleOAuth;
use App\Services\IndexNow;
use App\Services\SearchService;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Search-engine integrations: connect a Google account + Bing key once, then
 * per-site connect / verify / request-indexing / sync search performance.
 */
final class SearchController
{
    /** Global integrations page. */
    public function integrations(): Response
    {
        $google = [
            'configured' => GoogleOAuth::configured(),
            'connected'  => GoogleOAuth::connected(),
            'account'    => (string) (SearchCredential::forProvider('google')['account'] ?? ''),
        ];
        return Response::html(view('integrations/index', [
            'google'     => $google,
            'bing'       => ['configured' => BingWebmaster::configured()],
            'indexnow'   => ['configured' => IndexNow::configured(), 'key' => IndexNow::key()],
            'cloudflare' => ['configured' => Cloudflare::configured()],
            'ok'         => Session::getFlash('ok'),
            'error'      => Session::getFlash('error'),
        ]));
    }

    /** Begin Google OAuth. */
    public function googleConnect(): Response
    {
        if (!GoogleOAuth::configured()) {
            Session::flash('error', 'Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET first.');
            return Response::redirect(app_url('integrations'));
        }
        $state = bin2hex(random_bytes(16));
        Session::put('google_oauth_state', $state);
        return Response::redirect(GoogleOAuth::authorizeUrl($state));
    }

    /** Google OAuth callback. */
    public function googleCallback(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        $expected = (string) Session::get('google_oauth_state', '');
        Session::forget('google_oauth_state');
        if ($state === '' || !hash_equals($expected, $state)) {
            Session::flash('error', 'OAuth state mismatch. Please try again.');
            return Response::redirect(app_url('integrations'));
        }
        if ((string) $request->query('error', '') !== '') {
            Session::flash('error', 'Google authorization was declined.');
            return Response::redirect(app_url('integrations'));
        }
        $code = (string) $request->query('code', '');
        if ($code === '') {
            Session::flash('error', 'No authorization code returned.');
            return Response::redirect(app_url('integrations'));
        }
        $res = GoogleOAuth::exchangeCode($code);
        Session::flash($res['ok'] ? 'ok' : 'error', $res['ok'] ? 'Google account connected.' : ('Google: ' . $res['error']));
        return Response::redirect(app_url('integrations'));
    }

    public function googleDisconnect(): Response
    {
        SearchCredential::disconnect('google');
        Session::flash('ok', 'Google account disconnected.');
        return Response::redirect(app_url('integrations'));
    }

    /* ── Per-site actions ─────────────────────────────────────────────── */

    private function site(array $params): array
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        return $site;
    }

    public function connect(Request $request, array $params): Response
    {
        $site = $this->site($params);
        $provider = (string) $request->input('provider', '');
        $res = match ($provider) {
            'google' => SearchService::connectGoogle($site),
            'bing'   => SearchService::connectBing($site),
            default  => ['ok' => false, 'message' => 'Unknown provider.'],
        };
        Session::flash($res['ok'] ? 'ok' : 'error', $res['message']);
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings#search'));
    }

    public function verify(Request $request, array $params): Response
    {
        $site = $this->site($params);
        $provider = (string) $request->input('provider', 'google');
        $res = $provider === 'bing'
            ? SearchService::verifyBing($site)
            : SearchService::verifyGoogle($site);
        Session::flash($res['ok'] ? 'ok' : 'error', $res['message']);
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings#search'));
    }

    public function index(Request $request, array $params): Response
    {
        $site = $this->site($params);
        $urlsRaw = trim((string) $request->input('urls', ''));
        // Remember the operator's edited list so removals/additions persist
        // across reloads (otherwise the sitemap pre-fill re-adds them).
        \App\Models\Site::updateIndexUrls((int) $site['id'], $urlsRaw);
        $urls = [];
        foreach (preg_split('/\s+/', $urlsRaw) ?: [] as $u) {
            $u = trim($u);
            if ($u !== '') {
                $urls[] = str_contains($u, '://') ? $u : ('https://' . SearchService::domain($site) . '/' . ltrim($u, '/'));
            }
        }
        $results = SearchService::requestIndexing($site, $urls);
        Session::flash('index_result', implode("\n", $results));
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings#search'));
    }

    /** Clear the saved URL list so the box repopulates from the sitemap. */
    public function resetIndexUrls(Request $request, array $params): Response
    {
        $site = $this->site($params);
        \App\Models\Site::updateIndexUrls((int) $site['id'], null);
        Session::flash('ok', 'Reset to your sitemap pages.');
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings#search'));
    }

    public function sync(Request $request, array $params): Response
    {
        $site = $this->site($params);
        $results = SearchService::syncMetrics($site, 90);
        Session::flash('ok', implode(' ', $results));
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings#search'));
    }

    public function disconnect(Request $request, array $params): Response
    {
        $site = $this->site($params);
        $provider = (string) $request->input('provider', '');
        if (in_array($provider, ['google', 'bing'], true)) {
            SearchConnection::delete((int) $site['id'], $provider);
            Session::flash('ok', ucfirst($provider) . ' disconnected from this site.');
        }
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings#search'));
    }

    /* ── Public endpoints ─────────────────────────────────────────────── */

    /**
     * IndexNow key file for our own domain. Client sites serve their own via
     * the WordPress plugin.
     */
    public function indexNowKey(): Response
    {
        $key = IndexNow::key();
        if ($key === '') {
            throw HttpException::notFound('Not found.');
        }
        return Response::text($key)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Verification tags + IndexNow key for the WordPress plugin to inject.
     * Public but keyed by the opaque site public_id.
     */
    public function siteTags(Request $request): Response
    {
        // Accept ?key= (used by the WP plugin, matching /api/verify) or ?site=.
        $publicId = (string) $request->query('key', $request->query('site', ''));
        $site = $publicId !== '' ? Site::findByPublicId($publicId) : null;
        if ($site === null) {
            return Response::json(['ok' => false], 404);
        }
        $tags = SearchService::tagsForSite($site);
        return Response::json(['ok' => true] + $tags)
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
