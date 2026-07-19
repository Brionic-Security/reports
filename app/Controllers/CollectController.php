<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Ingest;
use App\Support\Request;
use App\Support\Response;

/**
 * Public tracking endpoint. Receives beacons from the tracker script on any
 * connected website (cross-origin) and records a page view or custom event.
 *
 * Responses are always 204 with permissive CORS so the browser never blocks or
 * surfaces errors — tracking must be invisible and fire-and-forget.
 */
final class CollectController
{
    public function collect(Request $request): Response
    {
        $payload = $this->payload($request);

        $siteKey = (string) ($payload['s'] ?? '');
        if ($siteKey !== '') {
            try {
                Ingest::record(
                    $siteKey,
                    (string) ($payload['p'] ?? '/'),
                    (string) ($payload['r'] ?? ''),
                    $request->userAgent(),
                    $request->ip(),
                    ((string) ($payload['t'] ?? 'pageview')) === 'event' ? 'event' : 'pageview',
                    isset($payload['n']) ? (string) $payload['n'] : null,
                    isset($payload['w']) ? (string) $payload['w'] : null,
                    isset($payload['via']) ? (string) $payload['via'] : null
                );
            } catch (\Throwable $e) {
                logger('collect failed: ' . $e->getMessage());
            }
        }

        return $this->noContentCors();
    }

    /** CORS preflight (only triggered by non-simple requests). */
    public function options(Request $request): Response
    {
        return $this->noContentCors();
    }

    /**
     * Server-side connection check used by the WordPress plugin's "Test
     * connection" button. Validates the site key and records the check.
     */
    public function verify(Request $request): Response
    {
        $key = (string) ($request->query('key') ?? $request->input('key', ''));
        $site = $key !== '' ? \App\Models\Site::findByPublicId($key) : null;

        if ($site === null) {
            return Response::json(['ok' => false, 'error' => 'unknown_site_key'], 404)
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Cache-Control', 'no-store');
        }

        \App\Support\Database::run(
            'UPDATE sites SET plugin_verified_at = ? WHERE id = ?',
            [now(), (int) $site['id']]
        );

        return Response::json(['ok' => true, 'name' => (string) $site['name'], 'domain' => (string) $site['domain']])
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Plugin-driven maintenance notification (e.g. WordPress auto-updates).
     * Emails the site's weekly-report recipients, so notification addresses are
     * managed centrally in the Reports dashboard rather than in the plugin.
     */
    public function pluginNotify(Request $request): Response
    {
        $key = (string) ($request->query('key') ?? $request->input('key', ''));
        $site = $key !== '' ? \App\Models\Site::findByPublicId($key) : null;
        if ($site === null) {
            return Response::json(['ok' => false, 'error' => 'unknown_site_key'], 404)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        // Abuse guard: at most one notice per site per 5 minutes.
        $stamp = storage_path('cache/notify_' . (int) $site['id'] . '.txt');
        if (is_file($stamp) && (time() - (int) filemtime($stamp)) < 300) {
            return Response::json(['ok' => true, 'sent' => 0, 'throttled' => true])
                ->withHeader('Access-Control-Allow-Origin', '*');
        }
        @file_put_contents($stamp, (string) time());

        $recipients = \App\Services\ReportService::recipients((string) ($site['report_email'] ?? ''));
        if ($recipients === []) {
            return Response::json(['ok' => true, 'sent' => 0, 'note' => 'no_recipients'])
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        $title = mb_substr(trim(strip_tags((string) $request->input('title', 'Automatic updates'))), 0, 120);
        if ($title === '') {
            $title = 'Automatic updates';
        }
        $lines = [];
        foreach (preg_split('/\r?\n/', (string) $request->input('summary', '')) ?: [] as $l) {
            $l = mb_substr(trim(strip_tags((string) $l)), 0, 200);
            if ($l !== '') {
                $lines[] = $l;
            }
            if (count($lines) >= 50) {
                break;
            }
        }
        if ($lines === []) {
            return Response::json(['ok' => true, 'sent' => 0, 'note' => 'empty'])
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        $msg = \App\Services\Email::maintenanceNotice($site, $title, $lines);
        $sent = 0;
        foreach ($recipients as $to) {
            if (\App\Services\Mailer::send($to, $msg['subject'], $msg['html'], $msg['text'])) {
                $sent++;
            }
        }
        return Response::json(['ok' => true, 'sent' => $sent])
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $request->all();
    }

    private function noContentCors(): Response
    {
        return (new Response('', 204))
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Cache-Control', 'no-store');
    }
}
