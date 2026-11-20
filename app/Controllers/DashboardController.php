<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Response;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\DashboardRepository;

final class DashboardController
{
    public function staff(): never
    {
        $repository = new DashboardRepository();

        Response::success([
            ...$repository->staffCounts(),
            'recent_activity' => $repository->recentActivity(),
            'latest_imports' => $repository->latestImports(),
        ]);
    }

    public function student(): never
    {
        $id = (int) AuthMiddleware::studentId();
        $repository = new DashboardRepository();

        Response::success([
            ...$repository->studentCounts($id),
            'recent_activity' => $repository->studentRecent($id),
        ]);
    }
}
