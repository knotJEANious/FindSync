<?php

declare(strict_types=1);

namespace FindSync\Repositories;

use FindSync\Services\MatchingService;
use PDO;
use Throwable;

final class DashboardRepository extends Repository
{
    /** @return array<string, int> */
    public function staffCounts(): array
    {
        $openLostStmt = $this->pdo->prepare('SELECT COUNT(*) FROM lost_reports WHERE status = :status');
        $openLostStmt->execute([':status' => 'open']);

        $availableFoundStmt = $this->pdo->prepare('SELECT COUNT(*) FROM found_items WHERE status = :status');
        $availableFoundStmt->execute([':status' => 'available']);

        $releasedClaimsStmt = $this->pdo->prepare('SELECT COUNT(*) FROM claim_requests WHERE status = :status');
        $releasedClaimsStmt->execute([':status' => 'released']);

        return [
            'open_lost' => (int) $openLostStmt->fetchColumn(),
            'available_found' => (int) $availableFoundStmt->fetchColumn(),
            'candidate_matches' => $this->candidateMatchCount(),
            'completed_claims' => (int) $releasedClaimsStmt->fetchColumn(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function recentActivity(int $limit = 8): array
    {
        $logs = (new AuditRepository())->all($limit);

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'entity_type' => (string) $row['entity_type'],
                'entity_id' => $row['entity_id'] === null ? null : (string) $row['entity_id'],
                'created_at' => (string) $row['created_at'],
                'actor_username' => $row['actor_username'] === null ? null : (string) $row['actor_username'],
            ];
        }, $logs);
    }

    /** @return array<int, array<string, mixed>> */
    public function latestImports(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, source_type, filename, total_rows, accepted_rows, rejected_rows, created_at
             FROM import_batches
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'source_type' => (string) $row['source_type'],
                'filename' => (string) $row['filename'],
                'total_rows' => (int) $row['total_rows'],
                'accepted_rows' => (int) $row['accepted_rows'],
                'rejected_rows' => (int) $row['rejected_rows'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    /** @return array<string, int> */
    public function studentCounts(int $studentId): array
    {
        $lostStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM lost_reports WHERE student_id = :student_id AND status = :status'
        );
        $lostStmt->execute([':student_id' => $studentId, ':status' => 'open']);

        $foundStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM found_items WHERE finder_student_id = :student_id AND status = :status'
        );
        $foundStmt->execute([':student_id' => $studentId, ':status' => 'available']);

        $pendingStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM claim_requests c
             INNER JOIN lost_reports l ON l.id = c.lost_report_id
             WHERE l.student_id = :student_id AND c.status = :status'
        );
        $pendingStmt->execute([':student_id' => $studentId, ':status' => 'pending']);

        $releasedStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM claim_requests c
             INNER JOIN lost_reports l ON l.id = c.lost_report_id
             WHERE l.student_id = :student_id AND c.status = :status'
        );
        $releasedStmt->execute([':student_id' => $studentId, ':status' => 'released']);

        return [
            'my_lost_open' => (int) $lostStmt->fetchColumn(),
            'my_found_available' => (int) $foundStmt->fetchColumn(),
            'my_pending_claims' => (int) $pendingStmt->fetchColumn(),
            'my_released_claims' => (int) $releasedStmt->fetchColumn(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function studentRecent(int $studentId, int $limit = 8): array
    {
        $limit = max(1, $limit);
        $activity = [];

        $claimStmt = $this->pdo->prepare(
            'SELECT c.id, c.status, c.created_at, c.lost_report_id, c.found_item_id
             FROM claim_requests c
             INNER JOIN lost_reports l ON l.id = c.lost_report_id
             WHERE l.student_id = :student_id
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT :limit'
        );
        $claimStmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $claimStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $claimStmt->execute();

        foreach ($claimStmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $activity[] = [
                'type' => 'claim',
                'label' => 'Claim #' . $id,
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'id' => $id,
                'lost_report_id' => (int) $row['lost_report_id'],
                'found_item_id' => (int) $row['found_item_id'],
            ];
        }

        $lostStmt = $this->pdo->prepare(
            'SELECT id, status, created_at FROM lost_reports
             WHERE student_id = :student_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $lostStmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $lostStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $lostStmt->execute();

        foreach ($lostStmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $activity[] = [
                'type' => 'lost_report',
                'label' => 'Lost report #' . $id,
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'id' => $id,
            ];
        }

        $foundStmt = $this->pdo->prepare(
            'SELECT id, status, created_at FROM found_items
             WHERE finder_student_id = :student_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $foundStmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $foundStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $foundStmt->execute();

        foreach ($foundStmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $activity[] = [
                'type' => 'found_item',
                'label' => 'Found item #' . $id,
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'id' => $id,
            ];
        }

        usort($activity, static function (array $a, array $b): int {
            $byCreatedAt = strcmp((string) $b['created_at'], (string) $a['created_at']);
            return $byCreatedAt !== 0 ? $byCreatedAt : ((int) $b['id'] <=> (int) $a['id']);
        });

        return array_slice($activity, 0, $limit);
    }

    private function candidateMatchCount(): int
    {
        try {
            $result = MatchingService::allForStaff();
            $matches = $result['matches'] ?? null;
            return is_array($matches) ? count($matches) : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
