<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

/**
 * Serves the tracker script (b.js) through PHP so we control its caching.
 *
 * SiteGround's static "Direct Delivery" forces a 1-year immutable cache on files
 * in the web root, which strands tracker updates in visitors' browsers. Serving
 * it dynamically lets us send a short, revalidating cache (1 hour + ETag) so
 * fixes reach every site quickly while staying efficient via 304 responses.
 */
final class TrackerController
{
    private const MAX_AGE = 3600; // 1 hour, then revalidate via ETag.

    public function serve(Request $request): Response
    {
        $file = base_path('resources/b.js');
        $js = is_file($file) ? (string) file_get_contents($file) : '';
        $etag = '"' . substr(md5($js), 0, 20) . '"';

        $headers = [
            'Content-Type'                => 'application/javascript; charset=UTF-8',
            'Cache-Control'               => 'public, max-age=' . self::MAX_AGE . ', must-revalidate',
            'ETag'                        => $etag,
            'Access-Control-Allow-Origin' => '*',
        ];

        if (trim((string) $request->header('If-None-Match', '')) === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response($js, 200, $headers);
    }
}
