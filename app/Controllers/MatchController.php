<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Response;
use FindSync\Database\Database;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Services\MatchingService;
use PDO;

final class MatchController
{
    public function index(): never
    {
        if (AuthMiddleware::isStaff()) {
            Response::success(MatchingService::allForStaff());
        }

        $studentId = AuthMiddleware::studentId();
        $matches = [];
        $policy = MatchingService::POLICY;

        if ($studentId !== null) {
            $stmt = Database::connection()->prepare(
                "SELECT id FROM lost_reports WHERE student_id = :student_id AND status = 'open' ORDER BY id ASC"
            );
            $stmt->execute([':student_id' => $studentId]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($ids as $id) {
                $result = MatchingService::findFor((int) $id, false);
                if ($result['policy'] !== MatchingService::POLICY) {
                    $policy = (string) $result['policy'];
                }
                foreach ($result['matches'] as $match) {
                    $matches[] = $match;
                }
            }
        }

        Response::success(['policy' => $policy, 'matches' => $matches]);
    }
}
