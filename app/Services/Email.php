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
    private const DARK = '#15171c';
    private const INK = '#1c2129';
    private const MUTED = '#6b7280';

    /**
     * A weekly (or custom-period) traffic report for one site.
     *
     * @param array<string,mixed> $site
     * @param array<string,mixed> $data  from Stats::forSite()
     */
    public static function report(array $site, array $data, string $periodLabel): array
    {
        $name = self::esc((string) $site['name']);
        $domain = self::esc((string) $site['domain']);

        $body = self::p('Here is the traffic summary for <strong>' . $name . '</strong> (' . $domain . ') for <strong>' . self::esc($periodLabel) . '</strong>.')
            . self::stats([
                ['Unique visitors', (int) $data['visitors']],
                ['Page views', (int) $data['pageviews']],
            ])
            . self::listSection('Top pages', $data['top_pages'], 'path', 'n')
            . self::listSection('Where visitors came from', $data['referrers'], 'referer_host', 'n')
            . self::listSection('Top countries', $data['countries'], 'country', 'n')
            . self::listSection('Devices', array_map(fn ($r) => ['label' => $r['label'], 'n' => $r['n']], $data['devices']), 'label', 'n')
            . self::pMuted('This is an automated report from ' . self::esc((string) config('app.name')) . '. Numbers exclude bots and crawlers.');

        $subject = 'Website report for ' . (string) $site['name'] . ' — ' . $periodLabel;
        $text = "Traffic summary for {$site['name']} ({$site['domain']}) — {$periodLabel}\n\n"
            . "Unique visitors: {$data['visitors']}\nPage views: {$data['pageviews']}\n";

        return self::shell($subject, $body, $text);
    }

    // ── layout pieces ─────────────────────────────────────────────────────────

    /** @param array<int,array{0:string,1:int}> $items */
    private static function stats(array $items): string
    {
        $cells = '';
        foreach ($items as [$label, $value]) {
            $cells .= '<td align="center" style="padding:6px 10px;">'
                . '<div style="font-size:30px;font-weight:800;color:' . self::INK . ';">' . self::esc((string) num($value)) . '</div>'
                . '<div style="font-size:12px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.06em;">' . self::esc($label) . '</div>'
                . '</td>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;background:#f7f8fa;border-radius:10px;padding:14px 0;"><tr>' . $cells . '</tr></table>';
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function listSection(string $title, array $rows, string $labelKey, string $valKey): string
    {
        if (!$rows) {
            return '';
        }
        $max = max(array_map(static fn ($r) => (int) $r[$valKey], $rows)) ?: 1;
        $lines = '';
        foreach (array_slice($rows, 0, 6) as $r) {
            $label = (string) ($r[$labelKey] ?? '');
            if ($label === '') {
                $label = '—';
            }
            $pct = (int) round(((int) $r[$valKey] / $max) * 100);
            $lines .= '<tr>'
                . '<td style="padding:6px 0;font-size:14px;color:' . self::INK . ';">'
                . '<div style="background:linear-gradient(90deg,' . self::RED . '22,' . self::RED . '11);border-radius:6px;padding:5px 8px;">' . self::esc($label) . '</div>'
                . '</td>'
                . '<td width="60" align="right" style="padding:6px 0 6px 10px;font-size:14px;font-weight:700;color:' . self::INK . ';white-space:nowrap;">' . self::esc((string) num((int) $r[$valKey])) . '</td>'
                . '</tr>';
        }
        return '<h2 style="margin:22px 0 4px;font-size:14px;color:' . self::MUTED . ';text-transform:uppercase;letter-spacing:.08em;">' . self::esc($title) . '</h2>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $lines . '</table>';
    }

    // ── shell + helpers ───────────────────────────────────────────────────────

    private static function shell(string $title, string $bodyHtml, string $text): array
    {
        $app = self::esc((string) config('app.name'));
        $year = date('Y');
        $support = self::esc((string) config('mail.support_email'));

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
            . '<body style="margin:0;padding:0;background:#0b0d12;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b0d12;padding:28px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:540px;background:#ffffff;border-radius:14px;overflow:hidden;">'
            . '<tr><td style="background:' . self::DARK . ';padding:20px 28px;" align="left">'
            . '<span style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . self::RED . ';vertical-align:middle;"></span>'
            . '<span style="color:#ffffff;font-size:17px;font-weight:800;vertical-align:middle;margin-left:9px;">' . $app . '</span>'
            . '</td></tr>'
            . '<tr><td style="height:4px;background:' . self::RED . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px 28px 6px;">'
            . '<h1 style="margin:0 0 6px;color:' . self::INK . ';font-size:20px;font-weight:800;">' . self::esc($title) . '</h1>'
            . '</td></tr>'
            . '<tr><td style="padding:0 28px 26px;color:' . self::INK . ';font-size:15px;line-height:1.6;">' . $bodyHtml . '</td></tr>'
            . '<tr><td style="padding:18px 28px;background:#f5f6f8;color:' . self::MUTED . ';font-size:12px;line-height:1.6;">'
            . $app . ' &middot; privacy-first website analytics<br>'
            . 'Questions? Contact <a href="mailto:' . $support . '" style="color:' . self::RED . ';text-decoration:none;">' . $support . '</a>.<br>'
            . '&copy; ' . $year . ' Brionic Security LLC.'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        return ['subject' => $title, 'html' => $html, 'text' => $text];
    }

    private static function p(string $html): string
    {
        return '<p style="margin:0 0 16px;">' . $html . '</p>';
    }

    private static function pMuted(string $html): string
    {
        return '<p style="margin:16px 0 0;color:' . self::MUTED . ';font-size:13px;line-height:1.6;">' . $html . '</p>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
