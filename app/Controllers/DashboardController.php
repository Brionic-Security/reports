<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Site;
use App\Services\Stats;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class DashboardController
{
    public function overview(Request $request): Response
    {
        $range = (string) $request->query('range', '30d');
        $from = $request->query('from');
        $to = $request->query('to');

        $sites = Site::all();
        $stats = Stats::overview($range, is_string($from) ? $from : null, is_string($to) ? $to : null);

        return Response::html(view('dashboard/overview', [
            'sites' => $sites,
            'stats' => $stats,
            'range' => $range,
            'from'  => $from,
            'to'    => $to,
        ]));
    }

    public function site(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }

        $range = (string) $request->query('range', '30d');
        $from = $request->query('from');
        $to = $request->query('to');

        $stats = Stats::forSite((int) $site['id'], $range, is_string($from) ? $from : null, is_string($to) ? $to : null);

        return Response::html(view('dashboard/site', [
            'site'  => $site,
            'stats' => $stats,
            'range' => $range,
            'from'  => $from,
            'to'    => $to,
        ]));
    }
}
