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
