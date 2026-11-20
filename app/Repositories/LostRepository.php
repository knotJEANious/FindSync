<?php

declare(strict_types=1);

namespace FindSync\Repositories;

/** Read/write access to lost_reports with joined student details. */
final class LostRepository extends Repository
{
    /** @return array<int, array<string, mixed>> */
    public function allFor(?int $studentId, ?string $search): array
    {
        $sql = 'SELECT l.id, l.student_id, s.student_number, s.full_name AS student_name, '
            . 'l.category, l.description, l.location, l.event_date, l.status, l.source, '
            . 'l.created_at, l.updated_at '
            . 'FROM lost_reports l LEFT JOIN students s ON s.id = l.student_id';
        $params = [];
        $where = [];

        if ($studentId !== null) {
            $where[] = 'l.student_id = :student_id';
            $params[':student_id'] = $studentId;
        }

        if ($search !== null && $search !== '') {
            // Distinct placeholders: native PDO prepares cannot reuse one named parameter.
            $where[] = '(l.category LIKE :q1 OR l.description LIKE :q2 OR l.location LIKE :q3 '
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
        $sql .= ' ORDER BY l.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.student_id, s.student_number, s.full_name AS student_name, '
            . 'l.category, l.description, l.location, l.event_date, l.status, l.source, '
            . 'l.created_at, l.updated_at '
            . 'FROM lost_reports l LEFT JOIN students s ON s.id = l.student_id '
            . 'WHERE l.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findDuplicate(int $studentId, string $description, string $date, string $location): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM lost_reports '
            . 'WHERE student_id = :student_id AND description = :description '
            . 'AND event_date = :event_date AND location = :location LIMIT 1'
        );
        $stmt->execute([
            ':student_id' => $studentId,
            ':description' => normalize_text($description),
            ':event_date' => normalize_text($date),
            ':location' => normalize_text($location),
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = (string) ($data['created_at'] ?? now_utc());
        $stmt = $this->pdo->prepare(
            'INSERT INTO lost_reports '
            . '(student_id, category, description, location, event_date, status, source, '
            . 'created_by_user_id, created_at, updated_at) '
            . 'VALUES (:student_id, :category, :description, :location, :event_date, :status, :source, '
            . ':created_by_user_id, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':student_id' => (int) $data['student_id'],
            ':category' => (string) $data['category'],
            ':description' => (string) $data['description'],
            ':location' => (string) $data['location'],
            ':event_date' => (string) $data['event_date'],
            ':status' => (string) ($data['status'] ?? 'open'),
            ':source' => (string) ($data['source'] ?? 'web'),
            ':created_by_user_id' =>
                isset($data['created_by_user_id']) ? (int) $data['created_by_user_id'] : null,
            ':created_at' => $now,
            ':updated_at' => (string) ($data['updated_at'] ?? $now),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
