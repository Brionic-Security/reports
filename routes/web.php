<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CollectController;
use App\Controllers\DashboardController;
use App\Controllers\DownloadController;
use App\Controllers\ReportController;
use App\Controllers\SearchController;
use App\Controllers\SiteController;
use App\Controllers\TrackerController;
use App\Support\Response;
use App\Support\Router;

return function (Router $router): void {

    // Root → dashboard (redirects to login if not authenticated).
    $router->get('/', fn () => Response::redirect(app_url('dashboard')));

    // Tracker script served via PHP for controllable caching (short + ETag).
    $router->get('/b.js', [TrackerController::class, 'serve']);

    // ── Public tracking endpoint (cross-origin, no CSRF) ─────────────────────
    // The tracker sends a "simple" beacon (text/plain), so no CORS preflight is
    // triggered; an OPTIONS handler is registered anyway for fetch()-based use.
    $router->post('/collect', [CollectController::class, 'collect']);
    $router->options('/collect', [CollectController::class, 'options']);

    // Server-side connection check for the WordPress plugin's "Test" button.
    $router->get('/api/verify', [CollectController::class, 'verify']);
    $router->post('/api/verify', [CollectController::class, 'verify']);

    // Search-engine verification tags + IndexNow key for the WordPress plugin.
    $router->get('/api/search-tags', [SearchController::class, 'siteTags']);

    // IndexNow key file for our own domain (client sites serve their own).
    $indexNowKey = (string) config('search.indexnow.key', '');
    if ($indexNowKey !== '') {
        $router->get('/' . $indexNowKey . '.txt', [SearchController::class, 'indexNowKey']);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
    $router->post('/logout', [AuthController::class, 'logout'], ['admin', 'csrf']);

    // ── Dashboard (operator only) ────────────────────────────────────────────
    $router->group(['middleware' => ['admin']], function (Router $r) {
        $r->get('/dashboard', [DashboardController::class, 'overview']);
        $r->get('/dashboard/export.csv', [DashboardController::class, 'exportOverview']);
        $r->get('/dashboard/realtime.json', [DashboardController::class, 'realtimeOverview']);

        $r->get('/sites', [SiteController::class, 'index']);
        $r->post('/sites', [SiteController::class, 'store'], ['csrf']);
        $r->get('/sites/{id}', [DashboardController::class, 'site']);
        $r->get('/sites/{id}/export.csv', [DashboardController::class, 'exportSite']);
        $r->get('/sites/{id}/realtime.json', [DashboardController::class, 'realtimeSite']);
        $r->get('/sites/{id}/settings', [SiteController::class, 'show']);
        $r->get('/sites/{id}/plugin.zip', [DownloadController::class, 'wordpressPlugin']);
        $r->post('/sites/{id}/validate', [SiteController::class, 'validate'], ['csrf']);
        $r->post('/sites/{id}', [SiteController::class, 'update'], ['csrf']);
        $r->post('/sites/{id}/delete', [SiteController::class, 'destroy'], ['csrf']);

        // Client traffic reports.
        $r->get('/sites/{id}/report', [ReportController::class, 'preview']);
        $r->post('/sites/{id}/report/send', [ReportController::class, 'send'], ['csrf']);
        $r->post('/sites/{id}/report/test', [ReportController::class, 'test'], ['csrf']);

        // Search-engine integrations (Google Search Console + Bing).
        $r->get('/integrations', [SearchController::class, 'integrations']);
        $r->get('/integrations/google/connect', [SearchController::class, 'googleConnect']);
        $r->get('/integrations/google/callback', [SearchController::class, 'googleCallback']);
        $r->post('/integrations/google/disconnect', [SearchController::class, 'googleDisconnect'], ['csrf']);

        $r->post('/sites/{id}/search/connect', [SearchController::class, 'connect'], ['csrf']);
        $r->post('/sites/{id}/search/verify', [SearchController::class, 'verify'], ['csrf']);
        $r->post('/sites/{id}/search/index', [SearchController::class, 'index'], ['csrf']);
        $r->post('/sites/{id}/search/reset-index', [SearchController::class, 'resetIndexUrls'], ['csrf']);
        $r->post('/sites/{id}/search/sync', [SearchController::class, 'sync'], ['csrf']);
        $r->post('/sites/{id}/search/disconnect', [SearchController::class, 'disconnect'], ['csrf']);
    });
};
