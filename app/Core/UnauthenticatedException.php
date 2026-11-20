<?php

declare(strict_types=1);

namespace FindSync\Core;

final class UnauthenticatedException extends AppException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message, 'UNAUTHENTICATED', 401);
    }
}
