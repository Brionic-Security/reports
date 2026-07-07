<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session wrapper with flash-message support.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => str_starts_with((string) config('app.url'), 'https://'),
        ]);
        session_name('breports_session');
        session_start();
        self::ageFlash();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return $_SESSION ?? [];
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$key] ?? $default;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }

    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        self::flash('_old_input', $input);
    }

    public static function ageFlash(): void
    {
        $_SESSION['_flash'] = $_SESSION['_flash_next'] ?? [];
        $_SESSION['_flash_next'] = [];
    }
}
