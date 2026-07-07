<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Session;

/**
 * Single-operator authentication. Credentials live in the environment
 * (config/auth.php → ADMIN_EMAIL + ADMIN_PASSWORD_HASH), so there is no user
 * table to compromise. Multi-user support can be added later.
 */
final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $adminEmail = (string) config('auth.admin.email');
        $hash = (string) config('auth.admin.password_hash');

        if ($adminEmail === '' || $hash === '') {
            return false;
        }
        if (!hash_equals(strtolower($adminEmail), strtolower(trim($email)))) {
            // Still run password_verify to keep timing uniform.
            password_verify($password, $hash);
            return false;
        }
        if (!password_verify($password, $hash)) {
            return false;
        }

        Session::regenerate();
        Session::put('operator', $adminEmail);
        return true;
    }

    public static function check(): bool
    {
        return Session::get('operator') !== null;
    }

    public static function email(): string
    {
        return (string) Session::get('operator', '');
    }

    public static function logout(): void
    {
        Session::forget('operator');
        Session::regenerate();
    }
}
