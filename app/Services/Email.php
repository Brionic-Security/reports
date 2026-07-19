<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Email template catalog. Each method returns a fully rendered message:
 *   ['subject' => ..., 'html' => ..., 'text' => ...]
 *
 * All HTML uses inline styles (email clients ignore <style>/external CSS)
 * wrapped in a shared branded shell. Brand colors: red #d92b32 / gold #c9a23c.
 */
final class Email
{
    private const RED = '#d92b32';
    private const GOLD = '#c9a23c';
    private const GREEN = '#2ed3a7';
    private const MAC_GREEN = '#34c759';   /* humans (macOS system green) */
    private const MAC_YELLOW = '#ffcc00';  /* bots (macOS system yellow) */
    private const DARK = '#15171c';
    private const INK = '#1c2129';
    private const MUTED = '#6b7280';
    private const LINE = '#eceef1';

    /**
     * A weekly (or custom-period) traffic report for one site.
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $data  from Stats::forSite() (+ 'series')
     */
    public static function report(array $site, array $data, string $periodLabel): array
    {
        $name = self::esc((string) $site['name']);
        $domain = self::esc((string) $site['domain']);
        $favicon = 'https://www.google.com/s2/favicons?domain=' . rawurlencode((string) $site['domain']) . '&sz=64';

        $visitors  = (int) ($data['visitors'] ?? 0);
        $pageviews = (int) ($data['pageviews'] ?? 0);
        $bots      = (int) ($data['bots'] ?? 0);
        $alltime   = (int) ($data['alltime'] ?? 0);

        $hero = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;"><tr>'
            . '<td width="58" valign="middle" style="padding-right:14px;">'
            . '<img src="' . self::esc($favicon) . '" width="46" height="46" alt="" style="display:block;border-radius:10px;border:1px solid ' . self::LINE . ';background:#f1f2f5;">'
            . '</td><td valign="middle">'
            . '<div style="font-size:27px;font-weight:800;color:' . self::INK . ';line-height:1.15;">' . $name . '</div>'
            . '<div style="font-size:14px;color:' . self::MUTED . ';">' . $domain . ' &middot; ' . self::esc($periodLabel) . '</div>'
            . '</td></tr></table>';

        // Prepend flag emoji to each country.
        $countries = array_map(fn ($r) => [
            'country' => self::flag((string) ($r['cc'] ?? '')) . (string) $r['country'],
            'n' => (int) $r['n'],
        ], $data['countries'] ?? []);

        $body = $hero
            . self::metrics([
                ['Page views', $pageviews, self::RED],
                ['Unique visitors', $visitors, self::MAC_GREEN],
                ['Bot hits', $bots, '#b45309'],
                ['All-time views', $alltime, self::INK],
            ])
            . self::splitBar($pageviews, $bots)
            . self::dayChart($data['series'] ?? [])
            . self::twoCol(
                self::listSection('Top countries', $countries, 'country', 'n', 6, true),
                self::listSection('Top cities', $data['cities'] ?? [], 'city', 'n', 6, true)
            )
            . self::twoCol(
                self::segChart('Devices', $data['devices'] ?? [], 'label', 'n'),
                self::segChart('Browsers', $data['browsers'] ?? [], 'label', 'n')
            )
            . self::twoCol(
                self::segChart('Operating systems', $data['os'] ?? [], 'label', 'n'),
                self::listSection('Top pages', $data['top_pages'] ?? [], 'path', 'n', 6, true)
            )
            . self::listSection('Where visitors came from', $data['referrers'] ?? [], 'referer_host', 'n', 8, true)
            . (!empty($data['events']) ? self::listSection('Custom events', $data['events'], 'name', 'n', 6) : '')
            . self::pMuted('Automated report from ' . self::esc((string) config('app.name'))
                . '. Human figures exclude bots &amp; crawlers; uniques use a privacy-friendly daily hash &mdash; no cookies, no stored IPs.');

        $subject = 'Website report for ' . (string) $site['name'] . ' — ' . $periodLabel;
        $text = "Traffic summary for {$site['name']} ({$site['domain']}) — {$periodLabel}\n\n"
            . "Page views: {$pageviews}\nUnique visitors: {$visitors}\nBot hits: {$bots}\nAll-time page views: {$alltime}\n";

        return self::shell($subject, $body, $text);
    }

