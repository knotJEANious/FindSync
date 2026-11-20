<?php

declare(strict_types=1);

namespace FindSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use FindSync\Core\AppException;
use FindSync\Core\ConflictException;
use FindSync\Core\ForbiddenException;
use FindSync\Core\NotFoundException;
use FindSync\Core\ValidationException;
use FindSync\Database\Database;
use FindSync\Repositories\ClaimRepository;
use FindSync\Repositories\ReleaseRepository;
use PDOException;
use RuntimeException;
use Throwable;

final class ClaimService
{
    public static function create(
        int $lostReportId,
        int $foundItemId,
        string $evidence,
        int $requesterUserId,
        ?int $requesterStudentId
    ): array {
        $evidence = normalize_text($evidence);
        $length = mb_strlen($evidence);
        if ($length < 10 || $length > 2000) {
            throw new ValidationException(
                'Ownership evidence must be between 10 and 2000 characters.',
                ['ownership_evidence' => 'Provide 10–2000 characters describing how you own this item.']
            );
        }

        $lost = self::fetchRow(
            'SELECT id, student_id, category, status, event_date FROM lost_reports WHERE id = :id',
            [':id' => $lostReportId]
        );
        if ($lost === null) {
            throw new NotFoundException('Lost report not found.');
        }

        $found = self::fetchRow(
            'SELECT id, category, status, event_date FROM found_items WHERE id = :id',
            [':id' => $foundItemId]
        );
        if ($found === null) {
            throw new NotFoundException('Found item not found.');
        }

        if ($requesterStudentId === null || (int) $lost['student_id'] !== $requesterStudentId) {
            throw new ForbiddenException('You may only claim using your own lost report.');
        }
        if ((string) $lost['status'] !== 'open') {
            throw new ConflictException('This lost report is no longer open.', 'LOST_REPORT_NOT_OPEN');
        }
        if ((string) $found['status'] !== 'available') {
            throw new ConflictException('This found item is no longer available.', 'FOUND_ITEM_NOT_AVAILABLE');
        }
        if ((string) $lost['category'] !== (string) $found['category']) {
            throw new ValidationException(
                'The found item category must match your lost report.',
                ['found_item_id' => 'Category mismatch.']
            );
        }

        $utc = new DateTimeZone('UTC');
        $lossDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $lost['event_date'], $utc);
        $foundDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $found['event_date'], $utc);
        if ($lossDate !== false && $foundDate !== false && $foundDate < $lossDate->modify('-7 days')) {
            throw new ConflictException('Found item predates the loss; claim not allowed.', 'CLAIM_DATE_CONFLICT');
        }

        $claims = new ClaimRepository();

        Database::begin();
        try {
            if ($claims->activeClaimForFound($foundItemId) !== null) {
                throw new ConflictException('This found item already has an active claim.', 'CLAIM_ACTIVE');
            }
            if ($claims->existsActiveClaimForLost($lostReportId)) {
                throw new ConflictException('This lost report already has an active claim.', 'CLAIM_ACTIVE');
            }

            $now = now_utc();
            $claimId = $claims->create([
                'lost_report_id' => $lostReportId,
                'found_item_id' => $foundItemId,
                'requester_user_id' => $requesterUserId,
                'ownership_evidence' => $evidence,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $claims->insertLock($foundItemId, $claimId);

            // Only identifiers go to the audit log; evidence is never logged.
            AuditService::log(
                'CLAIM_REQUESTED',
                'claim',
                $claimId,
                'Claim ' . $claimId . ' submitted for lost report ' . $lostReportId . ' and found item ' . $foundItemId
            );

            Database::commit();
        } catch (PDOException $e) {
            Database::rollBack();
            if (self::isDuplicateKey($e)) {
                throw new ConflictException('This found item already has an active claim.', 'CLAIM_ACTIVE');
            }
            throw new RuntimeException('Claim request could not be created.', 0, $e);
        } catch (Throwable $e) {
            Database::rollBack();
            if ($e instanceof AppException) {
                throw $e;
            }
            throw new RuntimeException('Claim request could not be created.', 0, $e);
        }

        return ['claim' => $claims->findById($claimId)];
    }

    /** Approval only changes the claim; it never changes report status or allocates an item. */
    public static function approve(int $claimId, int $staffId, string $notes): array
    {
        $notes = safe_details($notes, 2000);
        $claims = new ClaimRepository();

        Database::begin();
        try {
            $claim = $claims->findForUpdate($claimId);
            if ($claim === null) {
                throw new NotFoundException('Claim request not found.');
            }
            if ((string) $claim['status'] !== 'pending') {
                throw new ConflictException('Only pending claims can be approved.', 'CLAIM_NOT_PENDING');
            }

            $updated = $claims->updateStatus(
                $claimId,
                'approved',
                $staffId,
                $notes === '' ? null : $notes,
                null,
                'pending'
            );
            if (!$updated) {
                throw new ConflictException('This claim changed while it was being reviewed.', 'CLAIM_CONFLICT');
            }

            AuditService::log(
                'CLAIM_APPROVED',
                'claim',
                $claimId,
                'Claim ' . $claimId . ' approved by staff ' . $staffId
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            if ($e instanceof AppException) {
                throw $e;
            }
            throw new RuntimeException('Claim approval could not be completed.', 0, $e);
        }

        return $claims->findById($claimId) ?? [];
    }

    /** Rejection releases the found-item lock so another student may claim it. */
    public static function reject(int $claimId, int $staffId, string $reason): array
    {
        $reason = safe_details($reason, 2000);
        if ($reason === '') {
            throw new ValidationException('A rejection reason is required.', ['reason' => 'Required.']);
        }

        $claims = new ClaimRepository();

        Database::begin();
        try {
            $claim = $claims->findForUpdate($claimId);
            if ($claim === null) {
                throw new NotFoundException('Claim request not found.');
            }
            $status = (string) $claim['status'];
            if (!in_array($status, ['pending', 'approved'], true)) {
                throw new ConflictException('This claim can no longer be rejected.', 'CLAIM_NOT_REJECTABLE');
            }

            $updated = $claims->updateStatus(
                $claimId,
                'rejected',
                $staffId,
                $reason,
                null,
                $status
            );
            if (!$updated) {
                throw new ConflictException('This claim changed while it was being reviewed.', 'CLAIM_CONFLICT');
            }

            $claims->deleteLock((int) $claim['found_item_id']);
            // The reason is stored as staff notes only; it is not written to the audit log.
            AuditService::log(
                'CLAIM_REJECTED',
                'claim',
                $claimId,
                'Claim ' . $claimId . ' rejected by staff ' . $staffId
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            if ($e instanceof AppException) {
                throw $e;
            }
            throw new RuntimeException('Claim rejection could not be completed.', 0, $e);
        }

        return $claims->findById($claimId) ?? [];
    }

    /**
     * THE CRITICAL PATH: allocate a found item to a lost report atomically.
     * Every guard and every write happens inside one transaction with row locks.
     *
     * @return array{claim: array<string, mixed>|null, release: array<string, mixed>}
     */
    public static function release(int $claimId, int $staffId): array
    {
        $pdo = Database::connection();
        $claims = new ClaimRepository();
        $releases = new ReleaseRepository();

        Database::begin();
        try {
            // 1. Lock the claim row first so concurrent releases serialize on it.
            $claimStmt = $pdo->prepare(
                'SELECT id, lost_report_id, found_item_id, status, staff_notes '
                . 'FROM claim_requests WHERE id = :id FOR UPDATE'
            );
            $claimStmt->execute([':id' => $claimId]);
            $claim = $claimStmt->fetch();
            if ($claim === false) {
                throw new NotFoundException('Claim request not found.');
            }

            $lostId = (int) $claim['lost_report_id'];
            $foundId = (int) $claim['found_item_id'];

            // 2. Lock both sides of the allocation.
            $lostStmt = $pdo->prepare('SELECT id, status FROM lost_reports WHERE id = :id FOR UPDATE');
            $lostStmt->execute([':id' => $lostId]);
            $lost = $lostStmt->fetch();
            if ($lost === false) {
                throw new ConflictException('The lost report no longer exists.', 'RELEASE_CONFLICT');
            }

            $foundStmt = $pdo->prepare('SELECT id, status FROM found_items WHERE id = :id FOR UPDATE');
            $foundStmt->execute([':id' => $foundId]);
            $found = $foundStmt->fetch();
            if ($found === false) {
                throw new ConflictException('The found item no longer exists.', 'RELEASE_CONFLICT');
            }

            // 3. Re-verify all business guards while the rows are locked.
            if ((string) $claim['status'] !== 'approved') {
                throw new ConflictException('Only approved claims can be released.', 'RELEASE_CONFLICT');
            }
            if ((string) $lost['status'] !== 'open') {
                throw new ConflictException('The lost report is no longer open.', 'RELEASE_CONFLICT');
            }
            if ((string) $found['status'] !== 'available') {
                throw new ConflictException('The found item is no longer available.', 'RELEASE_CONFLICT');
            }
            if ($releases->existsForLost($lostId) || $releases->existsForFound($foundId)) {
                throw new ConflictException('This item was already released or changed concurrently.', 'RELEASE_CONFLICT');
            }

            // 4. Write the immutable release record (unique indexes are the final guard),
            //    then flip all three statuses. Any failure rolls everything back.
            $releasedAt = now_utc();
            $releaseId = $releases->insert([
                'claim_request_id' => $claimId,
                'lost_report_id' => $lostId,
                'found_item_id' => $foundId,
                'released_by_user_id' => $staffId,
                'released_at' => $releasedAt,
            ]);

            $claimNotes = $claim['staff_notes'] === null ? null : (string) $claim['staff_notes'];
            $released = $claims->updateStatus(
                $claimId,
                'released',
                $staffId,
                $claimNotes,
                $releasedAt,
                'approved'
            );
            if (!$released) {
                throw new ConflictException('This claim changed during release.', 'RELEASE_CONFLICT');
            }

            $resolveLost = $pdo->prepare(
                "UPDATE lost_reports SET status = 'resolved', updated_at = :updated_at WHERE id = :id"
            );
            $resolveLost->execute([':updated_at' => $releasedAt, ':id' => $lostId]);

            $claimFound = $pdo->prepare(
                "UPDATE found_items SET status = 'claimed', updated_at = :updated_at WHERE id = :id"
            );
            $claimFound->execute([':updated_at' => $releasedAt, ':id' => $foundId]);

            // The allocation is complete, so this is no longer an active claim.
            $claims->deleteLock($foundId);

            AuditService::log('CLAIM_RELEASED', 'claim', $claimId, 'Release recorded for claim ' . $claimId);

            // 5. Commit only after every write succeeded.
            Database::commit();
        } catch (PDOException $e) {
            Database::rollBack();
            if (self::isConcurrentConflict($e)) {
                throw new ConflictException('This item was already released or changed concurrently.', 'RELEASE_CONFLICT');
            }
            throw new RuntimeException('Release could not be completed.', 0, $e);
        } catch (Throwable $e) {
            Database::rollBack();
            if ($e instanceof AppException) {
                throw $e;
            }
            throw new RuntimeException('Release could not be completed.', 0, $e);
        }

        $release = self::fetchRow(
            'SELECT id, claim_request_id, lost_report_id, found_item_id, released_by_user_id, released_at '
            . 'FROM releases WHERE id = :id',
            [':id' => $releaseId]
        ) ?? [
            'id' => $releaseId,
            'claim_request_id' => $claimId,
            'lost_report_id' => $lostId,
            'found_item_id' => $foundId,
            'released_by_user_id' => $staffId,
            'released_at' => $releasedAt,
        ];

        return ['claim' => $claims->findById($claimId), 'release' => $release];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private static function fetchRow(string $sql, array $params): ?array
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private static function isDuplicateKey(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        return $state === '23000' || in_array($driverCode, [19, 1062], true);
    }

    private static function isConcurrentConflict(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // 23000: duplicate/unique violation; 40001 + 1213: deadlock;
        // HY000 + 1205: lock wait timeout; 19/5/6: SQLite constraint/busy/locked.
        return in_array($state, ['23000', '40001', '40P01'], true)
            || in_array($driverCode, [5, 6, 19, 1062, 1205, 1213, 3572], true);
    }
}
