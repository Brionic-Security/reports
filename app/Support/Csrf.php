<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSRF token generation and verification.
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf', $token);
        }
        return $token;
    }

    public static function verify(?string $token): bool
    {
        $stored = Session::get('_csrf');
        return is_string($stored) && is_string($token) && hash_equals($stored, $token);
    }
}
