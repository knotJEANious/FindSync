<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Request;
use FindSync\Core\Response;
use FindSync\Core\ValidationException;
use FindSync\Middleware\AuthMiddleware;
use FindSync\Repositories\ClaimRepository;
use FindSync\Services\ClaimService;

final class ClaimController
{
    public function index(array $params = []): never
    {
        $staff = AuthMiddleware::isStaff();

        // Students are always scoped to their own claims, so no other student's
        // ownership evidence can be returned here.
        Response::success([
            'claims' => (new ClaimRepository())->allFor($staff ? null : AuthMiddleware::userId(), $staff),
        ]);
    }

    public function approve(array $params = []): never
    {
        AuthMiddleware::requireStaff();
        $data = Request::json();

        $claim = ClaimService::approve(
            (int) ($params['id'] ?? 0),
            AuthMiddleware::userId(),
            normalize_text($data['staff_notes'] ?? '')
        );

        Response::success(['claim' => $claim]);
    }

    public function reject(array $params = []): never
    {
        AuthMiddleware::requireStaff();
        $data = Request::json();

        $claim = ClaimService::reject(
            (int) ($params['id'] ?? 0),
            AuthMiddleware::userId(),
            normalize_text($data['reason'] ?? '')
        );

        Response::success(['claim' => $claim]);
    }

    public function release(array $params = []): never
    {
        AuthMiddleware::requireStaff();
        $data = Request::json();

        if (($data['confirm'] ?? null) !== true) {
            throw new ValidationException(
                'Release must be explicitly confirmed.',
                ['confirm' => 'Set confirm to true to release the item.']
            );
        }

        Response::success(ClaimService::release((int) ($params['id'] ?? 0), AuthMiddleware::userId()));
    }
}
