<?php

declare(strict_types=1);

namespace FindSync\Repositories;

final class ReleaseRepository extends Repository
{
    public function existsForLost(int $lostId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM releases WHERE lost_report_id = :lost_report_id LIMIT 1');
        $stmt->execute([':lost_report_id' => $lostId]);
        return $stmt->fetchColumn() !== false;
    }

    public function existsForFound(int $foundId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM releases WHERE found_item_id = :found_item_id LIMIT 1');
        $stmt->execute([':found_item_id' => $foundId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO releases '
            . '(claim_request_id, lost_report_id, found_item_id, released_by_user_id, released_at) '
            . 'VALUES (:claim_request_id, :lost_report_id, :found_item_id, :released_by_user_id, :released_at)'
        );
        $stmt->execute([
            ':claim_request_id' => (int) ($data['claim_request_id'] ?? 0),
            ':lost_report_id' => (int) ($data['lost_report_id'] ?? 0),
            ':found_item_id' => (int) ($data['found_item_id'] ?? 0),
            ':released_by_user_id' => (int) ($data['released_by_user_id'] ?? 0),
            ':released_at' => (string) ($data['released_at'] ?? now_utc()),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
