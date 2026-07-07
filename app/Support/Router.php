<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Simple regex-free router with named parameters ({id}) and middleware hooks.
 * Routes are matched in registration order.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:mixed,middleware:array}> */
    private array $routes = [];
    /** @var array<string,callable> */
    private array $middleware = [];
    private array $groupStack = [];

    public function registerMiddleware(string $name, callable $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function options(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('OPTIONS', $path, $handler, $middleware);
    }

    public function group(array $options, callable $callback): void
    {
        $this->groupStack[] = $options;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'] ?? '';
            $groupMiddleware = array_merge($groupMiddleware, $group['middleware'] ?? []);
        }
        $pattern = rtrim($prefix . $path, '/');
        if ($pattern === '') {
            $pattern = '/';
        }
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            'handler'    => $handler,
            'middleware' => array_merge($groupMiddleware, $middleware),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[$route['method']] = true;
                continue;
            }

            foreach ($route['middleware'] as $name) {
                if (!isset($this->middleware[$name])) {
                    continue;
                }
                $result = ($this->middleware[$name])($request, $params);
                if ($result instanceof Response) {
                    return $result;
                }
                if (is_array($result)) {
                    $params = array_merge($params, $result);
                }
            }

            return $this->runHandler($route['handler'], $request, $params);
        }

        if ($allowed) {
            return Response::json([
                'error' => ['type' => 'method_not_allowed', 'message' => 'Method not allowed.'],
            ], 405)->withHeader('Allow', implode(', ', array_keys($allowed)));
        }

        return $this->notFound($request);
    }

    /** @return array<string,string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $i => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $params[trim($part, '{}')] = rawurldecode($pathParts[$i]);
                continue;
            }
            if ($part !== $pathParts[$i]) {
                return null;
            }
        }
        return $params;
    }

    private function runHandler(mixed $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $result = $controller->$method($request, $params);
        } else {
            $result = $handler($request, $params);
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::noContent();
    }

    private function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json([
                'error' => ['type' => 'not_found', 'message' => 'Resource not found.'],
            ], 404);
        }
        return Response::html(view('errors/404'), 404);
    }
}
