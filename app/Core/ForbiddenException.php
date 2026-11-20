<?php

declare(strict_types=1);

namespace FindSync\Core;

final class ForbiddenException extends AppException
{
    public function __construct(string $message = 'You are not allowed to perform this action.', string $code = 'FORBIDDEN')
    {
        parent::__construct($message, $code, 403);
    }
}
