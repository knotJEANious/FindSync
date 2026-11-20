<?php

declare(strict_types=1);

namespace FindSync\Middleware;

use FindSync\Core\ForbiddenException;
use FindSync\Core\UnauthenticatedException;

final class AuthMiddleware
{
    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed> */
    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw new UnauthenticatedException();
        }
        return $user;
    }

    public static function userId(): int
    {
        return (int) (self::requireUser()['id'] ?? 0);
    }

    public static function studentId(): ?int
    {
        $value = self::user()['student_id'] ?? null;
        return $value === null ? null : (int) $value;
    }

    public static function role(): string
    {
        return (string) (self::user()['role'] ?? '');
    }

    public static function isStaff(): bool
    {
        return self::role() === 'staff';
    }

    public static function requireStaff(): void
    {
        if (!self::isStaff()) {
            throw new ForbiddenException('Staff access required.');
        }
    }

    public static function requireOwnedStudent(int $studentId): void
    {
        if (self::isStaff()) {
            return;
        }
        if (self::studentId() !== $studentId) {
            throw new ForbiddenException('You may only access your own records.');
        }
    }
}
