<?php

declare(strict_types=1);

namespace FindSync\Services;

use FindSync\Core\AppException;
use FindSync\Core\ConflictException;
use FindSync\Core\ValidationException;
use FindSync\Database\Database;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\StudentRepository;
use FindSync\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class AuthService
{
    /** @param array<string, mixed> $data */
    public static function register(array $data): array
    {
        $studentNumber = normalize_text($data['student_number'] ?? $data['studentNumber'] ?? '');
        $fullName = normalize_text($data['full_name'] ?? $data['name'] ?? '');
        $program = normalize_text($data['program'] ?? '');
        $username = normalize_text($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9-]{3,32}$/', $studentNumber)) {
            $errors['student_number'] = 'Use 3–32 letters, numbers or hyphens.';
        }
        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Full name must be 3–120 characters.';
        }
        if (mb_strlen($program) < 2 || mb_strlen($program) > 120) {
            $errors['program'] = 'Program must be 2–120 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)) {
            $errors['username'] = 'Username must be 3–80 letters, numbers, dots, underscores or hyphens.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please correct the registration fields.', $errors);
        }

        $users = new UserRepository();
        $students = new StudentRepository();
        if ($users->usernameExists($username)) {
            throw new ValidationException('That username is already taken.', ['username' => 'Already taken.']);
        }
        if ($students->existsStudentNumber($studentNumber)) {
            throw new ConflictException('That student number is already registered.', 'DUPLICATE_STUDENT_NUMBER');
        }

        Database::begin();
        try {
            $studentId = $students->create($studentNumber, $fullName, $program);
            $userId = $users->create($studentId, $username, password_hash($password, PASSWORD_DEFAULT), 'student');

            AuditService::log('STUDENT_REGISTERED', 'user', $userId, 'Student account created for ' . $studentNumber);
            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            if ($e instanceof AppException) {
                throw $e;
            }
            throw new RuntimeException('Registration could not be completed.', 0, $e);
        }

        return [
            'id' => $userId,
            'username' => $username,
            'role' => 'student',
            'student_id' => $studentId,
            'student_number' => $studentNumber,
            'full_name' => $fullName,
        ];
    }

    public static function login(string $username, string $password): array
    {
        $username = normalize_text($username);
        if ($username === '' || $password === '') {
            throw new ValidationException('Username and password are required.');
        }

        $user = (new UserRepository())->findByUsername($username);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw new ValidationException('Invalid username or password.');
        }

        session_regenerate_id(true);
        // Rotate CSRF at the privilege boundary; the UI refetches auth/csrf after login.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'student_id' => $user['student_id'] === null ? null : (int) $user['student_id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
        ];

        AuditService::log('LOGIN', 'user', (int) $user['id'], 'User signed in');
        return self::me() ?? [];
    }

    public static function logout(): void
    {
        $user = AuthMiddleware::user();
        if ($user !== null) {
            AuditService::log('LOGOUT', 'user', (int) ($user['id'] ?? 0), 'User signed out');
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /** @return array<string, mixed>|null */
    public static function me(): ?array
    {
        $user = AuthMiddleware::user();
        if ($user === null) {
            return null;
        }

        $result = [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'student_id' => $user['student_id'] === null ? null : (int) $user['student_id'],
            'student_number' => null,
            'full_name' => null,
            'program' => null,
        ];

        if ($result['student_id'] !== null) {
            $student = (new StudentRepository())->findById($result['student_id']);
            if ($student !== null) {
                $result['student_number'] = $student['student_number'];
                $result['full_name'] = $student['full_name'];
                $result['program'] = $student['program'];
            }
        }

        return $result;
    }
}
