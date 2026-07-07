<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Thrown when validation fails. Carries a map of field => first error message.
 */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The given data was invalid.', 422);
    }
}
