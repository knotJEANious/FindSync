<?php

declare(strict_types=1);

namespace FindSync\Repositories;

use PDO;

final class AuditRepository extends Repository
{
    /** @param array<string, mixed> $entry */
    public function insert(array $entry): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details, created_at)
             VALUES (:actor_user_id, :action, :entity_type, :entity_id, :details, :created_at)'
        );

        $actorUserId = $entry['actor_user_id'] ?? null;
        $stmt->bindValue(
            ':actor_user_id',
            $actorUserId === null ? null : (int) $actorUserId,
            $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );

        $entityId = $entry['entity_id'] ?? null;
        $stmt->bindValue(
            ':entity_id',
            $entityId === null ? null : safe_details($entityId, 64),
            $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );

        $details = $entry['details'] ?? null;
        $stmt->bindValue(
            ':details',
            $details === null ? null : safe_details($details, 500),
            $details === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );

        $stmt->bindValue(':action', safe_details($entry['action'] ?? '', 80), PDO::PARAM_STR);
        $stmt->bindValue(':entity_type', safe_details($entry['entity_type'] ?? '', 50), PDO::PARAM_STR);
        $stmt->bindValue(':created_at', (string) ($entry['created_at'] ?? now_utc()), PDO::PARAM_STR);

        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public function all(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.action,
                    a.entity_type, a.entity_id, a.details, a.created_at
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
                'actor_username' => $row['actor_username'] === null ? null : (string) $row['actor_username'],
                'action' => (string) $row['action'],
                'entity_type' => (string) $row['entity_type'],
                'entity_id' => $row['entity_id'] === null ? null : (string) $row['entity_id'],
                'details' => $row['details'] === null ? null : (string) $row['details'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }
}
