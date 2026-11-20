<?php

declare(strict_types=1);

namespace FindSync\Repositories;

final class StudentRepository extends Repository
{
    /** @return array<int, array<string, mixed>> */
    public function all(?string $search = null): array
    {
        $sql = 'SELECT id, student_number, full_name, program, created_at FROM students';
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= ' WHERE student_number LIKE :q OR full_name LIKE :q OR program LIKE :q';
            $params[':q'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY student_number ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, student_number, full_name, program, created_at FROM students WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByStudentNumber(string $studentNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, student_number, full_name, program, created_at FROM students WHERE student_number = :number');
        $stmt->execute([':number' => $studentNumber]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function existsStudentNumber(string $studentNumber): bool
    {
        return $this->findByStudentNumber($studentNumber) !== null;
    }

    public function create(string $studentNumber, string $fullName, string $program): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO students (student_number, full_name, program, created_at) VALUES (:number, :name, :program, :created_at)'
        );
        $stmt->execute([
            ':number' => $studentNumber,
            ':name' => $fullName,
            ':program' => $program,
            ':created_at' => now_utc(),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateProfile(int $id, string $fullName, string $program): bool
    {
        $stmt = $this->pdo->prepare('UPDATE students SET full_name = :name, program = :program WHERE id = :id');
        $stmt->execute([':name' => $fullName, ':program' => $program, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
