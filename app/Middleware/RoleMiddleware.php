<?php

declare(strict_types=1);

namespace FindSync\Middleware;

use FindSync\Core\ForbiddenException;

final class RoleMiddleware
{
    /** @param array<int, string> $roles */
    public static function requireRoles(array $roles): void
    {
        $user = AuthMiddleware::requireUser();
        if (!in_array((string) ($user['role'] ?? ''), $roles, true)) {
            throw new ForbiddenException('Your role cannot access this resource.');
        }
    }
}
