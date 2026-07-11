<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

/**
 * Serves the tracker script (b.js) through PHP so we control its delivery.
 *
 * SiteGround's static "Direct Delivery" forces a 1-year immutable cache on files
 * in the web root, which strands tracker updates in visitors' browsers for a
 * year. Serving it dynamically — and cookieless (the platform is cookieless by
 * design) — lets SiteGround's proxy apply its standard 1-day cache instead, so
 * tracker fixes reach every connected site within a day rather than a year.
 *
 * Note: on this host the proxy appends its own `max-age` to any Cache-Control we
 * send (and only skips caching when a Set-Cookie is present), so we set none and
 * stay cookieless — that yields a single, clean `max-age=86400` at the edge.
 */
final class TrackerController
{
    public function serve(Request $request): Response
    {
        $file = base_path('resources/b.js');
        $js = is_file($file) ? (string) file_get_contents($file) : '';

        return new Response($js, 200, [
            'Content-Type'                => 'application/javascript; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
