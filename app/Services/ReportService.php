<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReportRun;
use App\Models\Site;

/**
 * Builds and sends per-site traffic reports (the "Weekly Visit reports").
 */
final class ReportService
{
    /**
     * The reporting period covering the last N complete days (ending yesterday).
     *
     * @return array{from:string,to:string,label:string} Y-m-d dates + label
     */
    public static function period(int $days = 7): array
    {
        $to = gmdate('Y-m-d', strtotime('-1 day'));          // yesterday (inclusive)
        $from = gmdate('Y-m-d', strtotime('-' . $days . ' day'));
        $label = self::labelFor($from, $to);
        return ['from' => $from, 'to' => $to, 'label' => $label];
    }

    private static function labelFor(string $from, string $to): string
    {
        $f = strtotime($from);
        $t = strtotime($to);
        if (date('Y', $f) === date('Y', $t)) {
            if (date('m', $f) === date('m', $t)) {
                return date('M j', $f) . '–' . date('j, Y', $t);
            }
            return date('M j', $f) . ' – ' . date('M j, Y', $t);
        }
        return date('M j, Y', $f) . ' – ' . date('M j, Y', $t);
    }

    /**
     * Build the report data for a site and period, including a zero-filled
     * per-day series covering the whole window (for the chart).
     *
     * @return array<string,mixed>
     */
    public static function data(int $siteId, string $from, string $to): array
    {
        $data = Stats::forSite($siteId, 'custom', $from, $to);
        $data['series'] = self::fillDays($from, $to, $data['by_day'] ?? []);
        $data['alltime'] = (int) (\App\Support\Database::selectOne(
            "SELECT COUNT(*) c FROM events WHERE site_id = ? AND type = 'pageview' AND is_bot = 0",
            [$siteId]
        )['c'] ?? 0);
        return $data;
    }

    /**
     * Expand a sparse by-day list into one entry per calendar day in [from,to].
     *
     * @param array<int,array{date:string,humans:int,bots:int}> $byDay
     * @return array<int,array{date:string,humans:int,bots:int}>
     */
    private static function fillDays(string $from, string $to, array $byDay): array
    {
        $map = [];
        foreach ($byDay as $d) {
            $map[substr((string) $d['date'], 0, 10)] = $d;
        }
        $out = [];
        $cur = strtotime($from);
        $end = strtotime($to);
        // Cap at 92 days so a huge custom range can't explode the chart.
        for ($i = 0; $cur <= $end && $i < 92; $cur = strtotime('+1 day', $cur), $i++) {
            $key = date('Y-m-d', $cur);
            $out[] = [
                'date'   => $key,
                'humans' => (int) ($map[$key]['humans'] ?? 0),
                'bots'   => (int) ($map[$key]['bots'] ?? 0),
            ];
        }
        return $out;
    }

    /** Rendered HTML of the report (for on-screen preview). */
    public static function previewHtml(array $site, int $days = 7): string
    {
        $p = self::period($days);
        $data = self::data((int) $site['id'], $p['from'], $p['to']);
        return Email::report($site, $data, $p['label'])['html'];
    }

    /**
     * Parse a recipients string (commas / semicolons / whitespace / newlines)
     * into a de-duplicated list of valid email addresses.
     *
     * @return array<int,string>
     */
    public static function recipients(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($p)] = $p;
            }
        }
        return array_values($out);
    }

    /**
     * Send a report for one site to all configured recipients.
     *
     * @param array<string,mixed> $site
     * @return string one of: sent | skipped | no_email | failed
     */
    public static function send(array $site, int $days = 7, ?string $override = null, bool $force = false): string
    {
        $p = self::period($days);
        $siteId = (int) $site['id'];

        $recipients = self::recipients($override ?? (string) ($site['report_email'] ?? ''));
        if (!$recipients) {
            return 'no_email';
        }

        // Dedupe: don't re-send the same period to clients (overrides/tests bypass).
        if (!$force && $override === null && ReportRun::sentExists($siteId, $p['from'])) {
            return 'skipped';
        }

        $data = self::data($siteId, $p['from'], $p['to']);
        $msg = Email::report($site, $data, $p['label']);

        $anyOk = false;
        foreach ($recipients as $r) {
            $anyOk = Mailer::send($r, $msg['subject'], $msg['html'], $msg['text'], (string) $site['name']) || $anyOk;
        }

        if ($override === null) {
            ReportRun::record($siteId, $p['from'], $p['to'], implode(', ', $recipients), $anyOk ? 'sent' : 'failed');
        }

        return $anyOk ? 'sent' : 'failed';
    }

    /**
     * Send reports for every site that has a client email. Returns a summary.
     *
     * @return array<int,array{site:string,result:string}>
     */
    public static function sendAll(int $days = 7, bool $force = false): array
    {
        $out = [];
        foreach (Site::all() as $site) {
            $out[] = ['site' => (string) $site['name'], 'result' => self::send($site, $days, null, $force)];
        }
        return $out;
    }
}
