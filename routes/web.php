<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CollectController;
use App\Controllers\DashboardController;
use App\Controllers\SiteController;
use App\Support\Response;
use App\Support\Router;

return function (Router $router): void {

    // Root → dashboard (redirects to login if not authenticated).
    $router->get('/', fn () => Response::redirect(app_url('dashboard')));

    // ── Public tracking endpoint (cross-origin, no CSRF) ─────────────────────
    // The tracker sends a "simple" beacon (text/plain), so no CORS preflight is
    // triggered; an OPTIONS handler is registered anyway for fetch()-based use.
    $router->post('/collect', [CollectController::class, 'collect']);
    $router->options('/collect', [CollectController::class, 'options']);

    // ── Auth ─────────────────────────────────────────────────────────────────
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
    $router->post('/logout', [AuthController::class, 'logout'], ['admin', 'csrf']);

    // ── Dashboard (operator only) ────────────────────────────────────────────
    $router->group(['middleware' => ['admin']], function (Router $r) {
        $r->get('/dashboard', [DashboardController::class, 'overview']);

        $r->get('/sites', [SiteController::class, 'index']);
        $r->post('/sites', [SiteController::class, 'store'], ['csrf']);
        $r->get('/sites/{id}', [DashboardController::class, 'site']);
        $r->get('/sites/{id}/settings', [SiteController::class, 'show']);
        $r->post('/sites/{id}', [SiteController::class, 'update'], ['csrf']);
        $r->post('/sites/{id}/delete', [SiteController::class, 'destroy'], ['csrf']);
    });
};
