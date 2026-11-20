<?php

declare(strict_types=1);

namespace FindSync\Repositories;

final class UserRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, student_id, username, role, password_hash, created_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, student_id, username, role, password_hash, created_at FROM users WHERE username = :username');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByStudentId(int $studentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, student_id, username, role, password_hash, created_at FROM users WHERE student_id = :student_id');
        $stmt->execute([':student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function create(int $studentId, string $username, string $passwordHash, string $role = 'student'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (student_id, username, password_hash, role, created_at) VALUES (:student_id, :username, :hash, :role, :created_at)'
        );
        $stmt->execute([
            ':student_id' => $studentId,
            ':username' => $username,
            ':hash' => $passwordHash,
            ':role' => $role,
            ':created_at' => now_utc(),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
