<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Services\ClaimService;

final class ClaimRequestController
{
    public function store(array $params = []): never
    {
        $data = Request::json();

        Response::success(
            ClaimService::create(
                (int) ($data['lost_report_id'] ?? 0),
                (int) ($data['found_item_id'] ?? 0),
                normalize_text($data['ownership_evidence'] ?? ''),
                AuthMiddleware::userId(),
                AuthMiddleware::studentId()
            ),
            201
        );
    }
}
