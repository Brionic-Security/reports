<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env loader and typed accessor. Values are parsed once and cached.
 */
final class Env
{
    /** @var array<string,mixed> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$vars[trim($key)] = self::clean(trim($value));
        }
    }

    private static function clean(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if ($first === '"' || $first === "'") {
            $end = strrpos($value, $first);
            if ($end > 0) {
                return substr($value, 1, $end - 1);
            }
        }
        if ($first === '#') {
            return '';
        }
        if (preg_match('/\s+#/', $value)) {
            $value = (string) preg_split('/\s+#/', $value, 2)[0];
        }
        return trim($value);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$vars[$key] ?? $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$vars;
    }
}
