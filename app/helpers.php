<?php

/**
 * Global helper functions (autoloaded via composer "files").
 */

declare(strict_types=1);

use App\Support\Config;
use App\Support\Csrf;
use App\Support\Env;
use App\Support\Session;
use App\Support\View;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $root = \dirname(__DIR__);
        return $path === '' ? $root : $root . '/' . ltrim($path, '/');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path === '' ? '' : '/' . ltrim($path, '/')));
    }
}

if (!function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return base_path('resources' . ($path === '' ? '' : '/' . ltrim($path, '/')));
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $rel = 'assets/' . ltrim($path, '/');
        $file = base_path('public/' . $rel);
        $url = app_url($rel);
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
        return $url;
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = []): string
    {
        return View::render($template, $data);
    }
}

if (!function_exists('session')) {
    function session(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? Session::all() : Session::get($key, $default);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        $old = Session::getFlash('_old_input', []);
        return $old[$key] ?? $default;
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('logger')) {
    function logger(string $message, array $context = []): void
    {
        $line = '[' . gmdate('c') . '] ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        @file_put_contents(storage_path('logs/app.log'), $line . "\n", FILE_APPEND);
    }
}

if (!function_exists('num')) {
    /** Compact, human-friendly integer formatting (1234 -> 1.2k). */
    function num(int $n): string
    {
        if ($n < 1000) {
            return (string) $n;
        }
        if ($n < 1000000) {
            return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k';
        }
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $ts): string
    {
        if (!$ts) {
            return '—';
        }
        $diff = time() - strtotime($ts . ' UTC');
        if ($diff < 60) {
            return 'just now';
        }
        foreach ([3600 => 'm', 86400 => 'h', 2592000 => 'd', PHP_INT_MAX => 'mo'] as $limit => $unit) {
            if ($diff < $limit) {
                $value = match ($unit) {
                    'm' => intdiv($diff, 60),
                    'h' => intdiv($diff, 3600),
                    'd' => intdiv($diff, 86400),
                    default => intdiv($diff, 2592000),
                };
                return $value . $unit . ' ago';
            }
        }
        return $ts;
    }
}

if (!function_exists('date_range_bounds')) {
    /**
     * Resolve a date filter to [fromSql, toSql, label]. Custom from/to dates
     * (YYYY-MM-DD) take precedence over a preset (24h|7d|30d|90d|all).
     *
     * @return array{0:?string,1:?string,2:string}
     */
    function date_range_bounds(string $preset, ?string $from, ?string $to): array
    {
        $valid = static fn ($d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        if ($valid($from) || $valid($to)) {
            return [
                $valid($from) ? $from . ' 00:00:00' : null,
                $valid($to) ? gmdate('Y-m-d H:i:s', (int) strtotime($to . ' +1 day')) : null,
                'custom',
            ];
        }
        $secs = ['24h' => 86400, '7d' => 604800, '30d' => 2592000, '90d' => 7776000];
        if (isset($secs[$preset])) {
            return [gmdate('Y-m-d H:i:s', time() - $secs[$preset]), null, $preset];
        }
        return [null, null, 'all'];
    }
}

if (!function_exists('plugin_version')) {
    /** Current WordPress plugin version, read from its main file header. */
    function plugin_version(): string
    {
        static $v = null;
        if ($v !== null) {
            return $v;
        }
        $v = '';
        $file = base_path('plugins/wordpress/brionic-analytics/brionic-analytics.php');
        if (is_file($file)) {
            $head = (string) file_get_contents($file, false, null, 0, 4096);
            if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m)) {
                $v = trim($m[1]);
            }
        }
        return $v;
    }
}
