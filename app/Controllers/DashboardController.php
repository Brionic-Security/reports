<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Site;
use App\Models\UptimeCheck;
use App\Services\Stats;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class DashboardController
{
    public function overview(Request $request): Response
    {
        [$range, $from, $to] = self::rangeParams($request);

        $sites = Site::all();
        $stats = Stats::overview($range, $from, $to);
        $since30 = gmdate('Y-m-d H:i:s', time() - 2592000);
        $uptime30 = [];
        foreach ($sites as $s) {
            $uptime30[(int) $s['id']] = UptimeCheck::uptimePercent((int) $s['id'], $since30);
        }

        return Response::html(view('dashboard/overview', [
            'sites'    => $sites,
            'stats'    => $stats,
            'online'   => Stats::onlineAll(),
            'uptime'   => UptimeCheck::latestForAll(),
            'uptime30' => $uptime30,
            'spark'    => Stats::sparklines(14),
            'map'      => Stats::mapAll($range, $from, $to),
            'range'    => $range,
            'from'     => $from,
            'to'       => $to,
        ]));
    }

    public function site(Request $request, array $params): Response
    {
        $site = self::findOr404($params);
        [$range, $from, $to] = self::rangeParams($request);

        $stats = Stats::forSite((int) $site['id'], $range, $from, $to);
        $since30 = gmdate('Y-m-d H:i:s', time() - 2592000);

        return Response::html(view('dashboard/site', [
            'site'          => $site,
            'stats'         => $stats,
            'realtime'      => Stats::realtime((int) $site['id']),
            'monitorLast'   => UptimeCheck::latest((int) $site['id']),
            'uptime30'      => UptimeCheck::uptimePercent((int) $site['id'], $since30),
            'avgMs'         => UptimeCheck::avgResponseMs((int) $site['id'], $since30),
            'monitorRecent' => UptimeCheck::recent((int) $site['id'], 12),
            'range'         => $range,
            'from'          => $from,
            'to'            => $to,
        ]));
    }

    /** JSON: live visitor count + feed for a single site (polled by the dashboard). */
    public function realtimeSite(Request $request, array $params): Response
    {
        $site = self::findOr404($params);
        $rt = Stats::realtime((int) $site['id']);
        $feed = [];
        foreach ($rt['feed'] as $r) {
            $isBot = (int) $r['is_bot'] === 1;
            $feed[] = [
                'kind'   => $isBot ? 'bot' : ($r['type'] === 'event' ? 'event' : 'view'),
                'label'  => $isBot ? (string) ($r['bot_name'] ?: 'bot') : (string) ($r['name'] ?: $r['path']),
                'path'   => (string) $r['path'],
                'where'  => trim((string) ($r['city'] ?: '') . (($r['city'] && $r['country']) ? ', ' : '') . (string) ($r['country'] ?: '')),
                'device' => trim((string) ($r['browser'] ?: '') . (($r['browser'] && $r['device']) ? ' · ' : '') . (string) ($r['device'] ?: '')),
                'ago'    => time_ago((string) $r['created_at']),
            ];
        }
        return Response::json(['online' => $rt['online'], 'feed' => $feed]);
    }

    /** JSON: total + per-site online counts for the overview. */
    public function realtimeOverview(): Response
    {
        return Response::json(Stats::onlineAll());
    }

    /** CSV: daily traffic breakdown for a site over the selected range. */
    public function exportSite(Request $request, array $params): Response
    {
        $site = self::findOr404($params);
        [$range, $from, $to] = self::rangeParams($request);
        $stats = Stats::forSite((int) $site['id'], $range, $from, $to);

        $rows = [['date', 'human_pageviews', 'bot_hits']];
        foreach ($stats['by_day'] as $d) {
            $rows[] = [(string) $d['date'], (string) $d['humans'], (string) $d['bots']];
        }
        $name = (preg_replace('/[^a-z0-9]+/i', '-', (string) $site['domain']) ?? 'site') . '-' . $range . '.csv';
        return self::csv($rows, $name);
    }

    /** CSV: per-site totals across all sites for the selected range. */
    public function exportOverview(Request $request): Response
    {
        [$range, $from, $to] = self::rangeParams($request);
        $stats = Stats::overview($range, $from, $to);

        $rows = [['site', 'domain', 'unique_visitors', 'page_views']];
        foreach (Site::all() as $s) {
            $row = $stats[(int) $s['id']] ?? ['pageviews' => 0, 'visitors' => 0];
            $rows[] = [(string) $s['name'], (string) $s['domain'], (string) $row['visitors'], (string) $row['pageviews']];
        }
        return self::csv($rows, 'overview-' . $range . '.csv');
    }

    /** @param array<int,array<int,string>> $rows */
    private static function csv(array $rows, string $filename): Response
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($out, $r, ',', '"', '\\');
        }
        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);

        return new Response($body, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** @return array{0:string,1:?string,2:?string} */
    private static function rangeParams(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');
        return [
            (string) $request->query('range', '30d'),
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        ];
    }

    private static function findOr404(array $params): array
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        return $site;
    }
}
