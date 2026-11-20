<?php

declare(strict_types=1);

namespace FindSync\Middleware;

use FindSync\Core\ForbiddenException;

final class CsrfMiddleware
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validate(?string $token): void
    {
        $expected = self::token();
        if ($token === null || !hash_equals($expected, $token)) {
            throw new ForbiddenException('Invalid or missing CSRF token.', 'CSRF_INVALID');
        }
    }
}
