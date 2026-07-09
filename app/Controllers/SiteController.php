<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Site;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class SiteController
{
    public function index(): Response
    {
        return Response::html(view('sites/index', ['sites' => Site::all()]));
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));
        $domain = trim((string) $request->input('domain', ''));
        $email = trim((string) $request->input('report_email', ''));

        if ($name === '' || $domain === '') {
            Session::flash('error', 'Name and domain are required.');
            return Response::redirect(app_url('sites'));
        }

        $site = Site::create($name, $domain, $email !== '' ? $email : null);
        Session::flash('ok', 'Site added. Copy the snippet below into your website.');
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings'));
    }

    public function show(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        return Response::html(view('sites/show', [
            'site'    => $site,
            'snippet' => self::snippet((string) $site['public_id']),
            'ok'      => Session::getFlash('ok'),
            'error'   => Session::getFlash('error'),
            'runs'    => \App\Models\ReportRun::recentForSite((int) $site['id'], 6),
        ]));
    }

    public function update(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        $name = trim((string) $request->input('name', (string) $site['name']));
        $domain = trim((string) $request->input('domain', (string) $site['domain']));
        $email = trim((string) $request->input('report_email', ''));
        Site::update((int) $site['id'], $name, $domain, $email !== '' ? $email : null);

        $monitorUrl = trim((string) $request->input('monitor_url', ''));
        $monitorEnabled = $request->input('monitor_enabled') !== null;
        Site::updateMonitor((int) $site['id'], $monitorUrl !== '' ? $monitorUrl : null, $monitorEnabled);

        Session::flash('ok', 'Saved.');
        return Response::redirect(app_url('sites/' . $site['id'] . '/settings'));
    }

    public function destroy(Request $request, array $params): Response
    {
        $site = Site::find((int) ($params['id'] ?? 0));
        if ($site === null) {
            throw HttpException::notFound('Site not found.');
        }
        Site::delete((int) $site['id']);
        Session::flash('ok', 'Site deleted.');
        return Response::redirect(app_url('sites'));
    }

    public static function snippet(string $publicId): string
    {
        $src = app_url('b.js');
        return '<script defer data-site="' . e($publicId) . '" src="' . e($src) . '"></script>';
    }
}
