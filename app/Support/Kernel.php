<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The HTTP kernel ties the router, request, and error handling together.
 */
final class Kernel
{
    private Router $router;

    public function __construct()
    {
        // The public tracker endpoints (/b.js, /collect) are stateless and
        // cross-origin; starting a session there only emits a needless
        // Set-Cookie (third-party noise + blocks edge caching of the tracker).
        if (!self::isStatelessPath()) {
            Session::start();
        }
        $this->router = new Router();
        $this->registerMiddleware();
        $this->loadRoutes();
    }

    /** True for public, cookieless endpoints served cross-origin. */
    private static function isStatelessPath(): bool
    {
        $path = '/' . trim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'), '/');
        return $path === '/b.js' || $path === '/collect';
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function registerMiddleware(): void
    {
        $register = require base_path('app/Support/middleware.php');
        $register($this->router);
    }

    private function loadRoutes(): void
    {
        $web = require base_path('routes/web.php');
        $web($this->router);
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $this->handleValidation($request, $e);
        } catch (HttpException $e) {
            $response = $this->handleHttpException($request, $e);
        } catch (\Throwable $e) {
            $response = $this->handleError($request, $e);
        }

        $this->applySecurityHeaders($request, $response);
        return $response;
    }

    private function handleValidation(Request $request, ValidationException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::json([
                'error' => ['type' => 'validation_error', 'message' => 'The given data was invalid.', 'fields' => $e->errors],
            ], 422);
        }
        Session::flash('errors', $e->errors);
        Session::flashInput($request->all());
        $back = $request->header('referer', app_url());
        return Response::redirect($back ?: app_url());
    }

    private function handleHttpException(Request $request, HttpException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => ['type' => $e->errorType, 'message' => $e->getMessage()]], $e->getCode() ?: 400);
        }
        $view = 'errors/' . ($e->getCode() ?: 400);
        $body = View::exists($view) ? view($view) : view('errors/generic', ['status' => $e->getCode() ?: 400, 'message' => $e->getMessage()]);
        return Response::html($body, $e->getCode() ?: 400);
    }

    private function handleError(Request $request, \Throwable $e): Response
    {
        logger('Unhandled exception: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
        $debug = (bool) config('app.debug');
        if ($request->wantsJson()) {
            return Response::json([
                'error' => [
                    'type' => 'server_error',
                    'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.',
                    'where' => $debug ? $e->getFile() . ':' . $e->getLine() : null,
                ],
            ], 500);
        }
        if ($debug) {
            $body = '<pre style="padding:20px;font:14px/1.5 monospace;color:#b00">'
                . e($e->getMessage()) . "\n\n" . e($e->getTraceAsString()) . '</pre>';
            return Response::html($body, 500);
        }
        return Response::html(View::exists('errors/500') ? view('errors/500') : 'Server error', 500);
    }

    /**
     * Security headers. The public tracker endpoint (/collect, /b.js) is
     * cross-origin by design and sets its own permissive CORS in the handler;
     * everything else (the dashboard) is locked to same-origin.
     */
    private function applySecurityHeaders(Request $request, Response $response): void
    {
        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');

        // Only apply the strict CSP + HSTS to first-party HTML (the dashboard).
        $path = $request->path();
        if ($path === '/collect' || $path === '/b.js') {
            return;
        }
        $response
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "img-src 'self' data:",
                "style-src 'self' 'unsafe-inline'",
                "script-src 'self' 'unsafe-inline'",
                "connect-src 'self'",
                "font-src 'self'",
            ]))
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }
}
