<?php

declare(strict_types=1);

namespace FindSync\Core;

final class ConflictException extends AppException
{
    public function __construct(string $message = 'The record was changed by another action.', string $code = 'CONFLICT')
    {
        parent::__construct($message, $code, 409);
    }
}
