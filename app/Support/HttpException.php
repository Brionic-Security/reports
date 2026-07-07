<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HTTP exception carrying a status code and machine-readable error type.
 */
final class HttpException extends \RuntimeException
{
    public function __construct(int $status, string $message, public readonly string $errorType = 'error')
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, $message, 'not_found');
    }

    public static function unauthorized(string $message = 'Unauthorized.'): self
    {
        return new self(401, $message, 'unauthorized');
    }

    public static function forbidden(string $message = 'Forbidden.'): self
    {
        return new self(403, $message, 'forbidden');
    }

    public static function badRequest(string $message = 'Bad request.'): self
    {
        return new self(400, $message, 'bad_request');
    }

    public static function tooManyRequests(string $message = 'Too many requests.'): self
    {
        return new self(429, $message, 'rate_limited');
    }
}