    /**
     * A traffic spike/drop alert for the operator.
     *
     * @param array<string,mixed> $site
     */
    public static function trafficAlert(array $site, string $kind, int $yesterday, float $baseline, string $day): array
    {
        $name = self::esc((string) $site['name']);
        $up = $kind === 'spike';
        $color = $up ? self::RED : '#b45309';
        $pct = $baseline > 0 ? (int) round(abs($yesterday - $baseline) / $baseline * 100) : 100;
        $arrow = $up ? '&#9650;' : '&#9660;';
        $headline = ($up ? 'up' : 'down') . ' ' . $pct . '% vs the 7-day average';

        $body = self::p('Heads up — traffic on <strong>' . $name . '</strong> ('
                . self::esc((string) $site['domain']) . ') ' . ($up ? 'spiked' : 'dropped')
                . ' on ' . self::esc(date('M j, Y', strtotime($day))) . '.')
            . '<div style="font-size:22px;font-weight:800;color:' . $color . ';margin:2px 0 16px;">'
                . $arrow . ' ' . self::esc($headline) . '</div>'
            . self::stats([
                ['Yesterday', $yesterday, $color],
                ['7-day average', (int) round($baseline), self::MUTED],
            ])
            . self::pMuted('Human page views only (bots excluded). Baseline is the average of the previous 7 days.');

        $subject = ($up ? 'Traffic spike' : 'Traffic drop') . ' — ' . (string) $site['name']
            . ' (' . ($up ? '+' : '-') . $pct . '%)';
        $text = ($up ? 'Traffic spike' : 'Traffic drop') . " on {$site['name']} for {$day}: "
            . "{$yesterday} views vs 7-day avg " . round($baseline, 1) . ".\n";

        return self::shell($subject, $body, $text);
    }

    /** Downtime / recovery alert for a monitored site. */
    public static function uptimeAlert(array $site, string $kind, int $status, string $error, int $ms): array
    {
        $name = self::esc((string) $site['name']);
        $domain = self::esc((string) $site['domain']);
        $down = $kind === 'down';
        $color = $down ? self::RED : self::GREEN;
        $icon = $down ? '&#9888;' : '&#10003;';
        $headline = $down ? 'appears to be DOWN' : 'is back ONLINE';

        $detail = $down
            ? ($error !== '' ? self::esc($error) : ('HTTP ' . $status))
            : ('HTTP ' . $status . ' &middot; ' . num($ms) . ' ms');

        $body = self::p('<strong>' . $name . '</strong> (' . $domain . ') ' . $headline . '.')
            . '<div style="font-size:22px;font-weight:800;color:' . $color . ';margin:2px 0 16px;">'
                . $icon . ' ' . self::esc(strtoupper($down ? 'down' : 'up')) . '</div>'
            . self::stats([
                [$down ? 'Status' : 'Status code', $down ? ($status ?: 0) : $status, $color],
                ['Response', $ms, self::MUTED],
            ])
            . self::pMuted($down
                ? 'We could not reach the site. Details: ' . $detail . '. We&rsquo;ll email you again the moment it recovers.'
                : 'The site responded normally. Details: ' . $detail . '.');

        $subject = ($down ? 'Site DOWN' : 'Site recovered') . ' — ' . (string) $site['name'];
        $text = ($down ? "DOWN" : "RECOVERED") . ": {$site['name']} ({$site['domain']}) — "
            . ($down ? ($error !== '' ? $error : 'HTTP ' . $status) : 'HTTP ' . $status . ', ' . $ms . 'ms') . "\n";

        return self::shell($subject, $body, $text);
    }

