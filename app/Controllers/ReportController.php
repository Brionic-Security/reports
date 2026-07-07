<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Site;
use App\Services\Auth;
use App\Services\ReportService;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class ReportController
{
    /** Render the report email in the browser for preview. */
    public function preview(Request $request, array $params): Response
    {
        $site = $this->site($params);
        return Response::html(ReportService::previewHtml($site, $this->days($request)));
    }

    /** Send the report to the client's configured email now. */
    public function send(Request $request, array $params): Response
    {
        $site = $this->site($params);
        $result = ReportService::send($site, $this->days($request), null, true);
        Session::flash($result === 'sent' ? 'ok' : 'error', $this->message($result, (string) ($site['report_email'] ?? '')));
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings'));
    }

    /** Send a copy of the report to the operator (for testing). */
    public function test(Request $request, array $params): Response
    {
        $site = $this->site($params);
        $to = Auth::email();
        $result = ReportService::send($site, $this->days($request), $to, true);
        Session::flash($result === 'sent' ? 'ok' : 'error',
            $result === 'sent' ? 'Test report sent to ' . $to . '.' : 'Could not send the test report.');
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings'));
    }

    /** @param array<string,string> $params */
    private function site(array $params): array
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        return $site;
    }

    private function days(Request $request): int
    {
        return max(1, (int) $request->query('days', 7));
    }

    private function message(string $result, string $email): string
    {
        return match ($result) {
            'sent'     => 'Report sent to ' . $email . '.',
            'no_email' => 'Add a client report email first.',
            default    => 'Could not send the report — check the mail settings.',
        };
    }
}
