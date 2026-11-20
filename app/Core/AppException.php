<?php

declare(strict_types=1);

namespace FindSync\Core;

class AppException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $errors
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }
}