    /**
     * Maintenance notice from a connected site's plugin (e.g. after WordPress
     * auto-updates). $items are already-sanitised plaintext lines like
     * "[OK] Plugin: Name v1.2".
     *
     * @param string[] $items
     * @return array{subject:string,html:string,text:string}
     */
    public static function maintenanceNotice(array $site, string $eventTitle, array $items): array
    {
        $name = self::esc((string) $site['name']);
        $domain = self::esc((string) $site['domain']);
        $rows = '';
        foreach ($items as $line) {
            $line = (string) $line;
            $failed = stripos($line, '[FAILED]') !== false;
            $clean = trim((string) preg_replace('/^\s*\[(OK|FAILED)\]\s*/i', '', $line));
            $color = $failed ? self::RED : self::GREEN;
            $icon = $failed ? '&#10007;' : '&#10003;';
            $rows .= '<tr><td style="padding:7px 0;border-bottom:1px solid #eee;font-size:14px;color:#1d1d1f;">'
                . '<span style="color:' . $color . ';font-weight:700;">' . $icon . '</span>&nbsp; '
                . self::esc($clean) . '</td></tr>';
        }
        $body = self::p('<strong>' . $name . '</strong> (' . $domain . ') &mdash; ' . self::esc($eventTitle) . '.')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px;">' . $rows . '</table>'
            . self::pMuted('Automated maintenance notice from your Brionic Reports account. Recipients are managed in your Reports dashboard.');
        $subject = $eventTitle . ' — ' . (string) $site['name'];
        $text = $eventTitle . ' on ' . (string) $site['name'] . ' (' . (string) $site['domain'] . "):\n\n" . implode("\n", $items) . "\n";
        return self::shell($subject, $body, $text);
    }

    // ── layout pieces ─────────────────────────────────────────────────────────

