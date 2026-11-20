<?php

declare(strict_types=1);

namespace FindSync\Repositories;

final class ClaimRepository extends Repository
{
    private const CLAIM_COLUMNS = 'c.*, '
        . 'l.description AS lost_description, '
        . 'l.student_id AS lost_student_id, '
        . 's.student_number AS lost_student_number, '
        . 'f.description AS found_description, '
        . 'f.finder_student_id AS found_finder_student_id, '
        . 'u.username AS requester_username';

    private const CLAIM_JOINS = 'FROM claim_requests c '
        . 'JOIN lost_reports l ON l.id = c.lost_report_id '
        . 'JOIN found_items f ON f.id = c.found_item_id '
        . 'JOIN users u ON u.id = c.requester_user_id '
        . 'JOIN students s ON s.id = l.student_id';

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CLAIM_COLUMNS . ' ' . self::CLAIM_JOINS . ' WHERE c.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function allFor(?int $requesterUserId, bool $staff): array
    {
        $sql = 'SELECT ' . self::CLAIM_COLUMNS . ' ' . self::CLAIM_JOINS;
        $params = [];

        if ($staff) {
            // Staff see every claim.
        } elseif ($requesterUserId === null) {
            // Deny by default: an unscoped student request must see nothing.
            $sql .= ' WHERE 1 = 0';
        } else {
            $sql .= ' WHERE c.requester_user_id = :requester_user_id';
            $params[':requester_user_id'] = $requesterUserId;
        }

        $sql .= ' ORDER BY c.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function activeClaimForFound(int $foundItemId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, lost_report_id, found_item_id, requester_user_id, status, created_at "
            . "FROM claim_requests "
            . "WHERE found_item_id = :found_item_id AND status IN ('pending', 'approved') "
            . "LIMIT 1"
        );
        $stmt->execute([':found_item_id' => $foundItemId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Read a claim with a row lock; must be called inside an active transaction. */
    public function findForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, lost_report_id, found_item_id, status '
            . 'FROM claim_requests WHERE id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function existsActiveClaimForLost(int $lostReportId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM claim_requests "
            . "WHERE lost_report_id = :lost_report_id AND status IN ('pending', 'approved') "
            . "LIMIT 1"
        );
        $stmt->execute([':lost_report_id' => $lostReportId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO claim_requests '
            . '(lost_report_id, found_item_id, requester_user_id, ownership_evidence, created_at, updated_at) '
            . 'VALUES (:lost_report_id, :found_item_id, :requester_user_id, :ownership_evidence, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':lost_report_id' => (int) ($data['lost_report_id'] ?? 0),
            ':found_item_id' => (int) ($data['found_item_id'] ?? 0),
            ':requester_user_id' => (int) ($data['requester_user_id'] ?? 0),
            ':ownership_evidence' => (string) ($data['ownership_evidence'] ?? ''),
            ':created_at' => (string) ($data['created_at'] ?? now_utc()),
            ':updated_at' => (string) ($data['updated_at'] ?? now_utc()),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(
        int $id,
        string $status,
        ?int $reviewerId,
        ?string $staffNotes,
        ?string $releasedAt,
        ?string $expectedStatus = null
    ): bool {
        $sql = 'UPDATE claim_requests SET '
            . 'status = :status, '
            . 'reviewed_by_user_id = :reviewed_by_user_id, '
            . 'reviewed_at = :reviewed_at, '
            . 'staff_notes = :staff_notes, '
            . 'released_at = :released_at, '
            . 'updated_at = :updated_at '
            . 'WHERE id = :id';
        $params = [
            ':status' => $status,
            ':reviewed_by_user_id' => $reviewerId,
            ':reviewed_at' => now_utc(),
            ':staff_notes' => $staffNotes,
            ':released_at' => $releasedAt,
            ':updated_at' => now_utc(),
            ':id' => $id,
        ];

        // Check-and-write in one statement so a stale approve/reject cannot
        // overwrite a status changed by another request after our read.
        if ($expectedStatus !== null) {
            $sql .= ' AND status = :expected_status';
            $params[':expected_status'] = $expectedStatus;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function insertLock(int $foundItemId, int $claimId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO found_claim_locks (found_item_id, claim_request_id, created_at) '
            . 'VALUES (:found_item_id, :claim_request_id, :created_at)'
        );
        $stmt->execute([
            ':found_item_id' => $foundItemId,
            ':claim_request_id' => $claimId,
            ':created_at' => now_utc(),
        ]);
    }

    public function deleteLock(int $foundItemId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM found_claim_locks WHERE found_item_id = :found_item_id');
        $stmt->execute([':found_item_id' => $foundItemId]);
    }
}
