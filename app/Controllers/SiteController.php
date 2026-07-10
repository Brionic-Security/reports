<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Site;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class SiteController
{
    public function index(): Response
    {
        return Response::html(view('sites/index', ['sites' => Site::all()]));
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $domain = trim((string) $request->input('domain', ''));
        $email = trim((string) $request->input('report_email', ''));

        if ($name === '' || $domain === '') {
            Session::flash('error', 'Name and domain are required.');
            return Response::redirect(app_url('sites'));
        }

        $site = Site::create($name, $domain, $email !== '' ? $email : null);
        Session::flash('ok', 'Site added. Copy the snippet below into your website.');
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings'));
    }

    public function show(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        return Response::html(view('sites/show', [
            'site'       => $site,
            'snippet'    => self::snippet((string) $site['public_id']),
            'ok'         => Session::getFlash('ok'),
            'error'      => Session::getFlash('error'),
            'runs'       => \App\Models\ReportRun::recentForSite((int) $site['id'], 6),
            'connection' => self::connectionStatus((int) $site['id']),
        ]));
    }

    /**
     * Actively check the connection: first any received data, then fetch the
     * live homepage and look for the tracker snippet. Gives actionable feedback.
     */
    public function validate(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        $id = (int) $site['id'];

        // 1) Have we already received data?
        $status = self::connectionStatus($id);
        if ($status['any']) {
            $methods = [];
            if ($status['wordpress'] > 0) { $methods[] = 'WordPress plugin'; }
            if ($status['snippet'] > 0) { $methods[] = 'code snippet'; }
            Session::flash('ok', 'Connected! Receiving data via ' . implode(' + ', $methods) . '.');
            return Response::redirect(app_url('sites/' . $id . '/settings'));
        }

        // 2) No data yet — fetch the homepage and look for the tracker.
        $url = 'https://' . Site::normalizeDomain((string) $site['domain']);
        [$ok, $html, $err] = self::fetchHtml($url);
        if (!$ok) {
            Session::flash('error', 'Could not reach ' . $url . ' to check (' . $err . '). Verify the domain and try again.');
            return Response::redirect(app_url('sites/' . $id . '/settings'));
        }

        $key = (string) $site['public_id'];
        $hasScript = stripos($html, $key) !== false && stripos($html, '/b.js') !== false;
        $hasMarker = stripos($html, 'Brionic Reports active') !== false;

        if ($hasScript) {
            $via = '';
            if (preg_match('/data-via=["\']([a-z]+)["\']/i', $html, $m)) {
                $via = ' (' . strtolower($m[1]) . ')';
            }
            Session::flash('ok', 'The Brionic tracker' . $via . ' is installed on your homepage, but no visits have been recorded yet. '
                . 'Open your site in a normal (logged-out) browser window, then click Validate again in a minute.');
        } elseif ($hasMarker) {
            Session::flash('error', 'The Brionic Reports plugin is active, but the tracking script is being removed from the page — usually by a caching or JavaScript-optimisation plugin. '
                . 'Clear your site cache and exclude "b.js" from JS optimisation/deferral, then try again.');
        } else {
            Session::flash('error', 'The Brionic tracker was not found on ' . $url . '. Two common causes: '
                . '(1) a page cache is serving an old copy — purge your site/CDN cache; or '
                . '(2) the plugin is not Active, or the site key was not saved (open Settings → Brionic Reports in WordPress and confirm it says "Active"). Then try again.');
        }
        return Response::redirect(app_url('sites/' . $id . '/settings'));
    }

    /**
     * Fetch a URL's HTML (first ~512KB) for connection validation.
     *
     * @return array{0:bool,1:string,2:string} [ok, html, error]
     */
    private static function fetchHtml(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [false, '', 'curl unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'BrionicReportsValidator/1.0 (+https://reports.brionicsecurity.com)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RANGE          => '0-524287',
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false || $body === null) {
            return [false, '', $err !== '' ? $err : 'no response'];
        }
        if ($code >= 400) {
            return [false, '', 'HTTP ' . $code];
        }
        return [true, (string) $body, ''];
    }

    /**
     * Detect whether (and how) the site is currently sending data, based on the
     * install-method marker on recent events (last 30 days).
     *
     * @return array{any:bool,wordpress:int,snippet:int,last:?string}
     */
    private static function connectionStatus(int $siteId): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $rows = \App\Support\Database::select(
            "SELECT COALESCE(NULLIF(via, ''), 'html') via, COUNT(*) n, MAX(created_at) last
             FROM events WHERE site_id = ? AND created_at >= ?
             GROUP BY COALESCE(NULLIF(via, ''), 'html')",
            [$siteId, $since]
        );
        $wp = 0;
        $snippet = 0;
        $last = null;
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            if ((string) $r['via'] === 'wordpress') {
                $wp += $n;
            } else {
                $snippet += $n;
            }
            if ($last === null || (string) $r['last'] > $last) {
                $last = (string) $r['last'];
            }
        }
        return ['any' => ($wp + $snippet) > 0, 'wordpress' => $wp, 'snippet' => $snippet, 'last' => $last];
    }

    public function update(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        $name = trim((string) $request->input('name', (string) $site['name']));
        $domain = trim((string) $request->input('domain', (string) $site['domain']));
        $email = trim((string) $request->input('report_email', ''));
        Site::update((int) $site['id'], $name, $domain, $email !== '' ? $email : null);

        $monitorUrl = trim((string) $request->input('monitor_url', ''));
        $monitorEnabled = $request->input('monitor_enabled') !== null;
        Site::updateMonitor((int) $site['id'], $monitorUrl !== '' ? $monitorUrl : null, $monitorEnabled);

        Session::flash('ok', 'Saved.');
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings'));
    }

    public function destroy(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        Site::delete((int) $site['id']);
        Session::flash('ok', 'Site deleted.');
        return Response::redirect(app_url('sites'));
    }

    public static function snippet(string $publicId): string
    {
        $src = app_url('b.js');
        return '<script defer data-site="' . e($publicId) . '" src="' . e($src) . '"></script>';
    }
}