    /** @param array<int,array{0:string,1:int,2:string}> $items */
    private static function stats(array $items): string
    {
        $cells = '';
        foreach ($items as [$label, $value, $color]) {
            $cells .= '<td width="50%" align="center" style="padding:18px 10px;">'
                . '<div style="font-size:40px;font-weight:800;color:' . $color . ';line-height:1;">' . self::esc((string) num($value)) . '</div>'
                . '<div style="font-size:12px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.07em;margin-top:6px;">' . self::esc($label) . '</div>'
                . '</td>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 6px;background:#f7f8fa;border:1px solid ' . self::LINE . ';border-radius:12px;"><tr>' . $cells . '</tr></table>';
    }

    /**
     * Vertical bar chart of daily traffic (bots stacked over humans).
     *
     * @param array<int,array{date:string,humans:int,bots:int}> $series
     */
    private static function dayChart(array $series): string
    {
        if (!$series) {
            return '';
        }
        $max = 1;
        foreach ($series as $d) {
            $max = max($max, (int) $d['humans'] + (int) $d['bots']);
        }
        $h = 120;
        $cols = '';
        foreach ($series as $d) {
            $humans = (int) $d['humans'];
            $bots = (int) $d['bots'];
            $hh = $humans > 0 ? max(3, (int) round($humans / $max * $h)) : 0;
            $bh = $bots > 0 ? max(3, (int) round($bots / $max * $h)) : 0;
            $day = date('D', strtotime((string) $d['date']));
            $cols .= '<td valign="bottom" align="center" style="padding:0 3px;">'
                . '<div style="font-size:11px;color:' . self::INK . ';font-weight:700;margin-bottom:3px;height:14px;">' . ($humans ?: '') . '</div>'
                . ($bh > 0 ? '<div style="height:' . $bh . 'px;background:' . self::MAC_YELLOW . ';border-radius:3px 3px 0 0;"></div>' : '')
                . ($hh > 0 ? '<div style="height:' . $hh . 'px;background:' . self::MAC_GREEN . ';border-radius:' . ($bh > 0 ? '0' : '3px 3px 0 0') . ';"></div>' : '<div style="height:2px;background:' . self::LINE . ';"></div>')
                . '<div style="font-size:10px;color:' . self::MUTED . ';margin-top:6px;">' . self::esc(substr($day, 0, 1)) . '</div>'
                . '</td>';
        }
        return '<h2 style="margin:24px 0 6px;font-size:13px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.08em;">Visitors by day</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafbfc;border:1px solid ' . self::LINE . ';border-radius:12px;"><tr><td style="padding:14px 12px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cols . '</tr></table></td></tr></table>';
    }

    /** Two side-by-side sections that fill the wider layout. */
    private static function twoCol(string $left, string $right): string
    {
        if ($left === '' && $right === '') {
            return '';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td width="50%" valign="top" style="padding-right:10px;">' . $left . '</td>'
            . '<td width="50%" valign="top" style="padding-left:10px;">' . $right . '</td>'
            . '</tr></table>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function listSection(string $title, array $rows, string $labelKey, string $valKey, int $limit = 6, bool $compact = false): string
    {
        if (!$rows) {
            return '';
        }
        $max = max(array_map(static fn ($r) => (int) $r[$valKey], $rows)) ?: 1;
        $lines = '';
        foreach (array_slice($rows, 0, $limit) as $r) {
            $label = (string) ($r[$labelKey] ?? '');
            if ($label === '') {
                $label = '—';
            }
            $pct = max(8, (int) round(((int) $r[$valKey] / $max) * 100));
            $lines .= '<tr>'
                . '<td style="padding:4px 0;font-size:13px;color:' . self::INK . ';">'
                . '<div style="background:' . self::RED . '14;border-left:3px solid ' . self::RED . ';border-radius:4px;padding:6px 8px;width:' . $pct . '%;min-width:110px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . self::esc($label) . '</div>'
                . '</td>'
                . '<td width="46" align="right" style="padding:4px 0 4px 8px;font-size:13px;font-weight:700;color:' . self::INK . ';white-space:nowrap;">' . self::esc((string) num((int) $r[$valKey])) . '</td>'
                . '</tr>';
        }
        return '<h2 style="margin:' . ($compact ? '18px' : '22px') . ' 0 4px;font-size:13px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.08em;">' . self::esc($title) . '</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $lines . '</table>';
    }

    /** Regional-indicator flag emoji for a 2-letter country code (or ''). */
    private static function flag(string $cc): string
    {
        $cc = strtoupper(trim($cc));
        if (strlen($cc) !== 2 || !ctype_alpha($cc)) {
            return '';
        }
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65) . ' ';
    }

    /** A 2x2 grid of headline metrics. @param array<int,array{0:string,1:int,2:string}> $items */
    private static function metrics(array $items): string
    {
        $rows = '';
        foreach (array_chunk($items, 2) as $pair) {
            $cells = '';
            foreach ($pair as [$label, $value, $color]) {
                $cells .= '<td width="50%" align="center" style="padding:16px 10px;background:#f7f8fa;border-radius:12px;">'
                    . '<div style="font-size:34px;font-weight:800;color:' . $color . ';line-height:1;">' . self::esc((string) num((int) $value)) . '</div>'
                    . '<div style="font-size:12px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.06em;margin-top:5px;">' . self::esc($label) . '</div>'
                    . '</td>';
            }
            $rows .= '<tr>' . $cells . '</tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="8" style="margin:0 0 8px;">' . $rows . '</table>';
    }

    /** A humans-vs-bots split bar with legend. */
    private static function splitBar(int $human, int $bot): string
    {
        $tot = $human + $bot;
        if ($tot <= 0) {
            return '';
        }
        $hp = (int) round($human / $tot * 100);
        $bp = 100 - $hp;
        $bar = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;margin:4px 0 6px;"><tr>'
            . ($hp > 0 ? '<td width="' . $hp . '%" style="background:' . self::MAC_GREEN . ';height:20px;font-size:0;line-height:0;">&nbsp;</td>' : '')
            . ($bp > 0 ? '<td width="' . $bp . '%" style="background:' . self::MAC_YELLOW . ';height:20px;font-size:0;line-height:0;">&nbsp;</td>' : '')
            . '</tr></table>';
        $legend = '<div style="font-size:12px;color:' . self::MUTED . ';">'
            . '<span style="color:' . self::MAC_GREEN . ';">&#9632;</span> Humans ' . num($human) . ' (' . $hp . '%)&nbsp;&nbsp;'
            . '<span style="color:' . self::MAC_YELLOW . ';">&#9632;</span> Bots ' . num($bot) . ' (' . $bp . '%)</div>';
        return '<h2 style="margin:20px 0 6px;font-size:13px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.08em;">Humans vs bots</h2>' . $bar . $legend;
    }

    /**
     * A colored segmented bar with legend — the email-safe equivalent of the
     * dashboard donut. Slices use the macOS system palette by index.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private static function segChart(string $title, array $rows, string $labelKey = 'label', string $valKey = 'n'): string
    {
        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$valKey] ?? 0) > 0));
        if (!$rows) {
            return '';
        }
        $palette = ['#34c759', '#007aff', '#ff9500', '#af52de', '#ff2d55', '#32ade6', '#ffcc00', '#00c7be'];
        $tot = 0;
        foreach ($rows as $r) {
            $tot += (int) $r[$valKey];
        }
        $tot = max(1, $tot);
        $seg = '';
        $legend = '';
        $shown = 0;
        $i = 0;
        foreach (array_slice($rows, 0, 8) as $r) {
            $label = (string) ($r[$labelKey] ?? '');
            if ($label === '') {
                $label = 'Unknown';
            }
            $c = $palette[$i % count($palette)];
            $pc = (int) round((int) $r[$valKey] / $tot * 100);
            if ($pc > 0) {
                $seg .= '<td width="' . $pc . '%" style="background:' . $c . ';height:22px;font-size:0;line-height:0;">&nbsp;</td>';
                $shown += $pc;
            }
            $legend .= '<span style="white-space:nowrap;margin-right:14px;display:inline-block;">'
                . '<span style="color:' . $c . ';">&#9632;</span> ' . self::esc($label) . ' ' . $pc . '%</span> ';
            $i++;
        }
        // Fill any remainder (rounding / categories beyond the top 8) neutrally.
        if ($shown < 100) {
            $seg .= '<td width="' . (100 - $shown) . '%" style="background:#e6e8ec;height:22px;font-size:0;line-height:0;">&nbsp;</td>';
        }
        return '<h2 style="margin:18px 0 6px;font-size:13px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.08em;">' . self::esc($title) . '</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;"><tr>' . $seg . '</tr></table>'
            . '<div style="font-size:12px;color:' . self::MUTED . ';margin-top:7px;line-height:1.9;">' . $legend . '</div>';
    }

    // ── shell + helpers ───────────────────────────────────────────────────────

    private static function shell(string $title, string $bodyHtml, string $text): array
    {
        $app = self::esc((string) config('app.name'));
        $year = date('Y');
        $support = self::esc((string) config('mail.support_email'));
        $logo = self::esc(asset('img/brionic-icon.png'));

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
            . '<body style="margin:0;padding:0;background:#0b0d12;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b0d12;padding:28px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;">'
            . '<tr><td style="background:' . self::DARK . ';padding:16px 40px;" align="left">'
            . '<img src="' . $logo . '" width="26" height="26" alt="" style="vertical-align:middle;border:0;display:inline-block;">'
            . '<span style="color:#ffffff;font-size:16px;font-weight:800;vertical-align:middle;margin-left:10px;">' . $app . '</span>'
            . '</td></tr>'
            . '<tr><td style="height:4px;background:' . self::RED . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:34px 40px;color:' . self::INK . ';font-size:15px;line-height:1.6;">' . $bodyHtml . '</td></tr>'
            . '<tr><td style="padding:20px 40px;background:#f5f6f8;color:' . self::MUTED . ';font-size:12px;line-height:1.6;">'
            . $app . ' &middot; privacy-first website analytics<br>'
            . 'Questions? Contact <a href="mailto:' . $support . '" style="color:' . self::RED . ';text-decoration:none;">' . $support . '</a>.<br>'
            . '&copy; ' . $year . ' Brionic Security LLC.'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        return ['subject' => $title, 'html' => $html, 'text' => $text];
    }

    private static function pMuted(string $html): string
    {
        return '<p style="margin:24px 0 0;color:' . self::MUTED . ';font-size:13px;line-height:1.6;">' . $html . '</p>';
    }

    private static function p(string $html): string
    {
        return '<p style="margin:0 0 16px;">' . $html . '</p>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
