<?php

declare(strict_types=1);

namespace FindSync\Core;

final class ValidationException extends AppException
{
    /** @param array<string, mixed> $errors */
    public function __construct(string $message = 'Validation failed.', array $errors = [])
    {
        parent::__construct($message, 'VALIDATION_ERROR', 422, $errors);
    }
}
