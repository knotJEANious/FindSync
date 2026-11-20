<?php

declare(strict_types=1);

namespace FindSync\Repositories;

use PDO;

final class ImportRepository extends Repository
{
    /** @param array<string, mixed> $data */
    public function createBatch(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_batches '
            . '(source_type, filename, total_rows, accepted_rows, rejected_rows, created_by_user_id, created_at) '
            . 'VALUES (:source_type, :filename, :total_rows, :accepted_rows, :rejected_rows, '
            . ':created_by_user_id, :created_at)'
        );
        $stmt->execute([
            ':source_type' => (string) $data['source_type'],
            ':filename' => (string) $data['filename'],
            ':total_rows' => (int) $data['total_rows'],
            ':accepted_rows' => (int) $data['accepted_rows'],
            ':rejected_rows' => (int) $data['rejected_rows'],
            ':created_by_user_id' => (int) $data['created_by_user_id'],
            ':created_at' => (string) $data['created_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addError(int $batchId, int $rowNumber, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_errors (batch_id, row_number, reason, created_at) '
            . 'VALUES (:batch_id, :row_number, :reason, :created_at)'
        );
        $stmt->execute([
            ':batch_id' => $batchId,
            ':row_number' => $rowNumber,
            ':reason' => $reason,
            ':created_at' => now_utc(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT b.id, b.source_type, b.filename, b.total_rows, b.accepted_rows, b.rejected_rows, '
            . 'b.created_by_user_id, b.created_at, '
            . '(SELECT COUNT(*) FROM import_errors e WHERE e.batch_id = b.id) AS error_count '
            . 'FROM import_batches b '
            . 'ORDER BY b.id DESC'
        );

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'source_type' => (string) $row['source_type'],
                'filename' => (string) $row['filename'],
                'total_rows' => (int) $row['total_rows'],
                'accepted_rows' => (int) $row['accepted_rows'],
                'rejected_rows' => (int) $row['rejected_rows'],
                'created_by_user_id' => $row['created_by_user_id'] === null
                    ? null : (int) $row['created_by_user_id'],
                'created_at' => (string) $row['created_at'],
                'error_count' => (int) $row['error_count'],
            ];
        }, $stmt->fetchAll());
    }
}
