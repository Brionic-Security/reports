<?php

declare(strict_types=1);

/**
 * Middleware registration. Returns a closure that registers named middleware
 * onto the router. Middleware may return a Response to short-circuit.
 */

use App\Services\Auth;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Router;
use App\Support\Session;

return function (Router $router): void {

    // Require an authenticated operator (God Mode). Cloaked: redirects to login.
    $router->registerMiddleware('admin', function (Request $request) {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                return Response::json(['error' => ['type' => 'unauthorized', 'message' => 'Please sign in.']], 401);
            }
            Session::flash('intended', $request->path());
            return Response::redirect(app_url('login'));
        }
        return null;
    });

    // Login page only.
    $router->registerMiddleware('guest', function () {
        if (Auth::check()) {
            return Response::redirect(app_url('dashboard'));
        }
        return null;
    });

    // CSRF protection for state-changing browser requests.
    $router->registerMiddleware('csrf', function (Request $request) {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->input('_token') ?? $request->header('x-csrf-token');
            if (!Csrf::verify(is_string($token) ? $token : null)) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => ['type' => 'csrf', 'message' => 'CSRF token mismatch.']], 419);
                }
                return Response::html('<h1>419 — Page expired</h1><p>Please go back and try again.</p>', 419);
            }
        }
        return null;
    });
};
