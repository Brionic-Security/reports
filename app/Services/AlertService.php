<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Alert;
use App\Models\Site;
use App\Support\Database;

/**
 * Detects notable traffic changes (spikes/drops) and emails the operator.
 * Compares yesterday's human page views to the average of the prior 7 days.
 */
final class AlertService
{
    /** @return array<int,array<string,mixed>> summary of alerts raised */
    public static function run(): array
    {
        if (!config('alerts.enabled', true)) {
            return [];
        }
        $spike = (float) config('alerts.spike_factor', 2.0);
        $drop = (float) config('alerts.drop_factor', 0.4);
        $minBase = (int) config('alerts.min_baseline', 20);
        $to = (string) (config('alerts.to') ?: config('auth.admin.email'));

        $day = gmdate('Y-m-d', strtotime('-1 day'));
        $out = [];

        foreach (Site::all() as $site) {
            $sid = (int) $site['id'];
            $yesterday = self::dayViews($sid, $day);
            $baseline = self::baseline($sid, $day);

            $kind = self::classify($yesterday, $baseline, $spike, $drop, $minBase);
            if ($kind === null || $to === '' || Alert::sentToday($sid, $day, $kind)) {
                continue;
            }

            $msg = Email::trafficAlert($site, $kind, $yesterday, $baseline, $day);
            $ok = Mailer::send($to, $msg['subject'], $msg['html'], $msg['text']);
            Alert::record($sid, $day, $kind, "yesterday={$yesterday} baseline=" . round($baseline, 1));
            $out[] = ['site' => $site['name'], 'kind' => $kind, 'yesterday' => $yesterday, 'baseline' => round($baseline, 1), 'sent' => $ok];
        }

        return $out;
    }

    private static function classify(int $y, float $baseline, float $spike, float $drop, int $minBase): ?string
    {
        if ($baseline >= $minBase) {
            if ($y >= $baseline * $spike) {
                return 'spike';
            }
            if ($y <= $baseline * $drop) {
                return 'drop';
            }
            return null;
        }
        // Baseline too small to compare — only flag a clear burst from near-zero.
        if ($y >= max($minBase, 5) * $spike) {
            return 'spike';
        }
        return null;
    }

    /** Human page views on a single UTC day. */
    private static function dayViews(int $siteId, string $day): int
    {
        return (int) (Database::selectOne(
            "SELECT COUNT(*) c FROM events
             WHERE site_id = ? AND type = 'pageview' AND is_bot = 0
               AND created_at >= ? AND created_at < ?",
            [$siteId, $day . ' 00:00:00', gmdate('Y-m-d H:i:s', strtotime($day . ' +1 day'))]
        )['c'] ?? 0);
    }

    /** Average daily human page views over the 7 days before $day. */
    private static function baseline(int $siteId, string $day): float
    {
        $from = gmdate('Y-m-d 00:00:00', strtotime($day . ' -7 day'));
        $to = $day . ' 00:00:00';
        $total = (int) (Database::selectOne(
            "SELECT COUNT(*) c FROM events
             WHERE site_id = ? AND type = 'pageview' AND is_bot = 0
               AND created_at >= ? AND created_at < ?",
            [$siteId, $from, $to]
        )['c'] ?? 0);
        return $total / 7.0;
    }
}
