<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Identifier helpers producing prefixed resource IDs and secure tokens.
 */
final class Id
{
    public static function generate(string $prefix, int $length = 24): string
    {
        return $prefix . '_' . self::randomLower($length);
    }

    public static function randomLower(int $length): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
