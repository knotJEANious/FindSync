<?php

declare(strict_types=1);

namespace FindSync\Core;

final class NotFoundException extends AppException
{
    public function __construct(string $message = 'Record not found.')
    {
        parent::__construct($message, 'NOT_FOUND', 404);
    }
}
