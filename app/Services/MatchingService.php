<?php

declare(strict_types=1);

namespace FindSync\Services;

use DateTimeImmutable;
use DateTimeZone;
use FindSync\Config\Config;
use FindSync\Core\NotFoundException;
use FindSync\Database\Database;
use FindSync\Middleware\AuthMiddleware;
use PDO;

final class MatchingService
{
    public const POLICY = 'Heuristic rule-based score, not proof of ownership or calibrated probability. Found items dated more than 7 days before the loss are excluded.';

    /** @var array<string, true> */
    private const STOPWORDS = ['with' => true, 'the' => true, 'and' => true, 'from' => true, 'item' => true];

    /**
     * @return array{policy: string, matches: list<array<string, mixed>>, lost_report_id: int}
     */
    public static function findFor(int $lostReportId, bool $staff): array
    {
        $lost = self::loadLostReport($lostReportId);

        if (!$staff) {
            AuthMiddleware::requireOwnedStudent((int) $lost['student_id']);
        }

        if (($lost['status'] ?? '') !== 'open') {
            return ['policy' => self::POLICY, 'matches' => [], 'lost_report_id' => $lostReportId];
        }

        $candidates = [];
        $excluded = 0;
        $threshold = Config::int('MATCH_THRESHOLD');

        foreach (self::availableFoundItems((string) $lost['category']) as $item) {
            $scored = self::score($lost, $item);
            if ($scored === null) {
                $excluded++;
                continue;
            }
            if ($scored['score'] < $threshold) {
                continue;
            }

            $candidates[] = [
                'item' => $item,
                'score' => $scored['score'],
                'reasons' => $scored['reasons'],
            ];
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return ((int) $a['item']['id']) <=> ((int) $b['item']['id']);
            }
            return $b['score'] <=> $a['score'];
        });

        $matches = [];
        foreach ($candidates as $candidate) {
            $matches[] = $staff
                ? self::staffMatch($lostReportId, $lost, $candidate['item'], $candidate['score'], $candidate['reasons'])
                : self::studentMatch($lostReportId, $candidate['item'], $candidate['score'], $candidate['reasons']);
        }

        return [
            'policy' => self::policyWithExclusions($excluded),
            'matches' => $matches,
            'lost_report_id' => $lostReportId,
        ];
    }

    /**
     * @return array{policy: string, matches: list<array<string, mixed>>}
     */
    public static function allForStaff(): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id FROM lost_reports WHERE status = 'open' ORDER BY id ASC"
        );
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $matches = [];
        $excluded = 0;
        foreach ($ids as $id) {
            $result = self::findFor((int) $id, true);
            $excluded += self::exclusionCountFromPolicy((string) $result['policy']);
            foreach ($result['matches'] as $match) {
                $matches[] = $match;
            }
        }

        return ['policy' => self::policyWithExclusions($excluded), 'matches' => $matches];
    }

    public static function policyWithExclusions(int $excluded): string
    {
        if ($excluded <= 0) {
            return self::POLICY;
        }

        return self::POLICY . sprintf(
            ' Excluded %d candidate %s dated more than 7 days before the loss.',
            $excluded,
            $excluded === 1 ? 'item' : 'items'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadLostReport(int $lostReportId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT lr.id, lr.student_id, lr.category, lr.description, lr.location, lr.event_date, lr.status,'
            . ' s.student_number AS lost_student_number'
            . ' FROM lost_reports lr JOIN students s ON s.id = lr.student_id'
            . ' WHERE lr.id = :id'
        );
        $stmt->execute([':id' => $lostReportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException('Lost report not found.');
        }
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function availableFoundItems(string $category): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT fi.id, fi.finder_student_id, fi.category, fi.description, fi.location, fi.event_date,'
            . ' fs.student_number AS finder_number, fs.full_name AS finder_name'
            . ' FROM found_items fi LEFT JOIN students fs ON fs.id = fi.finder_student_id'
            . " WHERE fi.status = 'available' AND fi.category = :category"
            . ' ORDER BY fi.id ASC'
        );
        $stmt->execute([':category' => $category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $lost
     * @param array<string, mixed> $item
     * @return array{score: int, reasons: list<string>}|null Null means the pair violates the date policy.
     */
    private static function score(array $lost, array $item): ?array
    {
        $score = 45;
        $reasons = ['same category'];

        $lostLocation = canonical_location((string) ($lost['location'] ?? ''));
        $foundLocation = canonical_location((string) ($item['location'] ?? ''));
        if ($lostLocation !== null && $foundLocation !== null && $lostLocation === $foundLocation) {
            $score += 20;
            $reasons[] = 'same location: ' . $lostLocation;
        }

        $shared = self::sharedKeywords(
            (string) ($lost['description'] ?? ''),
            (string) ($item['description'] ?? '')
        );
        if ($shared !== []) {
            $score += min(25, count($shared) * 8);
            $reasons[] = 'shared keywords: ' . implode(', ', $shared);
        }

        $lostDate = self::parseDate((string) ($lost['event_date'] ?? ''));
        $foundDate = self::parseDate((string) ($item['event_date'] ?? ''));
        if ($lostDate !== null && $foundDate !== null) {
            $diff = $lostDate->diff($foundDate);
            if ($diff->invert === 1 && $diff->days > 7) {
                return null;
            }
            if ($diff->days <= 7) {
                $score += 10;
                $reasons[] = 'dates within 7 days';
            }
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    /**
     * @return list<string>
     */
    private static function sharedKeywords(string $lostDescription, string $foundDescription): array
    {
        $shared = array_values(array_intersect(self::tokens($lostDescription), self::tokens($foundDescription)));
        sort($shared, SORT_STRING);
        return $shared;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $description): array
    {
        preg_match_all('/[a-z0-9]+/', strtolower($description), $matches);
        $tokens = [];
        foreach ($matches[0] as $token) {
            if (strlen($token) <= 2 || isset(self::STOPWORDS[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        if (!valid_iso_date($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date === false ? null : $date;
    }

    /**
     * @param array<string, mixed> $lost
     * @param array<string, mixed> $item
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private static function staffMatch(
        int $lostReportId,
        array $lost,
        array $item,
        int $score,
        array $reasons
    ): array {
        return [
            'found_item_id' => (int) $item['id'],
            'found_description' => (string) $item['description'],
            'finder_student_id' => $item['finder_student_id'] === null ? null : (int) $item['finder_student_id'],
            'finder_number' => $item['finder_number'] === null ? null : (string) $item['finder_number'],
            'finder_name' => $item['finder_name'] === null ? null : (string) $item['finder_name'],
            'score' => $score,
            'reasons' => $reasons,
            'lost_report_id' => $lostReportId,
            'lost_description' => (string) $lost['description'],
            'lost_student_number' => (string) ($lost['lost_student_number'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private static function studentMatch(
        int $lostReportId,
        array $item,
        int $score,
        array $reasons
    ): array {
        // Students receive stable codes only. Free-text reasons can contain keywords
        // from the finder's description, which would disclose distinguishing details.
        $codes = [];
        foreach ($reasons as $reason) {
            if ($reason === 'same category') {
                $codes[] = 'same_category';
            } elseif (str_starts_with($reason, 'same location:')) {
                $codes[] = 'same_location';
            } elseif (str_starts_with($reason, 'shared keywords:')) {
                $codes[] = 'shared_keywords';
            } elseif ($reason === 'dates within 7 days') {
                $codes[] = 'dates_near';
            }
        }

        return [
            'found_item_id' => (int) $item['id'],
            'category' => (string) $item['category'],
            'location' => (string) $item['location'],
            'event_date' => (string) $item['event_date'],
            'score' => $score,
            'reasons' => $codes,
            'lost_report_id' => $lostReportId,
            'description_preview' => 'Details withheld until staff verification',
        ];
    }

    private static function exclusionCountFromPolicy(string $policy): int
    {
        if ($policy === self::POLICY) {
            return 0;
        }
        return preg_match('/Excluded (\d+) candidate/', $policy, $matches) === 1 ? (int) $matches[1] : 0;
    }
}
