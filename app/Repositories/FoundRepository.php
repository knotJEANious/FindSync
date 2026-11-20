<?php

declare(strict_types=1);

namespace FindSync\Repositories;

/** Read/write access to found_items with joined finder details. */
final class FoundRepository extends Repository
{
    /** @return array<int, array<string, mixed>> */
    public function allFor(?int $finderStudentId, ?string $search): array
    {
        $sql = 'SELECT f.id, f.finder_student_id, s.student_number AS finder_number, '
            . 's.full_name AS finder_name, f.category, f.description, f.location, f.event_date, '
            . 'f.status, f.source, f.created_at, f.updated_at '
            . 'FROM found_items f LEFT JOIN students s ON s.id = f.finder_student_id';
        $params = [];
        $where = [];

        if ($finderStudentId !== null) {
            $where[] = 'f.finder_student_id = :finder_student_id';
            $params[':finder_student_id'] = $finderStudentId;
        }

        if ($search !== null && $search !== '') {
            // Distinct placeholders: native PDO prepares cannot reuse one named parameter.
            $where[] = '(f.category LIKE :q1 OR f.description LIKE :q2 OR f.location LIKE :q3 '
                . 'OR s.student_number LIKE :q4 OR s.full_name LIKE :q5)';
            $like = '%' . $search . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
            $params[':q5'] = $like;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY f.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.id, f.finder_student_id, s.student_number AS finder_number, '
            . 's.full_name AS finder_name, f.category, f.description, f.location, f.event_date, '
            . 'f.status, f.source, f.created_at, f.updated_at '
            . 'FROM found_items f LEFT JOIN students s ON s.id = f.finder_student_id '
            . 'WHERE f.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findDuplicate(?int $finderStudentId, string $description, string $date, string $location): bool
    {
        $where = $finderStudentId === null
            ? 'finder_student_id IS NULL'
            : 'finder_student_id = :finder_student_id';
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM found_items WHERE ' . $where . ' AND description = :description '
            . 'AND event_date = :event_date AND location = :location LIMIT 1'
        );
        $params = [
            ':description' => normalize_text($description),
            ':event_date' => normalize_text($date),
            ':location' => normalize_text($location),
        ];
        if ($finderStudentId !== null) {
            $params[':finder_student_id'] = $finderStudentId;
        }
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = (string) ($data['created_at'] ?? now_utc());
        $stmt = $this->pdo->prepare(
            'INSERT INTO found_items '
            . '(finder_student_id, category, description, location, event_date, status, source, '
            . 'created_by_user_id, created_at, updated_at) '
            . 'VALUES (:finder_student_id, :category, :description, :location, :event_date, :status, :source, '
            . ':created_by_user_id, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':finder_student_id' => isset($data['finder_student_id'])
                ? (int) $data['finder_student_id'] : null,
            ':category' => (string) $data['category'],
            ':description' => (string) $data['description'],
            ':location' => (string) $data['location'],
            ':event_date' => (string) $data['event_date'],
            ':status' => (string) ($data['status'] ?? 'available'),
            ':source' => (string) ($data['source'] ?? 'web'),
            ':created_by_user_id' =>
                isset($data['created_by_user_id']) ? (int) $data['created_by_user_id'] : null,
            ':created_at' => $now,
            ':updated_at' => (string) ($data['updated_at'] ?? $now),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
