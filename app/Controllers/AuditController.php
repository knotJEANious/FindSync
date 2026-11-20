<?php

declare(strict_types=1);

namespace FindSync\Controllers;

use FindSync\Core\Response;
use FindSync\Repositories\AuditRepository;

final class AuditController
{
    public function index(): never
    {
        Response::success(['logs' => (new AuditRepository())->all(200)]);
    }
}
